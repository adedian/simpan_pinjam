<?php
declare(strict_types=1);

namespace App\Services;

final class Pagination
{
    public const PER_PAGE = 25;

    /**
     * @return array{page:int,pages:int,per_page:int,offset:int,total:int,from:int,to:int}
     */
    public static function make(int $total, int $requestedPage, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, $requestedPage));
        $offset  = ($page - 1) * $perPage;

        return [
            'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => $offset, 'total' => $total,
            'from' => $total === 0 ? 0 : $offset + 1, 'to' => min($total, $offset + $perPage),
        ];
    }

    /** Lolos-kan "%" dan "_" agar pencarian pengguna tidak menjadi wildcard. */
    public static function likeEscape(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
