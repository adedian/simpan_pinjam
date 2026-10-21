<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditLog;
use App\Services\Auth;
use App\Services\PayoutService;
use App\Services\ReportService;
use App\Services\ReportWorkbook;
use App\Services\RuleViolation;

/**
 * Pencairan Akhir (rekap pembagian akhir). Hanya Superadmin (izin payout.manage, dipaksa di route). Lihat PayoutService.
 */
final class PayoutController extends BaseController
{
    /** Periode dari query (?periode=); bawaan: periode AKTIF, lalu yang terbaru. Id tak dikenal = bawaan. */
    private function period(Request $request): int
    {
        $periods = ReportService::periods();
        $want = (int) $request->queryStr('periode');
        foreach ($periods as $p) {
            if ($p['id'] === $want) {
                return $want;
            }
        }
        foreach ($periods as $p) {
            if ($p['status'] === 'AKTIF') {
                return $p['id'];
            }
        }
        return $periods[0]['id'] ?? 0;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $period = $this->period($request);
        $batches = PayoutService::batches($period);
        $active = null;
        foreach ($batches as $b) {
            if ($b['void_reason'] === null) {
                $active = $b;
                break;
            }
        }
        return $this->view($request, 'pencairan/index', [
            'title'    => 'Pencairan Akhir',
            'periods'  => ReportService::periods(),
            'period'   => $period,
            'batches'  => $batches,
            'active'   => $active,
            'snapshot' => PayoutService::baseSnapshot($period),
        ]);
    }

    /** Formulir penyusunan: usulan dari snapshot terbaru yang berlaku + isian potongan manual. @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $period = $this->period($request);
        $snap = PayoutService::baseSnapshot($period);
        if ($snap === null) {
            Session::flash('warning', 'Belum ada snapshot bagi hasil yang berlaku pada periode ini. Tetapkan snapshot di Pembagian Bunga dulu.');
            return $this->redirect('/pencairan?periode=' . $period);
        }
        foreach (PayoutService::batches($period) as $b) {
            if ($b['void_reason'] === null) {
                Session::flash('warning', 'Periode ini sudah punya pencairan akhir yang berlaku.');
                return $this->redirect('/pencairan/' . (int) $b['id']);
            }
        }
        $pv = PayoutService::preview($period, $snap['id']);
        if ($pv === null) {
            $this->abort(404);
        }
        return $this->view($request, 'pencairan/form', [
            'title'   => 'Susun Pencairan Akhir',
            'period'  => $period,
            'pv'      => $pv,
            'units'   => PayoutService::UNITS,
        ]);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $period = (int) ($request->post['periode'] ?? 0);
        if (!in_array($period, array_column(ReportService::periods(), 'id'), true)) {
            $this->abort(404);
        }
        $input = is_array($request->post['d'] ?? null) ? $request->post['d'] : [];
        $back = '/pencairan/baru?periode=' . $period;
        try {
            $id = PayoutService::create($request, $user, $period, (int) ($request->post['snapshot'] ?? 0), (int) ($request->post['unit'] ?? 0), $input, $request->str('note'));
        } catch (RuleViolation $e) {
            return $this->backWithErrors($back, $e->errors, ['d' => $input, 'unit' => $request->str('unit'), 'note' => $request->str('note')]);
        }
        Session::flash('success', 'Pencairan akhir disusun dan tidak akan berubah lagi. Catat penerimaan uang tiap anggota di halaman ini.');
        return $this->redirect('/pencairan/' . $id);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $p = PayoutService::find((int) $params['id']);
        if ($p === null) {
            $this->abort(404);
        }
        $user = Auth::user();
        $report = PayoutService::report($p);
        // Kolom aksi penerimaan tidak masuk struktur laporan (yang dipakai Excel); tampilan menambah sendiri per baris.
        return $this->view($request, 'pencairan/show', [
            'title'     => 'Pencairan Akhir · ' . $p['period_name'],
            'p'         => $p,
            'report'    => $report,
            'denoms'    => PayoutService::denominations($p['lines']),
            'canExport' => Gate::allows($user, 'report.export'),
            'printedBy' => (string) ($user['name'] ?? ''),
            'printedAt' => date('d-m-Y H:i'),
        ]);
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if (!Gate::allows($user, 'report.export')) {
            $this->abort(403);
        }
        $p = PayoutService::find((int) $params['id']);
        if ($p === null) {
            $this->abort(404);
        }
        $report = PayoutService::report($p);
        AuditLog::record($request, $user, 'PAYOUT_EXPORTED', 'payout', (int) $p['id'], 'pencairan', null, ['rows' => count($report['rows'])]);
        return new Response(ReportWorkbook::build($report), 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => 'attachment; filename="pencairan-akhir-' . (int) $p['id'] . '-' . date('Y-m-d') . '.xlsx"',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Catat satu anggota sudah menerima uangnya. @param array<string,string> $params */
    public function receive(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        if (PayoutService::find($id) === null) {
            $this->abort(404);
        }
        try {
            PayoutService::receive($request, Auth::user(), $id, (int) ($request->post['line'] ?? 0), $request->str('received_on'), $request->str('note'));
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
            return $this->redirect('/pencairan/' . $id);
        }
        Session::flash('success', 'Penerimaan uang dicatat.');
        return $this->redirect('/pencairan/' . $id);
    }

    /** @param array<string,string> $params */
    public function void(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        if (PayoutService::find($id) === null) {
            $this->abort(404);
        }
        try {
            PayoutService::void($request, Auth::user(), $id, $request->str('reason'));
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
            return $this->redirect('/pencairan/' . $id);
        }
        Session::flash('success', 'Pencairan dibatalkan. Datanya tetap tersimpan sebagai riwayat; pencairan baru boleh disusun ulang.');
        return $this->redirect('/pencairan/' . $id);
    }
}
