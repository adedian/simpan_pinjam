<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Models\Transaction;
use App\Services\AuditLog;
use App\Services\Auth;
use App\Services\ReportService;

/**
 * Laporan. Hanya membaca. Cakupan data ditegakkan di ReportService (SQL); izin di route.
 * Unduhan Excel hanya untuk pemilik report.export (Head, Pemeriksa) dan SELALU tercatat di audit log.
 */
final class ReportController extends BaseController
{
    /** @param array<string,string> $params */
    public function simpanan(Request $request, array $params = []): Response
    {
        return $this->table($request, 'simpanan');
    }

    /** @param array<string,string> $params */
    public function pinjaman(Request $request, array $params = []): Response
    {
        return $this->table($request, 'pinjaman');
    }

    /** @param array<string,string> $params */
    public function angsuran(Request $request, array $params = []): Response
    {
        return $this->table($request, 'angsuran');
    }

    /** @param array<string,string> $params */
    public function saldo(Request $request, array $params = []): Response
    {
        return $this->table($request, 'saldo');
    }

    /** @param array<string,string> $params */
    public function transaksi(Request $request, array $params = []): Response
    {
        return $this->table($request, 'transaksi');
    }

    /** @param array<string,string> $params */
    public function regu(Request $request, array $params = []): Response
    {
        return $this->table($request, 'regu');
    }

    /** Pilih anggota untuk kartu anggota. @param array<string,string> $params */
    public function members(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $f    = ReportService::filters($request->query, $user);
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $all  = ReportService::members($user, $f);
        $pager = \App\Services\Pagination::make(count($all), $page);

        return $this->view($request, 'laporan/members', [
            'title'   => 'Laporan Per Anggota',
            'canExport' => Gate::allows($user, 'report.export'),
            'rows'    => array_slice($all, $pager['offset'], $pager['per_page']),
            'pager'   => $pager,
            'filters' => $f,
            'teams'   => ReportService::level($user) === ReportService::ALL ? ReportService::teams() : [],
        ]);
    }

    /** Kartu satu anggota. Di luar cakupan = 404 yang sama dengan anggota yang tidak ada. @param array<string,string> $params */
    public function member(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $card = ReportService::statement($user, $id);
        if ($card === null) {
            if (\App\Models\Member::exists($id)) {
                AuditLog::record($request, $user, 'ACCESS_DENIED_SCOPE', 'member', $id, null, null, ['path' => $request->path]);
            }
            $this->abort(404);
        }
        return $this->view($request, 'laporan/member', [
            'title'     => 'Laporan · ' . $card['member']['name'],
            'card'      => $card,
            'types'     => Transaction::TYPE_LABELS,
            'kinds'     => \App\Services\SavingService::KIND_LABELS,
            'canExport' => Gate::allows($user, 'report.export'),
            'printedBy' => (string) ($user['name'] ?? ''),
            'printedAt' => date('d-m-Y H:i'),
        ]);
    }

    /**
     * Formulir cetak (Rekap Pinjaman / Tabungan Hari Raya) satu anggota. Di luar cakupan = 404 yang sama dengan tidak ada.
     * @param array<string,string> $params
     */
    public function sheet(Request $request, array $params = []): Response
    {
        $user  = Auth::user();
        $id    = (int) $params['id'];
        $sheet = \App\Services\MemberSheet::build($user, $id);
        if ($sheet === null) {
            if (\App\Models\Member::exists($id)) {
                AuditLog::record($request, $user, 'ACCESS_DENIED_SCOPE', 'member', $id, null, null, ['path' => $request->path]);
            }
            $this->abort(404);
        }
        return $this->sheetView($request, (string) $params['kind'], [$sheet], $sheet, ReportService::filters([], $user), []);
    }

    /** Formulir cetak massal: semua anggota dalam cakupan, bisa disaring regu atau nama. @param array<string,string> $params */
    public function sheets(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $f    = ReportService::filters($request->query, $user);
        $teams = ReportService::level($user) === ReportService::ALL ? ReportService::teams() : [];
        return $this->sheetView($request, (string) $params['kind'], \App\Services\MemberSheet::buildMany($user, $f), null, $f, $teams);
    }

