<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/** Halaman khusus tamu (login). Pengguna yang sudah masuk dikembalikan ke beranda. */
final class GuestMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return Auth::user() !== null ? Response::redirect('/') : $next($request);
    }
}
