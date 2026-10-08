<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Pagination;
use App\Services\Scope;

/**
 * Pembacaan transaksi. SEMUA query dibatasi cakupan data pengguna (Scope) di SQL, bukan di tampilan:
 * Head/Pemeriksa melihat semua, Ketua Regu melihat transaksi anggota regunya (ditambah transaksi yang
 * ia buat sendiri bila anggotanya sudah pindah), Anggota hanya miliknya.
 */
final class Transaction
{
    /** @var array<string,string> */
    public const TYPE_LABELS = [
        'SIMPANAN'           => 'Simpanan',
        'PENARIKAN'          => 'Penarikan',
        'PENCAIRAN_PINJAMAN' => 'Pencairan pinjaman',
        'ANGSURAN'           => 'Angsuran',
        'BIAYA'              => 'Biaya',
    ];
    /** @var array<int,string> */
    public const STATUSES = ['DRAFT', 'MENUNGGU_VALIDASI', 'DISETUJUI', 'DITOLAK', 'DIBATALKAN'];

    /**
     * Potongan SQL cakupan untuk alias tabel transaksi.
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array{0:string,1:array<int,int>}
     */
    public static function scopeSql(?array $user, string $alias = 't'): array
    {
        [$sql, $params] = Scope::memberCondition($user, "{$alias}.member_id", 'transaction');
        if (Scope::level($user, 'transaction') === Scope::TEAM) {
            $sql      = "({$sql} OR {$alias}.created_by = ?)";
            $params[] = (int) ($user['id'] ?? 0);
        }
        return [$sql, $params];
    }

    /**
     * Detail satu transaksi, HANYA bila dalam cakupan pengguna. Di luar cakupan = tidak ada (null),
     * sama dengan transaksi yang memang tidak ada.
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array<string,mixed>|null
     */
    public static function findScoped(?array $user, int $id): ?array
    {
        [$scope, $params] = self::scopeSql($user);
        $rows = Database::select(
            "SELECT t.id, t.trx_no, t.doc_no, t.type, t.member_id, t.team_id, t.period_month_id, t.trx_date, t.amount, t.status,
                    t.reverses_id, t.description, t.source, t.created_by, t.status_changed_at, t.created_at, t.updated_at,
                    pm.month_date, s.kind,
                    m.member_no, m.name AS member_name, tl.name AS team_name, cu.name AS creator_name, cu.username AS creator_username,
                    rt.doc_no AS reverses_doc_no
             FROM transactions t
             JOIN period_months pm ON pm.id = t.period_month_id
             LEFT JOIN savings s ON s.transaction_id = t.id
             LEFT JOIN members m ON m.id = t.member_id
             LEFT JOIN team_leaders tl ON tl.id = t.team_id
             LEFT JOIN users cu ON cu.id = t.created_by
             LEFT JOIN transactions rt ON rt.id = t.reverses_id
             WHERE t.id = ? AND t.deleted_at IS NULL AND ({$scope})",
            array_merge([$id], $params)
        );
        return $rows[0] ?? null;
    }

    /** Ada atau tidak (tanpa cakupan). Hanya untuk mencatat percobaan akses di luar cakupan. */
    public static function exists(int $id): bool
    {
        return Database::select('SELECT 1 FROM transactions WHERE id = ? AND deleted_at IS NULL', [$id]) !== [];
    }

    /**
     * Riwayat perpindahan status (terlama dulu). Pemanggil WAJIB sudah memastikan transaksi dalam cakupan.
     * @return array<int,array<string,mixed>>
     */
    public static function validations(int $transactionId): array
    {
        return Database::select(
            'SELECT v.from_status, v.to_status, v.note, v.created_at, u.name AS actor_name
             FROM transaction_validations v LEFT JOIN users u ON u.id = v.actor_user_id
             WHERE v.transaction_id = ? ORDER BY v.id',
            [$transactionId]
        );
    }

