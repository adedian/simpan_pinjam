<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public static function validate(Request $request): bool
    {
        $sent  = $request->post['_token'] ?? $request->header('X-CSRF-TOKEN') ?? '';
        $known = (string) ($_SESSION['_csrf'] ?? '');
        return $known !== '' && is_string($sent) && hash_equals($known, $sent);
    }
}
