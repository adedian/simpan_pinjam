<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Helpers\Money;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\AuditLog;
use App\Services\Auth;
use App\Services\FormToken;
use App\Services\InstallmentService;
use App\Services\LoanService;
use App\Services\ReversalService;
use App\Services\RuleViolation;
use App\Services\SavingService;
use App\Services\SettingsService;
use App\Services\ValidationService;

final class TransactionController extends BaseController
{
    /** Segmen URL formulir ubah menurut jenis transaksi, dan layanan yang mengurusnya. */
    private const EDIT_SEGMENT = ['SIMPANAN' => 'simpanan', 'PENCAIRAN_PINJAMAN' => 'pinjaman', 'ANGSURAN' => 'angsuran'];
    private const SERVICE = ['SIMPANAN' => SavingService::class, 'PENCAIRAN_PINJAMAN' => LoanService::class, 'ANGSURAN' => InstallmentService::class];
    private const NOUN = ['SIMPANAN' => 'Simpanan', 'PENCAIRAN_PINJAMAN' => 'Pinjaman', 'ANGSURAN' => 'Pembayaran'];

    // ------------------------------------------------------------------ daftar

    /** Daftar simpanan dalam cakupan pengguna. @param array<string,string> $params */
    public function savings(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $filters = $this->filters($request, ['type' => 'SIMPANAN']);
        $result  = Transaction::search($user, $filters, (int) ($request->query['page'] ?? 1));

        return $this->view($request, 'transaksi/savings', [
            'title'     => 'Simpanan',
            'rows'      => $result['rows'],
            'pager'     => $result['pager'],
            'totals'    => $result['totals'],
            'filters'   => $filters,
            'months'    => Transaction::allMonths(),
            'kinds'     => SavingService::KIND_LABELS,
            'member'    => $this->filterMember($user, $filters),
            'canCreate' => $this->canCreate($user),
        ]);
    }

    /** Riwayat semua jenis transaksi dalam cakupan pengguna (hanya baca). @param array<string,string> $params */
    public function history(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $filters = $this->filters($request, []);
        $result  = Transaction::search($user, $filters, (int) ($request->query['page'] ?? 1));

        return $this->view($request, 'transaksi/history', [
            'title'   => 'Riwayat Transaksi',
            'rows'    => $result['rows'],
            'pager'   => $result['pager'],
            'totals'  => $result['totals'],
            'filters' => $filters,
            'months'  => Transaction::allMonths(),
            'types'   => Transaction::TYPE_LABELS,
            'member'  => $this->filterMember($user, $filters),
        ]);
    }

