<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Menolak semua request pengubah-data (POST/PUT/PATCH/DELETE) tanpa token CSRF yang valid. */
final class CsrfMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !Csrf::validate($request)) {
            throw new HttpException(419);
        }
        return $next($request);
    }
}
