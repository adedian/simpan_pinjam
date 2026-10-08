<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Pagination;
use App\Services\Scope;

final class Member
{
    /**
     * Detail satu anggota, HANYA bila berada dalam cakupan data pengguna.
     * Anggota di luar cakupan dan anggota yang tidak ada menghasilkan hasil yang sama (null),
     * sehingga keberadaan data tidak bocor.
     *
     * @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array<string,mixed>|null
     */
    public static function findScoped(?array $user, int $id): ?array
    {
        [$where, $params] = Scope::memberCondition($user, 'm.id');
        $rows = Database::select(
            "SELECT m.id, m.member_no, m.name, m.address_block, m.active_from, m.status, m.is_manager, m.reserve_exempt, m.notes, m.updated_at,
                    a.team_id, tl.name AS team_name,
                    COALESCE(s.savings_balance, 0) AS savings_balance
             FROM members m
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id
             LEFT JOIN v_member_savings s ON s.member_id = m.id
             WHERE m.id = ? AND m.deleted_at IS NULL AND ({$where})",
            array_merge([$id], $params)
        );
        return $rows[0] ?? null;
    }

    /** Ada atau tidak (tanpa pembatasan cakupan). Hanya untuk mencatat percobaan akses di luar cakupan. */
    public static function exists(int $id): bool
    {
        return Database::select('SELECT 1 FROM members WHERE id = ? AND deleted_at IS NULL', [$id]) !== [];
    }

    /**
     * Pinjaman efektif milik anggota. Pemanggil WAJIB sudah memastikan anggota ada dalam cakupan.
     * @return array<int,array<string,mixed>>
     */
    public static function loans(int $memberId): array
    {
        return Database::select(
            'SELECT loan_id, transaction_id, loan_no, principal, total_due, paid, outstanding, loan_status
             FROM v_loan_balances WHERE member_id = ? ORDER BY loan_id',
            [$memberId]
        );
    }

    /**
     * Riwayat keanggotaan regu (terbaru dulu).
     * @return array<int,array<string,mixed>>
     */
    public static function teamHistory(int $memberId): array
    {
        return Database::select(
            'SELECT a.valid_from, a.valid_to, tl.name AS team_name
             FROM member_team_assignments a JOIN team_leaders tl ON tl.id = a.team_id
             WHERE a.member_id = ? ORDER BY a.valid_from DESC, a.id DESC',
            [$memberId]
        );
    }

    /**
     * Daftar anggota dalam cakupan pengguna, dengan pencarian dan filter.
     *
     * @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @param array{q?:string,team?:int,status?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,pager:array<string,int>}
     */
    public static function search(?array $user, array $filters, int $page): array
    {
        [$scopeSql, $params] = Scope::memberCondition($user, 'm.id');
        $where = ['m.deleted_at IS NULL', "({$scopeSql})"];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . Pagination::likeEscape($q) . '%';
            $where[] = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR m.address_block LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['team'])) {
            $where[] = 'a.team_id = ?';
            $params[] = (int) $filters['team'];
        }
        if (in_array($filters['status'] ?? '', ['AKTIF', 'NONAKTIF'], true)) {
            $where[] = 'm.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        $from = "FROM members m
                 LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
                 LEFT JOIN team_leaders tl ON tl.id = a.team_id
                 LEFT JOIN v_member_savings s ON s.member_id = m.id
                 LEFT JOIN (SELECT member_id, SUM(outstanding) AS outstanding FROM v_loan_balances GROUP BY member_id) lb ON lb.member_id = m.id
                 WHERE {$whereSql}";

        $total = (int) Database::select("SELECT COUNT(*) AS n {$from}", $params)[0]['n'];
        $pager = Pagination::make($total, $page);

        // LIMIT/OFFSET adalah bilangan bulat hasil perhitungan sendiri (bukan input pengguna).
        $rows = Database::select(
            "SELECT m.id, m.member_no, m.name, m.address_block, m.status, m.is_manager, m.reserve_exempt,
                    a.team_id, tl.name AS team_name,
                    COALESCE(s.savings_balance, 0) AS savings_balance, COALESCE(lb.outstanding, 0) AS outstanding
             {$from} ORDER BY m.member_no LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        );
        return ['rows' => $rows, 'pager' => $pager];
    }
}
