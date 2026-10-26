<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Helpers\Money;

/**
 * Validasi transaksi (Setujui / Tolak). Satu-satunya jalan transaksi menjadi DISETUJUI di aplikasi, dan hanya
 * DISETUJUI yang masuk saldo. Semuanya terjadi dalam satu transaksi database bersama riwayat status dan audit.
 *
 * Hanya Superadmin (izin transaction.validate) yang menyetujui atau menolak, dan ia boleh menyetujui semua transaksi,
 * termasuk yang ia catat sendiri sebagai Admin. Tidak ada akun Pemeriksa dan tidak ada pemisahan pembuat-penyetuju.
 *
 * Penyetujuan memeriksa ulang keadaan terkini (tidak percaya pada apa yang benar saat diajukan):
 *  - pencairan dan pengeluaran kas: kas tersedia cukup, diperiksa SECARA ATOMIK (kunci global kas) sehingga dua
 *    persetujuan serentak tidak bisa sama-sama memakai kas yang sama (Q6);
 *  - anggota masih aktif; jadwal pinjaman utuh; pembagian angsuran sah dan tidak melebihi tagihan.
 *  - transaksi pembalik (Phase 10): aturannya di ReversalService::assertApprovable.
 * Penolakan wajib beralasan. DISETUJUI dan DITOLAK final.
 */
final class ValidationService
{
    /**
     * Apakah pengguna ini boleh memvalidasi transaksi ini? (Tidak mengubah apa pun.)
     * Hanya pemilik izin transaction.validate (Superadmin/HEAD). Tidak ada pemisahan pembuat dan penyetuju:
     * Superadmin menyetujui SEMUA transaksi, termasuk yang ia catat sendiri sebagai Admin (keputusan pemilik koperasi).
     *
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int} $user
     * @return array{eligible:bool,reason:?string}
     */
    public static function assess(array $user): array
    {
        if (!Gate::allows($user, 'transaction.validate')) {
            return ['eligible' => false, 'reason' => 'Akun Anda tidak berhak memvalidasi transaksi.'];
        }
        return ['eligible' => true, 'reason' => null];
    }

    /**
     * Penilaian untuk tampilan.
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int} $user
     * @param array<string,mixed> $trx
     * @return array{eligible:bool,reason:?string}
     */
    public static function assessFor(array $user, array $trx): array
    {
        return self::assess($user);
    }

