<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

/**
 * Report Pembagian Bunga per anggota. SATU sumber rumus: ProfitShare (mengikuti Excel koperasi, lihat catatan di kelasnya).
 * Kelas ini hanya menyajikan, menyaring menurut cakupan pengguna, dan menyimpan snapshot; ia tidak menghitung ulang rumus.
 *
 * Dua sumber angka:
 *   langsung : dihitung dari transaksi DISETUJUI saat laporan dibuka (perkiraan; bisa berubah saat data baru masuk)
 *   snapshot : hasil hitung yang disimpan Superadmin (tabel profit_share_snapshots/lines, hanya-tambah), tidak berubah.
 *              Dapat DIBATALKAN (profit_share_snapshot_voids, juga hanya-tambah): snapshot tetap tersimpan dan terbaca,
 *              tetapi ditandai batal dan tidak dihitung sebagai penetapan yang berlaku.
 *
 * Cakupan data ditegakkan di sini (bukan di tampilan): anggota yang tampil dibatasi lewat ReportService::members; angka global
 * (pool bunga, total saldo semua anggota, total dasar peminjam) hanya dikembalikan untuk pengguna cakupan seluruh koperasi.
 */
final class ProfitReport
{
    public const SOURCE_LIVE = 'langsung';
    public const SOURCE_SNAPSHOT = 'snapshot';

    // ------------------------------------------------------------------ data sumber

