<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Perkiraan bagi hasil bunga per anggota untuk formulir cetak. HANYA MEMBACA: tidak ada angka yang disimpan, semuanya
 * dihitung dari transaksi DISETUJUI dan jadwal cicilan (jadi koreksi/pembalik ikut bersih).
 *
 * Aturan (disetujui di Phase 1, Q8; analisis §3.2):
 *   - Bunga yang DITERIMA pada bulan k+1 dibagi berdasarkan keadaan akhir bulan k.
 *       pool penabung  = 40% x bunga diterima bulan k+1  (porsi = saldo tabungan anggota / total saldo semua anggota)
 *       pool peminjam  = 40% x bunga diterima bulan k+1  (porsi = sisa POKOK anggota / total sisa pokok semua anggota)
 *     Persentase dari pengaturan (profit_share_saver_pct, profit_share_borrower_pct).
 *   - Dari bagian penabung dan peminjam dipotong cadangan (reserve_pct, 5%), kecuali anggota berstatus reserve_exempt.
 *   - Bunga dalam satu pembayaran = pembayaran x total_bunga / total_tagihan pinjaman (bunga flat, pro rata).
 *   - Bunga bulan-bulan yang BELUM terjadi diproyeksikan dari jadwal cicilan: sisa tiap cicilan dianggap dibayar penuh
 *     pada bulan jatuh temponya, atau bulan setelah data terakhir bila sudah lewat. Pinjaman baru tidak diproyeksikan.
 *   - Seluruh pool terbagi (tidak ada bulan yang terlewat). Bila suatu bulan tak punya penerima, pool bulan itu tidak
 *     dibagikan (dilaporkan di `undistributed`).
 *
 * Hasil tiap anggota dibulatkan ke rupiah penuh per baris; formulir menjumlahkan baris yang sudah dibulatkan.
 */
