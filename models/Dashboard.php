<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Services\LiveFeed;
use App\Services\Scope;

/**
 * Angka untuk Dashboard. Semua angka uang berasal dari view saldo (hanya transaksi DISETUJUI); tidak ada angka
 * yang disimpan. Query yang menyangkut anggota atau transaksi dibatasi cakupan data (Scope) di SQL, sama dengan
 * halaman daftar, supaya dashboard tidak pernah menampilkan lebih dari yang boleh dilihat penggunanya.
 */
final class Dashboard
{
    /**
     * Ringkasan global koperasi (v_global_summary). Hanya untuk cakupan semua; pemanggil menjaganya.
     * @return array{saldo_tabungan:int,kas_tersedia:int,piutang_beredar:int,bunga_dibukukan:int,biaya:int,selisih:int}
     */
    public static function summary(): array
    {
        $r = Database::select('SELECT saldo_tabungan, kas_tersedia, piutang_beredar, bunga_dibukukan, biaya, selisih FROM v_global_summary')[0];
        return array_map('intval', $r);
    }

    /**
     * Jumlah masalah integritas yang terdeteksi (harus 0). Pemeriksaannya memindai seluruh transaksi (±1 detik pada
     * 34.000 transaksi), jadi hasilnya disimpan sebentar: dipakai ulang selama penanda perubahan data (LiveFeed::version,
     * naik pada setiap perubahan sah) tidak berubah DAN umurnya di bawah INTEGRITY_CACHE_TTL detik (bawaan 60).
     * Batas umur itu penting: perubahan di luar aplikasi (mis. langsung lewat phpMyAdmin) tidak menaikkan penanda,
     * dan justru itulah yang harus terdeteksi; paling lambat satu menit kemudian. TTL 0 = selalu hitung ulang.
     */
    public static function integrityIssues(): int
    {
        $ttl = (int) Config::get('app.integrity_cache_ttl', 60);
        if ($ttl <= 0) {
            return self::countIntegrity();
        }
        $db      = (string) Database::select('SELECT DATABASE() AS d')[0]['d'];
        $file    = BASE_PATH . '/storage/cache/integrity-' . md5($db) . '.json';
        $version = LiveFeed::version();
        $cached  = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['v'] ?? null) === $version && isset($cached['t'], $cached['n']) && time() - (int) $cached['t'] < $ttl) {
            return (int) $cached['n'];
        }
        $n = self::countIntegrity();
        if (is_dir(dirname($file)) && is_writable(dirname($file))) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['v' => $version, 't' => time(), 'n' => $n])) !== false) {
                @rename($tmp, $file);   // tulis atomik: pembaca tidak pernah melihat berkas setengah jadi
            }
        }
        return $n;
    }

    private static function countIntegrity(): int
    {
        return (int) Database::select('SELECT COUNT(*) AS n FROM v_integrity_issues')[0]['n'];
    }

    /** Nama periode aktif, atau null bila tidak ada. */
    public static function activePeriod(): ?string
    {
        $r = Database::select("SELECT name FROM periods WHERE status = 'AKTIF' ORDER BY id DESC LIMIT 1");
        return $r === [] ? null : (string) $r[0]['name'];
    }

    /**
     * Arus bulanan periode aktif sampai bulan berjalan, dalam cakupan pengguna. Pembalik mengurangi (tanda minus),
     * jadi koreksi langsung tampak bersih. `tabungan` = perubahan tabungan bulan itu; `base` = tabungan sebelum bulan pertama.
     *
     * @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array{months:array<int,array{month:string,simpanan:int,angsuran:int,pencairan:int,tabungan:int}>,base:int}
     */
    public static function monthly(?array $user): array
    {
        [$cond, $params] = Scope::memberCondition($user, 'l.member_id', 'transaction');
        $rows = Database::select(
            "SELECT pm.month_date,
                    COALESCE(SUM(CASE WHEN l.type = 'SIMPANAN' THEN l.sign * l.amount END), 0) AS simpanan,
                    COALESCE(SUM(CASE WHEN l.type = 'ANGSURAN' THEN l.sign * l.amount END), 0) AS angsuran,
                    COALESCE(SUM(CASE WHEN l.type = 'PENCAIRAN_PINJAMAN' THEN l.sign * l.amount END), 0) AS pencairan,
                    COALESCE(SUM(l.savings_delta), 0) AS tabungan
             FROM period_months pm
             JOIN periods p ON p.id = pm.period_id AND p.status = 'AKTIF'
             LEFT JOIN v_ledger l ON l.period_month_id = pm.id AND ({$cond})
             WHERE pm.month_date <= ?
             GROUP BY pm.id, pm.month_date ORDER BY pm.month_date",
            array_merge($params, [date('Y-m-01')])
        );
        $months = array_map(static fn (array $r): array => [
            'month' => (string) $r['month_date'], 'simpanan' => (int) $r['simpanan'], 'angsuran' => (int) $r['angsuran'],
            'pencairan' => (int) $r['pencairan'], 'tabungan' => (int) $r['tabungan'],
        ], $rows);

        $base = 0;
        if ($months !== []) {
            $base = (int) Database::select(
                "SELECT COALESCE(SUM(l.savings_delta), 0) AS s FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id
                 WHERE pm.month_date < ? AND ({$cond})",
                array_merge([$months[0]['month']], $params)
            )[0]['s'];
        }
        return ['months' => $months, 'base' => $base];
    }

    /**
     * Ringkasan per regu (keanggotaan saat ini): anggota, tabungan, sisa pinjaman, tunggakan.
     * @return array<int,array{id:int,name:string,members:int,savings:int,outstanding:int,overdue:int}>
     */
    public static function teams(): array
    {
        $rows = Database::select(
            "SELECT tl.id, tl.name, COUNT(m.id) AS members,
                    COALESCE(SUM(s.savings_balance), 0) AS savings,
                    COALESCE(SUM(lb.outstanding), 0) AS outstanding,
                    COALESCE(SUM(o.overdue), 0) AS overdue
             FROM team_leaders tl
             LEFT JOIN member_team_assignments a ON a.team_id = tl.id AND a.valid_to IS NULL
             LEFT JOIN members m ON m.id = a.member_id AND m.deleted_at IS NULL
             LEFT JOIN v_member_savings s ON s.member_id = m.id
             LEFT JOIN (SELECT member_id, SUM(outstanding) AS outstanding FROM v_loan_balances GROUP BY member_id) lb ON lb.member_id = m.id
             LEFT JOIN (SELECT member_id, SUM(remaining_amount) AS overdue FROM v_overdue_installments GROUP BY member_id) o ON o.member_id = m.id
             WHERE tl.is_active = 1
             GROUP BY tl.id, tl.name ORDER BY tl.name"
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'members' => (int) $r['members'],
            'savings' => (int) $r['savings'], 'outstanding' => (int) $r['outstanding'], 'overdue' => (int) $r['overdue'],
        ], $rows);
    }

    /**
     * Anggota satu regu beserta tabungan, sisa pinjaman, dan tunggakannya (tabungan terbesar dulu).
     * Pemanggil WAJIB memastikan pengguna boleh melihat regu ini.
     * @return array<int,array{id:int,member_no:string,name:string,status:string,savings:int,outstanding:int,overdue:int}>
     */
    public static function teamMembers(int $teamId): array
    {
        $rows = Database::select(
            "SELECT m.id, m.member_no, m.name, m.status,
                    COALESCE(s.savings_balance, 0) AS savings, COALESCE(lb.outstanding, 0) AS outstanding, COALESCE(o.overdue, 0) AS overdue
             FROM members m
             JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL AND a.team_id = ?
             LEFT JOIN v_member_savings s ON s.member_id = m.id
             LEFT JOIN (SELECT member_id, SUM(outstanding) AS outstanding FROM v_loan_balances GROUP BY member_id) lb ON lb.member_id = m.id
             LEFT JOIN (SELECT member_id, SUM(remaining_amount) AS overdue FROM v_overdue_installments GROUP BY member_id) o ON o.member_id = m.id
             WHERE m.deleted_at IS NULL
             ORDER BY savings DESC, m.name",
            [$teamId]
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'member_no' => (string) $r['member_no'], 'name' => (string) $r['name'], 'status' => (string) $r['status'],
            'savings' => (int) $r['savings'], 'outstanding' => (int) $r['outstanding'], 'overdue' => (int) $r['overdue'],
        ], $rows);
    }

    /**
     * Ringkasan pribadi seorang anggota: tabungan per jenis, pinjaman, dan cicilan berikutnya.
     * @return array{savings:int,byKind:array<string,int>,loans:array<int,array<string,mixed>>,outstanding:int,overdue:int,next:?array{due_month:string,remaining:int}}
     */
    public static function member(int $memberId): array
    {
        $savings = (int) (Database::select('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$memberId])[0]['savings_balance'] ?? 0);

        $byKind = [];
        foreach (Database::select(
            "SELECT s.kind, SUM(l.sign * l.amount) AS total FROM v_ledger l JOIN savings s ON s.transaction_id = l.transaction_id
             WHERE l.member_id = ? AND l.type = 'SIMPANAN' GROUP BY s.kind",
            [$memberId]
        ) as $r) {
            $byKind[(string) $r['kind']] = (int) $r['total'];
        }

        $loans = Database::select(
            'SELECT b.loan_no, b.transaction_id, b.principal, b.total_due, b.paid, b.outstanding, b.loan_status
             FROM v_loan_balances b WHERE b.member_id = ? ORDER BY b.loan_id',
            [$memberId]
        );
        $outstanding = array_sum(array_map(static fn (array $l): int => (int) $l['outstanding'], $loans));

        $overdue = (int) (Database::select('SELECT COALESCE(SUM(remaining_amount), 0) AS s FROM v_overdue_installments WHERE member_id = ?', [$memberId])[0]['s'] ?? 0);
        $next = Database::select(
            "SELECT s.due_month, s.remaining_amount FROM v_installment_status s JOIN v_loan_balances b ON b.loan_id = s.loan_id
             WHERE b.member_id = ? AND s.remaining_amount > 0 ORDER BY s.due_month, s.loan_id, s.seq LIMIT 1",
            [$memberId]
        )[0] ?? null;

        return [
            'savings' => $savings, 'byKind' => $byKind, 'loans' => $loans, 'outstanding' => (int) $outstanding, 'overdue' => $overdue,
            'next' => $next === null ? null : ['due_month' => (string) $next['due_month'], 'remaining' => (int) $next['remaining_amount']],
        ];
    }

    /**
     * Transaksi terbaru dalam cakupan pengguna (semua status).
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array<int,array<string,mixed>>
     */
    public static function recent(?array $user, int $limit = 6): array
    {
        [$scope, $params] = Transaction::scopeSql($user);
        $limit = max(1, min(20, $limit));
        return Database::select(
            "SELECT t.id, t.doc_no, t.type, t.amount, t.status, t.reverses_id, t.trx_date, m.name AS member_name
             FROM transactions t LEFT JOIN members m ON m.id = t.member_id
             WHERE t.deleted_at IS NULL AND ({$scope})
             ORDER BY t.id DESC LIMIT {$limit}",
            $params
        );
    }

    /**
     * Berapa transaksi buatan pengguna ini yang perlu perhatiannya: draft, menunggu validasi, dan ditolak 30 hari terakhir.
     * @return array{DRAFT:int,MENUNGGU_VALIDASI:int,DITOLAK:int}
     */
    public static function ownWork(int $userId): array
    {
        $out = ['DRAFT' => 0, 'MENUNGGU_VALIDASI' => 0, 'DITOLAK' => 0];
        foreach (Database::select(
            "SELECT status, COUNT(*) AS n FROM transactions
             WHERE created_by = ? AND deleted_at IS NULL
               AND (status IN ('DRAFT','MENUNGGU_VALIDASI') OR (status = 'DITOLAK' AND status_changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)))
             GROUP BY status",
            [$userId]
        ) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
