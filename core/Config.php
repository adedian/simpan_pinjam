<?php
declare(strict_types=1);

namespace App\Core;

/** Akses konfigurasi bergaya "file.kunci.bersarang", dimuat dari /config/*.php secara malas. */
final class Config
{
    /** @var array<string,array<mixed>> */
    private static array $files = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file  = array_shift($parts);

        if (!isset(self::$files[$file])) {
            $path = BASE_PATH . '/config/' . $file . '.php';
            self::$files[$file] = is_file($path) ? (array) require $path : [];
        }

        $value = self::$files[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
