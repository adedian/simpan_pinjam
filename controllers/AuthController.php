<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\Auth;

final class AuthController extends BaseController
{
    /** @param array<string,string> $params */
    public function showLogin(Request $request, array $params = []): Response
    {
        return $this->view($request, 'login', ['title' => 'Masuk'], 'auth');
    }

    /** @param array<string,string> $params */
    public function login(Request $request, array $params = []): Response
    {
        $username = $request->str('username');
        $password = $request->str('password');

        $errors = Validator::validate(
            ['username' => $username, 'password' => $password],
            ['username' => 'required|max:50', 'password' => 'required|maxbytes:200'],
            ['username' => 'Nama pengguna', 'password' => 'Kata sandi'],
        );
        if ($errors !== []) {
            return $this->backWithErrors('/login', $errors, ['username' => $username]);
        }

        $result = Auth::attempt($request, $username, $password);
        if (!$result['ok']) {
            // Pesan sama untuk semua kegagalan (tidak membocorkan keberadaan akun).
            return $this->backWithErrors('/login', ['form' => (string) $result['error']], ['username' => $username]);
        }

        $user = Auth::user();
        if ($user !== null && $user['must_change_password']) {
            Session::forget('intended');
            return $this->redirect('/profil/password');
        }
        return $this->redirect(Auth::safeRedirect(Session::pull('intended')));
    }

    /** @param array<string,string> $params */
    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout($request);
        Session::start($request); // sesi baru yang bersih untuk pesan di halaman login
        Session::flash('success', 'Anda telah keluar.');
        return $this->redirect('/login');
    }
}
