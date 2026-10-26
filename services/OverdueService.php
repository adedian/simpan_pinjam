<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Tunggakan pinjaman per cicilan, untuk notifikasi Ketua Regu dan Superadmin. HANYA MEMBACA.
 *
 * Definisi (satu-satunya, dipakai juga oleh kolom "Tunggakan" di laporan dan Tagihan lewat view v_overdue_installments):
 *   - cicilan dari pinjaman yang berlaku (pencairan DISETUJUI dan belum dibalik);
 *   - masih ada sisa (jadwal - pembayaran DISETUJUI > 0). Pembayaran yang masih MENUNGGU VALIDASI belum mengurangi;
 *   - bulan jatuh tempo sudah LEWAT (kurang dari bulan berjalan).
 * Tanggal jatuh tempo = due_date cicilan (pinjaman disetujui sejak migrasi 012: tanggal persetujuan + n bulan); pinjaman
 * lama tanpa tanggal memakai akhir bulan jatuh tempo. Hari keterlambatan dihitung dari tanggal itu sampai hari ini (zona
 * waktu aplikasi: koneksi database diset +07:00, sama dengan PHP). Jatuh tempo HARI INI belum menunggak. Cicilan yang
 * belum jatuh tempo TIDAK dihitung. Pembayaran sebagian hanya menyisakan sisa kewajiban cicilan itu.
 *
 * Cakupan data dipaksa di query lewat Scope::memberCondition: Ketua Regu hanya melihat anggota regunya, Anggota dirinya.
 */
