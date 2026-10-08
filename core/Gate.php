<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pemeriksaan izin berbasis peran. Ini lapisan "boleh melakukan apa";
 * pembatasan "data milik siapa" (scope regu / diri sendiri) diterapkan di query model.
 */
final class Gate
{
    /** @param array{roles?:array<int,string>}|null $user */
    public static function allows(?array $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }
        $map = (array) Config::get('permissions.roles', []);
        foreach ((array) ($user['roles'] ?? []) as $role) {
            if (in_array($permission, $map[$role] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array{roles?:array<int,string>}|null $user */
    public static function authorize(?array $user, string $permission): void
    {
        if (!self::allows($user, $permission)) {
            throw new HttpException(403);
        }
    }
}