final class ProfitShare
{
    /**
     * @return array{
     *   period_id:int, as_of:?string, months:int,
     *   interest_pool:float, undistributed:float,
     *   members:array<int,array{saver:int,borrower:int,saver_gross:float,borrower_gross:float,reserve_exempt:bool}>
     * }
     */
    public static function compute(int $periodId): array
    {
        $months = Database::select('SELECT id, month_date FROM period_months WHERE period_id = ? ORDER BY month_date', [$periodId]);
        $idx = [];                                   // period_month_id => 1..N
        $dateIdx = [];                               // 'YYYY-MM-01' => 1..N
        foreach ($months as $i => $m) {
            $idx[(int) $m['id']] = $i + 1;
            $dateIdx[(string) $m['month_date']] = $i + 1;
        }
        $n = count($months);

        $saverPct    = (int) SettingsService::get('profit_share_saver_pct') / 100;
        $borrowerPct = (int) SettingsService::get('profit_share_borrower_pct') / 100;
        $reservePct  = (float) SettingsService::get('reserve_pct') / 100;

        $exempt = [];
        foreach (Database::select('SELECT id, reserve_exempt FROM members WHERE deleted_at IS NULL') as $r) {
            $exempt[(int) $r['id']] = (int) $r['reserve_exempt'] === 1;
        }

        // Tabungan per anggota per bulan (selisih), lalu kumulatif per akhir bulan.
        $sav = [];                                   // member => [idx => selisih]
        $asOf = 0;
        foreach (Database::select(
            'SELECT l.member_id, l.period_month_id, SUM(l.savings_delta) AS d FROM v_ledger l
             JOIN period_months pm ON pm.id = l.period_month_id WHERE pm.period_id = ? AND l.member_id IS NOT NULL
             GROUP BY l.member_id, l.period_month_id',
            [$periodId]
        ) as $r) {
            $sav[(int) $r['member_id']][$idx[(int) $r['period_month_id']]] = (int) $r['d'];
        }
        foreach (Database::select(
            'SELECT MAX(pm.month_date) AS d FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id WHERE pm.period_id = ?',
            [$periodId]
        ) as $r) {
            $asOf = isset($dateIdx[(string) $r['d']]) ? $dateIdx[(string) $r['d']] : 0;
        }

        // Pinjaman efektif (pencairan disetujui dan tidak dibalik) pada periode ini.
        $loans = [];                                 // loan_id => [member, principal, interest, disbursed idx]
        foreach (Database::select(
            'SELECT ln.id, ln.member_id, ln.principal, ln.total_interest, t.period_month_id
             FROM loans ln JOIN v_loan_balances b ON b.loan_id = ln.id JOIN transactions t ON t.id = ln.transaction_id
             JOIN period_months pm ON pm.id = t.period_month_id WHERE pm.period_id = ?',
            [$periodId]
        ) as $r) {
            $loans[(int) $r['id']] = ['m' => (int) $r['member_id'], 'p' => (int) $r['principal'], 'i' => (int) $r['total_interest'], 'k' => $idx[(int) $r['period_month_id']]];
        }

        // Pembayaran nyata per pinjaman per bulan (pembalik berbobot negatif sudah menetralkan).
        $pay = [];                                   // loan => [idx => rupiah]
        foreach (Database::select(
            "SELECT li.loan_id, t.period_month_id, SUM(ip.amount) AS a
             FROM installment_payments ip JOIN loan_installments li ON li.id = ip.installment_id
             JOIN transactions t ON t.id = ip.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL
             JOIN period_months pm ON pm.id = t.period_month_id WHERE pm.period_id = ?
             GROUP BY li.loan_id, t.period_month_id",
            [$periodId]
        ) as $r) {
            if (isset($loans[(int) $r['loan_id']])) {
                $pay[(int) $r['loan_id']][$idx[(int) $r['period_month_id']]] = (float) $r['a'];
            }
        }

        // Proyeksi: sisa tiap cicilan dibayar pada max(bulan jatuh tempo, bulan sesudah data terakhir).
        foreach (Database::select('SELECT loan_id, due_month, remaining_amount FROM v_installment_status WHERE remaining_amount > 0') as $r) {
            $lid = (int) $r['loan_id'];
            if (!isset($loans[$lid]) || !isset($dateIdx[(string) $r['due_month']])) {
                continue;
            }
            $k = max($dateIdx[(string) $r['due_month']], $asOf + 1);
            if ($k <= $n) {
                $pay[$lid][$k] = ($pay[$lid][$k] ?? 0.0) + (float) $r['remaining_amount'];
            }
        }

        // Bunga diterima per bulan, dan sisa pokok akhir bulan per anggota.
        $interest = array_fill(1, max($n, 1), 0.0);
        $out = [];                                   // member => [idx => sisa pokok]
        foreach ($loans as $lid => $L) {
            $due = $L['p'] + $L['i'];
            if ($due <= 0) {
                continue;
            }
            $rest = (float) $L['p'];
            for ($k = 1; $k <= $n; $k++) {
                $x = $pay[$lid][$k] ?? 0.0;
                $interest[$k] += $x * $L['i'] / $due;
                $rest -= $x * $L['p'] / $due;
                if ($k >= $L['k']) {
                    $out[$L['m']][$k] = ($out[$L['m']][$k] ?? 0.0) + max(0.0, $rest);
                }
            }
        }

        $members = [];
        $touch = static function (int $id) use (&$members, $exempt): void {
            $members[$id] ??= ['saver_gross' => 0.0, 'borrower_gross' => 0.0, 'reserve_exempt' => $exempt[$id] ?? false];
        };
        $balance = [];                               // member => [idx => saldo akhir bulan]
        foreach ($sav as $mid => $byMonth) {
            $run = 0;
            for ($k = 1; $k <= $n; $k++) {
                $run += $byMonth[$k] ?? 0;
                $balance[$mid][$k] = $run;
            }
            $touch($mid);
        }
        foreach ($out as $mid => $_) {
            $touch($mid);
        }

        $poolTotal = 0.0;
        $undistributed = 0.0;
        for ($k = 1; $k < $n; $k++) {
            $base = $interest[$k + 1];
            if ($base <= 0) {
                continue;
            }
            $poolTotal += $base * ($saverPct + $borrowerPct);
            $sumS = 0.0;
            $sumB = 0.0;
            foreach ($balance as $b) {
                $sumS += max(0, $b[$k]);
            }
            foreach ($out as $o) {
                $sumB += $o[$k] ?? 0.0;
            }
            if ($sumS > 0) {
                foreach ($balance as $mid => $b) {
                    $members[$mid]['saver_gross'] += max(0, $b[$k]) / $sumS * $base * $saverPct;
                }
            } else {
                $undistributed += $base * $saverPct;
            }
            if ($sumB > 0) {
                foreach ($out as $mid => $o) {
                    $members[$mid]['borrower_gross'] += ($o[$k] ?? 0.0) / $sumB * $base * $borrowerPct;
                }
            } else {
                $undistributed += $base * $borrowerPct;
            }
        }

        foreach ($members as $mid => &$m) {
            $keep = $m['reserve_exempt'] ? 1.0 : 1.0 - $reservePct;
            $m['saver']    = (int) round($m['saver_gross'] * $keep);
            $m['borrower'] = (int) round($m['borrower_gross'] * $keep);
        }
        unset($m);

        return [
            'period_id' => $periodId, 'as_of' => $asOf > 0 ? (string) $months[$asOf - 1]['month_date'] : null, 'months' => $n,
            'interest_pool' => $poolTotal, 'undistributed' => $undistributed, 'members' => $members,
        ];
    }
}
