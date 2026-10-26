<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Keadaan keuangan PADA TANGGAL TERTENTU (untuk laporan dengan rentang tanggal bebas). HANYA MEMBACA.
 *
 * Semua metode memakai tanggal transaksi (transactions.trx_date) yang DISETUJUI, inklusif ($asOf ikut dihitung), sehingga
 * transaksi pada tanggal akhir mana pun jamnya tetap terhitung (trx_date bertipe DATE). Pinjaman yang tampil adalah pinjaman
 * berlaku sekarang (pinjaman yang kini sudah dibalik tidak muncul, termasuk untuk tanggal sebelum pembaliknya).
 * Jatuh tempo memakai tanggal efektif cicilan: due_date nyata, atau akhir bulan jatuh tempo untuk pinjaman lama.
 *
 * Bila $asOf kosong atau tidak lebih lama dari hari ini, pemanggil memakai view saldo "sekarang" (isCurrent) supaya
 * angka laporan tanpa rentang sama persis dengan sebelumnya.
 */
final class AsOf
{
    public static function isCurrent(?string $asOf): bool
    {
        return $asOf === null || $asOf === '' || $asOf >= date('Y-m-d');
    }

    /** @param array<int,int> $ids */
    private static function in(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }

    /**
     * Saldo tabungan per anggota.
     * @param array<int,int> $ids
     * @return array<int,int> [anggota] => rupiah
     */
    public static function savings(array $ids, string $asOf): array
    {
        $out = array_fill_keys(array_map('intval', $ids), 0);
        if ($ids === []) {
            return $out;
        }
        foreach (Database::select('SELECT member_id, SUM(savings_delta) AS v FROM v_ledger WHERE member_id IN (' . self::in($ids) . ') AND trx_date <= ? GROUP BY member_id', [$asOf]) as $r) {
            $out[(int) $r['member_id']] = (int) $r['v'];
        }
        return $out;
    }

    /**
     * Pembayaran DISETUJUI sampai tanggal itu, per pinjaman (subquery siap pakai).
     */
    private const PAID_PER_LOAN = "SELECT li.loan_id, SUM(ip.amount) AS paid
        FROM installment_payments ip
        JOIN loan_installments li ON li.id = ip.installment_id
        JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL AND pt.trx_date <= ?
        GROUP BY li.loan_id";

    /**
     * Sisa pinjaman (pokok + bunga) per anggota, hanya pinjaman yang sudah dicairkan sampai tanggal itu.
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    public static function outstanding(array $ids, string $asOf): array
    {
        $out = array_fill_keys(array_map('intval', $ids), 0);
        if ($ids === []) {
            return $out;
        }
        foreach (Database::select(
            'SELECT b.member_id, SUM(b.total_due - COALESCE(p.paid, 0)) AS v
             FROM v_loan_balances b
             JOIN transactions t ON t.id = b.transaction_id AND t.trx_date <= ?
             LEFT JOIN (' . self::PAID_PER_LOAN . ') p ON p.loan_id = b.loan_id
             WHERE b.member_id IN (' . self::in($ids) . ') GROUP BY b.member_id',
            [$asOf, $asOf]
        ) as $r) {
            $out[(int) $r['member_id']] = (int) $r['v'];
        }
        return $out;
    }

    /**
     * Subquery cicilan yang MENUNGGAK pada tanggal itu (kolom sama dengan view v_overdue_installments): jatuh tempo SEBELUM
     * tanggal itu dan masih ada sisa menurut pembayaran sampai tanggal itu. Parameter yang dibutuhkan: [$asOf, $asOf, $asOf].
     */
    public static function overdueSubquery(): string
    {
        return "SELECT li.id AS installment_id, li.loan_id, b.loan_no, b.member_id, li.seq, pm.month_date AS due_month, li.amount_due,
                       COALESCE(p.paid, 0) AS paid_amount, li.amount_due - COALESCE(p.paid, 0) AS remaining_amount,
                       COALESCE(li.due_date, LAST_DAY(pm.month_date)) AS due_date
                FROM loan_installments li
                JOIN v_loan_balances b ON b.loan_id = li.loan_id
                JOIN transactions t ON t.id = b.transaction_id AND t.trx_date <= ?
                JOIN period_months pm ON pm.id = li.due_month_id
                LEFT JOIN (SELECT ip.installment_id, SUM(ip.amount) AS paid
                           FROM installment_payments ip
                           JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL AND pt.trx_date <= ?
                           GROUP BY ip.installment_id) p ON p.installment_id = li.id
                WHERE li.amount_due - COALESCE(p.paid, 0) > 0
                  AND COALESCE(li.due_date, LAST_DAY(pm.month_date)) < ?";
    }

    /**
     * Total tunggakan per anggota pada tanggal itu.
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    public static function overdue(array $ids, string $asOf): array
    {
        $out = array_fill_keys(array_map('intval', $ids), 0);
        if ($ids === []) {
            return $out;
        }
        foreach (Database::select(
            'SELECT o.member_id, SUM(o.remaining_amount) AS v FROM (' . self::overdueSubquery() . ') o WHERE o.member_id IN (' . self::in($ids) . ') GROUP BY o.member_id',
            [$asOf, $asOf, $asOf]
        ) as $r) {
            $out[(int) $r['member_id']] = (int) $r['v'];
        }
        return $out;
    }

    /**
     * Terbayar dan tunggakan per pinjaman pada tanggal itu.
     * @param array<int,int> $loanIds
     * @return array<int,array{paid:int,overdue:int}>
     */
    public static function loanStates(array $loanIds, string $asOf): array
    {
        $out = [];
        foreach ($loanIds as $id) {
            $out[(int) $id] = ['paid' => 0, 'overdue' => 0];
        }
        if ($loanIds === []) {
            return $out;
        }
        $in = self::in($loanIds);
        foreach (Database::select('SELECT x.loan_id, x.paid FROM (' . self::PAID_PER_LOAN . ") x WHERE x.loan_id IN ({$in})", [$asOf]) as $r) {
            $out[(int) $r['loan_id']]['paid'] = (int) $r['paid'];
        }
        foreach (Database::select('SELECT o.loan_id, SUM(o.remaining_amount) AS v FROM (' . self::overdueSubquery() . ") o WHERE o.loan_id IN ({$in}) GROUP BY o.loan_id", [$asOf, $asOf, $asOf]) as $r) {
            $out[(int) $r['loan_id']]['overdue'] = (int) $r['v'];
        }
        return $out;
    }
}
