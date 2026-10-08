<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Helpers\Money;

/**
 * Pembayaran angsuran yang dicatat Ketua Regu.
 *
 * Pembayaran berupa SATU angka per anggota (seperti di Excel). Sistem membagikannya otomatis ke cicilan
 * pinjaman anggota itu, **cicilan paling tua dulu** (bulan jatuh tempo, lalu pinjaman, lalu urutan cicilan),
 * boleh sebagian, boleh melunasi lebih awal (cicilan yang belum jatuh tempo ikut terisi setelah yang lama
 * lunas), tanpa denda (Q7). Hasil pembagian disimpan di `installment_payments` dan SELALU berjumlah persis
 * nominal pembayaran.
 *
 * Pembagian memperhitungkan pembayaran yang sudah DISETUJUI dan yang sedang MENUNGGU validasi (dicadangkan),
 * sehingga dua pembayaran yang menunggu tidak pernah berebut cicilan yang sama. Rincian tidak bisa diubah
 * setelah diajukan (trigger database), maka pembagian dihitung ulang saat diajukan, ketika keadaannya terbaru.
 * Hanya pinjaman yang dicairkan SEBELUM bulan pembayaran yang bisa dibayar (sama dengan aturan impor Excel).
 * Jumlah yang melebihi sisa tagihan ditolak: tidak ada kelebihan bayar.
 */
