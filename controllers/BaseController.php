<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth;
use App\Services\LiveFeed;
use App\Services\Navigation;

abstract class BaseController
{
    /**
     * Halaman baca-saja yang diperbarui otomatis saat pengguna lain mengubah data. Halaman formulir
     * (isian bisa hilang), simulasi, profil, dan pengaturan sengaja tidak masuk daftar ini.
     */
    private const LIVE_VIEWS = [
        'home', 'member', 'master/members', 'master/teams', 'master/users',
        'transaksi/savings', 'transaksi/loans', 'transaksi/payments', 'transaksi/dues', 'transaksi/history', 'transaksi/show',
        'validasi/queue', 'validasi/history',
    ];

    /**
     * Render halaman ke dalam layout. Data bersama (pengguna, menu, flash, galat formulir)
     * disuntikkan di sini agar setiap controller tidak mengulanginya.
     *
     * @param array<string,mixed> $data
     */
    protected function view(Request $request, string $view, array $data = [], string $layout = 'app', int $status = 200): Response
    {
        $user = array_key_exists('user', $data) ? $data['user'] : Auth::user();

        // Permintaan latar belakang (pembaruan langsung) hanya MENGINTIP pesan sekali-tampil; kalau
        // diambil, pengguna kehilangan pesan/kata sandi sementara yang belum sempat dilihat.
        $background = Session::isBackground();

        $data += [
            'user'        => $user,
            'nav'         => Navigation::forUser($user, $request->path),
            'roleLabel'   => Navigation::roleLabel($user),
            'flash'       => $background ? (array) Session::get('_flash', []) : Session::pullFlash(),
            'errors'      => $background ? (array) Session::get('_errors', []) : Session::pull('_errors', []),
            'old'         => $background ? (array) Session::get('_old', []) : Session::pull('_old', []),
            'secret'      => $background ? Session::get('_secret') : Session::pull('_secret'),   // data rahasia sekali tampil (kata sandi sementara)
            'path'        => $request->path,
            'badges'      => $layout === 'app' ? LiveFeed::badges($user) : [],
            // Penanda perubahan diambil SEBELUM isi halaman dirender: isi halaman paling tidak sama barunya.
            'liveVersion' => $layout === 'app' && $status === 200 && in_array($view, self::LIVE_VIEWS, true) ? LiveFeed::version() : null,
        ];
        return Response::html(View::render($view, $data, $layout), $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    /**
     * Kembali ke formulir dengan galat dan isian lama (Post/Redirect/Get).
     * Kolom kata sandi tidak pernah disimpan ke sesi.
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     */
    protected function backWithErrors(string $path, array $errors, array $input = []): Response
    {
        unset($input['_token'], $input['password'], $input['password_confirmation'], $input['current_password']);
        Session::set('_errors', $errors);
        Session::set('_old', $input);
        return Response::redirect($path);
    }

    protected function abort(int $status): never
    {
        throw new HttpException($status);
    }
}