    /**
     * Detail transaksi. Di luar cakupan = 404 yang SAMA dengan transaksi yang tidak ada;
     * percobaannya dicatat di audit log.
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);

        return $this->view($request, 'transaksi/show', [
            'title'       => $trx['doc_no'],
            'trx'         => $trx,
            'validations' => Transaction::validations($id),
            'canAct'      => $this->isOwner($user, $trx),
            'kinds'       => SavingService::KIND_LABELS,
            'types'       => Transaction::TYPE_LABELS,
            'loanInfo'    => $trx['type'] === 'PENCAIRAN_PINJAMAN' ? Transaction::loanDetail($id) : null,
            'allocations' => $trx['type'] === 'ANGSURAN' ? Transaction::allocations($id) : null,
            'validation'  => $this->validationPanel($user, $trx),
            'reversal'    => ReversalService::offer($user, $trx),
            'editPath'    =>'/transaksi/' . (self::EDIT_SEGMENT[$trx['type']] ?? 'simpanan') . '/' . $id . '/ubah',
        ]);
    }

    /**
     * Panel validasi di halaman detail: hanya untuk pemilik izin validasi dan hanya selama menunggu validasi.
     * @param array<string,mixed>|null $user @param array<string,mixed> $trx
     * @return array{verdict:array<string,mixed>,cash:?int,noChecker:bool,impact:?array{cash_delta:int,savings_delta:int,member_savings:?int}}|null
     */
    private function validationPanel(?array $user, array $trx): ?array
    {
        if ($user === null || !Gate::allows($user, 'transaction.validate') || $trx['status'] !== 'MENUNGGU_VALIDASI') {
            return null;
        }
        $verdict  = ValidationService::assessFor($user, $trx);
        $reversal = $trx['reverses_id'] !== null;
        $impact   = null;
        if ($reversal) {
            $impact = ReversalService::impact((string) $trx['type'], (int) $trx['amount']) + ['member_savings' => null];
            if ($trx['member_id'] !== null) {
                $row = \App\Core\Database::select('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [(int) $trx['member_id']])[0] ?? null;
                $impact['member_savings'] = $row === null ? null : (int) $row['savings_balance'];
            }
        }
        return [
            'verdict'   => $verdict,
            'cash'      => $reversal || in_array($trx['type'], ['PENCAIRAN_PINJAMAN', 'PENARIKAN', 'BIAYA'], true) ? ValidationService::cash() : null,
            'noChecker' => $verdict['head_related'] && !ValidationService::hasActiveChecker(),
            'impact'    => $impact,
        ];
    }

    // ------------------------------------------------------------------ angsuran

    /** Daftar pembayaran angsuran dalam cakupan pengguna. @param array<string,string> $params */
    public function payments(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $filters = $this->filters($request, ['type' => 'ANGSURAN']);
        $result  = Transaction::search($user, $filters, (int) ($request->query['page'] ?? 1));

        return $this->view($request, 'transaksi/payments', [
            'title'     => 'Angsuran',
            'rows'      => $result['rows'],
            'pager'     => $result['pager'],
            'totals'    => $result['totals'],
            'filters'   => $filters,
            'months'    => Transaction::allMonths(),
            'member'    => $this->filterMember($user, $filters),
            'canCreate' => $this->canCreate($user),
        ]);
    }

    /** Tagihan: siapa yang masih punya sisa pinjaman, berapa yang sudah jatuh tempo, dan tunggakannya. @param array<string,string> $params */
    public function dues(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $rows = Transaction::dueByMember($user);
        return $this->view($request, 'transaksi/dues', [
            'title'     => 'Tagihan Angsuran',
            'rows'      => $rows,
            'canCreate' => $this->canCreate($user),
            'monthName' => month_label(date('Y-m-01')),
            'total'     => [
                'outstanding' => array_sum(array_map('intval', array_column($rows, 'outstanding'))),
                'due_now'     => array_sum(array_map('intval', array_column($rows, 'due_now'))),
                'overdue'     => array_sum(array_map('intval', array_column($rows, 'overdue'))),
            ],
        ]);
    }

    /** @param array<string,string> $params */
    public function createPayment(Request $request, array $params = []): Response
    {
        $this->requireRecorder(Auth::user());

        $months = Transaction::recordableMonths();
        $nominal = (int) ($request->query['nominal'] ?? 0);
        return $this->paymentForm($request, null, [
            'member_id' => (string) (int) ($request->query['anggota'] ?? 0) ?: '', 'period_month_id' => (string) ($months[0]['id'] ?? ''),
            'amount' => $nominal > 0 ? number_format($nominal, 0, ',', '.') : '', 'trx_date' => date('Y-m-d'), 'description' => '',
        ]);
    }

    /** @param array<string,string> $params */
    public function storePayment(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $this->requireRecorder($user);

        if (!FormToken::consume($request->str('_form_id'))) {
            Session::flash('warning', 'Formulir ini sudah pernah dikirim, jadi tidak diproses lagi. Periksa daftar angsuran sebelum mencatat ulang.');
            return $this->redirect('/transaksi/angsuran');
        }

        [$errors, $data] = InstallmentService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                $id = InstallmentService::create($request, $user, $data, $submit);
                Session::flash('success', $submit ? 'Pembayaran dicatat dan diajukan ke validasi.' : 'Pembayaran disimpan sebagai draft. Periksa pembagiannya ke cicilan, lalu ajukan bila sudah benar.');
                return $this->redirect('/transaksi/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/transaksi/angsuran/baru', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function editPayment(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);
        if ($trx['type'] !== 'ANGSURAN' || $trx['status'] !== 'DRAFT') {
            Session::flash('warning', 'Hanya draft angsuran yang bisa diubah.');
            return $this->redirect('/transaksi/' . $id);
        }
        return $this->paymentForm($request, $trx, [
            'member_id' => (string) $trx['member_id'], 'period_month_id' => (string) $trx['period_month_id'],
            'amount' => number_format((int) $trx['amount'], 0, ',', '.'), 'trx_date' => (string) $trx['trx_date'], 'description' => (string) ($trx['description'] ?? ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function updatePayment(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);

        [$errors, $data] = InstallmentService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                InstallmentService::update($request, $user, $id, $data, $request->str('_version'), $submit);
                Session::flash('success', $submit ? 'Perubahan disimpan dan pembayaran diajukan ke validasi.' : 'Draft pembayaran disimpan dan pembagiannya dihitung ulang.');
                return $this->redirect('/transaksi/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/transaksi/angsuran/' . $id . '/ubah', $errors, $request->post);
    }

    // ------------------------------------------------------------------ pinjaman

    /** Daftar pencairan pinjaman dalam cakupan pengguna. @param array<string,string> $params */
    public function loans(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $filters = $this->filters($request, ['type' => 'PENCAIRAN_PINJAMAN']);
        $result  = Transaction::search($user, $filters, (int) ($request->query['page'] ?? 1));

        return $this->view($request, 'transaksi/loans', [
            'title'     => 'Pinjaman',
            'rows'      => $result['rows'],
            'pager'     => $result['pager'],
            'totals'    => $result['totals'],
            'filters'   => $filters,
            'months'    => Transaction::allMonths(),
            'member'    => $this->filterMember($user, $filters),
            'canCreate' => $this->canCreate($user),
        ]);
    }

    /**
     * Kalkulator pinjaman: hanya menghitung dari pengaturan saat ini, tidak menyimpan apa pun.
     * @param array<string,string> $params
     */
    public function loanSimulation(Request $request, array $params = []): Response
    {
        $principalRaw = trim($request->queryStr('pokok'));
        $tenorRaw     = trim($request->queryStr('tenor'));
        $principal    = Money::parse($principalRaw);
        $tenor        = preg_match('/^\d{1,2}$/', $tenorRaw) === 1 ? (int) $tenorRaw : 0;
        $min     = (int) SettingsService::get('loan_tenor_min');
        $max     = (int) SettingsService::get('loan_tenor_max');
        $ceiling = (int) SettingsService::get('loan_max_amount');

        $errors = [];
        $quote  = null;
        if ($principalRaw !== '' || $tenorRaw !== '') {
            if ($principal === null || $principal <= 0 || $principal > LoanService::MAX_PRINCIPAL) {
                $errors['pokok'] = 'Isi jumlah pinjaman dengan angka bulat rupiah, mis. 5.000.000.';
            } elseif ($ceiling > 0 && $principal > $ceiling) {
                $errors['pokok'] = 'Melebihi plafon pinjaman ' . Money::format($ceiling) . '.';
            }
            if ($tenor < $min || $tenor > $max) {
                $errors['tenor'] = "Tenor harus antara {$min} dan {$max} bulan.";
            }
            if ($errors === []) {
                $quote = LoanService::quote((int) $principal, $tenor, LoanService::currentRateHundredths());
            }
        }
        return $this->view($request, 'transaksi/loan-simulation', [
            'title'     => 'Simulasi Pinjaman',
            'values'    => ['pokok' => $principalRaw, 'tenor' => $tenorRaw],
            'simErrors' => $errors,
            'quote'     => $quote,
            'rate'      => LoanService::currentRateHundredths() / 100,
            'tenorMin'  => $min,
            'tenorMax'  => $max,
            'ceiling'   => $ceiling,
            'principal' => $principal,
            'canCreate' => $this->canCreate(Auth::user()),
        ]);
    }

    /** @param array<string,string> $params */
    public function createLoan(Request $request, array $params = []): Response
    {
        $this->requireRecorder(Auth::user());

        $months = Transaction::recordableMonths();
        return $this->loanForm($request, null, [
            'member_id' => '', 'period_month_id' => (string) ($months[0]['id'] ?? ''), 'principal' => '', 'tenor' => '',
            'trx_date' => date('Y-m-d'), 'description' => '',
        ]);
    }

    /** @param array<string,string> $params */
    public function storeLoan(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $this->requireRecorder($user);

        if (!FormToken::consume($request->str('_form_id'))) {
            Session::flash('warning', 'Formulir ini sudah pernah dikirim, jadi tidak diproses lagi. Periksa daftar pinjaman sebelum mencatat ulang.');
            return $this->redirect('/transaksi/pinjaman');
        }

        [$errors, $data] = LoanService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                $id = LoanService::create($request, $user, $data, $submit);
                Session::flash('success', $submit ? 'Pinjaman dicatat dan diajukan ke validasi.' : 'Pinjaman disimpan sebagai draft. Periksa jadwal cicilannya, lalu ajukan bila sudah benar.');
                return $this->redirect('/transaksi/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/transaksi/pinjaman/baru', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function editLoan(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);
        if ($trx['type'] !== 'PENCAIRAN_PINJAMAN' || $trx['status'] !== 'DRAFT') {
            Session::flash('warning', 'Hanya draft pinjaman yang bisa diubah.');
            return $this->redirect('/transaksi/' . $id);
        }
        $info = Transaction::loanDetail($id);
        return $this->loanForm($request, $trx, [
            'member_id' => (string) $trx['member_id'], 'period_month_id' => (string) $trx['period_month_id'],
            'principal' => number_format((int) $trx['amount'], 0, ',', '.'), 'tenor' => (string) ($info['loan']['tenor_months'] ?? ''),
            'trx_date' => (string) $trx['trx_date'], 'description' => (string) ($trx['description'] ?? ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function updateLoan(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);

        [$errors, $data] = LoanService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                LoanService::update($request, $user, $id, $data, $request->str('_version'), $submit);
                Session::flash('success', $submit ? 'Perubahan disimpan dan pinjaman diajukan ke validasi.' : 'Draft pinjaman disimpan dan jadwal dihitung ulang.');
                return $this->redirect('/transaksi/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/transaksi/pinjaman/' . $id . '/ubah', $errors, $request->post);
    }

    // ------------------------------------------------------------------ catat / ubah

    /** @param array<string,string> $params */
    public function createSaving(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $this->requireRecorder($user);

        $months = Transaction::recordableMonths();
        return $this->form($request, null, [
            'member_id' => '', 'period_month_id' => (string) ($months[0]['id'] ?? ''), 'kind' => 'WAJIB',
            'amount' => '', 'trx_date' => date('Y-m-d'), 'description' => '',
        ]);
    }

    /** @param array<string,string> $params */
    public function storeSaving(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $this->requireRecorder($user);

        // Token formulir sekali pakai: kiriman ganda (klik dua kali, kirim ulang) tidak membuat transaksi kedua.
        if (!FormToken::consume($request->str('_form_id'))) {
            Session::flash('warning', 'Formulir ini sudah pernah dikirim, jadi tidak diproses lagi. Periksa daftar simpanan sebelum mencatat ulang.');
            return $this->redirect('/transaksi/simpanan');
        }

        [$errors, $data] = SavingService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                $id = SavingService::create($request, $user, $data, $submit);
                Session::flash('success', $submit ? 'Simpanan dicatat dan diajukan ke validasi.' : 'Simpanan disimpan sebagai draft. Ajukan ke validasi bila sudah benar.');
                return $this->redirect('/transaksi/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/transaksi/simpanan/baru', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function editSaving(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);
        if ($trx['type'] !== 'SIMPANAN' || $trx['status'] !== 'DRAFT') {
            Session::flash('warning', 'Hanya draft simpanan yang bisa diubah.');
            return $this->redirect('/transaksi/' . $id);
        }
        return $this->form($request, $trx, [
            'member_id' => (string) $trx['member_id'], 'period_month_id' => (string) $trx['period_month_id'], 'kind' => (string) $trx['kind'],
            'amount' => number_format((int) $trx['amount'], 0, ',', '.'), 'trx_date' => (string) $trx['trx_date'], 'description' => (string) ($trx['description'] ?? ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function updateSaving(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx  = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);

        [$errors, $data] = SavingService::parse($request->post);
        if ($errors === []) {
            try {
                $submit = $request->str('action', 'draft') === 'submit';
                SavingService::update($request, $user, $id, $data, $request->str('_version'), $submit);
                Session::flash('success', $submit ? 'Perubahan disimpan dan simpanan diajukan ke validasi.' : 'Draft simpanan disimpan.');
                return $this->redirect('/transaksi/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/transaksi/simpanan/' . $id . '/ubah', $errors, $request->post);
    }

    // ------------------------------------------------------------------ status

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);

        try {
            $service = self::SERVICE[$trx['type']];
            $service::submit($request, $user, $id, $request->str('_version'));
            Session::flash('success', self::NOUN[$trx['type']] . ' diajukan ke validasi.');
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
        }
        return $this->redirect('/transaksi/' . $id);
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $trx = $this->findOr404($request, $user, $id);
        $this->requireOwner($user, $trx);

        try {
            $service = self::SERVICE[$trx['type']];
            $service::cancel($request, $user, $id, $request->str('note'), $request->str('_version'));
            Session::flash('success', 'Transaksi dibatalkan. Riwayat dan nomor dokumennya tetap tersimpan.');
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
        }
        return $this->redirect('/transaksi/' . $id);
    }

    /**
     * Ajukan transaksi pembalik untuk transaksi yang sudah disetujui. Semua aturan ada di ReversalService;
     * di sini hanya cakupan data (di luar cakupan = 404 yang sama dengan tidak ada) dan pengalihan.
     * @param array<string,string> $params
     */
    public function reverse(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];
        $this->findOr404($request, $user, $id);
        $this->requireRecorder($user);

        try {
            $newId = ReversalService::request($request, $user, $id, $request->str('note'));
            Session::flash('success', 'Koreksi diajukan ke validasi. Transaksi asal tetap berlaku sampai pembalik ini disetujui.');
            return $this->redirect('/transaksi/' . $newId);
        } catch (RuleViolation $e) {
            Session::flash('danger', implode(' ', $e->errors));
            return $this->redirect('/transaksi/' . $id . '#koreksi');
        }
    }

    // ------------------------------------------------------------------ bantu

    /**
     * @param array<string,mixed>|null $trx null = formulir baru
     * @param array<string,mixed> $defaults nilai awal bila belum ada isian lama
     */
    private function form(Request $request, ?array $trx, array $defaults): Response
    {
        $user   = Auth::user();
        $old    = Session::get('_old', []);   // dibaca tanpa dihapus: view() menariknya untuk galat
        $values = $old !== [] ? $old : $defaults;

        $memberOptions = ['' => 'Pilih anggota'];
        foreach (Transaction::teamMembers((int) $user['team_id']) as $m) {
            $memberOptions[$m['id']] = $m['member_no'] . ' · ' . $m['name'];
        }
        $monthOptions = ['' => 'Pilih bulan'];
        foreach (Transaction::recordableMonths() as $mo) {
            $monthOptions[$mo['id']] = month_label((string) $mo['month_date']);
        }

        return $this->view($request, 'transaksi/saving-form', [
            'title'         => $trx === null ? 'Catat Simpanan' : 'Ubah Draft ' . $trx['doc_no'],
            'trx'           => $trx,
            'values'        => $values,
            'memberOptions' => $memberOptions,
            'monthOptions'  => $monthOptions,
            'kinds'         => SavingService::KINDS,
            'action'        => $trx === null ? url('/transaksi/simpanan') : url('/transaksi/simpanan/' . (int) $trx['id']),
            'version'       => $trx['updated_at'] ?? '',
            'formId'        => FormToken::issue(),
            'today'         => date('Y-m-d'),
        ]);
    }

    /**
     * @param array<string,mixed>|null $trx null = formulir baru
     * @param array<string,mixed> $defaults
     */
    private function loanForm(Request $request, ?array $trx, array $defaults): Response
    {
        $user   = Auth::user();
        $old    = Session::get('_old', []);
        $values = $old !== [] ? $old : $defaults;

        $memberOptions = ['' => 'Pilih anggota'];
        foreach (Transaction::teamMembers((int) $user['team_id']) as $m) {
            $memberOptions[$m['id']] = $m['member_no'] . ' · ' . $m['name'];
        }
        $monthOptions = ['' => 'Pilih bulan'];
        foreach (Transaction::recordableMonths() as $mo) {
            $monthOptions[$mo['id']] = month_label((string) $mo['month_date']);
        }

        return $this->view($request, 'transaksi/loan-form', [
            'title'         => $trx === null ? 'Catat Pinjaman' : 'Ubah Draft ' . $trx['doc_no'],
            'trx'           => $trx,
            'values'        => $values,
            'memberOptions' => $memberOptions,
            'monthOptions'  => $monthOptions,
            'action'        => $trx === null ? url('/transaksi/pinjaman') : url('/transaksi/pinjaman/' . (int) $trx['id']),
            'version'       => $trx['updated_at'] ?? '',
            'formId'        => FormToken::issue(),
            'today'         => date('Y-m-d'),
            'rate'          => LoanService::currentRateHundredths() / 100,
            'tenorMin'      => (int) SettingsService::get('loan_tenor_min'),
            'tenorMax'      => (int) SettingsService::get('loan_tenor_max'),
            'ceiling'       => (int) SettingsService::get('loan_max_amount'),
        ]);
    }

    /**
     * @param array<string,mixed>|null $trx null = formulir baru
     * @param array<string,mixed> $defaults
     */
    private function paymentForm(Request $request, ?array $trx, array $defaults): Response
    {
        $user   = Auth::user();
        $old    = Session::get('_old', []);
        $values = $old !== [] ? $old : $defaults;

        $memberOptions = ['' => 'Pilih anggota'];
        foreach (Transaction::teamMembers((int) $user['team_id']) as $m) {
            $memberOptions[$m['id']] = $m['member_no'] . ' · ' . $m['name'];
        }
        $monthOptions = ['' => 'Pilih bulan'];
        foreach (Transaction::recordableMonths() as $mo) {
            $monthOptions[$mo['id']] = month_label((string) $mo['month_date']);
        }

        return $this->view($request, 'transaksi/payment-form', [
            'title'         => $trx === null ? 'Catat Angsuran' : 'Ubah Draft ' . $trx['doc_no'],
            'trx'           => $trx,
            'values'        => $values,
            'memberOptions' => $memberOptions,
            'monthOptions'  => $monthOptions,
            'action'        => $trx === null ? url('/transaksi/angsuran') : url('/transaksi/angsuran/' . (int) $trx['id']),
            'version'       => $trx['updated_at'] ?? '',
            'formId'        => FormToken::issue(),
            'today'         => date('Y-m-d'),
        ]);
    }

    /**
     * @param array<string,mixed> $fixed
     * @return array{type:string,status:string,month:int,member:int,q:string,kind:string}
     */
    private function filters(Request $request, array $fixed): array
    {
        return [
            'type'   => (string) ($fixed['type'] ?? $request->query['type'] ?? ''),
            'status' => $request->queryStr('status'),
            'month'  => (int) ($request->query['bulan'] ?? 0),
            'member' => (int) ($request->query['anggota'] ?? 0),
            'q'      => clean_text($request->query['q'] ?? ''),
            'kind'   => $request->queryStr('jenis'),
        ];
    }

    /**
     * Anggota yang sedang disaring (untuk label di halaman), hanya bila dalam cakupan pengguna.
     * @param array<string,mixed> $user @param array<string,mixed> $filters
     * @return array<string,mixed>|null
     */
    private function filterMember(array $user, array $filters): ?array
    {
        return $filters['member'] > 0 ? Member::findScoped($user, (int) $filters['member']) : null;
    }

    /** @param array<string,mixed>|null $user */
    private function canCreate(?array $user): bool
    {
        return $user !== null && Gate::allows($user, 'transaction.create') && ($user['team_id'] ?? null) !== null;
    }

    /** @param array<string,mixed>|null $user */
    private function requireRecorder(?array $user): void
    {
        if (!$this->canCreate($user)) {
            $this->abort(403);
        }
    }

    /**
     * @param array<string,mixed>|null $user @param array<string,mixed> $trx
     */
    private function isOwner(?array $user, array $trx): bool
    {
        return $user !== null && Gate::allows($user, 'transaction.create')
            && isset(self::SERVICE[$trx['type']]) && (int) $trx['created_by'] === (int) $user['id'];
    }

    /** @param array<string,mixed>|null $user @param array<string,mixed> $trx */
    private function requireOwner(?array $user, array $trx): void
    {
        if (!$this->isOwner($user, $trx)) {
            $this->abort(403);
        }
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    private function findOr404(Request $request, ?array $user, int $id): array
    {
        $trx = Transaction::findScoped($user, $id);
        if ($trx === null) {
            if (Transaction::exists($id)) {
                AuditLog::record($request, $user, 'ACCESS_DENIED_SCOPE', 'transaction', $id, null, null, ['path' => $request->path]);
            }
            $this->abort(404);
        }
        return $trx;
    }
}
