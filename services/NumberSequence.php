<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Penomoran dokumen tanpa celah dan tanpa duplikat: TRX-2026-000001, SMP-2026-000001, dst.
 * WAJIB dipanggil di dalam transaksi database milik pemanggil. Baris urutan terkunci sampai commit,
 * sehingga dua request bersamaan tidak mendapat nomor yang sama, dan bila transaksi di-rollback
 * nomornya ikut kembali (tidak ada nomor yang hilang).
 */
final class NumberSequence
{
    public const PREFIX_BY_TYPE = [
        'SIMPANAN'           => 'SMP',
        'PENARIKAN'          => 'TRK',
        'PENCAIRAN_PINJAMAN' => 'PJM',
        'ANGSURAN'           => 'ANG',
        'BIAYA'              => 'BYA',
    ];

    public static function next(\PDO $pdo, string $prefix, int $year): string
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('NumberSequence::next harus dipanggil di dalam transaksi database.');
        }
        $key = $prefix . '-' . $year;

        $pdo->prepare('INSERT IGNORE INTO number_sequences (seq_key, last_value) VALUES (?, 0)')->execute([$key]);
        $pdo->prepare('UPDATE number_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE seq_key = ?')->execute([$key]);
        $value = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        return sprintf('%s-%d-%06d', $prefix, $year, $value);
    }

    /**
     * Nomor anggota berikutnya (AGT-058, dst.). Deret diinisialisasi dari nomor tertinggi yang
     * sudah ada, sehingga data hasil impor tidak bentrok. Wajib di dalam transaksi pemanggil.
     */
    public static function nextMemberNo(\PDO $pdo): string
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('NumberSequence::nextMemberNo harus dipanggil di dalam transaksi database.');
        }
        $pdo->exec("INSERT IGNORE INTO number_sequences (seq_key, last_value)
                    SELECT 'MEMBER', COALESCE(MAX(CAST(SUBSTRING(member_no, 5) AS UNSIGNED)), 0) FROM members");
        $pdo->exec("UPDATE number_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE seq_key = 'MEMBER'");
        return sprintf('AGT-%03d', (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn());
    }

    /** @return array{trx_no:string,doc_no:string} */
    public static function forTransaction(\PDO $pdo, string $type, int $year): array
    {
        $prefix = self::PREFIX_BY_TYPE[$type] ?? throw new \InvalidArgumentException('Tipe transaksi tidak dikenal: ' . $type);
        return [
            'trx_no' => self::next($pdo, 'TRX', $year),
            'doc_no' => self::next($pdo, $prefix, $year),
        ];
    }
}
