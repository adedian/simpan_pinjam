<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Gate;

/** Menyusun menu sidebar sesuai peran pengguna dari config/menu.php. */
final class Navigation
{
    /**
     * @param array{roles?:array<int,string>}|null $user
     * @return array<int,array{label:string,items:array<int,array{label:string,path:string,icon:string,phase:int,available:bool,active:bool,badge:?string}>}>
     */
    public static function forUser(?array $user, string $currentPath): array
    {
        $groups = [];
        foreach ((array) Config::get('menu', []) as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (!self::anyAllowed($user, $item['can'])) {
                    continue;
                }
                $items[] = [
                    'label'     => $item['label'],
                    'path'      => $item['path'],
                    'icon'      => $item['icon'],
                    'phase'     => $item['phase'],
                    'available' => (bool) $item['available'],
                    'active'    => self::isActive($item['path'], $currentPath),
                    'badge'     => $item['badge'] ?? null,
                ];
            }
            if ($items !== []) {
                $groups[] = ['label' => $group['label'], 'items' => $items];
            }
        }
        return $groups;
    }

    /** @param array<int,string> $permissions */
    private static function anyAllowed(?array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (Gate::allows($user, $permission)) {
                return true;
            }
        }
        return false;
    }

    private static function isActive(string $itemPath, string $currentPath): bool
    {
        return $itemPath === '/'
            ? $currentPath === '/'
            : $currentPath === $itemPath || str_starts_with($currentPath, $itemPath . '/');
    }

    /** @param array{roles?:array<int,string>}|null $user */
    public static function roleLabel(?array $user): string
    {
        $labels = (array) Config::get('permissions.labels', []);
        $names  = array_map(static fn (string $r): string => $labels[$r] ?? $r, (array) ($user['roles'] ?? []));
        return implode(' · ', $names);
    }
}
