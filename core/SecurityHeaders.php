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
        // Jangan umumkan versi PHP (membantu penyerang memilih celah yang dikenal).
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $response->headers += [
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
                . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
            // Isolasi lintas-asal: halaman tak bisa dibuka/dibaca jendela atau sumber daya situs lain.
            'Cross-Origin-Opener-Policy'   => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            // Halaman memuat data keuangan: jangan disimpan di cache browser/proxy.
            'Cache-Control'           => 'no-store, max-age=0',
        ];
        if ($request->isSecure()) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
    }
}
