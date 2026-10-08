<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;

/**
 * Wajib login. Untuk request AJAX/JSON dijawab 401 (bukan redirect) agar fetch() bisa menanganinya.
 * Pengguna dengan kata sandi sementara hanya boleh membuka halaman ganti kata sandi dan keluar.
 */
final class AuthMiddleware extends Middleware
{
    private const ALLOWED_WHEN_MUST_CHANGE = ['/profil/password', '/logout'];

    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            if ($request->expectsJson()) {
                return Response::json(['error' => 'Sesi berakhir. Silakan masuk kembali.'], 401);
            }
            if ($request->method === 'GET') {
                Session::set('intended', $request->path); // hanya path internal; divalidasi lagi saat dipakai
            }
            return Response::redirect('/login');
        }

        if ($user['must_change_password'] && !in_array($request->path, self::ALLOWED_WHEN_MUST_CHANGE, true)) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Anda harus mengganti kata sandi sementara terlebih dahulu.'], 403)
                : Response::redirect('/profil/password');
        }
        return $next($request);
    }
}
