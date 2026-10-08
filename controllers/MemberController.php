<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Member;
use App\Models\Team;
use App\Services\AuditLog;
use App\Services\Auth;
use App\Services\MemberService;
use App\Services\RuleViolation;
use App\Services\Scope;

final class MemberController extends BaseController
{
    /**
     * Daftar anggota dalam cakupan pengguna (Head/Pemeriksa: semua, Ketua Regu: regunya).
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $filters = [
            'q'      => clean_text($request->query['q'] ?? ''),
            'team'   => (int) ($request->query['team'] ?? 0),
            'status' => (string) ($request->query['status'] ?? ''),
        ];
        $result = Member::search($user, $filters, (int) ($request->query['page'] ?? 1));

        // Pilihan filter regu ikut dibatasi cakupan: ketua regu tidak boleh melihat nama regu lain.
        $teams = Team::activeOptions();
        if (Scope::level($user) !== Scope::ALL) {
            $teams = array_values(array_filter($teams, static fn (array $t): bool => (int) $t['id'] === (int) ($user['team_id'] ?? 0)));
        }

        return $this->view($request, 'master/members', [
            'title'     => 'Data Anggota',
            'rows'      => $result['rows'],
            'pager'     => $result['pager'],
            'filters'   => $filters,
            'teams'     => $teams,
            'canManage' => Gate::allows($user, 'member.manage'),
        ]);
    }

    /**
     * Detail anggota (hanya baca). Anggota di luar cakupan pengguna menghasilkan 404 yang SAMA
     * dengan anggota yang tidak ada; percobaannya dicatat di audit log.
     *
     * @param array<string,string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id   = (int) $params['id'];

        $member = Member::findScoped($user, $id);
        if ($member === null) {
            if (Member::exists($id)) {
                AuditLog::record($request, $user, 'ACCESS_DENIED_SCOPE', 'member', $id, null, null, ['path' => $request->path]);
            }
            $this->abort(404);
        }

        return $this->view($request, 'member', [
            'title'     => $member['name'],
            'member'    => $member,
            'loans'     => Member::loans($id),
            'history'   => Member::teamHistory($id),
            'canManage' => Gate::allows($user, 'member.manage'),
            'canTransactions' => Gate::allows($user, 'transaction.view.all') || Gate::allows($user, 'transaction.view.team') || Gate::allows($user, 'transaction.view.self'),
        ]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        return $this->form($request, null, ['status' => 'AKTIF', 'active_from' => date('Y-m'), 'team_id' => '']);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        [$errors, $data] = MemberService::parse($request->post);
        if ($errors === []) {
            try {
                $id = MemberService::create($request, Auth::user(), $data);
                Session::flash('success', 'Anggota berhasil ditambahkan.');
                return $this->redirect('/anggota/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors('/master/anggota/baru', $errors, $request->post);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $member = Member::findScoped(Auth::user(), (int) $params['id']);
        if ($member === null) {
            $this->abort(404);
        }
        return $this->form($request, $member, [
            'name' => $member['name'], 'address_block' => $member['address_block'] ?? '', 'active_from' => substr((string) $member['active_from'], 0, 7),
            'status' => $member['status'], 'is_manager' => (int) $member['is_manager'], 'reserve_exempt' => (int) $member['reserve_exempt'],
            'notes' => $member['notes'] ?? '', 'team_id' => (string) ($member['team_id'] ?? ''),
        ]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $id   = (int) $params['id'];
        $back = '/master/anggota/' . $id . '/ubah';
        if (Member::findScoped(Auth::user(), $id) === null) {
            $this->abort(404);
        }

        [$errors, $data] = MemberService::parse($request->post);
        if ($errors === []) {
            try {
                MemberService::update($request, Auth::user(), $id, $data, (string) $request->input('_version', ''));
                Session::flash('success', 'Data anggota disimpan.');
                return $this->redirect('/anggota/' . $id);
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        return $this->backWithErrors($back, $errors, $request->post);
    }

    /**
     * @param array<string,mixed>|null $member
     * @param array<string,mixed> $defaults nilai awal bila belum ada isian lama
     */
    private function form(Request $request, ?array $member, array $defaults): Response
    {
        $old = $_SESSION['_old'] ?? [];   // dibaca (tanpa dihapus) agar view() masih bisa menariknya untuk galat
        $values = $old !== [] ? $old : $defaults;

        return $this->view($request, 'master/member-form', [
            'title'   => $member === null ? 'Tambah Anggota' : 'Ubah Anggota',
            'member'  => $member,
            'values'  => $values,
            'teams'   => Team::activeOptions(),
            'action'  => $member === null ? url('/master/anggota') : url('/master/anggota/' . (int) $member['id']),
            'version' => $member['updated_at'] ?? '',
        ]);
    }
}
