<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Validator;
use App\Helpers\Money;

/**
 * Biaya operasional (buku tabungan, cetak laporan, dst.) yang dibebankan ke Kas SHU.
 *
 * Dicatat oleh Superadmin (izin expense.manage): biaya milik koperasi, bukan milik anggota atau regu, jadi tidak ada
 * Ketua Regu yang cakupannya cocok untuk mencatatnya. Alurnya sama dengan transaksi lain: DRAFT -> MENUNGGU_VALIDASI ->
 * DISETUJUI | DITOLAK (validasi di /validasi, izin transaction.validate), atau DIBATALKAN dengan alasan. Draft tidak pernah dihapus.
 * Sumber dana SELALU Kas SHU (keputusan koperasi, mengikuti Excel); kolom fund_source tetap 'SHU'.
 *
 * Biaya baru memengaruhi kas dan Kas SHU hanya setelah DISETUJUI (saldo dihitung dari transaksi disetujui, bukan dari sini).
 * Setiap perubahan terjadi dalam satu transaksi database bersama audit dan riwayat status.
 */
final class ExpenseService
{
    /** Batas kewajaran satu biaya; mencegah salah ketik nol berlebih. */
    public const MAX_AMOUNT = 50000000;
    public const FUND = 'SHU';

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>} [galat, data bersih]
     */
    public static function parse(array $in): array
    {
        $category   = clean_text($in['category'] ?? '');
        $description = clean_text($in['description'] ?? '');
        $amountRaw  = trim((string) ($in['amount'] ?? ''));
        $data = [
            'category'          => $category,
            'period_month_id'   => (int) ($in['period_month_id'] ?? 0),
            'amount'            => Money::parse($amountRaw) ?? 0,
            'trx_date'          => trim((string) ($in['trx_date'] ?? '')),
            'description'       => $description === '' ? null : $description,
            'confirm_duplicate' => !empty($in['confirm_duplicate']),
        ];

        $errors = Validator::validate(
            ['category' => $category, 'description' => $description],
            ['category' => 'required|max:60', 'description' => 'max:255'],
            ['category' => 'Keperluan biaya', 'description' => 'Keterangan'],
        );
        if ($data['period_month_id'] <= 0) {
            $errors['period_month_id'] = 'Bulan wajib dipilih.';
        }
        if ($amountRaw === '') {
            $errors['amount'] = 'Nominal wajib diisi.';
        } elseif (Money::parse($amountRaw) === null) {
            $errors['amount'] = 'Nominal: isi dengan angka bulat rupiah tanpa desimal, mis. 45.000.';
        } elseif ($data['amount'] <= 0) {
            $errors['amount'] = 'Nominal harus lebih dari nol.';
        } elseif ($data['amount'] > self::MAX_AMOUNT) {
            $errors['amount'] = 'Nominal terlalu besar (maksimum ' . Money::format(self::MAX_AMOUNT) . ' per biaya). Periksa kembali jumlah nolnya.';
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $data['trx_date']);
        if ($date === false || $date->format('Y-m-d') !== $data['trx_date']) {
            $errors['trx_date'] = 'Tanggal biaya tidak valid.';
        }
        return [$errors, $data];
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Catat biaya baru sebagai DRAFT; bila $submit, langsung diajukan ke validasi.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @return int id transaksi
     */
    public static function create(Request $request, array $actor, array $data, bool $submit): int
    {
        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $data, $submit): int {
            self::assertRecordable($pdo, $actor, $data, null);
            $num = NumberSequence::forTransaction($pdo, 'BIAYA', (int) substr((string) $data['trx_date'], 0, 4));
            $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, description, source, created_by)
                           VALUES (?, ?, 'BIAYA', NULL, NULL, ?, ?, ?, 'DRAFT', ?, 'APLIKASI', ?)")
                ->execute([$num['trx_no'], $num['doc_no'], $data['period_month_id'], $data['trx_date'], $data['amount'], $data['description'], $actor['id']]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO expenses (transaction_id, category, fund_source) VALUES (?, ?, ?)')->execute([$id, $data['category'], self::FUND]);

            AuditLog::record($request, $actor, 'EXPENSE_CREATED', 'transaction', $id, $num['doc_no'], null, self::snapshot($data), $pdo);
            if ($submit) {
                TransactionFlow::move($pdo, $request, $actor, ['id' => $id, 'doc_no' => $num['doc_no'], 'status' => 'DRAFT'], 'MENUNGGU_VALIDASI', null, 'EXPENSE_SUBMITTED');
            }
            return $id;
        });
    }

    /**
     * Ubah biaya yang masih DRAFT (hanya pembuatnya). Bila $submit, langsung diajukan.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @param string $version nilai updated_at saat formulir dibuka (deteksi edit bersamaan)
     */
    public static function update(Request $request, array $actor, int $id, array $data, string $version, bool $submit): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $data, $version, $submit): void {
            $trx = self::lockOwned($pdo, $actor, $id, $version);
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Hanya draft yang bisa diubah. Biaya ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . '.');
            }
            self::assertRecordable($pdo, $actor, $data, $id);

            $before = self::snapshot(self::rowData($trx));
            $after  = self::snapshot($data);
            $changed = array_keys(array_filter($after, static fn ($v, $k): bool => (string) ($before[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));
            if ($changed !== []) {
                $pdo->prepare('UPDATE transactions SET period_month_id = ?, trx_date = ?, amount = ?, description = ? WHERE id = ?')
                    ->execute([$data['period_month_id'], $data['trx_date'], $data['amount'], $data['description'], $id]);
                $pdo->prepare('UPDATE expenses SET category = ? WHERE transaction_id = ?')->execute([$data['category'], $id]);
                AuditLog::record($request, $actor, 'EXPENSE_UPDATED', 'transaction', $id, (string) $trx['doc_no'],
                    array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $pdo);
            }
            if ($submit) {
                TransactionFlow::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'EXPENSE_SUBMITTED');
            }
        });
    }

    /**
     * Ajukan draft ke validasi; aturan diperiksa ulang (periode bisa saja sudah ditutup sejak draft dibuat).
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function submit(Request $request, array $actor, int $id, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version): void {
            $trx = self::lockOwned($pdo, $actor, $id, $version);
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Biaya ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . ', tidak bisa diajukan lagi.');
            }
            self::assertRecordable($pdo, $actor, self::rowData($trx) + ['confirm_duplicate' => true], $id);
            TransactionFlow::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'EXPENSE_SUBMITTED');
        });
    }

    /**
     * Batalkan draft atau biaya yang menunggu validasi, dengan alasan. Yang sudah disetujui dikoreksi lewat transaksi pembalik.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function cancel(Request $request, array $actor, int $id, string $reason, string $version): void
    {
        $reason = clean_text($reason);
        if ($reason === '') {
            throw RuleViolation::field('note', 'Alasan pembatalan wajib diisi.');
        }
        if (mb_strlen($reason) > 200) {
            throw RuleViolation::field('note', 'Alasan pembatalan maksimal 200 karakter.');
        }
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $reason, $version): void {
            $trx = self::lockOwned($pdo, $actor, $id, $version);
            if (!in_array($trx['status'], ['DRAFT', 'MENUNGGU_VALIDASI'], true)) {
                throw RuleViolation::field('_form', 'Biaya ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . ', tidak bisa dibatalkan.');
            }
            TransactionFlow::move($pdo, $request, $actor, $trx, 'DIBATALKAN', $reason, 'EXPENSE_CANCELLED');
        });
    }

    // ------------------------------------------------------------------ baca

    /**
     * Daftar biaya (terbaru dulu) dengan keperluan, pembuat, dan status.
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(int $limit = 300): array
    {
        return Database::select(
            "SELECT t.id, t.doc_no, t.trx_date, t.amount, t.status, t.description, t.updated_at, t.created_by, t.source, e.category, e.fund_source, pm.month_date, cu.name AS creator_name
             FROM transactions t
             JOIN expenses e ON e.transaction_id = t.id
             JOIN period_months pm ON pm.id = t.period_month_id
             LEFT JOIN users cu ON cu.id = t.created_by
             WHERE t.type = 'BIAYA' AND t.reverses_id IS NULL AND t.deleted_at IS NULL
             ORDER BY t.trx_date DESC, t.id DESC LIMIT " . (int) $limit
        );
    }

    /**
     * Satu biaya untuk formulir ubah (null bila bukan biaya).
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::select(
            "SELECT t.*, e.category, e.fund_source FROM transactions t JOIN expenses e ON e.transaction_id = t.id
             WHERE t.id = ? AND t.type = 'BIAYA' AND t.deleted_at IS NULL",
            [$id]
        )[0] ?? null;
    }

    // ------------------------------------------------------------------ aturan

    /**
     * Aturan sebelum biaya boleh dicatat atau diajukan: berhak, bulan pada periode aktif dan sudah berjalan, tanggal wajar,
     * dan tidak ada biaya kembar tanpa konfirmasi. Dipanggil di dalam transaksi database.
     * @param array<string,mixed> $data
     * @param array{id:int,roles:array<int,string>} $actor
     */
    private static function assertRecordable(\PDO $pdo, array $actor, array $data, ?int $exceptId): void
    {
        if (!Gate::allows($actor, 'expense.manage')) {
            throw RuleViolation::field('_form', 'Akun Anda tidak berhak mencatat biaya.');
        }
        $stmt = $pdo->prepare("SELECT pm.id, pm.month_date FROM period_months pm JOIN periods p ON p.id = pm.period_id WHERE pm.id = ? AND p.status = 'AKTIF'");
        $stmt->execute([(int) $data['period_month_id']]);
        $month = $stmt->fetch();
        if ($month === false) {
            throw RuleViolation::field('period_month_id', 'Bulan tidak ditemukan atau periodenya sudah ditutup.');
        }
        if ((string) $month['month_date'] > date('Y-m-01')) {
            throw RuleViolation::field('period_month_id', 'Belum bisa mencatat biaya untuk bulan yang belum berjalan.');
        }
        $date = (string) $data['trx_date'];
        if ($date > date('Y-m-d')) {
            throw RuleViolation::field('trx_date', 'Tanggal tidak boleh di masa depan.');
        }
        if ($date < (string) $month['month_date']) {
            throw RuleViolation::field('trx_date', 'Tanggal tidak boleh sebelum awal bulan ' . month_label((string) $month['month_date']) . '.');
        }
        if (empty($data['confirm_duplicate'])) {
            $q = $pdo->prepare("SELECT t.doc_no FROM transactions t JOIN expenses e ON e.transaction_id = t.id
                                WHERE t.period_month_id = ? AND e.category = ? AND t.amount = ? AND t.reverses_id IS NULL AND t.deleted_at IS NULL
                                  AND t.status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI') AND t.id <> ? LIMIT 1");
            $q->execute([(int) $data['period_month_id'], (string) $data['category'], (int) $data['amount'], $exceptId ?? 0]);
            $dup = $q->fetchColumn();
            if ($dup !== false) {
                throw RuleViolation::field('duplicate', "Biaya yang sama persis (bulan, keperluan, nominal) sudah ada: {$dup}. Centang konfirmasi bila ini memang biaya terpisah.");
            }
        }
    }

    // ------------------------------------------------------------------ internal

    /**
     * Ambil dan kunci biaya milik pembuat (versi harus terbaru), lengkap dengan keperluannya.
     * @param array{id:int,roles:array<int,string>} $actor
     * @return array<string,mixed>
     */
    private static function lockOwned(\PDO $pdo, array $actor, int $id, string $version): array
    {
        $stmt = $pdo->prepare("SELECT t.*, e.category FROM transactions t JOIN expenses e ON e.transaction_id = t.id
                               WHERE t.id = ? AND t.type = 'BIAYA' AND t.reverses_id IS NULL AND t.deleted_at IS NULL FOR UPDATE");
        $stmt->execute([$id]);
        $trx = $stmt->fetch();
        if ($trx === false) {
            throw RuleViolation::field('_form', 'Biaya tidak ditemukan.');
        }
        if (!Gate::allows($actor, 'expense.manage') || (int) $trx['created_by'] !== (int) $actor['id']) {
            throw RuleViolation::field('_form', 'Hanya pembuat biaya yang boleh mengubah, mengajukan, atau membatalkannya.');
        }
        if ((string) $trx['updated_at'] !== $version) {
            throw RuleViolation::field('_form', 'Biaya ini baru saja berubah. Muat ulang halaman lalu ulangi.');
        }
        return $trx;
    }

    /** @param array<string,mixed> $trx baris transaksi + category @return array<string,mixed> */
    private static function rowData(array $trx): array
    {
        return [
            'category' => (string) $trx['category'], 'period_month_id' => (int) $trx['period_month_id'], 'amount' => (int) $trx['amount'],
            'trx_date' => (string) $trx['trx_date'], 'description' => $trx['description'],
        ];
    }

    /**
     * Isi biaya untuk audit (satu bentuk untuk sebelum/sesudah agar mudah dibandingkan).
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function snapshot(array $data): array
    {
        return [
            'category' => (string) $data['category'], 'period_month_id' => (int) $data['period_month_id'], 'amount' => (int) $data['amount'],
            'trx_date' => (string) $data['trx_date'], 'description' => $data['description'] ?? null, 'fund_source' => self::FUND,
        ];
    }
}
