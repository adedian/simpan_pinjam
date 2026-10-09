<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Data dua formulir cetak per anggota (tampilan mengikuti formulir Excel koperasi):
 *   - Rekap Pinjaman           : pokok, bunga, bayar angsuran, sisa pinjaman per bulan
 *   - Tabungan Hari Raya       : masuk, keluar, total tabungan per bulan + bagi hasil dan total diterima
 * HANYA MEMBACA. Semua angka dari transaksi DISETUJUI (pembalik mengurangi bulan asalnya); bagi hasil dari ProfitShare.
 * Cakupan anggota ditegakkan di SQL lewat ReportService::memberCondition, sama seperti laporan lain.
 */
final class MemberSheet
{
    /**
     * Satu anggota. Di luar cakupan atau tidak ada = null.
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>|null
     */
    public static function build(?array $user, int $memberId): ?array
    {
        [$scope, $params] = ReportService::memberCondition($user, 'm.id');
        $ok = Database::select(
            "SELECT m.id FROM members m WHERE m.id = ? AND m.deleted_at IS NULL AND ({$scope})",
            array_merge([$memberId], $params)
        );
        if ($ok === []) {
            return null;
        }
        $f = ReportService::filters([], $user);
        return self::load([$memberId], $f['period'])[0] ?? null;
    }

    /**
     * Semua anggota dalam cakupan (dan saringan regu/pencarian) untuk cetak massal.
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $f hasil ReportService::filters
     * @return array<int,array<string,mixed>>
     */
    public static function buildMany(?array $user, array $f): array
    {
        $ids = array_map(static fn (array $m): int => $m['id'], ReportService::members($user, $f));
        return $ids === [] ? [] : self::load($ids, $f['period']);
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>> urutan mengikuti $ids
     */
    private static function load(array $ids, int $periodId): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $period = Database::select('SELECT id, name FROM periods WHERE id = ?', [$periodId])[0] ?? ['id' => $periodId, 'name' => ''];
        $months = Database::select('SELECT id, month_date, meeting_date FROM period_months WHERE period_id = ? ORDER BY month_date', [$periodId]);

        $info = [];
        foreach (Database::select(
            "SELECT m.id, m.member_no, m.name, m.address_block, m.active_from, tl.name AS team_name
             FROM members m
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id
             WHERE m.id IN ({$in})",
            $ids
        ) as $r) {
            $info[(int) $r['id']] = $r;
        }

        // Jumlah bersih per anggota, bulan, dan jenis (pembalik bertanda negatif).
        $net = [];
        foreach (Database::select(
            "SELECT l.member_id, l.period_month_id, l.type, SUM(l.sign * l.amount) AS a
             FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id
             WHERE pm.period_id = ? AND l.member_id IN ({$in}) AND l.type IN ('SIMPANAN','PENARIKAN','PENCAIRAN_PINJAMAN','ANGSURAN')
             GROUP BY l.member_id, l.period_month_id, l.type",
            array_merge([$periodId], $ids)
        ) as $r) {
            $net[(int) $r['member_id']][(int) $r['period_month_id']][(string) $r['type']] = (int) $r['a'];
        }

        // Bunga dan tenor pinjaman efektif per bulan pencairan.
        $loans = [];
        foreach (Database::select(
            "SELECT ln.member_id, t.period_month_id, SUM(ln.total_interest) AS interest, GROUP_CONCAT(ln.tenor_months ORDER BY ln.id) AS tenors
             FROM loans ln JOIN v_loan_balances b ON b.loan_id = ln.id
             JOIN transactions t ON t.id = ln.transaction_id JOIN period_months pm ON pm.id = t.period_month_id
             WHERE pm.period_id = ? AND ln.member_id IN ({$in}) GROUP BY ln.member_id, t.period_month_id",
            array_merge([$periodId], $ids)
        ) as $r) {
            $loans[(int) $r['member_id']][(int) $r['period_month_id']] = ['interest' => (int) $r['interest'], 'tenors' => array_map('intval', explode(',', (string) $r['tenors']))];
        }

        // Nomor cicilan yang dilunasi (bersih) tiap bulan, untuk kolom Keterangan.
        $seqs = [];
        foreach (Database::select(
            "SELECT t.member_id, t.period_month_id, li.seq, SUM(ip.amount) AS a
             FROM installment_payments ip JOIN loan_installments li ON li.id = ip.installment_id
             JOIN transactions t ON t.id = ip.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL
             JOIN period_months pm ON pm.id = t.period_month_id
             WHERE pm.period_id = ? AND t.member_id IN ({$in})
             GROUP BY t.member_id, t.period_month_id, li.seq HAVING SUM(ip.amount) > 0 ORDER BY li.seq",
            array_merge([$periodId], $ids)
        ) as $r) {
            $seqs[(int) $r['member_id']][(int) $r['period_month_id']][] = (int) $r['seq'];
        }

        $share = ProfitShare::compute($periodId);

        $out = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                continue;
            }
            $m = $info[$id];
            $saveRows = [];
            $loanRows = [];
            $total = 0;
            $remain = 0;
            $disbursed = 0;
            foreach ($months as $i => $pm) {
                $pid  = (int) $pm['id'];
                $date = (string) ($pm['meeting_date'] ?? $pm['month_date']);
                $t    = $net[$id][$pid] ?? [];

                $in_  = (int) ($t['SIMPANAN'] ?? 0);
                $outg = (int) ($t['PENARIKAN'] ?? 0);
                $total += $in_ - $outg;
                $saveRows[] = ['no' => $i + 1, 'date' => $date, 'in' => $in_, 'out' => $outg, 'total' => $total];

                $pokok = (int) ($t['PENCAIRAN_PINJAMAN'] ?? 0);
                $bunga = (int) ($loans[$id][$pid]['interest'] ?? 0);
                $bayar = (int) ($t['ANGSURAN'] ?? 0);
                $remain += $pokok + $bunga - $bayar;
                $disbursed += $pokok;
                $notes = [];
                if (isset($loans[$id][$pid])) {
                    $notes[] = 'Tenor ' . implode(' & ', $loans[$id][$pid]['tenors']) . ' bulan';
                }
                if (isset($seqs[$id][$pid])) {
                    $notes[] = 'Cicilan ke-' . implode(' & ', $seqs[$id][$pid]);
                }
                $loanRows[] = ['no' => $i + 1, 'date' => $date, 'pokok' => $pokok, 'bunga' => $bunga, 'bayar' => $bayar, 'sisa' => $remain, 'note' => implode('; ', $notes)];
            }

