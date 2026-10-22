<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Transaction;
use App\Services\Auth;
use App\Services\ExpenseService;
use App\Services\FormToken;
use App\Services\RuleViolation;

/**
 * Biaya operasional dibebankan ke Kas SHU. Hanya Superadmin (izin expense.manage, dipaksa di route); validasi tetap di /validasi.
 * Lihat ExpenseService untuk aturan.
 */
final class BiayaController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $rows = ExpenseService::listAll();
        $sum  = ['DISETUJUI' => 0, 'MENUNGGU_VALIDASI' => 0, 'DRAFT' => 0];
        foreach ($rows as $r) {
            if (isset($sum[$r['status']])) {
                $sum[$r['status']] += (int) $r['amount'];
            }
        }
        return $this->view($request, 'biaya/index', [
            'title' => 'Biaya',
            'rows'  => $rows,
            'sum'   => $sum,
            'me'    => (int) ($user['id'] ?? 0),
        ]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $months = Transaction::recordableMonths();
        return $this->form($request, null, [
            'category' => '', 'period_month_id' => (string) ($months[0]['id'] ?? ''), 'amount' => '', 'trx_date' => date('Y-m-d'), 'description' => '',
        ]);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        // Token formulir sekali pakai: kiriman ganda tidak membuat biaya kedua.
        if (!FormToken::consume($request->str('_form_id'))) {
            Session::flash('warning', 'Formulir ini sudah pernah dikirim, jadi tidak diproses lagi. Periksa daftar biaya sebelum mencatat ulang.');
            return $this->redirect('/biaya');
        }
        [$errors, $data] = ExpenseService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                ExpenseService::create($request, $user, $data, $submit);
                Session::flash('success', $submit ? 'Biaya dicatat dan diajukan ke validasi.' : 'Biaya disimpan sebagai draft. Ajukan ke validasi bila sudah benar.');
                return $this->redirect('/biaya');
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/biaya/baru', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $trx  = ExpenseService::find((int) $params['id']);
        if ($trx === null || (int) $trx['created_by'] !== (int) $user['id']) {
            $this->abort(404);
        }
        if ($trx['status'] !== 'DRAFT') {
            Session::flash('warning', 'Hanya draft biaya yang bisa diubah.');
            return $this->redirect('/biaya');
        }
        return $this->form($request, $trx, [
            'category' => (string) $trx['category'], 'period_month_id' => (string) $trx['period_month_id'], 'amount' => number_format((int) $trx['amount'], 0, ',', '.'),
            'trx_date' => (string) $trx['trx_date'], 'description' => (string) ($trx['description'] ?? ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = ExpenseService::find($id);
        if ($trx === null || (int) $trx['created_by'] !== (int) $user['id']) {
            $this->abort(404);
        }
        [$errors, $data] = ExpenseService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                ExpenseService::update($request, $user, $id, $data, $request->str('_version'), $submit);
                Session::flash('success', $submit ? 'Perubahan disimpan dan biaya diajukan ke validasi.' : 'Draft biaya disimpan.');
                return $this->redirect('/biaya');
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/biaya/' . $id . '/ubah', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        if (ExpenseService::find($id) === null) {
            $this->abort(404);
        }
        try {
            ExpenseService::submit($request, Auth::user(), $id, $request->str('_version'));
            Session::flash('success', 'Biaya diajukan ke validasi.');
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
        }
        return $this->redirect('/biaya');
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        if (ExpenseService::find($id) === null) {
            $this->abort(404);
        }
        try {
            ExpenseService::cancel($request, Auth::user(), $id, $request->str('note'), $request->str('_version'));
            Session::flash('success', 'Biaya dibatalkan. Nomor dokumen dan riwayatnya tetap tersimpan.');
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
        }
        return $this->redirect('/biaya');
    }

    /** @param array<string,mixed>|null $trx @param array<string,mixed> $defaults */
    private function form(Request $request, ?array $trx, array $defaults): Response
    {
        $old    = Session::get('_old', []);   // dibaca tanpa dihapus: view() menariknya untuk galat
        $values = $old !== [] ? $old : $defaults;
        $monthOptions = ['' => 'Pilih bulan'];
        foreach (Transaction::recordableMonths() as $mo) {
            $monthOptions[$mo['id']] = month_label((string) $mo['month_date']);
        }
        return $this->view($request, 'biaya/form', [
            'title'        => $trx === null ? 'Catat Biaya' : 'Ubah Draft ' . $trx['doc_no'],
            'trx'          => $trx,
            'values'       => $values,
            'monthOptions' => $monthOptions,
            'action'       => $trx === null ? url('/biaya') : url('/biaya/' . (int) $trx['id']),
            'version'      => $trx['updated_at'] ?? '',
            'formId'       => FormToken::issue(),
            'today'        => date('Y-m-d'),
        ]);
    }
}
