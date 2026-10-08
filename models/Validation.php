<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Pagination;
use App\Services\ValidationService;

/**
 * Antrean dan riwayat validasi. Hanya untuk pemilik izin `transaction.validate` (dijaga di route); semua
 * validator melihat seluruh koperasi, jadi tidak ada pembatasan regu di sini.
 */
final class Validation
{
    /**
     * Transaksi yang menunggu validasi (terlama dulu), beserta penilaian kewenangan pengguna ini per baris.
     *
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int} $user
     * @param array{type?:string,q?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,pager:array<string,int>,byType:array<string,array{n:int,sum:int}>}
     */
    public static function queue(array $user, array $filters, int $page): array
    {
        $where  = ["t.status = 'MENUNGGU_VALIDASI'", 't.deleted_at IS NULL'];
        $params = [];
        $byType = self::summary();

        if (isset(Transaction::TYPE_LABELS[$filters['type'] ?? ''])) {
            $where[]  = 't.type = ?';
            $params[] = $filters['type'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . Pagination::likeEscape($q) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR t.doc_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM transactions t LEFT JOIN members m ON m.id = t.member_id LEFT JOIN users cu ON cu.id = t.created_by';

        $total = (int) Database::select("SELECT COUNT(*) AS n {$from} WHERE {$whereSql}", $params)[0]['n'];
        $pager = Pagination::make($total, $page);

        $rows = Database::select(
            "SELECT t.id, t.doc_no, t.type, t.amount, t.trx_date, t.member_id, t.team_id, t.created_by, t.reverses_id, t.status_changed_at,
                    m.member_no, m.name AS member_name, cu.name AS creator_name,
                    " . ValidationService::HEAD_RELATED_SQL . " AS head_related,
                    (SELECT tl.leader_member_id FROM team_leaders tl WHERE tl.id = t.team_id) AS leader_member_id
             {$from} WHERE {$whereSql}
             ORDER BY t.status_changed_at, t.id LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        );
        foreach ($rows as &$r) {
            $r['verdict'] = ValidationService::assess($user, $r, (bool) $r['head_related'], $r['leader_member_id'] === null ? null : (int) $r['leader_member_id']);
        }
        unset($r);
        return ['rows' => $rows, 'pager' => $pager, 'byType' => $byType];
    }

    /**
     * Jumlah dan nilai yang menunggu per jenis (tanpa filter).
     * @return array<string,array{n:int,sum:int}>
     */
    public static function summary(): array
    {
        $out = [];
        foreach (array_keys(Transaction::TYPE_LABELS) as $type) {
            $out[$type] = ['n' => 0, 'sum' => 0];
        }
        foreach (Database::select("SELECT type, COUNT(*) AS n, COALESCE(SUM(IF(reverses_id IS NULL, amount, -amount)), 0) AS total FROM transactions WHERE status = 'MENUNGGU_VALIDASI' AND deleted_at IS NULL GROUP BY type") as $r) {
            $out[(string) $r['type']] = ['n' => (int) $r['n'], 'sum' => (int) $r['total']];
        }
        return $out;
    }

    /**
     * Keputusan validasi yang diambil lewat aplikasi (setuju/tolak), terbaru dulu. Data impor Excel (tanpa
     * validator) sengaja tidak ikut.
     *
     * @param array{decision?:string,type?:string,q?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,pager:array<string,int>}
     */
    public static function decisions(array $filters, int $page): array
    {
        $where  = ["v.to_status IN ('DISETUJUI','DITOLAK')", 'v.actor_user_id IS NOT NULL'];
        $params = [];
        if (in_array($filters['decision'] ?? '', ['DISETUJUI', 'DITOLAK'], true)) {
            $where[]  = 'v.to_status = ?';
            $params[] = $filters['decision'];
        }
        if (isset(Transaction::TYPE_LABELS[$filters['type'] ?? ''])) {
            $where[]  = 't.type = ?';
            $params[] = $filters['type'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . Pagination::likeEscape($q) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR t.doc_no LIKE ? ESCAPE '\\\\' OR u.name LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like, $like);
        }
        $from = 'FROM transaction_validations v
                 JOIN transactions t ON t.id = v.transaction_id
                 JOIN users u ON u.id = v.actor_user_id
                 LEFT JOIN members m ON m.id = t.member_id
                 LEFT JOIN users cu ON cu.id = t.created_by';
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::select("SELECT COUNT(*) AS n {$from} WHERE {$whereSql}", $params)[0]['n'];
        $pager = Pagination::make($total, $page);
        $rows  = Database::select(
            "SELECT v.id, v.to_status, v.note, v.created_at, t.id AS transaction_id, t.doc_no, t.type, t.amount, t.reverses_id,
                    m.member_no, m.name AS member_name, u.name AS actor_name, cu.name AS creator_name
             {$from} WHERE {$whereSql}
             ORDER BY v.id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        );
        return ['rows' => $rows, 'pager' => $pager];
    }
}