            $s = $share['members'][$id] ?? ['saver' => 0, 'borrower' => 0];
            $out[] = [
                'member' => [
                    'id' => $id, 'member_no' => (string) $m['member_no'], 'no' => (int) preg_replace('/\D+/', '', (string) $m['member_no']),
                    'name' => (string) $m['name'], 'address' => (string) ($m['address_block'] ?? ''), 'active_from' => (string) $m['active_from'],
                    'team' => (string) ($m['team_name'] ?? ''),
                ],
                'period' => (string) $period['name'],
                'as_of'  => $share['as_of'],
                'note'   => $share['excel']
                    ? 'Bagi hasil adalah perkiraan mengikuti perhitungan Excel koperasi: cicilan dianggap dibayar sesuai jadwal. Belum dikurangi sisa pinjaman.'
                    : 'Bagi hasil adalah perkiraan' . ($share['as_of'] !== null ? ' per data ' . month_label((string) $share['as_of']) : '') . '; cicilan yang belum dibayar dihitung sesuai jadwal. Belum dikurangi sisa pinjaman.',
                'loan'   => ['rows' => $loanRows, 'remaining' => $remain],
                'saving' => [
                    'rows' => $saveRows, 'total' => $total, 'saver_share' => (int) $s['saver'], 'loan_total' => $disbursed,
                    'borrower_share' => (int) $s['borrower'], 'received' => $total + (int) $s['saver'] + (int) $s['borrower'],
                ],
            ];
        }
        return $out;
    }
}