    /**
     * Semua snapshot periode ini, termasuk yang dibatalkan (untuk riwayat). 'voided' = null bila masih berlaku.
     * @return array<int,array{id:int,as_of:string,created_at:string,created_by:string,note:?string,voided:?array{reason:string,at:string,by:string}}> terbaru dulu
     */
    public static function snapshots(int $periodId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'as_of' => (string) $r['as_of_month'], 'created_at' => (string) $r['created_at'],
            'created_by' => (string) ($r['name'] ?? '-'), 'note' => $r['note'] === null ? null : (string) $r['note'],
            'voided' => $r['void_id'] === null ? null : ['reason' => (string) $r['void_reason'], 'at' => (string) $r['voided_at'], 'by' => (string) ($r['void_by'] ?? '-')],
        ], Database::select(
            'SELECT s.id, s.as_of_month, s.created_at, s.note, u.name, v.id AS void_id, v.reason AS void_reason, v.voided_at, vu.name AS void_by
             FROM profit_share_snapshots s
             LEFT JOIN users u ON u.id = s.created_by
             LEFT JOIN profit_share_snapshot_voids v ON v.snapshot_id = s.id
             LEFT JOIN users vu ON vu.id = v.voided_by
             WHERE s.period_id = ? ORDER BY s.as_of_month DESC, s.id DESC',
            [$periodId]
        ));
    }

    /**
     * Data bagi hasil yang sudah dinormalkan (live atau snapshot). Snapshot yang bukan milik periode ini dianggap tidak ada.
     * @return array<string,mixed>|null null bila snapshot tidak ditemukan
     */
    public static function dataset(int $periodId, int $snapshotId = 0): ?array
    {
        if ($snapshotId <= 0) {
            $r = ProfitShare::compute($periodId, null, true);
            $mode = $r['excel'] ? 'EXCEL' : 'ATURAN';
            return [
                'source' => self::SOURCE_LIVE, 'snapshot_id' => null, 'period_id' => $periodId, 'as_of' => $r['as_of'], 'mode' => $mode,
                'calculated_at' => date('Y-m-d H:i:s'), 'status' => 'Perkiraan (dihitung langsung)',
                'saver_pct' => (int) SettingsService::get('profit_share_saver_pct'), 'borrower_pct' => (int) SettingsService::get('profit_share_borrower_pct'),
                'shu_pct' => (int) SettingsService::get('profit_share_shu_pct'), 'reserve_pct' => (float) SettingsService::get('reserve_pct'),
                'interest_pool' => $r['interest_pool'], 'undistributed' => $r['undistributed'], 'members' => $r['members'], 'cycles' => $r['cycles'] ?? [],
                'voided' => null,
            ];
        }
        $s = Database::select('SELECT * FROM profit_share_snapshots WHERE id = ? AND period_id = ?', [$snapshotId, $periodId])[0] ?? null;
        if ($s === null) {
            return null;
        }
        $voided = null;
        foreach (self::snapshots($periodId) as $sn) {
            if ($sn['id'] === $snapshotId) {
                $voided = $sn['voided'];
            }
        }
        $members = [];
        foreach (Database::select('SELECT * FROM profit_share_lines WHERE snapshot_id = ?', [$snapshotId]) as $l) {
            $members[(int) $l['member_id']] = [
                'saver_gross' => (float) $l['saver_gross'], 'borrower_gross' => (float) $l['borrower_gross'],
                'saver' => (int) $l['saver_net'], 'borrower' => (int) $l['borrower_net'], 'reserve_exempt' => (int) $l['reserve_exempt'] === 1,
                'savings_last' => (int) $l['savings_last'], 'outstanding_last' => (int) $l['outstanding_last'], 'team_id' => $l['team_id'] === null ? null : (int) $l['team_id'],
                'rows' => json_decode((string) ($l['detail_json'] ?? '[]'), true) ?: [],
            ];
        }
        return [
            'source' => self::SOURCE_SNAPSHOT, 'snapshot_id' => (int) $s['id'], 'period_id' => $periodId, 'as_of' => (string) $s['as_of_month'], 'mode' => (string) $s['mode'],
            'calculated_at' => (string) $s['created_at'], 'status' => $voided === null ? 'Snapshot ditetapkan' : 'Snapshot DIBATALKAN',
            'saver_pct' => (int) $s['saver_pct'], 'borrower_pct' => (int) $s['borrower_pct'], 'shu_pct' => (int) $s['shu_pct'], 'reserve_pct' => (float) $s['reserve_pct'],
            'interest_pool' => (float) $s['interest_pool'], 'undistributed' => (float) $s['undistributed'], 'members' => $members,
            'cycles' => json_decode((string) ($s['cycles_json'] ?? '[]'), true) ?: [], 'note' => $s['note'], 'voided' => $voided,
        ];
    }

    // ------------------------------------------------------------------ laporan tabel

    /**
     * Struktur laporan (sama dengan tabel lain di ReportService: dipakai HTML, cetak, dan unduhan Excel).
     * @param array<string,mixed>|null $user @param array<string,mixed> $f hasil ReportService::filters (+ 'snap')
     * @return array<string,mixed>
     */
    public static function build(?array $user, array $f): array
    {
        $global = ReportService::level($user) === ReportService::ALL;
        $columns = [
            ['key' => 'no', 'label' => 'No. Anggota', 'type' => 'text'], ['key' => 'name', 'label' => 'Nama', 'type' => 'text'],
            ['key' => 'team', 'label' => 'Regu', 'type' => 'text'], ['key' => 'leader', 'label' => 'Ketua Regu', 'type' => 'text'],
            ['key' => 'savings', 'label' => 'Saldo tabungan', 'type' => 'money'], ['key' => 'outstanding', 'label' => 'Sisa pinjaman', 'type' => 'money'],
            ['key' => 'gross', 'label' => 'Bagi hasil bruto', 'type' => 'money'], ['key' => 'reserve', 'label' => 'Cadangan', 'type' => 'money'],
            ['key' => 'saver', 'label' => 'Bagi hasil penabung', 'type' => 'money'], ['key' => 'borrower', 'label' => 'Bagi hasil peminjam', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total bagi hasil', 'type' => 'money'],
        ];
        $base = ['key' => 'bagihasil', 'title' => 'Report Pembagian Bunga', 'subtitle' => '', 'columns' => $columns, 'rows' => [], 'totals' => null, 'note' => '', 'summary' => [], 'meta' => null];
        $data = self::dataset((int) $f['period'], (int) ($f['snap'] ?? 0));
        $fallback = $data === null;
        if ($fallback) {
            $data = self::dataset((int) $f['period'], 0);   // snapshot bukan milik periode ini: tampilkan perhitungan langsung
        }

        $leaders = [];
        foreach (Database::select('SELECT tl.id, lm.name FROM team_leaders tl LEFT JOIN members lm ON lm.id = tl.leader_member_id') as $r) {
            $leaders[(int) $r['id']] = (string) ($r['name'] ?? '-');
        }
        $period = '';
        foreach (ReportService::periods() as $p) {
            if ($p['id'] === (int) $f['period']) {
                $period = $p['name'];
            }
        }
        $reservePct = (float) $data['reserve_pct'];
        $rows = [];
        foreach (ReportService::members($user, ['team' => (int) $f['team'], 'q' => (string) $f['q']]) as $m) {
            $d = $data['members'][$m['id']] ?? null;
            $row = [
                'no' => $m['member_no'], 'name' => $m['name'], 'team' => $m['team_name'], 'leader' => $m['team_id'] === null ? '-' : ($leaders[$m['team_id']] ?? '-'),
                'savings' => 0, 'outstanding' => 0, 'gross' => 0, 'reserve' => 0, 'saver' => 0, 'borrower' => 0, 'total' => 0,
                '_links' => ['name' => '/laporan/bagihasil/' . $m['id'] . self::queryFor($f)],
            ];
            if ($d !== null) {
                $gross = (float) $d['saver_gross'] + (float) $d['borrower_gross'];
                $row['savings'] = (int) $d['savings_last'];
                $row['outstanding'] = (int) $d['outstanding_last'];
                $row['gross'] = (int) round($gross);
                $row['reserve'] = $d['reserve_exempt'] ? 0 : (int) round($gross * $reservePct / 100);
                $row['saver'] = (int) $d['saver'];
                $row['borrower'] = (int) $d['borrower'];
                $row['total'] = $row['saver'] + $row['borrower'];
            }
            $rows[] = $row;
        }

        $sum = static fn (string $k): int => (int) array_sum(array_column($rows, $k));
        $totals = ['no' => 'Jumlah', 'name' => '', 'team' => '', 'leader' => '', 'savings' => $sum('savings'), 'outstanding' => $sum('outstanding'), 'gross' => $sum('gross'),
            'reserve' => $sum('reserve'), 'saver' => $sum('saver'), 'borrower' => $sum('borrower'), 'total' => $sum('total')];

        $asOf = $data['as_of'] !== null ? month_label((string) $data['as_of']) : '-';
        $when = date('d-m-Y H:i', (int) strtotime((string) $data['calculated_at']));
        $base['subtitle'] = 'Periode ' . $period . ' · data sampai ' . $asOf . ' · ' . $data['status'] . ' · dihitung ' . $when . ' · rupiah';
        $base['rows'] = $rows;
        $base['totals'] = $rows === [] ? null : $totals;
        $base['note'] = ($fallback ? 'Snapshot yang dipilih tidak ada pada periode ini, jadi yang tampil perhitungan langsung. ' : '') . self::voidText($data) . self::ruleText($data);
        $base['meta'] = ['source' => $data['source'], 'snapshot_id' => $data['snapshot_id'], 'as_of' => $data['as_of'], 'mode' => $data['mode'], 'status' => $data['status'], 'when' => $when, 'global' => $global, 'voided' => $data['voided']];

        // Ringkasan. Angka GLOBAL (pool seluruh koperasi) hanya untuk cakupan seluruh koperasi; yang lain melihat jumlah barisnya saja.
        $base['summary'] = [
            ['label' => 'Total bagi hasil ' . ($global && (int) $f['team'] === 0 && (string) $f['q'] === '' ? 'seluruh anggota' : 'yang ditampilkan'), 'value' => $totals['total'], 'hint' => 'Penabung + peminjam, setelah cadangan'],
            ['label' => 'Bagi hasil penabung', 'value' => $totals['saver'], 'hint' => $data['saver_pct'] . '% dari bunga, menurut saldo tabungan'],
            ['label' => 'Bagi hasil peminjam', 'value' => $totals['borrower'], 'hint' => $data['borrower_pct'] . '% dari bunga, menurut dasar pinjaman'],
            ['label' => 'Cadangan ' . rtrim(rtrim(number_format($reservePct, 2, ',', '.'), '0'), ',') . '%', 'value' => $totals['reserve'], 'hint' => 'Dipotong dari bagi hasil (kecuali anggota yang dikecualikan)'],
        ];
        if ($global) {
            $gross = 0.0;
            foreach ($data['members'] as $dm) {
                $gross += (float) $dm['saver_gross'] + (float) $dm['borrower_gross'];
            }
            $base['control'] = [
                'pool' => (int) round($data['interest_pool']), 'gross' => (int) round($gross), 'undistributed' => (int) round($data['undistributed']),
                'ok' => abs($gross + (float) $data['undistributed'] - (float) $data['interest_pool']) < 1.0,
            ];
        }
        return $base;
    }

    /** Teks aturan yang dipakai (ditampilkan di bawah laporan dan ikut ke Excel). @param array<string,mixed> $data */
    private static function ruleText(array $data): string
    {
        $txt = 'Dasar: bunga yang diterima bulan berikutnya dibagi menurut keadaan akhir bulan sebelumnya: ' . $data['saver_pct'] . '% untuk penabung (porsi = saldo tabungan anggota / total saldo) dan '
            . $data['borrower_pct'] . '% untuk peminjam (porsi = dasar pinjaman anggota / total dasar), ' . $data['shu_pct'] . '% ke Kas SHU. Cadangan '
            . rtrim(rtrim(number_format((float) $data['reserve_pct'], 2, ',', '.'), '0'), ',') . '% dipotong dari bagi hasil anggota kecuali yang dikecualikan; setiap baris dibulatkan ke rupiah. ';
        if ($data['mode'] === 'EXCEL') {
            $txt .= 'Mengikuti perhitungan Excel koperasi: cicilan dianggap dibayar sesuai jadwal (bukan pembayaran nyata), dasar peminjam = sisa pokok sampai Mei lalu sisa bunga sejak Juni, '
                . 'dan bagi hasil penabung bulan Desember tidak dibagikan. ';
        }
        if ($data['source'] === self::SOURCE_LIVE) {
            return $txt . 'Angka ini perkiraan yang dihitung ulang tiap dibuka; simpan snapshot untuk menetapkannya.';
        }
        return $txt . ($data['voided'] === null ? 'Angka ini snapshot yang sudah ditetapkan dan tidak berubah.' : 'Angka ini dari snapshot yang sudah dibatalkan dan hanya disimpan sebagai riwayat.');
    }

    /** Peringatan di awal catatan laporan bila snapshot yang tampil sudah dibatalkan. @param array<string,mixed> $data */
    private static function voidText(array $data): string
    {
        $v = $data['voided'] ?? null;
        if ($v === null) {
            return '';
        }
        return 'SNAPSHOT INI DIBATALKAN pada ' . date('d-m-Y H:i', (int) strtotime((string) $v['at'])) . ' oleh ' . $v['by'] . '. Alasan: ' . $v['reason'] . '. Jangan dipakai sebagai dasar pembagian. ';
    }

    /** Query string saringan aktif untuk tautan ke rincian. @param array<string,mixed> $f */
    public static function queryFor(array $f): string
    {
        $q = array_filter(['periode' => (string) $f['period'], 'snapshot' => (int) ($f['snap'] ?? 0) > 0 ? (string) $f['snap'] : ''], static fn (string $v): bool => $v !== '');
        return $q === [] ? '' : '?' . http_build_query($q);
    }

    // ------------------------------------------------------------------ rincian satu anggota

    /**
     * Rincian per siklus satu anggota, agar bisa ditelusuri. Di luar cakupan pengguna = null (sama dengan tidak ada).
     * @param array<string,mixed>|null $user @param array<string,mixed> $f
     * @return array<string,mixed>|null
     */
    public static function memberDetail(?array $user, int $memberId, array $f): ?array
    {
        $visible = array_values(array_filter(ReportService::members($user, ['team' => 0, 'q' => '']), static fn (array $m): bool => $m['id'] === $memberId));
        if ($visible === []) {
            return null;
        }
        $data = self::dataset((int) $f['period'], (int) ($f['snap'] ?? 0)) ?? self::dataset((int) $f['period'], 0);
        if ($data === null) {
            return null;
        }
        $global = ReportService::level($user) === ReportService::ALL;
        $d = $data['members'][$memberId] ?? ['saver_gross' => 0.0, 'borrower_gross' => 0.0, 'saver' => 0, 'borrower' => 0, 'reserve_exempt' => false, 'savings_last' => 0, 'outstanding_last' => 0, 'rows' => []];
        $cycles = [];
        foreach ($data['cycles'] as $k => $c) {
            $row = $d['rows'][$k] ?? $d['rows'][(string) $k] ?? [];
            $bal = (float) ($row['bal'] ?? 0);
            $out = (float) ($row['out'] ?? 0);
            $line = [
                'k' => (int) $c['k'], 'month' => (string) $c['month'], 'basis' => (string) $c['basis'],
                'savings' => (int) round($bal), 'saver_share' => (float) ($c['sum_savings'] > 0 ? $bal / $c['sum_savings'] : 0), 'saver' => (float) ($row['sg'] ?? 0),
                'dasar' => (int) round($out), 'borrower_share' => (float) ($c['sum_basis'] > 0 ? $out / $c['sum_basis'] : 0), 'borrower' => (float) ($row['bg'] ?? 0),
                'saver_paid' => (bool) $c['saver_paid'], 'borrower_paid' => (bool) $c['borrower_paid'],
            ];
            if ($global) {   // angka seluruh koperasi hanya untuk cakupan global
                $line += ['interest' => (float) $c['interest'], 'saver_pool' => (float) $c['saver_pool'], 'borrower_pool' => (float) $c['borrower_pool'], 'sum_savings' => (float) $c['sum_savings'], 'sum_basis' => (float) $c['sum_basis']];
            }
            $cycles[] = $line;
        }
        $m = $visible[0];
        $gross = (float) $d['saver_gross'] + (float) $d['borrower_gross'];
        return [
            'member' => $m, 'global' => $global, 'data' => ['source' => $data['source'], 'status' => $data['status'], 'as_of' => $data['as_of'], 'mode' => $data['mode'],
                'calculated_at' => $data['calculated_at'], 'saver_pct' => $data['saver_pct'], 'borrower_pct' => $data['borrower_pct'], 'shu_pct' => $data['shu_pct'], 'reserve_pct' => $data['reserve_pct'], 'snapshot_id' => $data['snapshot_id'], 'voided' => $data['voided']],
            'cycles' => $cycles, 'rule' => self::ruleText($data),
            'sum' => ['saver_gross' => (float) $d['saver_gross'], 'borrower_gross' => (float) $d['borrower_gross'], 'gross' => $gross,
                'reserve' => $d['reserve_exempt'] ? 0.0 : $gross * (float) $data['reserve_pct'] / 100, 'exempt' => (bool) $d['reserve_exempt'],
                'saver' => (int) $d['saver'], 'borrower' => (int) $d['borrower'], 'total' => (int) $d['saver'] + (int) $d['borrower'],
                'savings' => (int) $d['savings_last'], 'outstanding' => (int) $d['outstanding_last']],
        ];
    }

    // ------------------------------------------------------------------ snapshot

    /**
     * Simpan hasil hitung saat ini sebagai snapshot (hanya-tambah). Satu snapshot BERLAKU per (periode, bulan data): mencegah
     * penetapan ganda untuk keadaan data yang sama; snapshot yang sudah dibatalkan tidak dihitung. Tidak mengubah transaksi atau saldo apa pun.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function createSnapshot(Request $request, array $actor, int $periodId, ?string $note): int
    {
        $note = $note === null ? null : mb_substr(clean_text($note), 0, 255);
        $note = $note === '' ? null : $note;
        $r = ProfitShare::compute($periodId, null, true);
        if ($r['as_of'] === null || $r['members'] === []) {
            throw RuleViolation::field('_form', 'Belum ada data transaksi pada periode ini, jadi belum ada yang bisa disimpan.');
        }
        $reservePct = (float) SettingsService::get('reserve_pct');
        $team = [];
        foreach (Database::select('SELECT member_id, team_id FROM member_team_assignments WHERE valid_to IS NULL') as $a) {
            $team[(int) $a['member_id']] = (int) $a['team_id'];
        }
        $gross = 0.0;
        $reserve = 0.0;
        $saver = 0;
        $borrower = 0;
        foreach ($r['members'] as $m) {
            $g = $m['saver_gross'] + $m['borrower_gross'];
            $gross += $g;
            $reserve += $m['reserve_exempt'] ? 0.0 : $g * $reservePct / 100;
            $saver += $m['saver'];
            $borrower += $m['borrower'];
        }

        return Database::transaction(static function (\PDO $pdo) use ($request, $actor, $periodId, $note, $r, $team, $gross, $reserve, $saver, $borrower, $reservePct): int {
            // Kunci baris periode: dua penetapan bersamaan untuk periode yang sama berjalan bergantian, jadi pemeriksaan ganda di bawah andal
            // (indeks unik diganti di migrasi 010 supaya snapshot yang dibatalkan boleh ditetapkan ulang).
            $pdo->prepare('SELECT id FROM periods WHERE id = ? FOR UPDATE')->execute([$periodId]);
            $dup = $pdo->prepare(
                'SELECT s.id FROM profit_share_snapshots s LEFT JOIN profit_share_snapshot_voids v ON v.snapshot_id = s.id
                 WHERE s.period_id = ? AND s.as_of_month = ? AND v.id IS NULL'
            );
            $dup->execute([$periodId, $r['as_of']]);
            if ($dup->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Snapshot untuk data per ' . month_label((string) $r['as_of']) . ' sudah ada. Pembagian tidak boleh ditetapkan dua kali untuk keadaan data yang sama.');
            }
            $pdo->prepare(
                'INSERT INTO profit_share_snapshots (period_id, as_of_month, mode, saver_pct, borrower_pct, shu_pct, reserve_pct, interest_pool, undistributed, gross_total, reserve_total, saver_total, borrower_total, cycles_json, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $periodId, $r['as_of'], $r['excel'] ? 'EXCEL' : 'ATURAN', (int) SettingsService::get('profit_share_saver_pct'), (int) SettingsService::get('profit_share_borrower_pct'),
                (int) SettingsService::get('profit_share_shu_pct'), $reservePct, (int) round($r['interest_pool']), (int) round($r['undistributed']), (int) round($gross), (int) round($reserve),
                $saver, $borrower, json_encode($r['cycles'], JSON_UNESCAPED_UNICODE), $note, $actor['id'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare(
                'INSERT INTO profit_share_lines (snapshot_id, member_id, team_id, reserve_exempt, saver_gross, borrower_gross, saver_net, borrower_net, savings_last, outstanding_last, detail_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($r['members'] as $mid => $m) {
                $ins->execute([
                    $id, $mid, $team[$mid] ?? null, $m['reserve_exempt'] ? 1 : 0, round($m['saver_gross'], 4), round($m['borrower_gross'], 4), $m['saver'], $m['borrower'],
                    $m['savings_last'], $m['outstanding_last'], json_encode($m['rows'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }
            AuditLog::record($request, $actor, 'PROFIT_SNAPSHOT_CREATED', 'profit_snapshot', $id, month_label((string) $r['as_of']), null, [
                'period_id' => $periodId, 'as_of' => $r['as_of'], 'anggota' => count($r['members']), 'penabung' => $saver, 'peminjam' => $borrower, 'tak_terbagi' => (int) round($r['undistributed']), 'catatan' => $note,
            ], $pdo);
            return $id;
        });
    }
    /**
     * Batalkan snapshot (hanya-tambah): snapshot dan barisnya TIDAK diubah atau dihapus; yang ditambah satu catatan
     * pembatalan berisi alasan, pelaku, dan waktu. Sesudahnya bulan data yang sama boleh ditetapkan ulang. Tidak mengubah
     * transaksi, saldo, atau kas. Hanya sekali per snapshot.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function voidSnapshot(Request $request, array $actor, int $periodId, int $snapshotId, string $reason): void
    {
        $reason = mb_substr(clean_text($reason), 0, 255);
        if (mb_strlen($reason) < 5) {
            throw RuleViolation::field('reason', 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $periodId, $snapshotId, $reason): void {
            $q = $pdo->prepare('SELECT id, as_of_month FROM profit_share_snapshots WHERE id = ? AND period_id = ? FOR UPDATE');
            $q->execute([$snapshotId, $periodId]);
            $s = $q->fetch(\PDO::FETCH_ASSOC);
            if ($s === false) {
                throw RuleViolation::field('_form', 'Snapshot tidak ditemukan pada periode ini.');
            }
            $done = $pdo->prepare('SELECT id FROM profit_share_snapshot_voids WHERE snapshot_id = ?');
            $done->execute([$snapshotId]);
            if ($done->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Snapshot ini sudah dibatalkan.');
            }
            $pdo->prepare('INSERT INTO profit_share_snapshot_voids (snapshot_id, reason, voided_by) VALUES (?,?,?)')->execute([$snapshotId, $reason, $actor['id']]);
            AuditLog::record($request, $actor, 'PROFIT_SNAPSHOT_VOIDED', 'profit_snapshot', $snapshotId, month_label((string) $s['as_of_month']), null, [
                'period_id' => $periodId, 'as_of' => (string) $s['as_of_month'], 'alasan' => $reason,
            ], $pdo);
        });
    }
}
