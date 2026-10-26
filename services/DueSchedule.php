<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Aturan tanggal jatuh tempo angsuran (satu sumber; dipakai saat persetujuan dan oleh semua tampilan).
 *
 * Tanggal jatuh tempo cicilan ke-n = tanggal pinjaman DISETUJUI + n bulan, hari yang sama tiap bulan. Bila bulan tujuan
 * lebih pendek, dipakai hari terakhir bulan itu (31 Januari -> 28/29 Februari -> 31 Maret). Hitungan selalu dari tanggal
 * persetujuan asli, bukan berantai, jadi 31 Januari tidak "terseret" ke tanggal 28 untuk bulan-bulan berikutnya.
 *
 * Status ditentukan dari tanggal jatuh tempo, sisa kewajiban (hanya pembayaran DISETUJUI yang mengurangi), dan hari ini:
 *   sisa 0                    -> Lunas
 *   jatuh tempo < hari ini    -> Terlambat
 *   jatuh tempo = hari ini    -> Jatuh Tempo Hari Ini
 *   jatuh tempo > hari ini    -> Belum Jatuh Tempo
 */
final class DueSchedule
{
    public const LUNAS = 'LUNAS', TERLAMBAT = 'TERLAMBAT', HARI_INI = 'HARI_INI', BELUM = 'BELUM';

    /** Hanya untuk tes: menggantikan "hari ini" sebagai tanggal persetujuan agar pinjaman lama bisa disimulasikan. */
    private static ?string $approvalDateOverride = null;

    /** Tanggal pinjaman diterima = tanggal disetujui (zona waktu aplikasi). */
    public static function approvalDate(): string
    {
        return self::$approvalDateOverride ?? date('Y-m-d');
    }

    /** Hanya dipanggil dari tes. null = kembali ke hari ini. */
    public static function pretendApprovalDate(?string $date): void
    {
        self::$approvalDateOverride = $date;
    }

    /**
     * @return array<int,string> tanggal Y-m-d untuk cicilan 1..$tenor (indeks 0 = cicilan ke-1)
     */
    public static function dates(string $approvedOn, int $tenor): array
    {
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $approvedOn);
        if ($base === false || $base->format('Y-m-d') !== $approvedOn) {
            throw new \InvalidArgumentException('Tanggal persetujuan tidak valid.');
        }
        $year = (int) $base->format('Y');
        $mon  = (int) $base->format('n');
        $day  = (int) $base->format('j');
        $out  = [];
        for ($n = 1; $n <= $tenor; $n++) {
            $index = $mon - 1 + $n;
            $y     = $year + intdiv($index, 12);
            $m     = $index % 12 + 1;
            $last  = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
            $out[] = sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
        }
        return $out;
    }

    /**
     * @return array{key:string,label:string,days:int} days > 0 = hari terlambat, < 0 = hari menuju jatuh tempo
     */
    public static function status(string $dueDate, int $remaining, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $days = (int) floor((strtotime($today) - strtotime($dueDate)) / 86400);
        if ($remaining <= 0) {
            return ['key' => self::LUNAS, 'label' => 'Lunas', 'days' => 0];
        }
        if ($days > 0) {
            return ['key' => self::TERLAMBAT, 'label' => 'Terlambat', 'days' => $days];
        }
        if ($days === 0) {
            return ['key' => self::HARI_INI, 'label' => 'Jatuh Tempo Hari Ini', 'days' => 0];
        }
        return ['key' => self::BELUM, 'label' => 'Belum Jatuh Tempo', 'days' => $days];
    }

    /**
     * Isi due_date seluruh cicilan pinjaman yang BARU disetujui (satu kali; pinjaman yang sudah bertanggal tidak diubah).
     * Wajib dipanggil di dalam transaksi database persetujuan, sesudah status menjadi DISETUJUI.
     * @return array<int,string> tanggal per cicilan, kosong bila tidak ada yang diisi
     */
    public static function stamp(\PDO $pdo, int $loanTransactionId, string $approvedOn): array
    {
        $stmt = $pdo->prepare('SELECT id, tenor_months FROM loans WHERE transaction_id = ?');
        $stmt->execute([$loanTransactionId]);
        $loan = $stmt->fetch();
        if ($loan === false) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT id, seq, due_date FROM loan_installments WHERE loan_id = ? ORDER BY seq');
        $stmt->execute([(int) $loan['id']]);
        $rows = $stmt->fetchAll();
        if ($rows === [] || array_filter($rows, static fn (array $r): bool => $r['due_date'] !== null) !== []) {
            return [];
        }
        $dates = self::dates($approvedOn, count($rows));
        $upd   = $pdo->prepare('UPDATE loan_installments SET due_date = ? WHERE id = ? AND due_date IS NULL');
        foreach ($rows as $i => $r) {
            $upd->execute([$dates[$i], (int) $r['id']]);
        }
        return $dates;
    }
}
