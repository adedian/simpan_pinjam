<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Pendukung PWA: manifest dan halaman offline. Keduanya PUBLIK dan tidak berisi data apa pun (tanpa pengguna,
 * tanpa saldo), karena browser dan service worker mengambilnya tanpa login. Sengaja TIDAK memakai
 * BaseController::view(): ia mengambil pesan sekali-tampil (flash) sesi, padahal service worker mengambil
 * halaman offline di latar belakang dan akan "memakan" pesan milik pengguna.
 */
final class PwaController
{
    private const THEME      = '#115e84';   // --brand-700
    private const BACKGROUND = '#e5f0f2';   // --bg

    /** @param array<string,string> $params */
    public function manifest(Request $request, array $params = []): Response
    {
        $manifest = [
            'id'               => url('/'),
            'name'             => (string) config('app.name'),
            'short_name'       => (string) config('app.short'),
            'description'      => 'Catatan simpanan, pinjaman, dan angsuran koperasi PKK ("' . config('app.tagline') . '").',
            'lang'             => 'id',
            'dir'              => 'ltr',
            'start_url'        => url('/'),
            'scope'            => url('/'),
            'display'          => 'standalone',
            'background_color' => self::BACKGROUND,
            'theme_color'      => self::THEME,
            'categories'       => ['finance', 'business'],
            'icons'            => [
                ['src' => asset('img/pwa-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('img/pwa-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('img/pwa-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return Response::json($manifest)
            ->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** Halaman yang ditampilkan service worker saat perangkat tidak punya koneksi. */
    public function offline(Request $request, array $params = []): Response
    {
        $html = View::render('offline', [
            'title' => 'Tidak ada koneksi',
            'flash' => [],
            'user'  => null,
        ], 'guest');
        return Response::html($html);
    }
}
