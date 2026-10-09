<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Perkiraan bagi hasil bunga per anggota untuk formulir cetak. HANYA MEMBACA: tidak ada angka yang disimpan, semuanya
 * dihitung dari transaksi DISETUJUI, jadwal pinjaman, dan penyesuaian rencana (loan_plan_adjustments).
 *
 * Dasar (Phase 1, analisis §3.2): bunga yang DITERIMA pada bulan k+1 dibagi menurut keadaan AKHIR bulan k.
 *   pool penabung  = profit_share_saver_pct (40%) x bunga bulan k+1;  porsi = saldo tabungan / total saldo semua anggota
 *   pool peminjam  = profit_share_borrower_pct (40%) x bunga bulan k+1; porsi = sisa dasar anggota / total sisa dasar
 *   cadangan reserve_pct (5%) dipotong dari keduanya, kecuali anggota reserve_exempt.
 *   Bunga dalam satu pembayaran = pembayaran x total bunga / total tagihan pinjaman (bunga flat, pro rata).
 *   Tiap baris formulir dibulatkan ke rupiah; formulir menjumlahkan baris yang sudah dibulatkan.
 *
 * Dua mode (konstanta FOLLOW_EXCEL):
 *
 * 1) FOLLOW_EXCEL = true (dipakai, atas permintaan pemilik): angkanya SAMA PERSIS dengan sheet Excel koperasi
 *    "3-REKAP TABUNGAN+bunga" dan "7-BAGI HASIL PINJAMAN" (dibuktikan untuk 57 anggota, selisih 0 rupiah):
 *      - pembayaran = RENCANA: tiap pinjaman dianggap dibayar (pokok + bunga) / tenor mulai bulan sesudah pencairan,
 *        tanpa melihat pembayaran nyata (Excel mengetik rencana itu; Bu Parmi yang belum membayar tetap dianggap membayar),
 *        ditambah penyesuaian bendahara di loan_plan_adjustments;
 *      - dasar peminjam: sisa POKOK sampai Mei, lalu sisa BUNGA mulai Juni (Excel berganti dasar tanpa alasan tertulis);
 *      - bagi hasil penabung bulan Desember tidak dibagikan (Excel membiarkannya 0; bagian itu tak diberikan ke siapa pun).
 *    Kejanggalan di atas sengaja dipertahankan agar angka sama dengan Excel; hubungannya dengan periode ini saja
 *    (siklus ke-4 = Juni, ke-10 = Desember pada Mar 2026 - Feb 2027).
 *
 * 2) FOLLOW_EXCEL = false: aturan Q8 apa adanya (dasar sisa pokok sepanjang tahun, semua pool terbagi, pembayaran nyata
 *    ditambah proyeksi sisa jadwal untuk bulan yang belum terjadi). Perhitungan ini dipertahankan dan diuji.
 */
final class ProfitShare
{
    public const FOLLOW_EXCEL = true;
    private const EXCEL_BASIS_SWITCH_CYCLE = 4;    // siklus ke-4 (Juni): dasar peminjam berganti ke sisa bunga
    private const EXCEL_SKIP_SAVER_CYCLE = 10;     // siklus ke-10 (Desember): pool penabung tidak dibagikan

    /**
     * @return array{
     *   period_id:int, as_of:?string, months:int, excel:bool,
     *   interest_pool:float, undistributed:float,
     *   members:array<int,array{saver:int,borrower:int,saver_gross:float,borrower_gross:float,reserve_exempt:bool}>
     * }
     */
    public static function compute(int $periodId, ?bool $excel = null): array
    {
        $excel ??= self::FOLLOW_EXCEL;
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
        $loans = [];                                 // loan_id => [member, principal, interest, tenor, disbursed idx]
        foreach (Database::select(
            'SELECT ln.id, ln.member_id, ln.principal, ln.total_interest, ln.tenor_months, t.period_month_id
             FROM loans ln JOIN v_loan_balances b ON b.loan_id = ln.id JOIN transactions t ON t.id = ln.transaction_id
             JOIN period_months pm ON pm.id = t.period_month_id WHERE pm.period_id = ?',
            [$periodId]
        ) as $r) {
            $loans[(int) $r['id']] = ['m' => (int) $r['member_id'], 'p' => (int) $r['principal'], 'i' => (int) $r['total_interest'], 'n' => max(1, (int) $r['tenor_months']), 'k' => $idx[(int) $r['period_month_id']]];
        }

        $pay = [];                                   // loan => [idx => rupiah dibayar pada bulan itu]
        if ($excel) {
            // Rencana: (pokok + bunga) / tenor tiap bulan, mulai bulan sesudah pencairan; lalu penyesuaian bendahara.
            foreach ($loans as $lid => $L) {
                for ($j = 1; $j <= $L['n']; $j++) {
                    if ($L['k'] + $j <= $n) {
                        $pay[$lid][$L['k'] + $j] = ($L['p'] + $L['i']) / $L['n'];
                    }
                }
            }
            foreach (Database::select(
                'SELECT a.loan_id, a.period_month_id, a.amount FROM loan_plan_adjustments a JOIN period_months pm ON pm.id = a.period_month_id WHERE pm.period_id = ?',
                [$periodId]
            ) as $r) {
                if (isset($loans[(int) $r['loan_id']])) {
                    $k = $idx[(int) $r['period_month_id']];
                    $pay[(int) $r['loan_id']][$k] = ($pay[(int) $r['loan_id']][$k] ?? 0.0) + (float) $r['amount'];
                }
            }
        } else {
            // Pembayaran nyata per pinjaman per bulan (pembalik berbobot negatif sudah menetralkan).
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
        }

        // Bunga diterima per bulan, serta sisa pokok dan sisa bunga akhir bulan per anggota.
        $interest = array_fill(1, max($n, 1), 0.0);
        $outP = [];                                  // member => [idx => sisa pokok]
        $outI = [];                                  // member => [idx => sisa bunga]
        foreach ($loans as $lid => $L) {
            $due = $L['p'] + $L['i'];
            if ($due <= 0) {
                continue;
            }
            $restP = (float) $L['p'];
            $restI = (float) $L['i'];
            for ($k = 1; $k <= $n; $k++) {
                $x = $pay[$lid][$k] ?? 0.0;
                $interest[$k] += $x * $L['i'] / $due;
                $restP -= $x * $L['p'] / $due;
                $restI -= $x * $L['i'] / $due;
                if ($k >= $L['k']) {
                    $outP[$L['m']][$k] = ($outP[$L['m']][$k] ?? 0.0) + max(0.0, $restP);
                    $outI[$L['m']][$k] = ($outI[$L['m']][$k] ?? 0.0) + max(0.0, $restI);
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
        foreach ($outP as $mid => $_) {
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
            $out = $excel && $k >= self::EXCEL_BASIS_SWITCH_CYCLE ? $outI : $outP;
            $sumS = 0.0;
            $sumB = 0.0;
            foreach ($balance as $b) {
                $sumS += max(0, $b[$k]);
            }
            foreach ($out as $o) {
                $sumB += $o[$k] ?? 0.0;
            }
            if ($sumS > 0 && !($excel && $k === self::EXCEL_SKIP_SAVER_CYCLE)) {
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
            'period_id' => $periodId, 'as_of' => $asOf > 0 ? (string) $months[$asOf - 1]['month_date'] : null, 'months' => $n, 'excel' => $excel,
            'interest_pool' => $poolTotal, 'undistributed' => $undistributed, 'members' => $members,
        ];
    }
}
