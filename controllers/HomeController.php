<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Models\Dashboard;
use App\Models\Transaction;
use App\Models\Validation;
use App\Services\Auth;
use App\Services\Scope;
use App\Services\ValidationService;

/**
 * Dashboard per peran. Cakupan data mengikuti peran, dan ditegakkan di query (Scope), bukan di tampilan:
 *   Head / Pemeriksa : seluruh koperasi (+ antrean validasi, integritas);
 *   Ketua Regu       : regu yang dipimpin (+ pekerjaan sendiri);
 *   Anggota          : dirinya sendiri.
 * Purwati (Head + Ketua Regu) mendapat tampilan global ditambah bagian "Regu Saya".
 */
final class HomeController extends BaseController
{
    private const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $user  = Auth::user();
        $level = Scope::level($user, 'transaction');
        $d = [
            'title'  => 'Dashboard',
            'level'  => $level,
            'period' => Dashboard::activePeriod(),
            'month'  => month_label(date('Y-m-01')),
            'charts' => true,
            'recent' => Dashboard::recent($user, 6),
            'dues'   => $level === Scope::NONE ? [] : array_slice(array_values(array_filter(Transaction::dueByMember($user), static fn (array $r): bool => (int) $r['overdue'] > 0)), 0, 5),
            'blocks' => [],
        ];

        $flow = Dashboard::monthly($user);
        $d['flowCharts'] = $this->flowCharts($flow);

        if ($level === Scope::ALL) {
            $d['summary'] = Dashboard::summary();
            $d['teams']   = Dashboard::teams();
            $d['teamChart'] = $this->teamChart($d['teams']);
            $d['splitChart'] = $this->splitChart($d['summary']);
            if (Gate::allows($user, 'transaction.validate')) {
                $q = Validation::summary();
                $d['queue'] = ['n' => array_sum(array_column($q, 'n')), 'sum' => array_sum(array_column($q, 'sum'))];
            }
            if (Gate::allows($user, 'audit.view')) {
                $d['issues'] = Dashboard::integrityIssues();
            }
            if (Gate::allows($user, 'user.manage')) {
                $d['noChecker'] = !ValidationService::hasActiveChecker();
            }
        }

        if (Gate::allows($user, 'transaction.view.team') && ($user['team_id'] ?? null) !== null) {
            $members = Dashboard::teamMembers((int) $user['team_id']);
            $d['team'] = [
                'name'        => (string) (\App\Core\Database::select('SELECT name FROM team_leaders WHERE id = ?', [(int) $user['team_id']])[0]['name'] ?? ''),
                'members'     => $members,
                'count'       => count(array_filter($members, static fn (array $m): bool => $m['status'] === 'AKTIF')),
                'savings'     => array_sum(array_column($members, 'savings')),
                'outstanding' => array_sum(array_column($members, 'outstanding')),
                'overdue'     => array_sum(array_column($members, 'overdue')),
                'chart'       => $this->membersChart($members),
                'work'        => Dashboard::ownWork((int) $user['id']),
            ];
        }

        if ($level === Scope::SELF && ($user['member_id'] ?? null) !== null) {
            $d['me'] = Dashboard::member((int) $user['member_id']);
        }

        return $this->view($request, 'home', $d);
    }

    // ------------------------------------------------------------------ grafik

    /**
     * Spesifikasi grafik: dibaca skrip charts.js dari atribut data. Seri memakai nomor "slot" warna
     * (urutan tetap), bukan kode warna; kode warnanya ada di CSS. Null bila tidak ada angka untuk digambar.
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,data:array<int,int>,slot:int}> $series
     * @return array<string,mixed>|null
     */
    private function chart(string $id, string $title, string $type, array $labels, array $series, bool $horizontal = false, string $note = ''): ?array
    {
        $any = false;
        foreach ($series as $s) {
            foreach ($s['data'] as $v) {
                if ($v !== 0) {
                    $any = true;
                }
            }
        }
        return $any ? ['id' => $id, 'title' => $title, 'type' => $type, 'labels' => $labels, 'series' => $series, 'horizontal' => $horizontal, 'note' => $note] : null;
    }

    /**
     * @param array{months:array<int,array<string,mixed>>,base:int} $flow
     * @return array{flow:?array<string,mixed>,growth:?array<string,mixed>}
     */
    private function flowCharts(array $flow): array
    {
        $labels = array_map(static fn (array $m): string => self::SHORT_MONTHS[(int) substr($m['month'], 5, 2) - 1] . ' ' . substr($m['month'], 2, 2), $flow['months']);
        $running = $flow['base'];
        $growth = [];
        foreach ($flow['months'] as $m) {
            $running += $m['tabungan'];
            $growth[] = $running;
        }
        return [
            'flow' => $this->chart('flow', 'Arus per bulan', 'bar', $labels, [
                ['label' => 'Simpanan', 'data' => array_column($flow['months'], 'simpanan'), 'slot' => 1],
                ['label' => 'Angsuran', 'data' => array_column($flow['months'], 'angsuran'), 'slot' => 2],
                ['label' => 'Pencairan pinjaman', 'data' => array_column($flow['months'], 'pencairan'), 'slot' => 3],
            ], false, 'Transaksi disetujui, bersih dari koreksi.'),
            'growth' => $this->chart('growth', 'Saldo tabungan', 'line', $labels, [['label' => 'Saldo tabungan', 'data' => $growth, 'slot' => 1]], false, 'Akhir tiap bulan.'),
        ];
    }

    /** @param array<int,array<string,mixed>> $teams */
    private function teamChart(array $teams): ?array
    {
        return $this->chart('teams', 'Tabungan dan sisa pinjaman per regu', 'bar', array_column($teams, 'name'), [
            ['label' => 'Tabungan', 'data' => array_map('intval', array_column($teams, 'savings')), 'slot' => 1],
            ['label' => 'Sisa pinjaman', 'data' => array_map('intval', array_column($teams, 'outstanding')), 'slot' => 2],
        ], true);
    }

    /** @param array<string,int> $summary */
    private function splitChart(array $summary): ?array
    {
        return $this->chart('split', 'Kas dan piutang', 'doughnut', ['Kas tersedia', 'Piutang beredar'], [
            ['label' => 'Dana koperasi', 'data' => [max(0, $summary['kas_tersedia']), max(0, $summary['piutang_beredar'])], 'slot' => 1],
        ], false, 'Dana yang ada di kas dibandingkan dengan yang sedang dipinjam.');
    }

    /** @param array<int,array<string,mixed>> $members */
    private function membersChart(array $members): ?array
    {
        $top = array_slice($members, 0, 10);
        return $this->chart('members', 'Tabungan anggota (10 terbesar)', 'bar', array_column($top, 'name'), [
            ['label' => 'Tabungan', 'data' => array_map('intval', array_column($top, 'savings')), 'slot' => 1],
        ], true);
    }
}
