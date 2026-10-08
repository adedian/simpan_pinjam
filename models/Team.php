<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Team
{
    /**
     * Semua regu beserta ketua, jumlah anggota aktif, tabungan, dan sisa pinjaman anggotanya saat ini.
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Database::select(
            "SELECT tl.id, tl.name, tl.is_active, tl.leader_member_id, lm.name AS leader_name, lm.member_no AS leader_no,
                    COUNT(DISTINCT a.member_id) AS member_count,
                    COALESCE(SUM(s.savings_balance), 0) AS savings_total,
                    COALESCE(SUM(lb.outstanding), 0) AS outstanding_total
             FROM team_leaders tl
             JOIN members lm ON lm.id = tl.leader_member_id
             LEFT JOIN member_team_assignments a ON a.team_id = tl.id AND a.valid_to IS NULL
             LEFT JOIN v_member_savings s ON s.member_id = a.member_id
             LEFT JOIN (SELECT member_id, SUM(outstanding) AS outstanding FROM v_loan_balances GROUP BY member_id) lb ON lb.member_id = a.member_id
             GROUP BY tl.id, tl.name, tl.is_active, tl.leader_member_id, lm.name, lm.member_no
             ORDER BY tl.is_active DESC, tl.name"
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $rows = Database::select('SELECT id, name, leader_member_id, is_active, updated_at FROM team_leaders WHERE id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /** @return array<int,array{id:int,name:string}> regu aktif untuk dropdown */
    public static function activeOptions(): array
    {
        return Database::select('SELECT id, name FROM team_leaders WHERE is_active = 1 ORDER BY name');
    }

    /**
     * Calon ketua. Saat membuat regu baru: anggota aktif yang belum memimpin regu.
     * Saat mengubah regu: anggota yang saat ini berada di regu itu dan tidak memimpin regu lain.
     * @return array<int,array{id:int,name:string,member_no:string}>
     */
    public static function leaderCandidates(?int $teamId): array
    {
        if ($teamId === null) {
            return Database::select(
                "SELECT m.id, m.name, m.member_no FROM members m
                 WHERE m.status = 'AKTIF' AND m.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM team_leaders t WHERE t.leader_member_id = m.id)
                 ORDER BY m.name"
            );
        }
        return Database::select(
            "SELECT m.id, m.name, m.member_no FROM members m
             JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL AND a.team_id = ?
             WHERE m.status = 'AKTIF' AND m.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM team_leaders t WHERE t.leader_member_id = m.id AND t.id <> ?)
             ORDER BY m.name",
            [$teamId, $teamId]
        );
    }
}
