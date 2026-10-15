<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;

/**
 * Wajib login. Untuk request AJAX/JSON dijawab 401 (bukan redirect) agar fetch() bisa menanganinya.
 * Mengganti kata sandi bersifat sukarela: tidak ada pengguna yang dipaksa mengganti sebelum memakai sistem.
 */
final class AuthMiddleware extends Middleware
{
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

        return $next($request);
    }
}
