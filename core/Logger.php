<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function error(string $message, ?\Throwable $e = null): void
    {
        $detail = $e ? sprintf(' | %s: %s @ %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()) : '';
        self::write('ERROR', $message . $detail);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    private static function write(string $level, string $message): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, str_replace(["\r", "\n"], ' ', $message));
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