    /**
     * Daftar transaksi dalam cakupan pengguna dengan filter dan paginasi.
     *
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @param array{type?:string,status?:string,month?:int,member?:int,q?:string,kind?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,pager:array<string,int>,totals:array<string,array{n:int,sum:int}>}
     */
    public static function search(?array $user, array $filters, int $page): array
    {
        [$scopeSql, $params] = self::scopeSql($user);
        $where = ['t.deleted_at IS NULL', "({$scopeSql})"];

        if (isset(self::TYPE_LABELS[$filters['type'] ?? ''])) {
            $where[]  = 't.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['month'])) {
            $where[]  = 't.period_month_id = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['member'])) {
            $where[]  = 't.member_id = ?';
            $params[] = (int) $filters['member'];
        }
        if (in_array($filters['kind'] ?? '', ['POKOK', 'WAJIB', 'SUKARELA', 'CAMPURAN'], true)) {
            $where[]  = 's.kind = ?';
            $params[] = $filters['kind'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . Pagination::likeEscape($q) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR t.doc_no LIKE ? ESCAPE '\\\\' OR t.trx_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like, $like);
        }
        $baseWhere = implode(' AND ', $where);

        $from = 'FROM transactions t
                 JOIN period_months pm ON pm.id = t.period_month_id
                 LEFT JOIN savings s ON s.transaction_id = t.id
                 LEFT JOIN members m ON m.id = t.member_id';

        // Ringkasan per status mengikuti semua filter KECUALI filter status, agar angkanya tetap berguna saat status disaring.
        $totals = [];
        foreach (self::STATUSES as $st) {
            $totals[$st] = ['n' => 0, 'sum' => 0];
        }
        $sumRows = Database::select(
            "SELECT t.status, COUNT(*) AS n, COALESCE(SUM(IF(t.reverses_id IS NULL, t.amount, -t.amount)), 0) AS total {$from} WHERE {$baseWhere} GROUP BY t.status",
            $params
        );
        foreach ($sumRows as $r) {
            $totals[(string) $r['status']] = ['n' => (int) $r['n'], 'sum' => (int) $r['total']];
        }

        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $where[]  = 't.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::select("SELECT COUNT(*) AS n {$from} WHERE {$whereSql}", $params)[0]['n'];
        $pager = Pagination::make($total, $page);

        // LIMIT/OFFSET adalah bilangan bulat hasil perhitungan sendiri (bukan input pengguna).
        $rows = Database::select(
            "SELECT t.id, t.doc_no, t.type, t.trx_date, t.amount, t.status, t.reverses_id, t.source, t.member_id,
                    pm.month_date, s.kind, m.member_no, m.name AS member_name,
                    ln.tenor_months, ln.rate_pct_month, lb.outstanding, lb.loan_status
             {$from}
             LEFT JOIN loans ln ON ln.transaction_id = t.id
             LEFT JOIN v_loan_balances lb ON lb.transaction_id = t.id
             WHERE {$whereSql}
             ORDER BY t.trx_date DESC, t.id DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        );
        return ['rows' => $rows, 'pager' => $pager, 'totals' => $totals];
    }

    /**
     * Rincian pinjaman satu transaksi pencairan: data pinjaman, jadwal cicilan, dan saldonya (saldo hanya ada
     * setelah DISETUJUI). Pemanggil WAJIB sudah memastikan transaksi dalam cakupan.
     * @return array{loan:?array<string,mixed>,installments:array<int,array<string,mixed>>,balance:?array<string,mixed>}
     */
    public static function loanDetail(int $transactionId): array
    {
        $loan = Database::select(
            'SELECT id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on FROM loans WHERE transaction_id = ?',
            [$transactionId]
        )[0] ?? null;
        if ($loan === null) {
            return ['loan' => null, 'installments' => [], 'balance' => null];
        }
        return [
            'loan'         => $loan,
            'installments' => Database::select(
                'SELECT seq, due_month, amount_due, paid_amount, remaining_amount FROM v_installment_status WHERE loan_id = ? ORDER BY seq',
                [(int) $loan['id']]
            ),
            'balance'      => Database::select('SELECT paid, outstanding, outstanding_principal, loan_status FROM v_loan_balances WHERE transaction_id = ?', [$transactionId])[0] ?? null,
        ];
    }

