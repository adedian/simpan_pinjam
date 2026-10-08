<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    /** @var array<string,mixed> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        self::parse((string) file_get_contents($file));
    }

    public static function parse(string $content): void
    {
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\"'");
            self::$vars[$key] = match (strtolower($value)) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => $value,
            };
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::$vars) ? self::$vars[$key] : $default;
    }
}