    /**
     * Unduh Excel (.xlsx). Hanya report.export; tercatat di audit log (siapa, laporan apa, saringan, berapa baris).
     * Enam laporan rekap berbentuk tabel; "anggota" berisi formulir cetak (Rekap Pinjaman + Tabungan Hari Raya) satu anggota,
     * atau semua anggota dalam cakupan bila tanpa ?anggota=.
     * @param array<string,string> $params
     */
    public function download(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $key  = (string) $params['key'];
        if (!in_array($key, ReportService::KEYS, true) && $key !== 'anggota') {
            $this->abort(404);
        }
        $f = ReportService::filters($request->query, $user);
        if ($key === 'anggota') {
            $sheets = $f['anggota'] > 0
                ? array_filter([\App\Services\MemberSheet::build($user, $f['anggota'])])
                : \App\Services\MemberSheet::buildMany($user, $f);
            if ($sheets === []) {
                $this->abort(404);
            }
            $sheets = array_values($sheets);
            $label  = $f['anggota'] > 0 ? 'anggota-' . $sheets[0]['member']['member_no'] : 'anggota-semua';
            $rows   = count($sheets);
            $bytes  = \App\Services\SheetWorkbook::build($sheets);
            $name   = ($f['anggota'] > 0 ? 'formulir-' . $sheets[0]['member']['member_no'] : 'formulir-semua-anggota') . '-' . date('Y-m-d') . '.xlsx';
        } else {
            $report = ReportService::build($key, $user, $f, 1, true);
            $label  = (string) $report['key'];
            $rows   = count($report['rows']);
            $bytes  = \App\Services\ReportWorkbook::build($report);
            $name   = 'laporan-' . preg_replace('/[^a-z0-9_-]+/i', '-', $label) . '-' . date('Y-m-d') . '.xlsx';
        }

        AuditLog::record($request, $user, 'REPORT_EXPORTED', 'report', null, $label, null, [
            'filters' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== 0), 'rows' => $rows,
        ]);
        return new Response($bytes, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------------ bantu

    /**
     * @param array<int,array<string,mixed>> $sheets
     * @param array<string,mixed>|null $single
     * @param array<string,mixed> $filters
     * @param array<int,array{id:int,name:string}> $teams
     */
    private function sheetView(Request $request, string $kind, array $sheets, ?array $single, array $filters, array $teams): Response
    {
        $label = $kind === 'pinjaman' ? 'Rekap Pinjaman' : 'Tabungan Hari Raya';
        $keep  = array_filter(['regu' => $filters['team'] > 0 ? (string) $filters['team'] : '', 'q' => $filters['q']], static fn (string $v): bool => $v !== '');
        return $this->view($request, 'laporan/lembar', [
            'title'   => $label . ($single !== null ? ' · ' . $single['member']['name'] : ' · semua anggota'),
            'canExport' => Gate::allows(Auth::user(), 'report.export'),
            'kind'    => $kind,
            'sheets'  => $sheets,
            'single'  => $single,
            'filters' => $filters,
            'teams'   => $teams,
            'query'   => http_build_query($keep),
        ]);
    }

    private function table(Request $request, string $key): Response
    {
        $user  = Auth::user();
        $f     = ReportService::filters($request->query, $user);
        $level = ReportService::level($user);
        $report = ReportService::build($key, $user, $f, max(1, (int) ($request->query['page'] ?? 1)));

        return $this->view($request, 'laporan/table', [
            'title'     => $report['title'],
            'report'    => $report,
            'filters'   => $f,
            'fields'    => $this->fields($key, $f, $level),
            'canExport' => Gate::allows($user, 'report.export'),
            'level'     => $level,
            'printedBy' => (string) ($user['name'] ?? ''),
            'printedAt' => date('d-m-Y H:i'),
            'query'     => $this->activeQuery($request),
            'basePath'  => '/laporan/' . $key,
        ]);
    }

    /**
     * Isian saringan yang relevan untuk laporan ini.
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function fields(string $key, array $f, string $level): array
    {
        $out = [];
        if ($key !== 'transaksi') {
            $opts = [];
            foreach (ReportService::periods() as $p) {
                $opts[$p['id']] = $p['name'] . ($p['status'] === 'AKTIF' ? ' (aktif)' : '');
            }
            $out[] = ['name' => 'periode', 'label' => 'Periode', 'type' => 'select', 'options' => $opts, 'value' => (string) $f['period']];
        } else {
            $out[] = ['name' => 'dari', 'label' => 'Dari tanggal', 'type' => 'date', 'value' => $f['from']];
            $out[] = ['name' => 'sampai', 'label' => 'Sampai tanggal', 'type' => 'date', 'value' => $f['to']];
            $out[] = ['name' => 'jenis', 'label' => 'Jenis', 'type' => 'select', 'options' => ['' => 'Semua jenis'] + Transaction::TYPE_LABELS, 'value' => $f['type']];
            $statuses = ['' => 'Semua status'];
            foreach (Transaction::STATUSES as $s) {
                $statuses[$s] = ucwords(strtolower(str_replace('_', ' ', $s)));
            }
            $out[] = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $statuses, 'value' => $f['status']];
        }
        if ($level === ReportService::ALL) {
            $teams = ['0' => 'Semua regu'];
            foreach (ReportService::teams() as $t) {
                $teams[$t['id']] = $t['name'];
            }
            $out[] = ['name' => 'regu', 'label' => 'Regu', 'type' => 'select', 'options' => $teams, 'value' => (string) $f['team']];
        }
        if ($key === 'simpanan' || $key === 'angsuran' || $key === 'saldo' || $key === 'pinjaman' || $key === 'transaksi') {
            $out[] = ['name' => 'q', 'label' => 'Cari', 'type' => 'text', 'value' => $f['q'], 'placeholder' => $key === 'transaksi' ? 'Anggota atau nomor dokumen' : 'Nama atau nomor anggota'];
        }
        if ($key === 'pinjaman') {
            $out[] = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['' => 'Semua', 'AKTIF' => 'Aktif', 'LUNAS' => 'Lunas'], 'value' => $f['status']];
        }
        return $out;
    }

    /**
     * Saringan aktif untuk tautan unduhan dan paginasi.
     * @return array<string,string>
     */
    private function activeQuery(Request $request): array
    {
        $out = [];
        foreach (['periode', 'regu', 'q', 'dari', 'sampai', 'jenis', 'anggota'] as $k) {
            if ($request->queryStr($k) !== '') {
                $out[$k] = $request->queryStr($k);
            }
        }
        if (array_key_exists('status', $request->query)) {
            $out['status'] = $request->queryStr('status');
        }
        return $out;
    }
}
