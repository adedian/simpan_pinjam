<?php
declare(strict_types=1);

namespace App\Core;

use App\Controllers\ErrorController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CanMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;

/**
 * Siklus request:
 *   Request -> [middleware global] -> Router.match -> [middleware route] -> Controller -> Response
 * Semua error dikonversi menjadi Response di sini; tidak ada stack trace yang bocor ke pengguna produksi.
 */
final class App
{
    /** Middleware yang berlaku untuk SEMUA route, dalam urutan luar ke dalam. */
    private const GLOBAL_MIDDLEWARE = [CsrfMiddleware::class];

    /** Alias yang dipakai di config/routes.php. "can:izin" membawa argumen setelah titik dua. */
    private const ALIASES = [
        'auth'  => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'can'   => CanMiddleware::class,
    ];

    public function run(): void
    {
        $request = Request::capture();

        try {
            Session::start($request);
            $response = $this->handle($request);
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $e->status);
        } catch (\Throwable $e) {
            Logger::error('Unhandled exception', $e);
            $response = $this->errorResponse($request, 500, $e);
        }

        SecurityHeaders::apply($response, $request);
        $response->send();
    }

    private function handle(Request $request): Response
    {
        $router = new Router();
        (require BASE_PATH . '/config/routes.php')($router);

        $matched = $router->match($request->method, $request->path);

        $stack = array_merge(self::GLOBAL_MIDDLEWARE, array_map([$this, 'resolve'], $matched['middleware']));
        $core  = function (Request $request) use ($matched): Response {
            [$class, $method] = $matched['handler'];
            return $this->toResponse((new $class())->$method($request, $matched['params']));
        };

        $pipeline = array_reduce(
            array_reverse($stack),
            static function (callable $next, string $spec): callable {
                [$class, $arg] = array_pad(explode(':', $spec, 2), 2, null);
                $middleware = new $class($arg);
                return static fn (Request $r): Response => $middleware->handle($r, $next);
            },
            $core,
        );

        return $pipeline($request);
    }

    private function resolve(string $spec): string
    {
        [$alias, $arg] = array_pad(explode(':', $spec, 2), 2, null);
        if (!isset(self::ALIASES[$alias])) {
            throw new \LogicException('Middleware tidak dikenal: ' . $alias);
        }
        return self::ALIASES[$alias] . ($arg !== null ? ':' . $arg : '');
    }

    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_array($result)           => Response::json($result),
            default                     => Response::html((string) $result),
        };
    }

    private function errorResponse(Request $request, int $status, ?\Throwable $e = null): Response
    {
        $messages = [
            403 => 'Anda tidak memiliki akses ke halaman ini.',
            404 => 'Halaman yang Anda cari tidak ditemukan.',
            405 => 'Metode permintaan tidak diizinkan.',
            419 => 'Formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            500 => 'Terjadi kesalahan pada sistem. Kejadian ini sudah dicatat.',
        ];
        $status  = isset($messages[$status]) ? $status : 500;
        $message = $messages[$status];

        if ($request->expectsJson()) {
            return Response::json(['error' => $message], $status);
        }

        try {
            if (ErrorController::framedUser($status) !== null) {
                return (new ErrorController())->show($request, $status, $message);
            }
        } catch (\Throwable $inner) {
            Logger::error('Gagal merender halaman error dalam kerangka aplikasi', $inner);   // jatuh ke kartu tamu di bawah
        }

        try {
            $debug = $e !== null && (bool) Config::get('app.debug', false) ? (string) $e : null;
            $html  = View::render('error', ['status' => $status, 'message' => $message, 'debug' => $debug, 'title' => 'Error ' . $status], 'guest');
            return Response::html($html, $status);
        } catch (\Throwable $inner) {
            Logger::error('Gagal merender halaman error', $inner);
            return Response::html('<h1>Error ' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>', $status);
        }
    }
}