final class OverdueService
{
    /**
     * Semua cicilan yang menunggak dalam cakupan pengguna, terlama dulu.
     * @param array<string,mixed>|null $user
     * @param array{team?:int,q?:string,asof?:string} $f team hanya berlaku untuk cakupan seluruh koperasi; asof = tanggal pemeriksaan (Y-m-d) di masa lalu
     * @return array<int,array<string,mixed>>
     */
    public static function items(?array $user, array $f = []): array
    {
        [$scope, $params] = Scope::memberCondition($user, 'm.id', 'transaction');
        $where = ['m.deleted_at IS NULL', "({$scope})"];
        if (Scope::level($user, 'transaction') === Scope::ALL && (int) ($f['team'] ?? 0) > 0) {
            $where[]  = 'a.team_id = ?';
            $params[] = (int) $f['team'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like     = '%' . Pagination::likeEscape($q) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR o.loan_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        // Tanggal pemeriksaan: kosong/hari ini = keadaan sekarang (view); tanggal lampau = keadaan pada tanggal itu (AsOf).
        $asOf = (string) ($f['asof'] ?? '');
        $asOf = $asOf !== '' && !AsOf::isCurrent($asOf) ? $asOf : '';
        if ($asOf !== '') {
            $source = '(' . AsOf::overdueSubquery() . ')';
            // urutan ? di SQL: DATEDIFF di daftar kolom, lalu tiga di subquery AsOf, lalu cakupan dan saringan
            $params = array_merge([$asOf, $asOf, $asOf, $asOf], $params);
            $late   = 'DATEDIFF(?, o.due_date)';
            $pend   = '0';
        } else {
            $source = 'v_overdue_installments';
            $late   = 'DATEDIFF(CURDATE(), o.due_date)';
            $pend   = "(SELECT COALESCE(SUM(ip.amount), 0) FROM installment_payments ip
                          JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'MENUNGGU_VALIDASI' AND pt.deleted_at IS NULL AND pt.reverses_id IS NULL
                         WHERE ip.installment_id = o.installment_id)";
        }
        $rows = Database::select(
            "SELECT o.installment_id, o.loan_id, o.loan_no, o.member_id, o.seq, o.due_month, o.amount_due, o.paid_amount, o.remaining_amount,
                    b.transaction_id AS loan_trx_id, ln.tenor_months, m.member_no, m.name, a.team_id, tl.name AS team_name,
                    o.due_date, {$late} AS days_late,
                    {$pend} AS pending_amount
             FROM {$source} o
             JOIN v_loan_balances b ON b.loan_id = o.loan_id
             JOIN loans ln ON ln.id = o.loan_id
             JOIN members m ON m.id = o.member_id
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY days_late DESC, m.name, o.loan_id, o.seq',
            $params
        );
        return array_map(static fn (array $r): array => [
            'installment_id' => (int) $r['installment_id'], 'loan_id' => (int) $r['loan_id'], 'loan_no' => (string) $r['loan_no'], 'loan_trx_id' => (int) $r['loan_trx_id'],
            'member_id' => (int) $r['member_id'], 'member_no' => (string) $r['member_no'], 'name' => (string) $r['name'],
            'team_id' => $r['team_id'] === null ? null : (int) $r['team_id'], 'team_name' => (string) ($r['team_name'] ?? '-'),
            'seq' => (int) $r['seq'], 'tenor' => (int) $r['tenor_months'], 'due_month' => (string) $r['due_month'], 'due_date' => (string) $r['due_date'],
            'amount_due' => (int) $r['amount_due'], 'paid' => (int) $r['paid_amount'], 'remaining' => (int) $r['remaining_amount'],
            'days_late' => max(1, (int) $r['days_late']), 'pending' => (int) $r['pending_amount'],
            'status' => (int) $r['paid_amount'] > 0 ? 'Dibayar sebagian' : 'Belum dibayar',
        ], $rows);
    }

    /**
     * Cicilan yang JATUH TEMPO HARI INI dan belum lunas (belum menunggak: baru terlambat mulai besok), dalam cakupan pengguna.
     * @param array<string,mixed>|null $user
     * @return array{members:int,installments:int,amount:int}
     */
    public static function dueToday(?array $user): array
    {
        [$scope, $params] = Scope::memberCondition($user, 'm.id', 'transaction');
        $row = Database::select(
            "SELECT COUNT(*) AS n, COUNT(DISTINCT b.member_id) AS members, COALESCE(SUM(s.remaining_amount), 0) AS amount
             FROM v_installment_status s
             JOIN v_loan_balances b ON b.loan_id = s.loan_id
             JOIN members m ON m.id = b.member_id
             WHERE s.remaining_amount > 0 AND s.due_date = CURDATE() AND m.deleted_at IS NULL AND ({$scope})",
            $params
        )[0];
        return ['members' => (int) $row['members'], 'installments' => (int) $row['n'], 'amount' => (int) $row['amount']];
    }

    /**
     * Ringkasan dari daftar cicilan: jumlah anggota, cicilan, total tunggakan, dan terlama.
     * @param array<int,array<string,mixed>> $items
     * @return array{members:int,installments:int,amount:int,pending:int,max_days:int}
     */
    public static function summary(array $items): array
    {
        return [
            'members'      => count(array_unique(array_column($items, 'member_id'))),
            'installments' => count($items),
            'amount'       => (int) array_sum(array_column($items, 'remaining')),
            'pending'      => (int) array_sum(array_column($items, 'pending')),
            'max_days'     => $items === [] ? 0 : (int) max(array_column($items, 'days_late')),
        ];
    }

    /**
     * Bahan panel dashboard: ringkasan atas SEMUA tunggakan dalam cakupan dan daftar teratas.
     * @param array<string,mixed>|null $user
     * @return array{summary:array<string,int>,items:array<int,array<string,mixed>>,more:int,teams:array<int,array{name:string,members:int,installments:int,amount:int}>}
     */
    public static function panel(?array $user, int $limit = 10): array
    {
        $all = self::items($user);
        $teams = [];
        foreach ($all as $i) {
            $t = $i['team_name'];
            $teams[$t] ??= ['name' => $t, 'members' => [], 'installments' => 0, 'amount' => 0];
            $teams[$t]['members'][$i['member_id']] = true;
            $teams[$t]['installments']++;
            $teams[$t]['amount'] += $i['remaining'];
        }
        foreach ($teams as &$t) {
            $t['members'] = count($t['members']);
        }
        unset($t);
        return [
            'summary' => self::summary($all),
            'items'   => array_slice($all, 0, max(1, $limit)),
            'more'    => max(0, count($all) - $limit),
            'teams'   => array_values($teams),
            'today'   => self::dueToday($user),
        ];
    }
}
