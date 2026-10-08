<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Transaction;
use App\Models\Validation;
use App\Services\AuditLog;
use App\Services\Auth;
use App\Services\RuleViolation;
use App\Services\ValidationService;

/**
 * Validasi transaksi. Semua route dilindungi `can:transaction.validate`; kewenangan per transaksi
 * (bukan pembuat, bukan atas nama sendiri, transaksi Head hanya Pemeriksa) ditegakkan di ValidationService.
 */
final class ValidationController extends BaseController
{
    /** Antrean transaksi yang menunggu validasi. @param array<string,string> $params */
    public function queue(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $filters = ['type' => $request->queryStr('jenis'), 'q' => clean_text($request->query['q'] ?? '')];
        $result  = Validation::queue($user, $filters, (int) ($request->query['page'] ?? 1));

        return $this->view($request, 'validasi/queue', [
            'title'      => 'Menunggu Validasi',
            'rows'       => $result['rows'],
            'pager'      => $result['pager'],
            'byType'     => $result['byType'],
            'filters'    => $filters,
            'types'      => Transaction::TYPE_LABELS,
            'noChecker'  => !ValidationService::hasActiveChecker(),
            'cash'       => ValidationService::cash(),
        ]);
    }

    /** Riwayat keputusan validasi. @param array<string,string> $params */
    public function history(Request $request, array $params = []): Response
    {
        $filters = [
            'decision' => $request->queryStr('keputusan'),
            'type'     => $request->queryStr('jenis'),
            'q'        => clean_text($request->query['q'] ?? ''),
        ];
        $result = Validation::decisions($filters, (int) ($request->query['page'] ?? 1));

        return $this->view($request, 'validasi/history', [
            'title'   => 'Riwayat Validasi',
            'rows'    => $result['rows'],
            'pager'   => $result['pager'],
            'filters' => $filters,
            'types'   => Transaction::TYPE_LABELS,
        ]);
    }

    /** @param array<string,string> $params */
    public function approve(Request $request, array $params = []): Response
    {
        return $this->decide($request, (int) $params['id'], true);
    }

    /** @param array<string,string> $params */
    public function reject(Request $request, array $params = []): Response
    {
        return $this->decide($request, (int) $params['id'], false);
    }

    private function decide(Request $request, int $id, bool $approve): Response
    {
        $user = Auth::user();
        $trx  = Transaction::findScoped($user, $id);   // validator melihat semua; tetap lewat cakupan agar 404 seragam
        if ($trx === null) {
            if (Transaction::exists($id)) {
                AuditLog::record($request, $user, 'ACCESS_DENIED_SCOPE', 'transaction', $id, null, null, ['path' => $request->path]);
            }
            $this->abort(404);
        }

        try {
            $version = $request->str('_version');
            $note    = $request->str('note');
            if ($approve) {
                ValidationService::approve($request, $user, $id, $version, $note);
                Session::flash('success', 'Transaksi ' . $trx['doc_no'] . ' disetujui dan kini dihitung dalam saldo.');
            } else {
                ValidationService::reject($request, $user, $id, $version, $note);
                Session::flash('success', 'Transaksi ' . $trx['doc_no'] . ' ditolak. Pembuatnya perlu mencatat ulang bila perlu.');
            }
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
            return $this->redirect('/transaksi/' . $id);
        }
        return $this->redirect('/validasi');
    }
}
