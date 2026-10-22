<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Money;

/**
 * Laporan Jasa Pinjaman dan Kas SHU (sheet 6 Excel): per bulan siklus, bunga yang dibukukan saat pinjaman dicairkan dibagi
 * penabung / peminjam / Kas SHU menurut Pengaturan (bawaan 40/40/20); Kas SHU = pemasukan SHU - biaya operasional yang disetujui.
 * Sisa Kas SHU dibagi anggota / pengelola menurut Pengaturan (bawaan 60/40): hanya total, tanpa nama pengelola.
 *
 * Sumber angka: pinjaman efektif (v_loan_balances: disetujui, belum dibalik) dan transaksi BIAYA disetujui (v_ledger, pembalik
 * mengurangi). Tidak ada angka tersimpan. Hanya cakupan seluruh koperasi (report.view.global); lainnya mendapat laporan kosong.
 * Pembagian 40/40/20 per bulan dibulatkan per bulan dan bagian SHU adalah sisanya, sehingga ketiganya selalu berjumlah tepat sama dengan bunga.
 */
final class ShuReport
{
    /**
     * @param array<string,mixed>|null $user @param array<string,mixed> $f hasil ReportService::filters
     * @return array<string,mixed> struktur tabel laporan (+ 'summary' dan 'control')
     */
    public static function build(?array $user, array $f): array
    {
        $columns = [
            ['key' => 'month', 'label' => 'Bulan', 'type' => 'text'], ['key' => 'loans', 'label' => 'Pinjaman cair', 'type' => 'int'],
            ['key' => 'principal', 'label' => 'Pokok dicairkan', 'type' => 'money'], ['key' => 'interest', 'label' => 'Bunga dibukukan', 'type' => 'money'],
            ['key' => 'cumulative', 'label' => 'Bunga kumulatif', 'type' => 'money'], ['key' => 'saver', 'label' => 'Penabung', 'type' => 'money'],
            ['key' => 'borrower', 'label' => 'Peminjam', 'type' => 'money'], ['key' => 'shu_in', 'label' => 'Pemasukan Kas SHU', 'type' => 'money'],
            ['key' => 'expense', 'label' => 'Biaya', 'type' => 'money'], ['key' => 'balance', 'label' => 'Saldo Kas SHU', 'type' => 'money'],
        ];
        $base = ['key' => 'shu', 'title' => 'Jasa Pinjaman dan Kas SHU', 'subtitle' => '', 'columns' => $columns, 'rows' => [], 'totals' => null, 'note' => '', 'summary' => []];
        if (ReportService::level($user) !== ReportService::ALL) {
            return $base;
        }

        $period    = (int) $f['period'];
        $saverPct  = (int) SettingsService::get('profit_share_saver_pct');
        $borrPct   = (int) SettingsService::get('profit_share_borrower_pct');
        $shuPct    = (int) SettingsService::get('profit_share_shu_pct');
        $memberPct = (int) SettingsService::get('shu_member_pct');
        $mgrPct    = (int) SettingsService::get('shu_manager_pct');

        $loans = [];
        foreach (Database::select(
            'SELECT t.period_month_id AS mid, COUNT(*) AS n, SUM(b.principal) AS p, SUM(b.total_interest) AS i
             FROM v_loan_balances b JOIN transactions t ON t.id = b.transaction_id
             JOIN period_months pm ON pm.id = t.period_month_id WHERE pm.period_id = ? GROUP BY t.period_month_id',
            [$period]
        ) as $r) {
            $loans[(int) $r['mid']] = ['n' => (int) $r['n'], 'p' => (int) $r['p'], 'i' => (int) $r['i']];
        }
        $expense = [];
        foreach (Database::select(
            "SELECT l.period_month_id AS mid, SUM(l.sign * l.amount) AS a FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id
             WHERE l.type = 'BIAYA' AND pm.period_id = ? GROUP BY l.period_month_id",
            [$period]
        ) as $r) {
            $expense[(int) $r['mid']] = (int) $r['a'];
        }

        $rows = [];
        $cum = 0;
        $balance = 0;
        foreach (ReportService::months($period) as $m) {
            $l = $loans[$m['id']] ?? ['n' => 0, 'p' => 0, 'i' => 0];
            $saver = (int) round($l['i'] * $saverPct / 100);
            $borrower = (int) round($l['i'] * $borrPct / 100);
            $shu = $l['i'] - $saver - $borrower;   // sisa: tiga bagian selalu pas = bunga
            $exp = $expense[$m['id']] ?? 0;
            $cum += $l['i'];
            $balance += $shu - $exp;
            $rows[] = [
                'month' => month_label($m['month']), 'loans' => $l['n'], 'principal' => $l['p'], 'interest' => $l['i'], 'cumulative' => $cum,
                'saver' => $saver, 'borrower' => $borrower, 'shu_in' => $shu, 'expense' => $exp, 'balance' => $balance,
            ];
        }
        $sum = static fn (string $k): int => (int) array_sum(array_column($rows, $k));
        $totals = ['month' => 'Jumlah', 'loans' => $sum('loans'), 'principal' => $sum('principal'), 'interest' => $sum('interest'), 'cumulative' => $cum,
            'saver' => $sum('saver'), 'borrower' => $sum('borrower'), 'shu_in' => $sum('shu_in'), 'expense' => $sum('expense'), 'balance' => $balance];

        $memberShare = (int) round($balance * $memberPct / 100);
        $mgrShare = $balance - $memberShare;
        $periodName = '';
        foreach (ReportService::periods() as $p) {
            if ($p['id'] === $period) {
                $periodName = $p['name'];
            }
        }
        $base['subtitle'] = 'Periode ' . $periodName . ' · bunga dibukukan saat pinjaman dicairkan · rupiah';
        $base['rows'] = $rows;
        $base['totals'] = $rows === [] ? null : $totals;
        $base['summary'] = [
            ['label' => 'Pemasukan Kas SHU ' . $shuPct . '%', 'value' => $totals['shu_in'], 'hint' => 'Dari bunga yang dibukukan'],
            ['label' => 'Biaya operasional', 'value' => $totals['expense'], 'hint' => 'Disetujui; dibebankan ke Kas SHU'],
            ['label' => 'Sisa Kas SHU', 'value' => $balance, 'hint' => 'Pemasukan dikurangi biaya'],
            ['label' => 'Untuk anggota ' . $memberPct . '%', 'value' => $memberShare, 'hint' => 'Dari sisa Kas SHU'],
            ['label' => 'Untuk pengelola ' . $mgrPct . '%', 'value' => $mgrShare, 'hint' => 'Total saja, tanpa pembagian antar pengelola'],
        ];
        $base['control'] = ['ok' => $totals['saver'] + $totals['borrower'] + $totals['shu_in'] === $totals['interest'] && $totals['interest'] === $cum, 'interest' => $totals['interest']];
        $base['note'] = 'Bunga dibagi ' . $saverPct . '% penabung, ' . $borrPct . '% peminjam, ' . $shuPct . '% Kas SHU (Pengaturan). Kas SHU = pemasukan SHU dikurangi biaya operasional yang sudah disetujui. '
            . 'Sisa Kas SHU dibagi ' . $memberPct . '% anggota dan ' . $mgrPct . '% pengelola: anggota ' . Money::format($memberShare) . ', pengelola ' . Money::format($mgrShare)
            . '. Ini angka bulanan dari bunga yang dibukukan; pembagian bunga per anggota ada di Pembagian Bunga.';
        return $base;
    }
}
