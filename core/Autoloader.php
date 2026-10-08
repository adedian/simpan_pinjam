<?php
declare(strict_types=1);

namespace App\Core;

final class Autoloader
{
    /** @param array<string,string> $prefixes namespace prefix => base directory */
    public static function register(array $prefixes): void
    {
        spl_autoload_register(static function (string $class) use ($prefixes): void {
            foreach ($prefixes as $prefix => $dir) {
                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    continue;
                }
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        });
    }
}
