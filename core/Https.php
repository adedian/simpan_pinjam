<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Paksa HTTPS (Phase 16). Aktif hanya bila APP_FORCE_HTTPS=true DAN APP_URL berskema https://.
 * Tujuan pengalihan selalu diambil dari APP_URL (bukan dari header Host yang bisa dipalsukan).
 * Permintaan selain GET/HEAD tidak dialihkan (isinya sudah terkirim tanpa enkripsi): ditolak.
 */
final class Https
{
    /** Pengalihan/penolakan bila permintaan ini harus lewat HTTPS tetapi tidak; null bila boleh diteruskan. */
    public static function enforce(Request $request): ?Response
    {
        $url = (string) Config::get('app.url', '');
        if (!(bool) Config::get('app.force_https', false) || !str_starts_with($url, 'https://') || $request->isSecure()) {
            return null;
        }
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response('Gunakan HTTPS.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : '');
        return Response::redirect($origin . self::safeTarget((string) ($request->server['REQUEST_URI'] ?? '/')), 308);
    }

    /** Hanya path+query internal yang aman; selain itu ke "/". Mencegah pengalihan ke situs lain dan injeksi header. */
    public static function safeTarget(string $uri): string
    {
        if (preg_match('#^/(?!/)[^\s\\\\\x00-\x1f\x7f]*$#', $uri) !== 1) {
            return '/';
        }
        return $uri;
    }
}