final class InstallmentService
{
    public const MAX_AMOUNT = 1000000000;

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>} [galat, data bersih]
     */
    public static function parse(array $in): array
    {
        $description = clean_text($in['description'] ?? '');
        $raw         = trim((string) ($in['amount'] ?? ''));
        $data = [
            'member_id'         => (int) ($in['member_id'] ?? 0),
            'period_month_id'   => (int) ($in['period_month_id'] ?? 0),
            'amount'            => Money::parse($raw) ?? 0,
            'trx_date'          => trim((string) ($in['trx_date'] ?? '')),
            'description'       => $description === '' ? null : $description,
            'confirm_duplicate' => !empty($in['confirm_duplicate']),
        ];

        $errors = Validator::validate(['description' => $description], ['description' => 'max:255'], ['description' => 'Keterangan']);
        if ($data['member_id'] <= 0) {
            $errors['member_id'] = 'Anggota wajib dipilih.';
        }
        if ($data['period_month_id'] <= 0) {
            $errors['period_month_id'] = 'Bulan pembayaran wajib dipilih.';
        }
        if ($raw === '') {
            $errors['amount'] = 'Jumlah pembayaran wajib diisi.';
        } elseif (Money::parse($raw) === null) {
            $errors['amount'] = 'Jumlah: isi dengan angka bulat rupiah tanpa desimal, mis. 1.100.000.';
        } elseif ($data['amount'] <= 0) {
            $errors['amount'] = 'Jumlah harus lebih dari nol.';
        } elseif ($data['amount'] > self::MAX_AMOUNT) {
            $errors['amount'] = 'Jumlah terlalu besar. Periksa kembali jumlah nolnya.';
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $data['trx_date']);
        if ($date === false || $date->format('Y-m-d') !== $data['trx_date']) {
            $errors['trx_date'] = 'Tanggal pembayaran tidak valid.';
        }
        return [$errors, $data];
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Catat pembayaran sebagai DRAFT (alokasi sudah dihitung); bila $submit, langsung diajukan.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @return int id transaksi
     */
    public static function create(Request $request, array $actor, array $data, bool $submit): int
    {
        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $data, $submit): int {
            $plan = self::plan($pdo, $actor, $data, null);
            $num  = NumberSequence::forTransaction($pdo, 'ANGSURAN', (int) substr((string) $data['trx_date'], 0, 4));

            $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, description, source, created_by)
                           VALUES (?, ?, 'ANGSURAN', ?, ?, ?, ?, ?, 'DRAFT', ?, 'APLIKASI', ?)")
                ->execute([$num['trx_no'], $num['doc_no'], $data['member_id'], $plan['ctx']['team_id'], $data['period_month_id'], $data['trx_date'], $data['amount'], $data['description'], $actor['id']]);
            $id = (int) $pdo->lastInsertId();
            self::writeAllocations($pdo, $id, $plan['allocations']);

            AuditLog::record($request, $actor, 'PAYMENT_CREATED', 'transaction', $id, $num['doc_no'], null, self::snapshot($data, $plan), $pdo);
            if ($submit) {
                TransactionFlow::move($pdo, $request, $actor, ['id' => $id, 'doc_no' => $num['doc_no'], 'status' => 'DRAFT'], 'MENUNGGU_VALIDASI', null, 'PAYMENT_SUBMITTED');
            }
            return $id;
        });
    }

    /**
     * Ubah pembayaran yang masih DRAFT (hanya pembuatnya); alokasi dihitung ulang. Bila $submit, langsung diajukan.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @param string $version nilai updated_at saat formulir dibuka (deteksi edit bersamaan)
     */
    public static function update(Request $request, array $actor, int $id, array $data, string $version, bool $submit): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $data, $version, $submit): void {
            $trx = TransactionFlow::lockOwned($pdo, $actor, $id, $version, 'ANGSURAN');
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Hanya draft yang bisa diubah. Transaksi ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . '.');
            }
            $plan      = self::plan($pdo, $actor, $data, $id);
            $oldAlloc  = self::storedAllocations($pdo, $id);
            $before    = self::snapshot(self::rowData($trx), ['ctx' => ['team_id' => (int) $trx['team_id']], 'allocations' => $oldAlloc]);
            $after     = self::snapshot($data, $plan);
            $changed   = array_keys(array_filter($after, static fn ($v, $k): bool => json_encode($before[$k] ?? null) !== json_encode($v), ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $pdo->prepare('UPDATE transactions SET member_id = ?, team_id = ?, period_month_id = ?, trx_date = ?, amount = ?, description = ? WHERE id = ?')
                    ->execute([$data['member_id'], $plan['ctx']['team_id'], $data['period_month_id'], $data['trx_date'], $data['amount'], $data['description'], $id]);
                self::writeAllocations($pdo, $id, $plan['allocations']);
                AuditLog::record($request, $actor, 'PAYMENT_UPDATED', 'transaction', $id, (string) $trx['doc_no'],
                    array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $pdo);
            }
            if ($submit) {
                TransactionFlow::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'PAYMENT_SUBMITTED');
            }
        });
    }

    /**
     * Ajukan draft ke validasi. Semua aturan dan PEMBAGIAN dihitung ulang dengan keadaan terkini (pembayaran lain
     * mungkin sudah disetujui atau dicadangkan sejak draft dibuat); rincian tidak bisa diubah lagi setelah ini.
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     */
    public static function submit(Request $request, array $actor, int $id, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version): void {
            $trx = TransactionFlow::lockOwned($pdo, $actor, $id, $version, 'ANGSURAN');
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Transaksi ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . ', tidak bisa diajukan lagi.');
            }
            $data = self::rowData($trx) + ['confirm_duplicate' => true];
            $plan = self::plan($pdo, $actor, $data, $id);

            $old = self::storedAllocations($pdo, $id);
            if (json_encode($old) !== json_encode(self::compact($plan['allocations']))) {
                self::writeAllocations($pdo, $id, $plan['allocations']);
                AuditLog::record($request, $actor, 'PAYMENT_REALLOCATED', 'transaction', $id, (string) $trx['doc_no'], ['allocations' => $old], ['allocations' => self::compact($plan['allocations'])], $pdo);
            }
            TransactionFlow::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'PAYMENT_SUBMITTED');
        });
    }

    /**
     * Batalkan draft atau pembayaran yang menunggu validasi, dengan alasan. Cicilan yang dicadangkan terlepas.
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     */
    public static function cancel(Request $request, array $actor, int $id, string $reason, string $version): void
    {
        TransactionFlow::cancel($request, $actor, $id, $reason, $version, 'ANGSURAN', 'PAYMENT_CANCELLED');
    }

    // ------------------------------------------------------------------ aturan dan alokasi

    /**
     * @param array<string,mixed> $data
     * @param array{id:int,roles:array<int,string>,team_id?:?int} $actor
     * @return array{ctx:array<string,mixed>,allocations:array<int,array<string,mixed>>}
     */
    private static function plan(\PDO $pdo, array $actor, array $data, ?int $exceptId): array
    {
        $ctx = TransactionFlow::assertRecordable($pdo, $actor, $data);

        if (empty($data['confirm_duplicate'])) {
            $stmt = $pdo->prepare("SELECT doc_no FROM transactions
                                   WHERE type = 'ANGSURAN' AND member_id = ? AND period_month_id = ? AND amount = ? AND reverses_id IS NULL
                                     AND deleted_at IS NULL AND status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI') AND id <> ? LIMIT 1");
            $stmt->execute([$ctx['member_id'], (int) $data['period_month_id'], (int) $data['amount'], $exceptId ?? 0]);
            $dup = $stmt->fetchColumn();
            if ($dup !== false) {
                throw RuleViolation::field('duplicate', "Pembayaran yang sama persis (anggota, bulan, jumlah) sudah ada: {$dup}. Centang konfirmasi bila ini memang pembayaran terpisah.");
            }
        }
        return ['ctx' => $ctx, 'allocations' => self::allocate($pdo, $ctx['member_id'], $ctx['month'], (int) $data['amount'], $exceptId)];
    }

    /**
     * Cicilan yang masih punya sisa untuk dibayar anggota ini pada bulan tertentu, paling tua dulu.
     * Memperhitungkan pembayaran DISETUJUI dan pembayaran lain yang MENUNGGU validasi (dicadangkan).
     *
     * @return array<int,array{installment_id:int,loan_id:int,loan_no:string,seq:int,due_month:string,room:int}>
     */
    public static function payableInstallments(\PDO $pdo, int $memberId, string $monthDate, ?int $exceptTrxId): array
    {
        $stmt = $pdo->prepare("SELECT li.id AS installment_id, li.loan_id, lt.doc_no AS loan_no, li.seq, pm.month_date AS due_month,
                                      li.amount_due - COALESCE(ap.amt, 0) - COALESCE(rp.amt, 0) AS room
                               FROM v_loan_balances b
                               JOIN loan_installments li ON li.loan_id = b.loan_id
                               JOIN period_months pm ON pm.id = li.due_month_id
                               JOIN transactions lt ON lt.id = b.transaction_id
                               JOIN period_months lpm ON lpm.id = lt.period_month_id
                               LEFT JOIN (SELECT ip.installment_id, SUM(ip.amount) AS amt FROM installment_payments ip
                                          JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL
                                          GROUP BY ip.installment_id) ap ON ap.installment_id = li.id
                               LEFT JOIN (SELECT ip.installment_id, SUM(ip.amount) AS amt FROM installment_payments ip
                                          JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'MENUNGGU_VALIDASI' AND pt.deleted_at IS NULL AND pt.id <> ?
                                          WHERE ip.amount > 0   /* pembalik yang menunggu (alokasi negatif) tidak melonggarkan cadangan */
                                          GROUP BY ip.installment_id) rp ON rp.installment_id = li.id
                               WHERE b.member_id = ? AND lpm.month_date < ?
                               HAVING room > 0
                               ORDER BY pm.month_date, li.loan_id, li.seq");
        $stmt->execute([$exceptTrxId ?? 0, $memberId, $monthDate]);
        return array_map(static fn (array $r): array => [
            'installment_id' => (int) $r['installment_id'], 'loan_id' => (int) $r['loan_id'], 'loan_no' => (string) $r['loan_no'],
            'seq' => (int) $r['seq'], 'due_month' => (string) $r['due_month'], 'room' => (int) $r['room'],
        ], $stmt->fetchAll());
    }

    /**
     * Bagikan $amount ke cicilan paling tua dulu. Jumlah hasil bagi SELALU = $amount, atau melempar RuleViolation.
     * @return array<int,array{installment_id:int,loan_no:string,seq:int,due_month:string,amount:int}>
     */
    public static function allocate(\PDO $pdo, int $memberId, string $monthDate, int $amount, ?int $exceptTrxId): array
    {
        $rows = self::payableInstallments($pdo, $memberId, $monthDate, $exceptTrxId);
        if ($rows === []) {
            throw RuleViolation::field('amount', 'Anggota ini tidak punya cicilan yang bisa dibayar pada ' . month_label($monthDate) . '. Pinjaman yang dicairkan bulan ini baru bisa dibayar bulan berikutnya, dan pinjaman harus sudah disetujui.');
        }
        $total = array_sum(array_column($rows, 'room'));
        if ($amount > $total) {
            throw RuleViolation::field('amount', 'Melebihi sisa tagihan yang bisa dibayar (' . Money::format($total) . '). Kelebihan bayar tidak diterima; kurangi jumlahnya.');
        }
        $left = $amount;
        $out  = [];
        foreach ($rows as $r) {
            if ($left <= 0) {
                break;
            }
            $take  = min($r['room'], $left);
            $out[] = ['installment_id' => $r['installment_id'], 'loan_no' => $r['loan_no'], 'seq' => $r['seq'], 'due_month' => $r['due_month'], 'amount' => $take];
            $left -= $take;
        }
        return $out;
    }

    // ------------------------------------------------------------------ internal

    /** @param array<int,array<string,mixed>> $allocations */
    private static function writeAllocations(\PDO $pdo, int $trxId, array $allocations): void
    {
        $pdo->prepare('DELETE FROM installment_payments WHERE transaction_id = ?')->execute([$trxId]);
        $ins = $pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)');
        foreach ($allocations as $a) {
            $ins->execute([$trxId, $a['installment_id'], $a['amount']]);
        }
    }

    /** @return array<int,array{installment_id:int,amount:int}> */
    private static function storedAllocations(\PDO $pdo, int $trxId): array
    {
        $stmt = $pdo->prepare('SELECT ip.installment_id, ip.amount FROM installment_payments ip
                               JOIN loan_installments li ON li.id = ip.installment_id
                               JOIN period_months pm ON pm.id = li.due_month_id
                               WHERE ip.transaction_id = ? ORDER BY pm.month_date, li.loan_id, li.seq');
        $stmt->execute([$trxId]);
        return array_map(static fn (array $r): array => ['installment_id' => (int) $r['installment_id'], 'amount' => (int) $r['amount']], $stmt->fetchAll());
    }

    /**
     * @param array<int,array<string,mixed>> $allocations
     * @return array<int,array{installment_id:int,amount:int}>
     */
    private static function compact(array $allocations): array
    {
        return array_map(static fn (array $a): array => ['installment_id' => (int) $a['installment_id'], 'amount' => (int) $a['amount']], $allocations);
    }

    /**
     * @param array<string,mixed> $trx
     * @return array<string,mixed>
     */
    private static function rowData(array $trx): array
    {
        return [
            'member_id' => (int) $trx['member_id'], 'period_month_id' => (int) $trx['period_month_id'], 'amount' => (int) $trx['amount'],
            'trx_date' => (string) $trx['trx_date'], 'description' => $trx['description'],
        ];
    }

    /**
     * Isi pembayaran untuk audit (satu bentuk untuk sebelum/sesudah agar mudah dibandingkan).
     * @param array<string,mixed> $data @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function snapshot(array $data, array $plan): array
    {
        return [
            'member_id' => (int) $data['member_id'], 'team_id' => (int) $plan['ctx']['team_id'], 'period_month_id' => (int) $data['period_month_id'],
            'amount' => (int) $data['amount'], 'trx_date' => (string) $data['trx_date'], 'description' => $data['description'] ?? null,
            'allocations' => self::compact((array) $plan['allocations']),
        ];
    }
}
