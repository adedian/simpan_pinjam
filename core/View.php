<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var array<string,mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'app'): string
    {
        $data    = array_merge(self::$shared, $data);
        $content = self::include('pages/' . $view, $data);

        if ($layout === null) {
            return $content;
        }
        return self::include('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function include(string $name, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#i', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Nama view tidak valid.');
        }
        $file = BASE_PATH . '/views/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View tidak ditemukan: ' . $name);
        }
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($file, $data);
    }
}