    /** Kas tersedia saat ini (hanya transaksi DISETUJUI). */
    public static function cash(): int
    {
        return (int) Database::select('SELECT kas_tersedia FROM v_global_summary')[0]['kas_tersedia'];
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Setujui transaksi. Catatan opsional.
     * @param array{id:int,username:string,roles:array<int,string>,member_id?:?int} $actor
     */
    public static function approve(Request $request, array $actor, int $id, string $version, ?string $note): void
    {
        $note = self::cleanNote($note, false);
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version, $note): void {
            // Kunci global KAS diambil PERTAMA, sebelum pembacaan biasa apa pun: dalam REPEATABLE READ, potret data
            // dibuat pada pembacaan biasa pertama, jadi kunci yang diambil belakangan akan membaca kas yang basi.
            self::lockCash($pdo);
            $trx = self::lockPending($pdo, $actor, $id, $version);
            self::assertApprovable($pdo, $trx);
            TransactionFlow::move($pdo, $request, $actor, $trx, 'DISETUJUI', $note, 'TRX_APPROVED');
            // Tanggal pinjaman diterima = tanggal disetujui; dari sini jadwal jatuh tempo per tanggal ditetapkan (sekali).
            if ($trx['type'] === 'PENCAIRAN_PINJAMAN' && $trx['reverses_id'] === null) {
                $approvedOn = DueSchedule::approvalDate();
                $dates      = DueSchedule::stamp($pdo, (int) $trx['id'], $approvedOn);
                if ($dates !== []) {
                    AuditLog::record($request, $actor, 'LOAN_SCHEDULE_DATED', 'transaction', (int) $trx['id'], (string) $trx['doc_no'], null,
                        ['approved_on' => $approvedOn, 'first_due' => $dates[0], 'last_due' => $dates[count($dates) - 1], 'installments' => count($dates)], $pdo);
                }
            }
        });
    }

    /**
     * Tolak transaksi. Alasan wajib. Transaksi yang ditolak final; pembuat harus membuat transaksi baru.
     * @param array{id:int,username:string,roles:array<int,string>,member_id?:?int} $actor
     */
    public static function reject(Request $request, array $actor, int $id, string $version, ?string $note): void
    {
        $note = self::cleanNote($note, true);
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version, $note): void {
            $trx = self::lockPending($pdo, $actor, $id, $version);
            TransactionFlow::move($pdo, $request, $actor, $trx, 'DITOLAK', $note, 'TRX_REJECTED');
        });
    }

    // ------------------------------------------------------------------ internal

    /** Satu baris pengaturan dipakai sebagai kunci global kas; dilepas saat transaksi database selesai. */
    private static function lockCash(\PDO $pdo): void
    {
        $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_negative_cash' FOR UPDATE")->fetchAll();
    }

    private static function cleanNote(?string $note, bool $required): ?string
    {
        $note = clean_text($note ?? '');
        if ($required && $note === '') {
            throw RuleViolation::field('note', 'Alasan penolakan wajib diisi.');
        }
        if (mb_strlen($note) > 200) {
            throw RuleViolation::field('note', 'Catatan maksimal 200 karakter.');
        }
        return $note === '' ? null : $note;
    }

    /**
     * Kunci transaksi, pastikan masih MENUNGGU_VALIDASI dan versinya yang dilihat validator, lalu periksa
     * kewenangan (otoritatif; tampilan hanya cermin dari ini).
     * @param array{id:int,roles:array<int,string>,member_id?:?int} $actor
     * @return array<string,mixed>
     */
    private static function lockPending(\PDO $pdo, array $actor, int $id, string $version): array
    {
        $stmt = $pdo->prepare('SELECT t.* FROM transactions t WHERE t.id = ? AND t.deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$id]);
        $trx = $stmt->fetch();
        if ($trx === false) {
            throw RuleViolation::field('_form', 'Transaksi tidak ditemukan.');
        }
        if ($trx['status'] !== 'MENUNGGU_VALIDASI') {
            throw RuleViolation::field('_form', 'Transaksi ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . ', tidak ada yang perlu divalidasi.');
        }
        if ((string) $trx['updated_at'] !== $version) {
            throw RuleViolation::field('_form', 'Transaksi ini baru saja berubah. Muat ulang halaman, periksa lagi, lalu putuskan.');
        }

        $verdict = self::assess($actor);
        if (!$verdict['eligible']) {
            throw RuleViolation::field('_form', (string) $verdict['reason']);
        }
        return $trx;
    }

    /**
     * Pemeriksaan keadaan terkini sebelum DISETUJUI.
     * @param array<string,mixed> $trx
     */
    private static function assertApprovable(\PDO $pdo, array $trx): void
    {
        $type   = (string) $trx['type'];
        $amount = (int) $trx['amount'];

        if ($trx['reverses_id'] !== null) {
            // Pembalik: aturannya sendiri (anggota nonaktif tetap boleh dikoreksi; kas dan saldo dijaga di dalamnya).
            ReversalService::assertApprovable($pdo, $trx);
            return;
        }

        if (in_array($type, ['SIMPANAN', 'PENCAIRAN_PINJAMAN', 'PENARIKAN'], true)) {
            $stmt = $pdo->prepare('SELECT status FROM members WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([(int) $trx['member_id']]);
            if ($stmt->fetchColumn() !== 'AKTIF') {
                throw RuleViolation::field('_form', 'Anggota ini sudah tidak aktif, jadi transaksinya tidak bisa disetujui. Tolak transaksi ini.');
            }
        }

        if ($type === 'PENCAIRAN_PINJAMAN') {
            self::assertLoanIntact($pdo, (int) $trx['id'], $amount);
        }
        if ($type === 'ANGSURAN') {
            self::assertAllocationsSound($pdo, (int) $trx['id'], $amount);
        }

        if (in_array($type, ['PENCAIRAN_PINJAMAN', 'PENARIKAN', 'BIAYA'], true)) {
            self::assertCashCovers($pdo, $amount, $type);
        }
        if ($type === 'PENARIKAN') {
            if (!(bool) SettingsService::get('withdrawals_enabled')) {
                throw RuleViolation::field('_form', 'Penarikan tabungan sedang dinonaktifkan di Pengaturan.');
            }
            $stmt = $pdo->prepare('SELECT savings_balance FROM v_member_savings WHERE member_id = ?');
            $stmt->execute([(int) $trx['member_id']]);
            if ((int) $stmt->fetchColumn() < $amount) {
                throw RuleViolation::field('_form', 'Saldo tabungan anggota tidak cukup untuk penarikan ini.');
            }
        }
    }

    /**
     * Pengeluaran kas harus ≤ kas tersedia (kecuali pengaturan mengizinkan). Dipanggil setelah lockCash(): setiap
     * persetujuan bergiliran, dan pembacaan kas di sini melihat persetujuan sebelumnya.
     */
    private static function assertCashCovers(\PDO $pdo, int $amount, string $type): void
    {
        if ((bool) SettingsService::get('allow_negative_cash')) {
            return;
        }
        $cash = (int) $pdo->query('SELECT kas_tersedia FROM v_global_summary')->fetchColumn();
        if ($amount > $cash) {
            $what = $type === 'PENCAIRAN_PINJAMAN' ? 'Pencairan' : 'Pengeluaran';
            throw RuleViolation::field('_form', "Kas tersedia tidak cukup: {$what} " . Money::format($amount) . ' melebihi kas ' . Money::format($cash) . '. Tunggu kas bertambah, atau tolak transaksi ini.');
        }
    }

    private static function assertLoanIntact(\PDO $pdo, int $trxId, int $amount): void
    {
        $stmt = $pdo->prepare('SELECT l.id, l.principal, l.total_interest, (SELECT COALESCE(SUM(li.amount_due), 0) FROM loan_installments li WHERE li.loan_id = l.id) AS scheduled
                               FROM loans l WHERE l.transaction_id = ?');
        $stmt->execute([$trxId]);
        $loan = $stmt->fetch();
        if ($loan === false || (int) $loan['principal'] !== $amount || (int) $loan['scheduled'] !== (int) $loan['principal'] + (int) $loan['total_interest']) {
            throw RuleViolation::field('_form', 'Data pinjaman atau jadwal cicilan tidak konsisten, jadi tidak bisa disetujui. Tolak lalu minta dicatat ulang.');
        }
    }

    private static function assertAllocationsSound(\PDO $pdo, int $trxId, int $amount): void
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM installment_payments WHERE transaction_id = ?');
        $stmt->execute([$trxId]);
        if ((int) $stmt->fetchColumn() !== $amount) {
            throw RuleViolation::field('_form', 'Pembagian pembayaran ke cicilan tidak sama dengan jumlah dibayar, jadi tidak bisa disetujui. Tolak lalu minta dicatat ulang.');
        }
        // tiap cicilan: yang sudah disetujui + pembagian ini tidak boleh melebihi tagihan, dan pinjamannya harus masih efektif
        $stmt = $pdo->prepare("SELECT li.id, li.amount_due, ip.amount AS alloc,
                                      COALESCE((SELECT SUM(x.amount) FROM installment_payments x JOIN transactions xt ON xt.id = x.transaction_id AND xt.status = 'DISETUJUI' AND xt.deleted_at IS NULL
                                                WHERE x.installment_id = li.id), 0) AS paid,
                                      (SELECT COUNT(*) FROM v_loan_balances b WHERE b.loan_id = li.loan_id) AS effective
                               FROM installment_payments ip JOIN loan_installments li ON li.id = ip.installment_id
                               WHERE ip.transaction_id = ?");
        $stmt->execute([$trxId]);
        foreach ($stmt->fetchAll() as $r) {
            if ((int) $r['effective'] !== 1) {
                throw RuleViolation::field('_form', 'Pinjaman yang dibayar tidak lagi berlaku, jadi pembayaran tidak bisa disetujui. Tolak transaksi ini.');
            }
            if ((int) $r['paid'] + (int) $r['alloc'] > (int) $r['amount_due']) {
                throw RuleViolation::field('_form', 'Pembayaran ini akan melebihi tagihan sebuah cicilan (kelebihan bayar), jadi tidak bisa disetujui. Tolak lalu minta dicatat ulang.');
            }
        }
    }
}
