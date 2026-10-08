<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Services\Auth;
use App\Services\UserService;

final class UserController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->view($request, 'master/users', [
            'title'  => 'Data Pengguna',
            'users'  => User::all(),
            'labels' => (array) Config::get('permissions.labels', []),
            'me'     => Auth::user()['id'],
        ]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        return $this->form($request, null, ['username' => '', 'name' => '', 'roles' => [], 'member_no' => '']);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $input = $this->read($request);
        $errors = $this->validateBasics($input, true);
        if ($errors === []) {
            try {
                $result = UserService::create($input['username'], $input['name'], $input['roles'], $input['member_no'] ?: null, null, $request, Auth::user());
                Session::set('_secret', ['username' => $input['username'], 'password' => $result['password'], 'action' => 'dibuat']);
                Session::flash('success', 'Akun berhasil dibuat.');
                return $this->redirect('/master/pengguna');
            } catch (\InvalidArgumentException $e) {
                $errors = ['_form' => $e->getMessage()];
            }
        }
        return $this->backWithErrors('/master/pengguna/baru', $errors, $input);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $u = User::findForAdmin((int) $params['id']);
        if ($u === null) {
            $this->abort(404);
        }
        return $this->form($request, $u, ['username' => $u['username'], 'name' => $u['name'], 'roles' => $u['roles'], 'member_no' => $u['member_no'] ?? '']);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        if (User::findForAdmin($id) === null) {
            $this->abort(404);
        }
        $input  = $this->read($request);
        $errors = $this->validateBasics($input, false);
        if ($errors === []) {
            try {
                UserService::update($request, Auth::user(), $id, $input['name'], $input['roles'], $input['member_no'] ?: null, $request->str('_version'));
                Session::flash('success', 'Data pengguna disimpan.');
                return $this->redirect('/master/pengguna');
            } catch (\InvalidArgumentException $e) {
                $errors = ['_form' => $e->getMessage()];
            }
        }
        return $this->backWithErrors('/master/pengguna/' . $id . '/ubah', $errors, $input);
    }

    /** @param array<string,string> $params */
    public function setStatus(Request $request, array $params = []): Response
    {
        return $this->act(fn () => UserService::setActive($request, Auth::user(), (int) $params['id'], $request->str('active') === '1', $request->str('_version')),
            'Status akun diperbarui.');
    }

    /** @param array<string,string> $params */
    public function resetPassword(Request $request, array $params = []): Response
    {
        $id = (int) $params['id'];
        $u  = User::findForAdmin($id);
        if ($u === null) {
            $this->abort(404);
        }
        try {
            $password = UserService::resetPassword($request, Auth::user(), $id, $request->str('_version'));
        } catch (\InvalidArgumentException $e) {
            Session::flash('danger', $e->getMessage());
            return $this->redirect('/master/pengguna');
        }
        Session::set('_secret', ['username' => $u['username'], 'password' => $password, 'action' => 'diatur ulang']);
        Session::flash('success', 'Kata sandi sementara baru dibuat. Semua sesi akun itu dikeluarkan.');
        return $this->redirect('/master/pengguna');
    }

    /** @param array<string,string> $params */
    public function unlock(Request $request, array $params = []): Response
    {
        return $this->act(fn () => UserService::unlock($request, Auth::user(), (int) $params['id']), 'Kunci akun dibuka.');
    }

    private function act(callable $action, string $okMessage): Response
    {
        try {
            $action();
            Session::flash('success', $okMessage);
        } catch (\InvalidArgumentException $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->redirect('/master/pengguna');
    }

    /** @return array{username:string,name:string,roles:array<int,string>,member_no:string} */
    private function read(Request $request): array
    {
        $roles = $request->post['roles'] ?? [];
        return [
            'username'  => strtolower(trim($request->str('username'))),
            'name'      => clean_text($request->input('name', '')),
            'roles'     => is_array($roles) ? array_values(array_filter(array_map('strval', $roles))) : [],
            'member_no' => trim($request->str('member_no')),
        ];
    }

    /**
     * @param array{username:string,name:string,roles:array<int,string>,member_no:string} $in
     * @return array<string,string>
     */
    private function validateBasics(array $in, bool $creating): array
    {
        $errors = [];
        if ($creating && preg_match('/^[a-z0-9][a-z0-9._-]{2,29}$/', $in['username']) !== 1) {
            $errors['username'] = 'Nama pengguna 3-30 karakter: huruf kecil, angka, titik, garis bawah atau strip.';
        }
        if ($in['name'] === '' || mb_strlen($in['name']) > 100) {
            $errors['name'] = 'Nama wajib diisi (maksimal 100 karakter).';
        }
        if ($in['roles'] === []) {
            $errors['roles'] = 'Pilih minimal satu peran.';
        }
        return $errors;
    }

    /**
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $defaults
     */
    private function form(Request $request, ?array $user, array $defaults): Response
    {
        $old = Session::get('_old', []);
        return $this->view($request, 'master/user-form', [
            'title'   => $user === null ? 'Tambah Pengguna' : 'Ubah Pengguna',
            'account' => $user,
            'values'  => $old !== [] ? $old : $defaults,
            'labels'  => (array) Config::get('permissions.labels', []),
            'members' => User::memberOptions($user['member_id'] ?? null),
            'action'  => $user === null ? url('/master/pengguna') : url('/master/pengguna/' . (int) $user['id']),
            'version' => $user['updated_at'] ?? '',
            'isSelf'  => $user !== null && (int) $user['id'] === Auth::user()['id'],
        ]);
    }
}
