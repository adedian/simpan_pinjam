<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $idle = (int) Config::get('app.session.idle_timeout', 1800);
        $dir  = BASE_PATH . '/storage/sessions';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ($idle * 2));
        session_name((string) Config::get('app.session.name', 'adem_ayem_sid'));
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim(Url::basePath(), '/') . '/',
            'secure'   => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        if (isset($_SESSION['_last']) && ($now - (int) $_SESSION['_last']) > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
            self::flash('warning', 'Sesi berakhir karena tidak aktif. Silakan masuk kembali.');
        }
        // Permintaan latar belakang pembaruan langsung bukan aktivitas pengguna: tidak boleh memperpanjang sesi.
        if (!self::isBackground()) {
            $_SESSION['_last'] = $now;
        }
    }

    /** Permintaan otomatis dari halaman (pembaruan langsung), bukan tindakan pengguna. */
    public static function isBackground(): bool
    {
        return ($_SERVER['HTTP_X_LIVE'] ?? '') === '1';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Ganti ID sesi. Wajib dipanggil saat login/logout untuk mencegah session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /** Ambil lalu hapus nilai (untuk data sekali pakai seperti galat formulir). */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /** Hancurkan sesi sepenuhnya (logout): data, cookie, dan berkas sesi. */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }
}
