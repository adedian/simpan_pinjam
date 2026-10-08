<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Header keamanan untuk setiap respons, termasuk halaman error.
 * CSP ketat: tidak ada script/style inline, semua aset dari origin sendiri.
 * Konsekuensinya untuk view: jangan menulis <script> inline atau atribut style="".
 */
final class SecurityHeaders
{
    public static function apply(Response $response, Request $request): void
    {
        $response->headers += [
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
                . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
            // Halaman memuat data keuangan: jangan disimpan di cache browser/proxy.
            'Cache-Control'           => 'no-store, max-age=0',
        ];
        if ($request->isSecure()) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
    }
}
