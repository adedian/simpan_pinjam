<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Member;
use App\Services\Auth;
use App\Services\PasswordPolicy;

final class ProfileController extends BaseController
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $user   = Auth::user();
        $member = $user['member_id'] !== null ? Member::findScoped($user, (int) $user['member_id']) : null;
        return $this->view($request, 'profile', ['title' => 'Profil', 'member' => $member]);
    }

    /** @param array<string,string> $params */
    public function showPassword(Request $request, array $params = []): Response
    {
        $user     = Auth::user();
        $forced   = (bool) $user['must_change_password'];
        // Saat wajib ganti kata sandi, sembunyikan menu (semua tujuan lain dialihkan kembali ke sini).
        return $this->view($request, 'password', ['title' => 'Ganti Kata Sandi', 'forced' => $forced], $forced ? 'guest' : 'app');
    }

    /** @param array<string,string> $params */
    public function updatePassword(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new     = (string) $request->input('password', '');

        $errors = Validator::validate(
            ['current_password' => $current, 'password' => $new, 'password_confirmation' => (string) $request->input('password_confirmation', '')],
            [
                'current_password'      => 'required|maxbytes:200',
                'password'              => 'required|min:' . PasswordPolicy::MIN_LENGTH . '|maxbytes:' . PasswordPolicy::MAX_BYTES,
                'password_confirmation' => 'required|same:password',
            ],
            ['current_password' => 'Kata sandi saat ini', 'password' => 'Kata sandi baru', 'password_confirmation' => 'Konfirmasi kata sandi'],
        );
        if ($errors === []) {
            $errors = Auth::changePassword($request, $user, $current, $new);
        }
        if ($errors !== []) {
            return $this->backWithErrors('/profil/password', $errors);
        }

        Session::flash('success', 'Kata sandi berhasil diganti.');
        return $this->redirect('/');
    }
}