    /**
     * Pembagian satu pembayaran angsuran ke cicilan (paling tua dulu). Pemanggil WAJIB sudah memastikan
     * transaksi dalam cakupan.
     * @return array<int,array<string,mixed>>
     */
    public static function allocations(int $transactionId): array
    {
        return Database::select(
            'SELECT ip.amount, li.seq, pm.month_date AS due_month, lt.id AS loan_transaction_id, lt.doc_no AS loan_no
             FROM installment_payments ip
             JOIN loan_installments li ON li.id = ip.installment_id
             JOIN loans l ON l.id = li.loan_id
             JOIN transactions lt ON lt.id = l.transaction_id
             JOIN period_months pm ON pm.id = li.due_month_id
             WHERE ip.transaction_id = ? ORDER BY pm.month_date, l.id, li.seq',
            [$transactionId]
        );
    }

    /**
     * Tagihan per anggota dalam cakupan pengguna (hanya yang masih punya sisa pinjaman): sisa total, yang sudah
     * jatuh tempo sampai bulan berjalan, tunggakan (jatuh tempo SEBELUM bulan berjalan), dan pembayaran yang
     * sedang menunggu validasi. Angka sisa hanya menghitung pembayaran yang DISETUJUI.
     *
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array<int,array<string,mixed>>
     */
    public static function dueByMember(?array $user): array
    {
        [$scope, $params] = Scope::memberCondition($user, 'm.id', 'transaction');
        $cur = date('Y-m-01');
        return Database::select(
            "SELECT m.id, m.member_no, m.name, tl.name AS team_name,
                    SUM(s.remaining_amount) AS outstanding,
                    SUM(IF(s.due_month <= ?, s.remaining_amount, 0)) AS due_now,
                    SUM(IF(s.due_month < ?, s.remaining_amount, 0)) AS overdue,
                    (SELECT COALESCE(SUM(pt.amount), 0) FROM transactions pt
                      WHERE pt.member_id = m.id AND pt.type = 'ANGSURAN' AND pt.status = 'MENUNGGU_VALIDASI' AND pt.reverses_id IS NULL AND pt.deleted_at IS NULL) AS pending
             FROM v_installment_status s
             JOIN v_loan_balances b ON b.loan_id = s.loan_id
             JOIN members m ON m.id = b.member_id
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id
             WHERE s.remaining_amount > 0 AND m.deleted_at IS NULL AND ({$scope})
             GROUP BY m.id, m.member_no, m.name, tl.name
             ORDER BY overdue DESC, due_now DESC, m.name",
            array_merge([$cur, $cur], $params)
        );
    }

    /**
     * Bulan siklus yang boleh dicatat (periode aktif, tidak lebih dari bulan berjalan), terbaru dulu.
     * @return array<int,array{id:int,month_date:string}>
     */
    public static function recordableMonths(): array
    {
        return Database::select(
            "SELECT pm.id, pm.month_date FROM period_months pm JOIN periods p ON p.id = pm.period_id
             WHERE p.status = 'AKTIF' AND pm.month_date <= ? ORDER BY pm.month_date DESC",
            [date('Y-m-01')]
        );
    }

    /**
     * Semua bulan siklus (untuk filter daftar).
     * @return array<int,array{id:int,month_date:string}>
     */
    public static function allMonths(): array
    {
        return Database::select('SELECT id, month_date FROM period_months ORDER BY month_date DESC');
    }

    /**
     * Anggota aktif satu regu untuk pilihan formulir.
     * @return array<int,array{id:int,member_no:string,name:string}>
     */
    public static function teamMembers(int $teamId): array
    {
        return Database::select(
            "SELECT m.id, m.member_no, m.name FROM members m
             JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL AND a.team_id = ?
             WHERE m.status = 'AKTIF' AND m.deleted_at IS NULL ORDER BY m.name",
            [$teamId]
        );
    }
}
