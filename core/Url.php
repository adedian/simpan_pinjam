<?php
declare(strict_types=1);

namespace App\Core;

final class Url
{
    private static ?string $base = null;

    /** Path dasar aplikasi, mis. "/simpan_pinjam" (kosong bila di root domain). */
    public static function basePath(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $configured = Config::get('app.base_path', 'auto');
        if (is_string($configured) && $configured !== 'auto') {
            return self::$base = rtrim($configured, '/');
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_ends_with($script, '/public/index.php')) {
            return self::$base = substr($script, 0, -strlen('/public/index.php'));
        }
        return self::$base = rtrim(dirname($script), '/\\');
    }

    public static function to(string $path = '/'): string
    {
        return self::basePath() . '/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        $file    = BASE_PATH . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? '?v=' . filemtime($file) : '';
        return self::to('assets/' . ltrim($path, '/')) . $version;
    }
}
