<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;
use App\Models\Transaction;

/**
 * Laporan (Phase 12). Hanya MEMBACA: semua angka berasal dari view saldo (transaksi DISETUJUI), tidak ada angka
 * yang disimpan, dan pembalik (Phase 10) mengurangi bulan asalnya sehingga laporan selalu bersih dari koreksi.
 *
 * Cakupan data ditegakkan di SQL, bukan di tampilan:
 *   global (report.view.global)  : seluruh koperasi        team (report.view.team) : regu yang dipimpin
 *   self   (report.view.self)    : diri sendiri            none                    : tidak ada data
 * HTML dan Excel (ReportWorkbook) dibangun dari SATU struktur tabel yang sama, jadi unduhan selalu persis sama dengan layar.
 *
 * Struktur tabel: ['key','title','subtitle','columns'=>[['key','label','type'=>text|money|int|pct]],
 *                  'rows'=>[[kolom=>nilai, '_links'=>[kolom=>path]]], 'totals'=>?baris, 'note'=>string]
 * `pct` disimpan dalam seperseratus persen (123 = 1,23%).
 */
final class ReportService
{
    public const ALL = 'all';
    public const TEAM = 'team';
    public const SELF = 'self';
    public const NONE = 'none';

    public const KEYS = ['simpanan', 'pinjaman', 'angsuran', 'saldo', 'transaksi', 'regu'];
    public const EXPORT_MAX_ROWS = 50000;
    public const PER_PAGE = 50;

    // ------------------------------------------------------------------ cakupan

    /** @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user */
    public static function level(?array $user): string
    {
        if ($user === null) {
            return self::NONE;
        }
        if (Gate::allows($user, 'report.view.global')) {
            return self::ALL;
        }
        if (Gate::allows($user, 'report.view.team') && ($user['team_id'] ?? null) !== null) {
            return self::TEAM;
        }
        if (Gate::allows($user, 'report.view.self') && ($user['member_id'] ?? null) !== null) {
            return self::SELF;
        }
        return self::NONE;
    }

