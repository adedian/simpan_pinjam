<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Models\Audit;
use App\Services\AuditLog;
use App\Services\Auth;
use App\Services\ReportService;

/**
 * Layar Audit Log (Phase 13): hanya membaca jejak yang tidak bisa diubah. Izin audit.view (Head, Pemeriksa);
 * unduhan Excel juga butuh report.export dan dirinya sendiri tercatat (AUDIT_EXPORTED).
 */
final class AuditController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f      = Audit::filters($request->query);
        $result = Audit::search($f, max(1, (int) ($request->query['page'] ?? 1)));

        return $this->view($request, 'sistem/audit', [
            'title'     => 'Audit Log',
            'rows'      => $result['rows'],
            'pager'     => $result['pager'],
            'filters'   => $f,
            'summary'   => Audit::summary(),
            'actions'   => Audit::presentActions(),
            'groups'    => Audit::GROUPS,
            'entities'  => Audit::ENTITIES,
            'canExport' => Gate::allows(Auth::user(), 'report.export'),
            'query'     => array_filter($f, static fn (string $v): bool => $v !== ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $entry = Audit::find((int) $params['id']);
        if ($entry === null) {
            $this->abort(404);
        }
        return $this->view($request, 'sistem/audit-show', [
            'title'  => 'Audit #' . (int) $entry['id'],
            'entry'  => $entry,
            'before' => Audit::flatten($entry['before_data']),
            'after'  => Audit::flatten($entry['after_data']),
            'canUser' => Gate::allows(Auth::user(), 'user.manage'),
        ]);
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if (!Gate::allows($user, 'report.export')) {
            $this->abort(403);
        }
        $f      = Audit::filters($request->query);
        $result = Audit::search($f, 1, true);

        $columns = [];
        foreach ([['time', 'Waktu'], ['user', 'Pengguna'], ['roles', 'Peran'], ['action', 'Aksi'], ['code', 'Kode aksi'], ['entity', 'Objek'], ['entity_id', 'ID objek'], ['ref', 'Referensi'], ['ip', 'Alamat IP'], ['before', 'Sebelum'], ['after', 'Sesudah']] as [$k, $l]) {
            $columns[] = ['key' => $k, 'label' => $l, 'type' => 'text'];
        }
        $rows = [];
        foreach ($result['rows'] as $r) {
            $rows[] = [
                'time' => (string) $r['created_at'], 'user' => (string) ($r['username'] ?? ''), 'roles' => (string) ($r['roles'] ?? ''), 'action' => Audit::label((string) $r['action']),
                'code' => (string) $r['action'], 'entity' => Audit::ENTITIES[$r['entity_type']] ?? (string) $r['entity_type'], 'entity_id' => (string) ($r['entity_id'] ?? ''),
                'ref' => (string) ($r['reference_no'] ?? ''), 'ip' => (string) ($r['ip_address'] ?? ''),
                'before' => Audit::compactJson($r['before_data']), 'after' => Audit::compactJson($r['after_data']),
            ];
        }
        AuditLog::record($request, $user, 'AUDIT_EXPORTED', 'report', null, 'audit', null, [
            'filters' => array_filter($f, static fn (string $v): bool => $v !== ''), 'rows' => count($rows),
        ]);
        $bytes = \App\Services\ReportWorkbook::build(['title' => 'Audit Log', 'subtitle' => 'Dicetak ' . date('d-m-Y H:i'), 'columns' => $columns, 'rows' => $rows, 'totals' => null]);
        return new Response($bytes, 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => 'attachment; filename="audit-log-' . date('Y-m-d') . '.xlsx"',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
