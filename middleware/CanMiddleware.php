<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Gate;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\AuditLog;

/**
 * Pemakaian di route: 'can:transaction.validate' atau salah satu dari beberapa
 * 'can:member.view.all|member.view.team|member.view.self'.
 * Selalu dicek di server, bukan hanya disembunyikan di menu. Penolakan dicatat di audit log.
 */
final class CanMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();
        foreach (array_filter(explode('|', (string) $this->argument)) as $permission) {
            if (Gate::allows($user, $permission)) {
                return $next($request);
            }
        }
        if ($user !== null) {
            AuditLog::record($request, $user, 'ACCESS_DENIED', 'route', null, null, null, ['path' => $request->path, 'method' => $request->method, 'need' => $this->argument]);
        }
        throw new HttpException(403); // gagal-aman: tanpa argumen juga ditolak
    }
}
