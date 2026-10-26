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
        // Laporan ini per bulan siklus. Dengan rentang tanggal: bulan siklus yang beririsan dengan rentang (bulan penuh), dalam periode terpilih.
        $range = ReportService::rangeOf($f);
        $shown = array_values(array_filter(ReportService::months($period), static fn (array $m): bool => $range === null
            || ($m['month'] >= substr($range['from'], 0, 7) . '-01' && $m['month'] <= substr($range['to'], 0, 7) . '-01')));
        // Komponen menurut versi konfigurasi yang berlaku pada bulan itu (ProfitConfig); komponen dana dijadikan kolom tambahan.
        $danaNames = [];
        foreach ($shown as $m) {
            foreach (ProfitConfig::forMonth($m['month'])['items'] as $it) {
                if ($it['kind'] === 'DANA') {
                    $danaNames[$it['code']] = $it['name'];
                }
            }
        }
        $names = ['PENABUNG' => 'Penabung', 'PEMINJAM' => 'Peminjam', 'SHU' => 'Kas SHU'];
        $lastCfg = $shown === [] ? ProfitConfig::current() : ProfitConfig::forMonth($shown[count($shown) - 1]['month']);
        foreach ($lastCfg['items'] as $it) {
            $names[$it['code']] = $it['name'];
        }
        foreach ($shown as $m) {
            $l = $loans[$m['id']] ?? ['n' => 0, 'p' => 0, 'i' => 0];
            $alloc = ProfitConfig::allocate(ProfitConfig::forMonth($m['month'])['items'], $l['i']);   // Kas SHU menyerap selisih pembulatan: semua komponen pas = bunga
            $saver = $alloc['PENABUNG'] ?? 0;
            $borrower = $alloc['PEMINJAM'] ?? 0;
            $shu = $alloc['SHU'] ?? 0;
            $exp = $expense[$m['id']] ?? 0;
            $danaRow = [];
            foreach ($danaNames as $code => $_) {
                $danaRow['d_' . $code] = $alloc[$code] ?? 0;
            }
            $cum += $l['i'];
            $balance += $shu - $exp;
            $rows[] = [
                'month' => month_label($m['month']), 'loans' => $l['n'], 'principal' => $l['p'], 'interest' => $l['i'], 'cumulative' => $cum,
                'saver' => $saver, 'borrower' => $borrower, 'shu_in' => $shu, 'expense' => $exp, 'balance' => $balance,
            ] + $danaRow;
        }
        // Kolom: nama komponen terbaru; komponen dana (bila ada) setelah Peminjam.
        $columns = [
            ['key' => 'month', 'label' => 'Bulan', 'type' => 'text'], ['key' => 'loans', 'label' => 'Pinjaman cair', 'type' => 'int'],
            ['key' => 'principal', 'label' => 'Pokok dicairkan', 'type' => 'money'], ['key' => 'interest', 'label' => 'Bunga dibukukan', 'type' => 'money'],
            ['key' => 'cumulative', 'label' => 'Bunga kumulatif', 'type' => 'money'], ['key' => 'saver', 'label' => $names['PENABUNG'], 'type' => 'money'],
            ['key' => 'borrower', 'label' => $names['PEMINJAM'], 'type' => 'money'],
        ];
        foreach ($danaNames as $code => $label) {
            $columns[] = ['key' => 'd_' . $code, 'label' => $names[$code] ?? $label, 'type' => 'money'];
        }
        array_push($columns, ['key' => 'shu_in', 'label' => 'Pemasukan ' . $names['SHU'], 'type' => 'money'], ['key' => 'expense', 'label' => 'Biaya', 'type' => 'money'], ['key' => 'balance', 'label' => 'Saldo ' . $names['SHU'], 'type' => 'money']);
        $base['columns'] = $columns;
        $sum = static fn (string $k): int => (int) array_sum(array_column($rows, $k));
        $totals = ['month' => 'Jumlah', 'loans' => $sum('loans'), 'principal' => $sum('principal'), 'interest' => $sum('interest'), 'cumulative' => $cum,
            'saver' => $sum('saver'), 'borrower' => $sum('borrower'), 'shu_in' => $sum('shu_in'), 'expense' => $sum('expense'), 'balance' => $balance];
        $danaTotal = 0;
        foreach ($danaNames as $code => $_) {
            $totals['d_' . $code] = $sum('d_' . $code);
            $danaTotal += $totals['d_' . $code];
        }
        $shuItem = null;
        foreach ($lastCfg['items'] as $it) {
            if ($it['kind'] === 'SHU') {
                $shuItem = $it;
            }
        }
        $shuPctText = $shuItem === null ? '' : ProfitConfig::fmtPct($shuItem['pct']);
        $splitText = [];
        foreach ($lastCfg['items'] as $it) {
            $splitText[] = ProfitConfig::fmtPct($it['pct']) . ' ' . mb_strtolower($it['name']);
        }

        $memberShare = (int) round($balance * $memberPct / 100);
        $mgrShare = $balance - $memberShare;
        $periodName = '';
        foreach (ReportService::periods() as $p) {
            if ($p['id'] === $period) {
                $periodName = $p['name'];
            }
        }
        $base['subtitle'] = ($range === null ? 'Periode ' . $periodName : 'Periode ' . $periodName . ', bulan siklus ' . date_id($range['from']) . ' s.d. ' . date_id($range['to'])) . ' · bunga dibukukan saat pinjaman dicairkan · rupiah';
        $base['rows'] = $rows;
        $base['totals'] = $rows === [] ? null : $totals;
        $base['summary'] = [
            ['label' => 'Pemasukan ' . $names['SHU'] . ' ' . $shuPctText, 'value' => $totals['shu_in'], 'hint' => 'Dari bunga yang dibukukan'],
            ['label' => 'Biaya operasional', 'value' => $totals['expense'], 'hint' => 'Disetujui; dibebankan ke Kas SHU'],
            ['label' => 'Sisa Kas SHU', 'value' => $balance, 'hint' => 'Pemasukan dikurangi biaya'],
            ['label' => 'Untuk anggota ' . $memberPct . '%', 'value' => $memberShare, 'hint' => 'Dari sisa Kas SHU'],
            ['label' => 'Untuk pengelola ' . $mgrPct . '%', 'value' => $mgrShare, 'hint' => 'Total saja, tanpa pembagian antar pengelola'],
        ];
        $base['control'] = ['ok' => $totals['saver'] + $totals['borrower'] + $danaTotal + $totals['shu_in'] === $totals['interest'] && $totals['interest'] === $cum, 'interest' => $totals['interest']];
        $base['note'] = 'Bunga dibagi ' . implode(', ', $splitText) . ' (komponen pembagian bunga yang berlaku bulan terakhir pada laporan ini; tiap bulan memakai versi yang berlaku saat itu). ' . $names['SHU'] . ' = pemasukan SHU dikurangi biaya operasional yang sudah disetujui. '
            . 'Sisa Kas SHU dibagi ' . $memberPct . '% anggota dan ' . $mgrPct . '% pengelola: anggota ' . Money::format($memberShare) . ', pengelola ' . Money::format($mgrShare)
            . '. Ini angka bulanan dari bunga yang dibukukan; pembagian bunga per anggota ada di Pembagian Bunga.';
        return $base;
    }
}
