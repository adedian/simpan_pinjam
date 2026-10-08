<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Helpers\Money;

/**
 * Transaksi simpanan (setoran pokok, wajib, sukarela) oleh Ketua Regu.
 *
 * Alur status (dijaga juga oleh trigger database): DRAFT -> MENUNGGU_VALIDASI -> DISETUJUI | DITOLAK,
 * dan DRAFT / MENUNGGU_VALIDASI -> DIBATALKAN. Persetujuan dikerjakan di Phase 9; sampai itu simpanan
 * yang diajukan belum memengaruhi saldo mana pun (saldo hanya dihitung dari transaksi DISETUJUI).
 *
 * Setiap perubahan terjadi dalam SATU transaksi database bersama catatan audit dan riwayat status:
 * gagal satu, gagal semua. Draft tidak pernah dihapus (nomor dokumen tidak boleh bolong); salah catat
 * dibatalkan dengan alasan, bukan dihilangkan.
 */
final class SavingService
{
    /** @var array<string,string> */
    public const KINDS = ['POKOK' => 'Simpanan pokok', 'WAJIB' => 'Simpanan wajib', 'SUKARELA' => 'Simpanan sukarela'];
    /** Label untuk tampilan; CAMPURAN hanya ada pada data impor Excel dan tidak bisa dibuat dari aplikasi. */
    public const KIND_LABELS = self::KINDS + ['CAMPURAN' => 'Campuran (impor Excel)'];
    /** Batas kewajaran satu setoran; mencegah salah ketik nol berlebih. */
    public const MAX_AMOUNT = 100000000;

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>} [galat, data bersih]
     */
    public static function parse(array $in): array
    {
        $description = clean_text($in['description'] ?? '');
        $amountRaw   = trim((string) ($in['amount'] ?? ''));
        $data = [
            'member_id'         => (int) ($in['member_id'] ?? 0),
            'period_month_id'   => (int) ($in['period_month_id'] ?? 0),
            'kind'              => (string) ($in['kind'] ?? ''),
            'amount'            => Money::parse($amountRaw) ?? 0,
            'trx_date'          => trim((string) ($in['trx_date'] ?? '')),
            'description'       => $description === '' ? null : $description,
            'confirm_duplicate' => !empty($in['confirm_duplicate']),
        ];

        $errors = Validator::validate(
            ['kind' => $data['kind'], 'description' => $description],
            ['kind' => 'required|in:' . implode(',', array_keys(self::KINDS)), 'description' => 'max:255'],
            ['kind' => 'Jenis simpanan', 'description' => 'Keterangan'],
        );
        if ($data['member_id'] <= 0) {
            $errors['member_id'] = 'Anggota wajib dipilih.';
        }
        if ($data['period_month_id'] <= 0) {
            $errors['period_month_id'] = 'Bulan setoran wajib dipilih.';
        }
        if ($amountRaw === '') {
            $errors['amount'] = 'Nominal wajib diisi.';
        } elseif (Money::parse($amountRaw) === null) {
            $errors['amount'] = 'Nominal: isi dengan angka bulat rupiah tanpa desimal, mis. 50.000.';
        } elseif ($data['amount'] <= 0) {
            $errors['amount'] = 'Nominal harus lebih dari nol.';
        } elseif ($data['amount'] > self::MAX_AMOUNT) {
            $errors['amount'] = 'Nominal terlalu besar (maksimum ' . Money::format(self::MAX_AMOUNT) . ' per setoran). Periksa kembali jumlah nolnya.';
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $data['trx_date']);
        if ($date === false || $date->format('Y-m-d') !== $data['trx_date']) {
            $errors['trx_date'] = 'Tanggal setoran tidak valid.';
        }
        return [$errors, $data];
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Catat simpanan baru sebagai DRAFT; bila $submit, langsung diajukan ke validasi.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @return int id transaksi
     */
    public static function create(Request $request, array $actor, array $data, bool $submit): int
    {
        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $data, $submit): int {
            $ctx  = self::assertRecordable($pdo, $actor, $data, null);
            $num  = NumberSequence::forTransaction($pdo, 'SIMPANAN', (int) substr((string) $data['trx_date'], 0, 4));

            $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, description, source, created_by)
                           VALUES (?, ?, 'SIMPANAN', ?, ?, ?, ?, ?, 'DRAFT', ?, 'APLIKASI', ?)")
                ->execute([$num['trx_no'], $num['doc_no'], $data['member_id'], $ctx['team_id'], $data['period_month_id'], $data['trx_date'], $data['amount'], $data['description'], $actor['id']]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO savings (transaction_id, kind) VALUES (?, ?)')->execute([$id, $data['kind']]);

            AuditLog::record($request, $actor, 'SAVING_CREATED', 'transaction', $id, $num['doc_no'], null, self::snapshot($data, $ctx), $pdo);
            if ($submit) {
                self::move($pdo, $request, $actor, ['id' => $id, 'doc_no' => $num['doc_no'], 'status' => 'DRAFT'], 'MENUNGGU_VALIDASI', null, 'SAVING_SUBMITTED');
            }
            return $id;
        });
    }

    /**
     * Ubah simpanan yang masih DRAFT (hanya pembuatnya). Bila $submit, langsung diajukan.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @param string $version nilai updated_at saat formulir dibuka (deteksi edit bersamaan)
     */
    public static function update(Request $request, array $actor, int $id, array $data, string $version, bool $submit): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $data, $version, $submit): void {
            $trx = self::lockOwned($pdo, $actor, $id, $version);
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Hanya draft yang bisa diubah. Transaksi ini sudah ' . self::statusPhrase($trx['status']) . '.');
            }
            $ctx = self::assertRecordable($pdo, $actor, $data, $id);

            $before = self::snapshot(self::rowData($trx), ['team_id' => (int) $trx['team_id']]);
            $after  = self::snapshot($data, $ctx);
            $changed = array_keys(array_filter($after, static fn ($v, $k): bool => (string) ($before[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $pdo->prepare('UPDATE transactions SET member_id = ?, team_id = ?, period_month_id = ?, trx_date = ?, amount = ?, description = ? WHERE id = ?')
                    ->execute([$data['member_id'], $ctx['team_id'], $data['period_month_id'], $data['trx_date'], $data['amount'], $data['description'], $id]);
                $pdo->prepare('UPDATE savings SET kind = ? WHERE transaction_id = ?')->execute([$data['kind'], $id]);
                AuditLog::record($request, $actor, 'SAVING_UPDATED', 'transaction', $id, (string) $trx['doc_no'],
                    array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $pdo);
            }
            if ($submit) {
                self::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'SAVING_SUBMITTED');
            }
        });
    }

    /**
     * Ajukan draft ke validasi. Semua aturan diperiksa ulang: anggota bisa saja sudah pindah regu
     * atau dinonaktifkan sejak draft dibuat.
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     */
    public static function submit(Request $request, array $actor, int $id, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version): void {
            $trx = self::lockOwned($pdo, $actor, $id, $version);
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Transaksi ini sudah ' . self::statusPhrase($trx['status']) . ', tidak bisa diajukan lagi.');
            }
            self::assertRecordable($pdo, $actor, self::rowData($trx) + ['confirm_duplicate' => true], $id);
            self::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'SAVING_SUBMITTED');
        });
    }

    /**
     * Batalkan draft atau transaksi yang menunggu validasi, dengan alasan. Transaksi yang sudah
     * disetujui tidak bisa dibatalkan di sini (koreksinya lewat transaksi pembalik).
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     */
    public static function cancel(Request $request, array $actor, int $id, string $reason, string $version): void
    {
        TransactionFlow::cancel($request, $actor, $id, $reason, $version, 'SIMPANAN', 'SAVING_CANCELLED');
    }

    // ------------------------------------------------------------------ aturan

    /**
     * Semua aturan bisnis sebelum simpanan boleh dicatat / diajukan. Dipanggil di dalam transaksi database.
     * @param array<string,mixed> $data
     * @param array{id:int,roles:array<int,string>,team_id?:?int} $actor
     * @return array{team_id:int,member_no:string,member_name:string,month:string}
     */
    private static function assertRecordable(\PDO $pdo, array $actor, array $data, ?int $exceptId): array
    {
        $ctx = TransactionFlow::assertRecordable($pdo, $actor, $data);

        if (empty($data['confirm_duplicate'])) {
            $stmt = $pdo->prepare("SELECT t.doc_no, t.status FROM transactions t JOIN savings s ON s.transaction_id = t.id
                                   WHERE t.member_id = ? AND t.period_month_id = ? AND s.kind = ? AND t.amount = ? AND t.reverses_id IS NULL
                                     AND t.deleted_at IS NULL AND t.status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI') AND t.id <> ? LIMIT 1");
            $stmt->execute([$ctx['member_id'], (int) $data['period_month_id'], $data['kind'], (int) $data['amount'], $exceptId ?? 0]);
            $dup = $stmt->fetch();
            if ($dup !== false) {
                throw RuleViolation::field('duplicate', "Simpanan yang sama persis (anggota, bulan, jenis, nominal) sudah ada: {$dup['doc_no']}. Centang konfirmasi bila ini memang setoran terpisah.");
            }
        }
        return $ctx;
    }

    // ------------------------------------------------------------------ internal

    /**
     * Ambil dan kunci transaksi simpanan milik pembuat (versi harus terbaru), lengkap dengan jenis simpanannya.
     * @param array{id:int,roles:array<int,string>} $actor
     * @return array<string,mixed>
     */
    private static function lockOwned(\PDO $pdo, array $actor, int $id, string $version): array
    {
        $trx  = TransactionFlow::lockOwned($pdo, $actor, $id, $version, 'SIMPANAN');
        $stmt = $pdo->prepare('SELECT kind FROM savings WHERE transaction_id = ?');
        $stmt->execute([$id]);
        return $trx + ['kind' => (string) $stmt->fetchColumn()];
    }

    /**
     * @param array{id:int|string,doc_no:string,status:string} $trx
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    private static function move(\PDO $pdo, Request $request, array $actor, array $trx, string $to, ?string $note, string $auditAction): void
    {
        TransactionFlow::move($pdo, $request, $actor, $trx, $to, $note, $auditAction);
    }

    /** @param array<string,mixed> $trx baris transaksi + kind @return array<string,mixed> */
    private static function rowData(array $trx): array
    {
        return [
            'member_id' => (int) $trx['member_id'], 'period_month_id' => (int) $trx['period_month_id'], 'kind' => (string) $trx['kind'],
            'amount' => (int) $trx['amount'], 'trx_date' => (string) $trx['trx_date'], 'description' => $trx['description'],
        ];
    }

    /**
     * Isi transaksi untuk audit (satu bentuk untuk sebelum/sesudah agar mudah dibandingkan).
     * @param array<string,mixed> $data @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function snapshot(array $data, array $ctx): array
    {
        return [
            'member_id' => (int) $data['member_id'], 'team_id' => (int) $ctx['team_id'], 'period_month_id' => (int) $data['period_month_id'],
            'kind' => (string) $data['kind'], 'amount' => (int) $data['amount'], 'trx_date' => (string) $data['trx_date'],
            'description' => $data['description'] ?? null,
        ];
    }

    private static function statusPhrase(string $status): string
    {
        return TransactionFlow::statusPhrase($status);
    }
}
