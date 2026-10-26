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

    public const KEYS = ['simpanan', 'pinjaman', 'angsuran', 'saldo', 'transaksi', 'regu', 'bagihasil', 'shu'];
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

    public const PRESETS = ['bulan-ini', 'bulan-lalu', 'tahun-ini', 'tahun-lalu'];
    public const MAX_RANGE_MONTHS = 60;

    /**
     * Rentang cepat -> [dari, sampai] (Y-m-d, inklusif, zona waktu aplikasi).
     * @return array{0:string,1:string}
     */
    public static function presetRange(string $preset, ?string $today = null): array
    {
        $t = new \DateTimeImmutable($today ?? date('Y-m-d'));
        return match ($preset) {
            'bulan-ini'  => [$t->format('Y-m-01'), $t->format('Y-m-t')],
            'bulan-lalu' => [$t->modify('first day of last month')->format('Y-m-d'), $t->modify('last day of last month')->format('Y-m-d')],
            'tahun-ini'  => [$t->format('Y-01-01'), $t->format('Y-12-31')],
            'tahun-lalu' => [($t->format('Y') - 1) . '-01-01', ($t->format('Y') - 1) . '-12-31'],
            default      => ['', ''],
        };
    }

    /**
     * Rentang tanggal aktif, atau null bila laporan memakai pilihan Periode. Ujung yang dikosongkan diisi: awal = awal periode
     * paling awal, akhir = hari ini. Inklusif: transaksi pada tanggal akhir (jam berapa pun) ikut terhitung.
     * @param array<string,mixed> $f
     * @return array{from:string,to:string,open_from:bool,open_to:bool}|null
     */
    public static function rangeOf(array $f): ?array
    {
        $from = (string) ($f['from'] ?? '');
        $to   = (string) ($f['to'] ?? '');
        if ($from === '' && $to === '') {
            return null;
        }
        $openFrom = $from === '';
        $openTo   = $to === '';
        if ($openFrom) {
            $min  = Database::select('SELECT MIN(start_date) AS d FROM periods')[0]['d'] ?? null;
            $from = $min !== null ? (string) $min : date('Y-m-01');
        }
        if ($openTo) {
            $to = date('Y-m-d');
        }
        return ['from' => $from, 'to' => $to, 'open_from' => $openFrom, 'open_to' => $openTo];
    }

    /**
     * Penanda periode laporan yang sedang aktif, untuk judul/indikator tampilan dan Excel.
     * @param array<string,mixed> $f
     */
    public static function periodLabel(array $f): string
    {
        $r = self::rangeOf($f);
        if ($r === null) {
            return 'Periode ' . self::periodName($f);
        }
        return 'Tanggal ' . date_id($r['from']) . ' s.d. ' . date_id($r['to']);
    }

    /**
     * Kolom bulan kalender dalam rentang (maksimum MAX_RANGE_MONTHS), untuk rekap bulanan berdasarkan TANGGAL transaksi.
     * @param array{from:string,to:string} $r
     * @return array<int,array{key:string,label:string,ym:string}>
     */
    private static function calendarColumns(array $r): array
    {
        $cur  = new \DateTimeImmutable(substr($r['from'], 0, 7) . '-01');
        $end  = new \DateTimeImmutable(substr($r['to'], 0, 7) . '-01');
        $cols = [];
        while ($cur <= $end && count($cols) < self::MAX_RANGE_MONTHS) {
            $cols[] = ['key' => 'c' . $cur->format('Ym'), 'label' => month_label($cur->format('Y-m-d')), 'ym' => $cur->format('Ym')];
            $cur = $cur->modify('+1 month');
        }
        return $cols;
    }

    /**
     * Jumlah bersih per anggota per bulan kalender (menurut tanggal transaksi) dalam rentang.
     * @param array<int,int> $memberIds @param array{from:string,to:string} $r
     * @return array<int,array<string,int>> [anggota]['c202601'] => rupiah
     */
    private static function pivotRange(string $type, array $memberIds, array $r): array
    {
        if ($memberIds === []) {
            return [];
        }
        $mIn = implode(',', array_map('intval', $memberIds));
        $out = [];
        foreach (Database::select(
            "SELECT member_id, DATE_FORMAT(trx_date, '%Y%m') AS ym, SUM(sign * amount) AS v FROM v_ledger
             WHERE type = ? AND member_id IN ({$mIn}) AND trx_date BETWEEN ? AND ? GROUP BY member_id, ym",
            [$type, $r['from'], $r['to']]
        ) as $row) {
            $out[(int) $row['member_id']]['c' . $row['ym']] = (int) $row['v'];
        }
        return $out;
    }

    /** Keadaan pada tanggal akhir rentang bila tanggal itu sudah lewat; null = keadaan sekarang. */
    private static function asOfDate(?array $r): ?string
    {
        return $r !== null && !AsOf::isCurrent($r['to']) ? $r['to'] : null;
    }

    /**
     * Saringan dari query string, sudah dibersihkan dan dibatasi. Periode tak dikenal jatuh ke periode aktif.
     * @param array<string,mixed> $q
     * @param array<string,mixed>|null $user
     * @return array{period:int,team:int,q:string,from:string,to:string,preset:string,range_error:string,type:string,status:string,anggota:int,bulan:int,snap:int}
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

        // Rentang tanggal bebas. Tanggal yang diisi menang; "rentang cepat" hanya dipakai bila kedua tanggal kosong.
        $from   = $date($q['dari'] ?? '');
        $to     = $date($q['sampai'] ?? '');
        $preset = (string) ($q['rentang'] ?? '');
        if (!in_array($preset, self::PRESETS, true)) {
            $preset = '';
        }
        if ($from === '' && $to === '' && $preset !== '') {
            [$from, $to] = self::presetRange($preset);
        } elseif ($from !== '' || $to !== '') {
            $preset = '';
        }
        $rangeError = '';
        if ($from !== '' && $to !== '' && $from > $to) {
            $rangeError = 'Tanggal mulai tidak boleh setelah tanggal akhir. Rentang tanggal diabaikan.';
            $from = '';
            $to = '';
            $preset = '';
        }
        // Rentang tanpa periode yang dipilih sendiri: pakai periode yang memuat tanggal akhir (atau tanggal mulai), supaya
        // laporan yang menurut periode (Kas SHU, Pembagian Bunga) membuka program yang benar walau sudah lama berlalu.
        if (!in_array($period, $valid, true) && ($from !== '' || $to !== '')) {
            $probe = $to !== '' ? $to : $from;
            $hit   = Database::select('SELECT id FROM periods WHERE start_date <= ? AND end_date >= ? ORDER BY start_date DESC LIMIT 1', [$probe, $probe])[0]['id'] ?? null;
            if ($hit !== null) {
                $period = (int) $hit;
                $default = (int) $hit;
            }
        }
        return [
            'period'  => in_array($period, $valid, true) ? $period : (int) $default,
            'team'    => self::level($user) === self::ALL ? max(0, (int) ($q['regu'] ?? 0)) : 0,
            'q'       => mb_substr(clean_text($q['q'] ?? ''), 0, 60),
            'from'    => $from,
            'to'      => $to,
            'preset'  => $preset,
            'range_error' => $rangeError,
            'type'    => isset(Transaction::TYPE_LABELS[(string) ($q['jenis'] ?? '')]) ? (string) $q['jenis'] : '',
            'status'  => in_array($status, array_merge(Transaction::STATUSES, ['AKTIF', 'LUNAS']), true) ? $status : '',
            'anggota' => max(0, (int) ($q['anggota'] ?? 0)),
            'bulan'   => max(0, (int) ($q['bulan'] ?? 0)),
            'snap'    => max(0, (int) ($q['snapshot'] ?? 0)),
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
    private static function standing(array $memberIds, ?string $asOf = null): array
    {
        if ($memberIds === []) {
            return [];
        }
        $in  = implode(',', array_map('intval', $memberIds));
        $out = [];
        foreach ($memberIds as $id) {
            $out[(int) $id] = ['savings' => 0, 'outstanding' => 0, 'overdue' => 0];
        }
        if ($asOf !== null && !AsOf::isCurrent($asOf)) {
            // Rentang berakhir di masa lalu: keadaan pada tanggal akhir itu, bukan keadaan sekarang.
            foreach (AsOf::savings($memberIds, $asOf) as $id => $v) {
                $out[$id]['savings'] = $v;
            }
            foreach (AsOf::outstanding($memberIds, $asOf) as $id => $v) {
                $out[$id]['outstanding'] = $v;
            }
            foreach (AsOf::overdue($memberIds, $asOf) as $id => $v) {
                $out[$id]['overdue'] = $v;
            }
            return $out;
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
            'bagihasil' => ProfitReport::build($user, $f),
            'shu'       => ShuReport::build($user, $f),
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
        $range   = self::rangeOf($f);
        $members = self::members($user, $f);
        $ids     = array_column($members, 'id');
        if ($range === null) {
            $months = self::months($f['period']);
            $pivot  = [];
            foreach (self::pivot($type, $ids, array_column($months, 'id')) as $memberId => $byMonth) {
                foreach ($byMonth as $monthId => $v) {
                    $pivot[$memberId]['m' . $monthId] = $v;
                }
            }
            $cols   = array_map(static fn (array $m): array => ['key' => 'm' . $m['id'], 'label' => month_label($m['month']), 'type' => 'money'], $months);
            $stand  = self::standing($ids);
        } else {
            // Rentang bebas: bulan kalender menurut TANGGAL transaksi; saldo, sisa, dan tunggakan pada tanggal akhir.
            $cal   = self::calendarColumns($range);
            $pivot = self::pivotRange($type, $ids, $range);
            $cols  = array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'type' => 'money'], $cal);
            $stand = self::standing($ids, self::asOfDate($range));
            $note  = ($key === 'simpanan'
                ? 'Setoran bersih per bulan menurut tanggal transaksi dalam rentang (pembalik mengurangi); saldo tabungan = keadaan pada '
                : 'Angsuran bersih per bulan menurut tanggal pembayaran dalam rentang; sisa pinjaman dan tunggakan = keadaan pada ')
                . date_id(self::asOfDate($range) ?? date('Y-m-d')) . '.';
        }

        $columns = array_merge(
            [['key' => 'no', 'label' => 'No. Anggota', 'type' => 'text'], ['key' => 'name', 'label' => 'Nama', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text']],
            $cols,
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
            foreach ($cols as $c) {
                $v = $pivot[$m['id']][$c['key']] ?? 0;
                $row[$c['key']] = $v;
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
            'key' => $key, 'title' => $title, 'subtitle' => self::periodLabel($f) . ' · rupiah', 'columns' => $columns, 'rows' => $rows,
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
        $range   = self::rangeOf($f);
        $members = self::members($user, $f);
        $ids     = array_column($members, 'id');
        $delta   = [];   // [anggota][kunci kolom] => selisih
        $base    = [];   // saldo sebelum kolom pertama
        if ($range === null) {
            $months = self::months($f['period']);
            $cols   = array_map(static fn (array $m): array => ['key' => 'm' . $m['id'], 'label' => month_label($m['month']), 'type' => 'money'], $months);
            if ($ids !== [] && $months !== []) {
                $in = implode(',', array_map('intval', $ids));
                foreach (Database::select(
                    "SELECT l.member_id, l.period_month_id, SUM(l.savings_delta) AS v FROM v_ledger l
                     WHERE l.member_id IN ({$in}) AND l.period_month_id IN (" . implode(',', array_column($months, 'id')) . ') GROUP BY l.member_id, l.period_month_id'
                ) as $r) {
                    $delta[(int) $r['member_id']]['m' . $r['period_month_id']] = (int) $r['v'];
                }
                foreach (Database::select(
                    "SELECT l.member_id, SUM(l.savings_delta) AS v FROM v_ledger l JOIN period_months pm ON pm.id = l.period_month_id
                     WHERE l.member_id IN ({$in}) AND pm.month_date < ? GROUP BY l.member_id",
                    [$months[0]['month']]
                ) as $r) {
                    $base[(int) $r['member_id']] = (int) $r['v'];
                }
            }
            $note = 'Saldo tabungan kumulatif (termasuk sebelum periode ini). Porsi = bagian anggota dari total tabungan pada bulan terakhir.';
        } else {
            // Rentang bebas: saldo awal = semua sebelum tanggal mulai; lalu saldo akhir tiap bulan kalender (menurut tanggal transaksi).
            $cols = array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'type' => 'money'], self::calendarColumns($range));
            if ($ids !== []) {
                $in = implode(',', array_map('intval', $ids));
                foreach (Database::select(
                    "SELECT member_id, DATE_FORMAT(trx_date, '%Y%m') AS ym, SUM(savings_delta) AS v FROM v_ledger
                     WHERE member_id IN ({$in}) AND trx_date BETWEEN ? AND ? GROUP BY member_id, ym",
                    [$range['from'], $range['to']]
                ) as $r) {
                    $delta[(int) $r['member_id']]['c' . $r['ym']] = (int) $r['v'];
                }
                foreach (Database::select("SELECT member_id, SUM(savings_delta) AS v FROM v_ledger WHERE member_id IN ({$in}) AND trx_date < ? GROUP BY member_id", [$range['from']]) as $r) {
                    $base[(int) $r['member_id']] = (int) $r['v'];
                }
            }
            $note = 'Saldo tabungan kumulatif: saldo awal = semua transaksi sebelum ' . date_id($range['from']) . ', lalu saldo akhir tiap bulan menurut tanggal transaksi. Porsi = bagian anggota dari total tabungan pada bulan terakhir.';
        }

        $columns = array_merge(
            [['key' => 'no', 'label' => 'No. Anggota', 'type' => 'text'], ['key' => 'name', 'label' => 'Nama', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text']],
            $cols,
            [['key' => 'share', 'label' => 'Porsi', 'type' => 'pct']]
        );
        $rows = [];
        $last = [];
        foreach ($members as $m) {
            $row = ['no' => $m['member_no'], 'name' => $m['name'], 'team' => $m['team_name'], '_links' => ['name' => '/anggota/' . $m['id']]];
            $run = $base[$m['id']] ?? 0;
            foreach ($cols as $c) {
                $run += $delta[$m['id']][$c['key']] ?? 0;
                $row[$c['key']] = $run;
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
        foreach ($cols as $c) {
            $totals[$c['key']] = array_sum(array_column($rows, $c['key']));
        }
        $totals['share'] = $grand > 0 ? 10000 : 0;
        return [
            'key' => 'saldo', 'title' => 'Rekap Saldo', 'subtitle' => self::periodLabel($f) . ' · rupiah, saldo akhir tiap bulan', 'columns' => $columns, 'rows' => $rows,
            'totals' => $totals, 'note' => $note,
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
        $range = self::rangeOf($f);
        if ($range === null) {
            $where  = ['pm.period_id = ?', "({$scope})"];
            $params = array_merge([$f['period']], $params);
        } else {
            // Rentang bebas: pinjaman yang DICAIRKAN (tanggal transaksi pencairan) dalam rentang, inklusif.
            $where  = ['t.trx_date BETWEEN ? AND ?', "({$scope})"];
            $params = array_merge([$range['from'], $range['to']], $params);
        }
        if ($f['team'] > 0) {
            $where[]  = 'a.team_id = ?';
            $params[] = $f['team'];
        }
        if ($f['q'] !== '') {
            $like     = '%' . Pagination::likeEscape($f['q']) . '%';
            $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\' OR b.loan_no LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        // Status Aktif/Lunas pada rentang yang berakhir di masa lalu mengikuti keadaan pada tanggal akhir, jadi disaring setelah dihitung.
        $asOf = self::asOfDate($range);
        if ($asOf === null && in_array($f['status'], ['AKTIF', 'LUNAS'], true)) {
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
        $states = $asOf === null ? [] : AsOf::loanStates(array_map(static fn (array $r): int => (int) $r['loan_id'], $rows), $asOf);
        $out = [];
        foreach ($rows as $r) {
            $paid    = (int) $r['paid'];
            $overdue = (int) $r['overdue'];
            if ($asOf !== null) {
                $paid    = $states[(int) $r['loan_id']]['paid'];
                $overdue = $states[(int) $r['loan_id']]['overdue'];
            }
            $outstanding = (int) $r['total_due'] - $paid;
            $isPaid      = $outstanding <= 0;
            if ($asOf !== null && (($f['status'] === 'AKTIF' && $isPaid) || ($f['status'] === 'LUNAS' && !$isPaid))) {
                continue;
            }
            $out[] = [
                'loan_no' => (string) $r['loan_no'], 'name' => $r['name'] . ' (' . $r['member_no'] . ')', 'team' => (string) ($r['team_name'] ?? '-'), 'month' => month_label((string) $r['month_date']),
                'tenor' => (int) $r['tenor_months'], 'principal' => (int) $r['principal'], 'interest' => (int) $r['total_interest'], 'due' => (int) $r['total_due'],
                'paid' => $paid, 'outstanding' => $asOf === null ? (int) $r['outstanding'] : $outstanding, 'overdue' => $overdue, 'status' => ($asOf === null ? $r['loan_status'] === 'LUNAS' : $isPaid) ? 'Lunas' : 'Aktif',
                '_links' => ['loan_no' => '/transaksi/' . (int) $r['transaction_id'], 'name' => '/anggota/' . (int) $r['member_id']],
            ];
        }
        $stateText = $asOf === null ? 'keadaan sekarang' : 'keadaan pada ' . date_id($asOf);
        return [
            'key' => 'pinjaman', 'title' => 'Rekap Pinjaman', 'subtitle' => ($range === null ? 'Dicairkan pada periode ' . self::periodName($f) : 'Dicairkan ' . date_id($range['from']) . ' s.d. ' . date_id($range['to'])) . ' · rupiah', 'columns' => $columns, 'rows' => $out,
            'totals' => self::sumRow($columns, $out, 'Jumlah ' . count($out) . ' pinjaman'), 'note' => 'Hanya pinjaman yang berlaku (disetujui dan belum dibalik). Terbayar, sisa, dan tunggakan = ' . $stateText . '.',
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
            ['key' => 'interest', 'label' => 'Total bunga', 'type' => 'money'], ['key' => 'cash', 'label' => 'Arus kas regu', 'type' => 'money'],
        ];
        $base = ['key' => 'regu', 'title' => 'Laporan Per Ketua Regu', 'subtitle' => self::periodLabel($f) . ' · rupiah', 'columns' => $columns, 'rows' => [], 'totals' => null,
                 'note' => 'Anggota menurut keanggotaan regu saat ini. Setoran, angsuran, dan pencairan = jumlah bersih dalam periode; tabungan, sisa, tunggakan = keadaan sekarang. Total bunga = bunga dibukukan di muka dari pinjaman berlaku. Arus kas regu = setoran + angsuran − pencairan − penarikan atas regu itu (bukan uang fisik); jumlah semua regu berbeda dari kas koperasi sebesar biaya yang belum dibebankan ke regu.'];
        if (self::level($user) !== self::ALL) {
            return $base;
        }
        $range   = self::rangeOf($f);
        $members = self::members($user, ['team' => $f['team'], 'q' => '']);
        $ids     = array_column($members, 'id');
        if ($range === null) {
            $monthIds = array_column(self::months($f['period']), 'id');
            $saved    = self::pivot('SIMPANAN', $ids, $monthIds);
            $paid     = self::pivot('ANGSURAN', $ids, $monthIds);
            $lent     = self::pivot('PENCAIRAN_PINJAMAN', $ids, $monthIds);
            $stand    = self::standing($ids);
        } else {
            // Rentang bebas: arus menurut tanggal transaksi; tabungan, sisa, tunggakan pada tanggal akhir (Total bunga dan arus kas regu = sekarang).
            $saved = self::pivotRange('SIMPANAN', $ids, $range);
            $paid  = self::pivotRange('ANGSURAN', $ids, $range);
            $lent  = self::pivotRange('PENCAIRAN_PINJAMAN', $ids, $range);
            $stand = self::standing($ids, self::asOfDate($range));
            $base['subtitle'] = self::periodLabel($f) . ' · rupiah';
            $base['note'] .= ' Dengan rentang tanggal: arus menurut tanggal transaksi dalam rentang; tabungan, sisa, dan tunggakan menurut keadaan pada tanggal akhir; total bunga dan arus kas regu tetap keadaan sekarang.';
        }

        $teams = Database::select(
            'SELECT tl.id, tl.name, lm.name AS leader FROM team_leaders tl LEFT JOIN members lm ON lm.id = tl.leader_member_id
             WHERE tl.is_active = 1' . ($f['team'] > 0 ? ' AND tl.id = ?' : '') . ' ORDER BY tl.name',
            $f['team'] > 0 ? [$f['team']] : []
        );
        $rows = [];
        foreach ($teams as $t) {
            $row = ['team' => (string) $t['name'], 'leader' => (string) ($t['leader'] ?? '-'), 'members' => 0, 'saved' => 0, 'paid' => 0, 'lent' => 0, 'savings' => 0, 'outstanding' => 0, 'overdue' => 0, 'interest' => 0, 'cash' => 0];
            // Total bunga dan arus kas regu: definisi yang SAMA dengan dashboard Ketua Regu (TeamFinance).
            $fin = TeamFinance::summary((int) $t['id']);
            $row['interest'] = $fin['interest'];
            $row['cash'] = $fin['cash'];
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
                'SELECT s.seq, s.due_month, s.due_date, s.amount_due, s.paid_amount, s.remaining_amount FROM v_installment_status s
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