    /**
     * Potongan SQL pembatas anggota (berparameter).
     * @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @return array{0:string,1:array<int,int>}
     */
    public static function memberCondition(?array $user, string $column = 'm.id'): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
            throw new \InvalidArgumentException('Nama kolom tidak valid.');
        }
        return match (self::level($user)) {
            self::ALL  => ['1 = 1', []],
            self::TEAM => ["{$column} IN (SELECT a.member_id FROM member_team_assignments a WHERE a.team_id = ? AND a.valid_to IS NULL)", [(int) $user['team_id']]],
            self::SELF => ["{$column} = ?", [(int) $user['member_id']]],
            default    => ['1 = 0', []],
        };
    }

    // ------------------------------------------------------------------ saringan dan periode

    /** @return array<int,array{id:int,name:string,status:string}> terbaru dulu */
    public static function periods(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'status' => (string) $r['status']],
            Database::select('SELECT id, name, status FROM periods ORDER BY id DESC'));
    }

    /**
     * Bulan siklus satu periode. Periode aktif hanya sampai bulan berjalan; periode tutup semuanya.
     * @return array<int,array{id:int,month:string}>
     */
    public static function months(int $periodId): array
    {
        $rows = Database::select(
            "SELECT pm.id, pm.month_date FROM period_months pm JOIN periods p ON p.id = pm.period_id
             WHERE pm.period_id = ? AND (p.status = 'TUTUP' OR pm.month_date <= ?) ORDER BY pm.month_date",
            [$periodId, date('Y-m-01')]
        );
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'month' => (string) $r['month_date']], $rows);
    }

    /**
     * Saringan dari query string, sudah dibersihkan dan dibatasi. Periode tak dikenal jatuh ke periode aktif.
     * @param array<string,mixed> $q
     * @param array<string,mixed>|null $user
     * @return array{period:int,team:int,q:string,from:string,to:string,type:string,status:string,anggota:int,bulan:int}
     */
    public static function filters(array $q, ?array $user): array
    {
        $q       = array_map(static fn (mixed $v): mixed => is_scalar($v) ? $v : '', $q);   // q[]=x dst. bukan isian yang sah
        $periods = self::periods();
        $valid   = array_column($periods, 'id');
        $default = 0;
        foreach ($periods as $p) {
            if ($p['status'] === 'AKTIF') {
                $default = $p['id'];
                break;
            }
        }
        $default = $default ?: ($periods[0]['id'] ?? 0);
        $period  = (int) ($q['periode'] ?? 0);
        $date    = static function (mixed $v): string {
            $v = trim((string) $v);
            $d = \DateTime::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
        };
        $status  = array_key_exists('status', $q) ? (string) $q['status'] : 'DISETUJUI';
        return [
            'period'  => in_array($period, $valid, true) ? $period : (int) $default,
            'team'    => self::level($user) === self::ALL ? max(0, (int) ($q['regu'] ?? 0)) : 0,
            'q'       => mb_substr(clean_text($q['q'] ?? ''), 0, 60),
            'from'    => $date($q['dari'] ?? ''),
            'to'      => $date($q['sampai'] ?? ''),
            'type'    => isset(Transaction::TYPE_LABELS[(string) ($q['jenis'] ?? '')]) ? (string) $q['jenis'] : '',
            'status'  => in_array($status, array_merge(Transaction::STATUSES, ['AKTIF', 'LUNAS']), true) ? $status : '',
            'anggota' => max(0, (int) ($q['anggota'] ?? 0)),
            'bulan'   => max(0, (int) ($q['bulan'] ?? 0)),
        ];
    }

    /** @return array<int,array{id:int,name:string}> */
    public static function teams(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            Database::select('SELECT id, name FROM team_leaders WHERE is_active = 1 ORDER BY name'));
    }

    // ------------------------------------------------------------------ anggota dalam cakupan

    /**
     * Anggota dalam cakupan pengguna, terurut regu lalu nama.
     * @param array<string,mixed>|null $user
     * @param array{team:int,q:string} $f
     * @return array<int,array{id:int,member_no:string,name:string,status:string,team_id:?int,team_name:string}>
     */
    public static function members(?array $user, array $f): array
    {
        [$scope, $params] = self::memberCondition($user, 'm.id');
        $where = ['m.deleted_at IS NULL', "({$scope})"];
        if ($f['team'] > 0) {
            $where[]  = 'a.team_id = ?';
            $params[] = $f['team'];
        }
        if ($f['q'] !== '') {
            $like     = '%' . Pagination::likeEscape($f['q']) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like);
        }
        $rows = Database::select(
            'SELECT m.id, m.member_no, m.name, m.status, a.team_id, tl.name AS team_name
             FROM members m
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY tl.name, m.name, m.id',
            $params
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'member_no' => (string) $r['member_no'], 'name' => (string) $r['name'], 'status' => (string) $r['status'],
            'team_id' => $r['team_id'] === null ? null : (int) $r['team_id'], 'team_name' => (string) ($r['team_name'] ?? '-'),
        ], $rows);
    }

    /**
     * Jumlah bersih (bertanda: pembalik mengurangi) per anggota per bulan siklus untuk satu jenis transaksi.
     * @param array<int,int> $memberIds @param array<int,int> $monthIds
     * @return array<int,array<int,int>> [anggota][bulan] => rupiah
     */
    private static function pivot(string $type, array $memberIds, array $monthIds): array
    {
        if ($memberIds === [] || $monthIds === []) {
            return [];
        }
        $mIn = implode(',', array_map('intval', $memberIds));
        $pIn = implode(',', array_map('intval', $monthIds));
        $out = [];
        foreach (Database::select(
            "SELECT member_id, period_month_id, SUM(sign * amount) AS v FROM v_ledger
             WHERE type = ? AND member_id IN ({$mIn}) AND period_month_id IN ({$pIn}) GROUP BY member_id, period_month_id",
            [$type]
        ) as $r) {
            $out[(int) $r['member_id']][(int) $r['period_month_id']] = (int) $r['v'];
        }
        return $out;
    }

    /**
     * Angka sekarang per anggota: tabungan, sisa pinjaman, tunggakan.
     * @param array<int,int> $memberIds
     * @return array<int,array{savings:int,outstanding:int,overdue:int}>
     */
    private static function standing(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }
        $in  = implode(',', array_map('intval', $memberIds));
        $out = [];
        foreach ($memberIds as $id) {
            $out[(int) $id] = ['savings' => 0, 'outstanding' => 0, 'overdue' => 0];
        }
        foreach (Database::select("SELECT member_id, savings_balance FROM v_member_savings WHERE member_id IN ({$in})") as $r) {
            $out[(int) $r['member_id']]['savings'] = (int) $r['savings_balance'];
        }
        foreach (Database::select("SELECT member_id, SUM(outstanding) AS v FROM v_loan_balances WHERE member_id IN ({$in}) GROUP BY member_id") as $r) {
            $out[(int) $r['member_id']]['outstanding'] = (int) $r['v'];
        }
        foreach (Database::select("SELECT member_id, SUM(remaining_amount) AS v FROM v_overdue_installments WHERE member_id IN ({$in}) GROUP BY member_id") as $r) {
            $out[(int) $r['member_id']]['overdue'] = (int) $r['v'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ pembangun tabel

    /**
     * Bangun satu laporan tabel.
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $f hasil filters()
     * @return array<string,mixed>
     */
    public static function build(string $key, ?array $user, array $f, int $page = 1, bool $all = false): array
    {
        return match ($key) {
            'simpanan'  => self::matrixReport('simpanan', 'Rekap Simpanan', 'SIMPANAN', $user, $f, 'Setoran bersih per bulan siklus (pembalik mengurangi); kolom terakhir = saldo tabungan sekarang.'),
            'angsuran'  => self::matrixReport('angsuran', 'Rekap Angsuran', 'ANGSURAN', $user, $f, 'Angsuran bersih per bulan siklus; sisa pinjaman dan tunggakan = keadaan sekarang.'),
            'saldo'     => self::balanceReport($user, $f),
            'pinjaman'  => self::loanReport($user, $f),
            'regu'      => self::teamReport($user, $f),
            'transaksi' => self::transactionReport($user, $f, $page, $all),
            default     => throw new \InvalidArgumentException('Laporan tidak dikenal.'),
        };
    }

    /** @param array<int,array{id:int,month:string}> $months */
    private static function monthColumns(array $months): array
    {
        return array_map(static fn (array $m): array => ['key' => 'm' . $m['id'], 'label' => month_label($m['month']), 'type' => 'money'], $months);
    }

    /** @param array<string,mixed> $f */
    private static function periodName(array $f): string
    {
        foreach (self::periods() as $p) {
            if ($p['id'] === $f['period']) {
                return $p['name'];
            }
        }
        return '-';
    }

    /**
     * Rekap simpanan atau angsuran: anggota x bulan.
     * @param array<string,mixed>|null $user @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function matrixReport(string $key, string $title, string $type, ?array $user, array $f, string $note): array
    {
        $months  = self::months($f['period']);
        $members = self::members($user, $f);
        $ids     = array_column($members, 'id');
        $pivot   = self::pivot($type, $ids, array_column($months, 'id'));
        $stand   = self::standing($ids);

        $columns = array_merge(
            [['key' => 'no', 'label' => 'No. Anggota', 'type' => 'text'], ['key' => 'name', 'label' => 'Nama', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text']],
            self::monthColumns($months),
            [['key' => 'total', 'label' => 'Jumlah', 'type' => 'money']],
            $key === 'simpanan'
                ? [['key' => 'savings', 'label' => 'Saldo tabungan', 'type' => 'money']]
                : [['key' => 'outstanding', 'label' => 'Sisa pinjaman', 'type' => 'money'], ['key' => 'overdue', 'label' => 'Tunggakan', 'type' => 'money']]
        );

        $rows = [];
        $sum  = [];
        foreach ($members as $m) {
            $row = ['no' => $m['member_no'], 'name' => $m['name'], 'team' => $m['team_name'], '_links' => ['name' => '/anggota/' . $m['id']]];
            $total = 0;
            foreach ($months as $mo) {
                $v = $pivot[$m['id']][$mo['id']] ?? 0;
                $row['m' . $mo['id']] = $v;
                $total += $v;
            }
            $row['total'] = $total;
            if ($key === 'simpanan') {
                $row['savings'] = $stand[$m['id']]['savings'];
            } else {
                $row['outstanding'] = $stand[$m['id']]['outstanding'];
                $row['overdue']     = $stand[$m['id']]['overdue'];
            }
            $rows[] = $row;
        }
        return [
            'key' => $key, 'title' => $title, 'subtitle' => 'Periode ' . self::periodName($f) . ' · rupiah', 'columns' => $columns, 'rows' => $rows,
            'totals' => self::sumRow($columns, $rows, 'Jumlah ' . count($rows) . ' anggota'), 'note' => $note,
        ];
    }

    /**
     * Rekap saldo: tabungan kumulatif akhir bulan per anggota, dan porsinya terhadap total pada bulan terakhir.
     * @param array<string,mixed>|null $user @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function balanceReport(?array $user, array $f): array
    {
        $months  = self::months($f['period']);
        $members = self::members($user, $f);
        $ids     = array_column($members, 'id');
        $delta   = [];
        $base    = [];
        if ($ids !== [] && $months !== []) {
            $in = implode(',', array_map('intval', $ids));
            foreach (Database::select(
                "SELECT l.member_id, l.period_month_id, SUM(l.savings_delta) AS v FROM v_ledger l
                 WHERE l.member_id IN ({$in}) AND l.period_month_id IN (" . implode(',', array_column($months, 'id')) . ') GROUP BY l.member_id, l.period_month_id'
            ) as $r) {
                $delta[(int) $r['member_id']][(int) $r['period_month_id']] = (int) $r['v'];
            }
            foreach (Database::select(
                "SELECT l.member_id, SUM(l.savings_delta) AS v FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id
                 WHERE l.member_id IN ({$in}) AND pm.month_date < ? GROUP BY l.member_id",
                [$months[0]['month']]
            ) as $r) {
                $base[(int) $r['member_id']] = (int) $r['v'];
            }
        }

        $columns = array_merge(
            [['key' => 'no', 'label' => 'No. Anggota', 'type' => 'text'], ['key' => 'name', 'label' => 'Nama', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text']],
            self::monthColumns($months),
            [['key' => 'share', 'label' => 'Porsi', 'type' => 'pct']]
        );
        $rows = [];
        $last = [];
        foreach ($members as $m) {
            $row = ['no' => $m['member_no'], 'name' => $m['name'], 'team' => $m['team_name'], '_links' => ['name' => '/anggota/' . $m['id']]];
            $run = $base[$m['id']] ?? 0;
            foreach ($months as $mo) {
                $run += $delta[$m['id']][$mo['id']] ?? 0;
                $row['m' . $mo['id']] = $run;
            }
            $last[] = $run;
            $rows[] = $row;
        }
        $grand = array_sum($last);
        foreach ($rows as $i => &$row) {
            $row['share'] = $grand > 0 ? (int) round($last[$i] * 10000 / $grand) : 0;
        }
        unset($row);

        $totals = ['no' => '', 'name' => 'Jumlah ' . count($rows) . ' anggota', 'team' => ''];
        foreach ($months as $mo) {
            $totals['m' . $mo['id']] = array_sum(array_column($rows, 'm' . $mo['id']));
        }
        $totals['share'] = $grand > 0 ? 10000 : 0;
        return [
            'key' => 'saldo', 'title' => 'Rekap Saldo', 'subtitle' => 'Periode ' . self::periodName($f) . ' · rupiah, saldo akhir tiap bulan', 'columns' => $columns, 'rows' => $rows,
            'totals' => $totals, 'note' => 'Saldo tabungan kumulatif (termasuk sebelum periode ini). Porsi = bagian anggota dari total tabungan pada bulan terakhir.',
        ];
    }

    /**
     * Rekap pinjaman: satu baris per pinjaman yang berlaku (yang sudah dibalik tidak ikut), dicairkan dalam periode terpilih.
     * @param array<string,mixed>|null $user @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function loanReport(?array $user, array $f): array
    {
        [$scope, $params] = self::memberCondition($user, 'm.id');
        $where  = ['pm.period_id = ?', "({$scope})"];
        $params = array_merge([$f['period']], $params);
        if ($f['team'] > 0) {
            $where[]  = 'a.team_id = ?';
            $params[] = $f['team'];
        }
        if ($f['q'] !== '') {
            $like     = '%' . Pagination::likeEscape($f['q']) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR b.loan_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        if (in_array($f['status'], ['AKTIF', 'LUNAS'], true)) {
            $where[]  = 'b.loan_status = ?';
            $params[] = $f['status'];
        }
        $rows = Database::select(
            'SELECT b.loan_id, b.loan_no, b.transaction_id, b.member_id, b.principal, b.total_interest, b.total_due, b.paid, b.outstanding, b.loan_status,
                    ln.tenor_months, pm.month_date, m.member_no, m.name, tl.name AS team_name, COALESCE(od.overdue, 0) AS overdue
             FROM v_loan_balances b
             JOIN loans ln ON ln.id = b.loan_id
             JOIN transactions t ON t.id = b.transaction_id
             JOIN period_months pm ON pm.id = t.period_month_id
             JOIN members m ON m.id = b.member_id
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id
             LEFT JOIN (SELECT loan_id, SUM(remaining_amount) AS overdue FROM v_overdue_installments GROUP BY loan_id) od ON od.loan_id = b.loan_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY pm.month_date, b.loan_id',
            $params
        );
        $columns = [
            ['key' => 'loan_no', 'label' => 'Pinjaman', 'type' => 'text'], ['key' => 'name', 'label' => 'Anggota', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text'],
            ['key' => 'month', 'label' => 'Bulan cair', 'type' => 'text'], ['key' => 'tenor', 'label' => 'Tenor (bln)', 'type' => 'int'],
            ['key' => 'principal', 'label' => 'Pokok', 'type' => 'money'], ['key' => 'interest', 'label' => 'Bunga', 'type' => 'money'], ['key' => 'due', 'label' => 'Total tagihan', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Terbayar', 'type' => 'money'], ['key' => 'outstanding', 'label' => 'Sisa', 'type' => 'money'], ['key' => 'overdue', 'label' => 'Tunggakan', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'loan_no' => (string) $r['loan_no'], 'name' => $r['name'] . ' (' . $r['member_no'] . ')', 'team' => (string) ($r['team_name'] ?? '-'), 'month' => month_label((string) $r['month_date']),
                'tenor' => (int) $r['tenor_months'], 'principal' => (int) $r['principal'], 'interest' => (int) $r['total_interest'], 'due' => (int) $r['total_due'],
                'paid' => (int) $r['paid'], 'outstanding' => (int) $r['outstanding'], 'overdue' => (int) $r['overdue'], 'status' => $r['loan_status'] === 'LUNAS' ? 'Lunas' : 'Aktif',
                '_links' => ['loan_no' => '/transaksi/' . (int) $r['transaction_id'], 'name' => '/anggota/' . (int) $r['member_id']],
            ];
        }
        return [
            'key' => 'pinjaman', 'title' => 'Rekap Pinjaman', 'subtitle' => 'Dicairkan pada periode ' . self::periodName($f) . ' · rupiah', 'columns' => $columns, 'rows' => $out,
            'totals' => self::sumRow($columns, $out, 'Jumlah ' . count($out) . ' pinjaman'), 'note' => 'Hanya pinjaman yang berlaku (disetujui dan belum dibalik). Sisa dan tunggakan = keadaan sekarang.',
        ];
    }

    /**
     * Laporan per ketua regu (hanya cakupan global).
     * @param array<string,mixed>|null $user @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function teamReport(?array $user, array $f): array
    {
        $columns = [
            ['key' => 'team', 'label' => 'Regu', 'type' => 'text'], ['key' => 'leader', 'label' => 'Ketua regu', 'type' => 'text'], ['key' => 'members', 'label' => 'Anggota', 'type' => 'int'],
            ['key' => 'saved', 'label' => 'Setoran periode', 'type' => 'money'], ['key' => 'paid', 'label' => 'Angsuran periode', 'type' => 'money'], ['key' => 'lent', 'label' => 'Pencairan periode', 'type' => 'money'],
            ['key' => 'savings', 'label' => 'Tabungan sekarang', 'type' => 'money'], ['key' => 'outstanding', 'label' => 'Sisa pinjaman', 'type' => 'money'], ['key' => 'overdue', 'label' => 'Tunggakan', 'type' => 'money'],
        ];
        $base = ['key' => 'regu', 'title' => 'Laporan Per Ketua Regu', 'subtitle' => 'Periode ' . self::periodName($f) . ' · rupiah', 'columns' => $columns, 'rows' => [], 'totals' => null,
                 'note' => 'Anggota menurut keanggotaan regu saat ini. Setoran, angsuran, dan pencairan = jumlah bersih dalam periode; tabungan, sisa, tunggakan = keadaan sekarang.'];
        if (self::level($user) !== self::ALL) {
            return $base;
        }
        $monthIds = array_column(self::months($f['period']), 'id');
        $members  = self::members($user, ['team' => $f['team'], 'q' => '']);
        $ids      = array_column($members, 'id');
        $saved    = self::pivot('SIMPANAN', $ids, $monthIds);
        $paid     = self::pivot('ANGSURAN', $ids, $monthIds);
        $lent     = self::pivot('PENCAIRAN_PINJAMAN', $ids, $monthIds);
        $stand    = self::standing($ids);

        $teams = Database::select(
            'SELECT tl.id, tl.name, lm.name AS leader FROM team_leaders tl LEFT JOIN members lm ON lm.id = tl.leader_member_id
             WHERE tl.is_active = 1' . ($f['team'] > 0 ? ' AND tl.id = ?' : '') . ' ORDER BY tl.name',
            $f['team'] > 0 ? [$f['team']] : []
        );
        $rows = [];
        foreach ($teams as $t) {
            $row = ['team' => (string) $t['name'], 'leader' => (string) ($t['leader'] ?? '-'), 'members' => 0, 'saved' => 0, 'paid' => 0, 'lent' => 0, 'savings' => 0, 'outstanding' => 0, 'overdue' => 0];
            foreach ($members as $m) {
                if ($m['team_id'] !== (int) $t['id']) {
                    continue;
                }
                $row['members']++;
                $row['saved'] += array_sum($saved[$m['id']] ?? []);
                $row['paid']  += array_sum($paid[$m['id']] ?? []);
                $row['lent']  += array_sum($lent[$m['id']] ?? []);
                $row['savings']     += $stand[$m['id']]['savings'];
                $row['outstanding'] += $stand[$m['id']]['outstanding'];
                $row['overdue']     += $stand[$m['id']]['overdue'];
            }
            $rows[] = $row;
        }
        $base['rows']   = $rows;
        $base['totals'] = self::sumRow($columns, $rows, 'Jumlah');
        return $base;
    }

    /**
     * Laporan transaksi: semua jenis, rentang tanggal, dengan pembuat dan validator.
     * @param array<string,mixed>|null $user @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function transactionReport(?array $user, array $f, int $page, bool $all): array
    {
        [$mScope, $params] = self::memberCondition($user, 't.member_id');
        $scope = match (self::level($user)) {
            self::TEAM => "({$mScope} OR t.created_by = ?)",
            default    => "({$mScope})",
        };
        if (self::level($user) === self::TEAM) {
            $params[] = (int) $user['id'];
        }
        $where = ['t.deleted_at IS NULL', $scope];
        if ($f['from'] !== '') {
            $where[]  = 't.trx_date >= ?';
            $params[] = $f['from'];
        }
        if ($f['to'] !== '') {
            $where[]  = 't.trx_date <= ?';
            $params[] = $f['to'];
        }
        if ($f['type'] !== '') {
            $where[]  = 't.type = ?';
            $params[] = $f['type'];
        }
        if (in_array($f['status'], Transaction::STATUSES, true)) {
            $where[]  = 't.status = ?';
            $params[] = $f['status'];
        }
        if ($f['team'] > 0) {
            $where[]  = 'a.team_id = ?';
            $params[] = $f['team'];
        }
        if ($f['anggota'] > 0) {
            $where[]  = 't.member_id = ?';
            $params[] = $f['anggota'];
        }
        if ($f['q'] !== '') {
            $like     = '%' . Pagination::likeEscape($f['q']) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR t.doc_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        $from = 'FROM transactions t
                 LEFT JOIN members m ON m.id = t.member_id
                 LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
                 LEFT JOIN team_leaders tl ON tl.id = a.team_id
                 LEFT JOIN users cu ON cu.id = t.created_by';
        $w = implode(' AND ', $where);

        $agg   = Database::select("SELECT COUNT(*) AS n, COALESCE(SUM(IF(t.reverses_id IS NULL, t.amount, -t.amount)), 0) AS net {$from} WHERE {$w}", $params)[0];
        $total = (int) $agg['n'];
        $pager = $all ? Pagination::make($total, 1, 200) : Pagination::make($total, $page, self::PER_PAGE);
        $limit = $all ? self::EXPORT_MAX_ROWS : $pager['per_page'];
        $offset = $all ? 0 : $pager['offset'];

        $rows = Database::select(
            "SELECT t.id, t.doc_no, t.type, t.trx_date, t.amount, t.status, t.reverses_id, t.member_id, m.member_no, m.name AS member_name, tl.name AS team_name,
                    cu.name AS creator_name,
                    (SELECT u.name FROM transaction_validations v JOIN users u ON u.id = v.actor_user_id
                      WHERE v.transaction_id = t.id AND v.to_status IN ('DISETUJUI','DITOLAK') ORDER BY v.id DESC LIMIT 1) AS validator_name
             {$from} WHERE {$w} ORDER BY t.trx_date DESC, t.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        $columns = [
            ['key' => 'date', 'label' => 'Tanggal', 'type' => 'text'], ['key' => 'doc_no', 'label' => 'Dokumen', 'type' => 'text'], ['key' => 'type', 'label' => 'Jenis', 'type' => 'text'],
            ['key' => 'member', 'label' => 'Anggota', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text'], ['key' => 'amount', 'label' => 'Nominal', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'], ['key' => 'creator', 'label' => 'Dicatat oleh', 'type' => 'text'], ['key' => 'validator', 'label' => 'Divalidasi oleh', 'type' => 'text'],
        ];
        $statusLabel = static fn (string $s): string => ucwords(strtolower(str_replace('_', ' ', $s)));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'date' => date_id((string) $r['trx_date']), 'doc_no' => (string) $r['doc_no'] . ($r['reverses_id'] !== null ? ' (pembalik)' : ''),
                'type' => Transaction::TYPE_LABELS[$r['type']] ?? (string) $r['type'],
                'member' => $r['member_name'] === null ? '-' : $r['member_name'] . ' (' . $r['member_no'] . ')', 'team' => (string) ($r['team_name'] ?? '-'),
                'amount' => ($r['reverses_id'] !== null ? -1 : 1) * (int) $r['amount'], 'status' => $statusLabel((string) $r['status']),
                'creator' => (string) ($r['creator_name'] ?? 'Impor Excel'), 'validator' => (string) ($r['validator_name'] ?? '-'),
                '_links' => ['doc_no' => '/transaksi/' . (int) $r['id']] + ($r['member_id'] !== null ? ['member' => '/anggota/' . (int) $r['member_id']] : []),
            ];
        }
        $totals = ['date' => '', 'doc_no' => 'Jumlah ' . $total . ' transaksi', 'type' => '', 'member' => '', 'team' => '', 'status' => '', 'creator' => '', 'validator' => ''];
        // Nominal bersih hanya bermakna untuk satu jenis (simpanan dan penarikan tidak boleh dijumlah).
        $totals['amount'] = $f['type'] !== '' ? (int) $agg['net'] : null;
        return [
            'key' => 'transaksi', 'title' => 'Laporan Transaksi', 'subtitle' => self::describeFilters($f), 'columns' => $columns, 'rows' => $out, 'totals' => $totals,
            'pager' => $pager, 'note' => 'Nominal pembalik bertanda minus. Jumlah nominal hanya ditampilkan bila satu jenis dipilih.',
        ];
    }

    /** @param array<string,mixed> $f */
    private static function describeFilters(array $f): string
    {
        $parts = [];
        $parts[] = $f['from'] !== '' || $f['to'] !== '' ? 'Tanggal ' . ($f['from'] !== '' ? date_id($f['from']) : 'awal') . ' s.d. ' . ($f['to'] !== '' ? date_id($f['to']) : 'sekarang') : 'Semua tanggal';
        $parts[] = $f['type'] !== '' ? Transaction::TYPE_LABELS[$f['type']] : 'Semua jenis';
        $parts[] = in_array($f['status'], Transaction::STATUSES, true) ? 'Status ' . strtolower(str_replace('_', ' ', $f['status'])) : 'Semua status';
        return implode(' · ', $parts);
    }

    /**
     * Baris jumlah untuk kolom money/int (kolom lain dikosongkan; kolom pertama berisi keterangan).
     * @param array<int,array{key:string,type:string}> $columns @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function sumRow(array $columns, array $rows, string $label): array
    {
        $t = [];
        foreach ($columns as $i => $c) {
            if (in_array($c['type'], ['money', 'int'], true) && $c['key'] !== 'tenor') {
                $t[$c['key']] = (int) array_sum(array_column($rows, $c['key']));
            } else {
                $t[$c['key']] = $i === 0 ? $label : '';
            }
        }
        return $t;
    }

    // ------------------------------------------------------------------ kartu anggota

    /**
     * Laporan per anggota (kartu). Hanya bila anggota dalam cakupan pengguna; selain itu null.
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>|null
     */
    public static function statement(?array $user, int $memberId): ?array
    {
        [$scope, $params] = self::memberCondition($user, 'm.id');
        $m = Database::select(
            "SELECT m.id, m.member_no, m.name, m.status, m.active_from, tl.name AS team_name, lm.name AS leader_name
             FROM members m
             LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
             LEFT JOIN team_leaders tl ON tl.id = a.team_id LEFT JOIN members lm ON lm.id = tl.leader_member_id
             WHERE m.id = ? AND m.deleted_at IS NULL AND ({$scope})",
            array_merge([$memberId], $params)
        )[0] ?? null;
        if ($m === null) {
            return null;
        }
        $summary = \App\Models\Dashboard::member($memberId);

        $months = Database::select(
            "SELECT pm.month_date,
                    COALESCE(SUM(CASE WHEN l.type = 'SIMPANAN' THEN l.sign * l.amount END), 0) AS simpanan,
                    COALESCE(SUM(CASE WHEN l.type = 'ANGSURAN' THEN l.sign * l.amount END), 0) AS angsuran,
                    COALESCE(SUM(CASE WHEN l.type = 'PENCAIRAN_PINJAMAN' THEN l.sign * l.amount END), 0) AS pencairan,
                    COALESCE(SUM(l.savings_delta), 0) AS tabungan
             FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id
             WHERE l.member_id = ? GROUP BY pm.id, pm.month_date ORDER BY pm.month_date",
            [$memberId]
        );
        $running = 0;
        $monthly = [];
        foreach ($months as $r) {
            $running += (int) $r['tabungan'];
            $monthly[] = ['month' => (string) $r['month_date'], 'simpanan' => (int) $r['simpanan'], 'angsuran' => (int) $r['angsuran'], 'pencairan' => (int) $r['pencairan'], 'saldo' => $running];
        }

        $loans = [];
        foreach ($summary['loans'] as $l) {
            $loans[] = $l + ['installments' => Database::select(
                'SELECT s.seq, s.due_month, s.amount_due, s.paid_amount, s.remaining_amount FROM v_installment_status s
                 JOIN loans ln ON ln.id = s.loan_id WHERE ln.transaction_id = ? ORDER BY s.seq',
                [(int) $l['transaction_id']]
            )];
        }

        return [
            'member' => $m, 'summary' => $summary, 'monthly' => $monthly, 'loans' => $loans,
            'transactions' => self::build('transaksi', $user, ['period' => 0, 'team' => 0, 'q' => '', 'from' => '', 'to' => '', 'type' => '', 'status' => '', 'anggota' => $memberId, 'bulan' => 0], 1, true),
        ];
    }
}
