<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Helpers\Money;

/**
 * Pencairan Akhir: rekap pembagian akhir tabungan + bagi hasil per anggota (pengganti sheet 5-ABSEN).
 *
 * Disusun dari SATU snapshot bagi hasil yang masih berlaku (ProfitReport), jadi angkanya tidak berubah bila transaksi baru masuk:
 *   diterima = tabungan + bagi hasil penabung + bagi hasil peminjam - sisa pinjaman (pokok + bunga) - potongan arisan - potongan lain
 * lalu dibulatkan KE BAWAH ke kelipatan rounding_unit. Bila hasilnya nol atau negatif, diterima 0 dan kekurangannya dicatat (shortfall).
 * Dua potongan manual diisi Superadmin saat menyusun. Satu pencairan berlaku per periode; semua tabel hanya-tambah (migrasi 011).
 *
 * Ini lembar rekap, BUKAN pembukuan: tidak membuat transaksi, tidak mengubah saldo, kas, atau pinjaman.
 * Cakupan: hanya pemegang izin payout.manage (Superadmin) yang boleh melihat atau mengubah; ditegakkan di route.
 */
final class PayoutService
{
    /** Pilihan pembulatan ke bawah (rupiah). Pertama = bawaan. */
    public const UNITS = [1000, 100, 1];
    /** Pecahan uang untuk rincian kebutuhan tunai (sama dengan sheet 5-ABSEN: 100.000 sampai 100). */
    public const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    /**
     * Hitung satu baris. Murni (tanpa database).
     * @return array{subtotal:int,net:int,rounding:int,shortfall:int}
     */
    public static function calc(int $savings, int $saver, int $borrower, int $loan, int $arisan, int $other, int $unit): array
    {
        $subtotal = $savings + $saver + $borrower - $loan - $arisan - $other;
        if ($subtotal <= 0) {
            return ['subtotal' => $subtotal, 'net' => 0, 'rounding' => 0, 'shortfall' => -$subtotal];
        }
        $net = intdiv($subtotal, max(1, $unit)) * max(1, $unit);
        return ['subtotal' => $subtotal, 'net' => $net, 'rounding' => $subtotal - $net, 'shortfall' => 0];
    }

    // ------------------------------------------------------------------ dasar penyusunan

    /** Snapshot bagi hasil terbaru yang masih berlaku pada periode ini (id, as_of), atau null. @return array{id:int,as_of:string,created_at:string}|null */
    public static function baseSnapshot(int $periodId): ?array
    {
        foreach (ProfitReport::snapshots($periodId) as $s) {
            if ($s['voided'] === null) {
                return ['id' => $s['id'], 'as_of' => $s['as_of'], 'created_at' => $s['created_at']];
            }
        }
        return null;
    }

    /**
     * Baris usulan dari snapshot (sebelum potongan manual), terurut menurut nomor anggota. Null bila snapshot tidak ada atau dibatalkan.
     * 'drift' = jumlah anggota yang tabungan atau sisa pinjamannya SEKARANG berbeda dari snapshot (data bergerak sejak snapshot).
     * @return array{snapshot_id:int,as_of:string,rows:array<int,array<string,mixed>>,drift:int,live_as_of:?string}|null
     */
    public static function preview(int $periodId, int $snapshotId): ?array
    {
        $data = ProfitReport::dataset($periodId, $snapshotId);
        if ($data === null || $data['voided'] !== null) {
            return null;
        }
        $teams = [];
        foreach (Database::select('SELECT id, name FROM team_leaders') as $t) {
            $teams[(int) $t['id']] = (string) $t['name'];
        }
        $names = [];
        foreach (Database::select('SELECT id, member_no, name FROM members') as $m) {
            $names[(int) $m['id']] = [(string) $m['member_no'], (string) $m['name']];
        }
        $rows = [];
        foreach ($data['members'] as $mid => $m) {
            if ((int) $m['savings_last'] === 0 && (int) $m['saver'] === 0 && (int) $m['borrower'] === 0 && (int) $m['outstanding_last'] === 0) {
                continue;
            }
            $rows[] = [
                'member_id' => (int) $mid, 'no' => $names[$mid][0] ?? '-', 'name' => $names[$mid][1] ?? '-',
                'team_id' => $m['team_id'], 'team' => $m['team_id'] === null ? '-' : ($teams[$m['team_id']] ?? '-'),
                'savings' => (int) $m['savings_last'], 'saver' => (int) $m['saver'], 'borrower' => (int) $m['borrower'], 'loan' => (int) $m['outstanding_last'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp((string) $a['no'], (string) $b['no']));

        $live = ProfitShare::compute($periodId);
        $drift = 0;
        foreach (array_unique(array_merge(array_keys($data['members']), array_keys($live['members']))) as $mid) {
            $s = $data['members'][$mid] ?? null;
            $l = $live['members'][$mid] ?? null;
            if ((int) ($s['savings_last'] ?? 0) !== (int) ($l['savings_last'] ?? 0) || (int) ($s['outstanding_last'] ?? 0) !== (int) ($l['outstanding_last'] ?? 0)) {
                $drift++;
            }
        }
        return ['snapshot_id' => $snapshotId, 'as_of' => (string) $data['as_of'], 'rows' => $rows, 'drift' => $drift, 'live_as_of' => $live['as_of']];
    }

    // ------------------------------------------------------------------ menyusun

    /**
     * Susun pencairan dari snapshot. Satu pencairan berlaku per periode; snapshot harus masih berlaku.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @param array<int|string,array{arisan?:string,other?:string,note?:string}> $input per id anggota
     * @return int id pencairan
     */
    public static function create(Request $request, array $actor, int $periodId, int $snapshotId, int $unit, array $input, ?string $note): int
    {
        $errors = [];
        if (!in_array($unit, self::UNITS, true)) {
            $errors['unit'] = 'Pembulatan harus salah satu pilihan yang tersedia.';
        }
        $pv = self::preview($periodId, $snapshotId);
        if ($pv === null) {
            throw RuleViolation::field('_form', 'Snapshot bagi hasil tidak ditemukan pada periode ini atau sudah dibatalkan.');
        }
        $note = $note === null ? null : mb_substr(clean_text($note), 0, 255);
        $note = $note === '' ? null : $note;

        $lines = [];
        foreach ($pv['rows'] as $r) {
            $in = is_array($input[$r['member_id']] ?? null) ? $input[$r['member_id']] : [];
            $vals = [];
            foreach (['arisan', 'other'] as $k) {
                $raw = isset($in[$k]) && is_scalar($in[$k]) ? trim((string) $in[$k]) : '';
                $v = $raw === '' ? 0 : Money::parse($raw);
                if ($v === null) {
                    $errors['d_' . $r['member_id'] . '_' . $k] = $r['no'] . ': potongan ' . ($k === 'arisan' ? 'arisan' : 'lain') . ' harus berupa angka rupiah bulat (tanpa desimal).';
                    $v = 0;
                }
                $vals[$k] = $v;
            }
            $dn = isset($in['note']) && is_scalar($in['note']) ? mb_substr(clean_text((string) $in['note']), 0, 255) : '';
            if (($vals['arisan'] > 0 || $vals['other'] > 0) && $dn === '') {
                $errors['d_' . $r['member_id'] . '_note'] = $r['no'] . ': keterangan potongan wajib diisi bila ada potongan arisan atau lain.';
            }
            $lines[] = $r + ['arisan' => $vals['arisan'], 'other' => $vals['other'], 'dnote' => $dn === '' ? null : $dn];
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
        if ($lines === []) {
            throw RuleViolation::field('_form', 'Snapshot ini tidak memuat anggota yang punya tabungan, bagi hasil, atau pinjaman.');
        }

        return Database::transaction(static function (\PDO $pdo) use ($request, $actor, $periodId, $snapshotId, $unit, $lines, $note, $pv): int {
            // Kunci periode, lalu snapshot: bergantian dengan penetapan ulang dan pembatalan snapshot (yang mengunci baris snapshot).
            $pdo->prepare('SELECT id FROM periods WHERE id = ? FOR UPDATE')->execute([$periodId]);
            $snap = $pdo->prepare('SELECT id FROM profit_share_snapshots WHERE id = ? AND period_id = ? FOR UPDATE');
            $snap->execute([$snapshotId, $periodId]);
            if ($snap->fetchColumn() === false) {
                throw RuleViolation::field('_form', 'Snapshot bagi hasil tidak ditemukan pada periode ini.');
            }
            $void = $pdo->prepare('SELECT id FROM profit_share_snapshot_voids WHERE snapshot_id = ?');
            $void->execute([$snapshotId]);
            if ($void->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Snapshot bagi hasil ini sudah dibatalkan.');
            }
            $dup = $pdo->prepare(
                'SELECT p.id FROM final_payouts p LEFT JOIN final_payout_voids v ON v.payout_id = p.id WHERE p.period_id = ? AND v.id IS NULL'
            );
            $dup->execute([$periodId]);
            if ($dup->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Periode ini sudah punya pencairan akhir yang berlaku. Batalkan dulu bila perlu disusun ulang.');
            }

            $tot = ['savings' => 0, 'saver' => 0, 'borrower' => 0, 'loan' => 0, 'arisan' => 0, 'other' => 0, 'rounding' => 0, 'shortfall' => 0, 'net' => 0];
            $calc = [];
            foreach ($lines as $i => $l) {
                $c = self::calc($l['savings'], $l['saver'], $l['borrower'], $l['loan'], $l['arisan'], $l['other'], $unit);
                $calc[$i] = $c;
                foreach (['savings', 'saver', 'borrower', 'loan', 'arisan', 'other'] as $k) {
                    $tot[$k] += $l[$k];
                }
                $tot['rounding'] += $c['rounding'];
                $tot['shortfall'] += $c['shortfall'];
                $tot['net'] += $c['net'];
            }
            $pdo->prepare(
                'INSERT INTO final_payouts (period_id, snapshot_id, rounding_unit, members_count, savings_total, saver_total, borrower_total, loan_total, arisan_total, other_total, rounding_total, shortfall_total, net_total, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$periodId, $snapshotId, $unit, count($lines), $tot['savings'], $tot['saver'], $tot['borrower'], $tot['loan'], $tot['arisan'], $tot['other'], $tot['rounding'], $tot['shortfall'], $tot['net'], $note, $actor['id']]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare(
                'INSERT INTO final_payout_lines (payout_id, member_id, team_id, savings, saver_share, borrower_share, loan_outstanding, arisan, other, deduction_note, subtotal, rounding, shortfall, net_paid)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($lines as $i => $l) {
                $c = $calc[$i];
                $ins->execute([$id, $l['member_id'], $l['team_id'], $l['savings'], $l['saver'], $l['borrower'], $l['loan'], $l['arisan'], $l['other'], $l['dnote'], $c['subtotal'], $c['rounding'], $c['shortfall'], $c['net']]);
            }
            AuditLog::record($request, $actor, 'PAYOUT_CREATED', 'payout', $id, month_label($pv['as_of']), null, [
                'period_id' => $periodId, 'snapshot_id' => $snapshotId, 'anggota' => count($lines), 'pembulatan' => $unit, 'dibayarkan' => $tot['net'],
                'sisa_pinjaman_dipotong' => $tot['loan'], 'arisan' => $tot['arisan'], 'lain' => $tot['other'], 'kekurangan' => $tot['shortfall'], 'catatan' => $note,
            ], $pdo);
            return $id;
        });
    }

    // ------------------------------------------------------------------ pembatalan dan penerimaan

    /**
     * Batalkan pencairan (hanya-tambah, wajib beralasan). Ditolak bila sudah ada uang yang dicatat diterima: uang yang sudah
     * diserahkan tidak bisa "dibatalkan" lewat sistem.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function void(Request $request, array $actor, int $payoutId, string $reason): void
    {
        $reason = mb_substr(clean_text($reason), 0, 255);
        if (mb_strlen($reason) < 5) {
            throw RuleViolation::field('reason', 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $payoutId, $reason): void {
            $q = $pdo->prepare('SELECT id, period_id, snapshot_id FROM final_payouts WHERE id = ? FOR UPDATE');
            $q->execute([$payoutId]);
            $p = $q->fetch(\PDO::FETCH_ASSOC);
            if ($p === false) {
                throw RuleViolation::field('_form', 'Pencairan tidak ditemukan.');
            }
            $done = $pdo->prepare('SELECT id FROM final_payout_voids WHERE payout_id = ?');
            $done->execute([$payoutId]);
            if ($done->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Pencairan ini sudah dibatalkan.');
            }
            $paid = $pdo->prepare('SELECT COUNT(*) FROM final_payout_receipts r JOIN final_payout_lines l ON l.id = r.line_id WHERE l.payout_id = ?');
            $paid->execute([$payoutId]);
            if ((int) $paid->fetchColumn() > 0) {
                throw RuleViolation::field('_form', 'Pencairan ini tidak bisa dibatalkan karena sudah ada anggota yang dicatat menerima uangnya.');
            }
            $pdo->prepare('INSERT INTO final_payout_voids (payout_id, reason, voided_by) VALUES (?,?,?)')->execute([$payoutId, $reason, $actor['id']]);
            AuditLog::record($request, $actor, 'PAYOUT_VOIDED', 'payout', $payoutId, null, null, ['period_id' => (int) $p['period_id'], 'snapshot_id' => (int) $p['snapshot_id'], 'alasan' => $reason], $pdo);
        });
    }

    /**
     * Catat satu anggota sudah menerima uangnya (hanya-tambah; sekali per baris; tanggal tidak di masa depan dan tidak sebelum
     * pencairan disusun). Hanya untuk baris yang ada uang dibayarkan, pada pencairan yang belum dibatalkan.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function receive(Request $request, array $actor, int $payoutId, int $lineId, string $date, ?string $note): void
    {
        $d = \DateTime::createFromFormat('!Y-m-d', trim($date));
        if ($d === false || $d->format('Y-m-d') !== trim($date)) {
            throw RuleViolation::field('received_on', 'Tanggal terima tidak valid.');
        }
        if ($d->format('Y-m-d') > date('Y-m-d')) {
            throw RuleViolation::field('received_on', 'Tanggal terima tidak boleh di masa depan.');
        }
        $note = $note === null ? null : mb_substr(clean_text($note), 0, 255);
        $note = $note === '' ? null : $note;
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $payoutId, $lineId, $d, $note): void {
            $q = $pdo->prepare('SELECT id, DATE(created_at) AS created_on FROM final_payouts WHERE id = ? FOR UPDATE');
            $q->execute([$payoutId]);
            $p = $q->fetch(\PDO::FETCH_ASSOC);
            if ($p === false) {
                throw RuleViolation::field('_form', 'Pencairan tidak ditemukan.');
            }
            $v = $pdo->prepare('SELECT id FROM final_payout_voids WHERE payout_id = ?');
            $v->execute([$payoutId]);
            if ($v->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Pencairan ini sudah dibatalkan, jadi tidak bisa mencatat penerimaan.');
            }
            if ($d->format('Y-m-d') < (string) $p['created_on']) {
                throw RuleViolation::field('received_on', 'Tanggal terima tidak boleh sebelum pencairan disusun (' . date_id((string) $p['created_on']) . ').');
            }
            $l = $pdo->prepare('SELECT l.id, l.net_paid, m.member_no, m.name FROM final_payout_lines l JOIN members m ON m.id = l.member_id WHERE l.id = ? AND l.payout_id = ?');
            $l->execute([$lineId, $payoutId]);
            $line = $l->fetch(\PDO::FETCH_ASSOC);
            if ($line === false) {
                throw RuleViolation::field('_form', 'Baris anggota tidak ditemukan pada pencairan ini.');
            }
            if ((int) $line['net_paid'] <= 0) {
                throw RuleViolation::field('_form', 'Anggota ini tidak menerima uang tunai, jadi tidak ada penerimaan yang dicatat.');
            }
            $r = $pdo->prepare('SELECT id FROM final_payout_receipts WHERE line_id = ?');
            $r->execute([$lineId]);
            if ($r->fetchColumn() !== false) {
                throw RuleViolation::field('_form', 'Penerimaan anggota ini sudah dicatat.');
            }
            $pdo->prepare('INSERT INTO final_payout_receipts (line_id, received_on, note, recorded_by) VALUES (?,?,?,?)')->execute([$lineId, $d->format('Y-m-d'), $note, $actor['id']]);
            AuditLog::record($request, $actor, 'PAYOUT_RECEIVED', 'payout', $payoutId, (string) $line['member_no'], null, [
                'anggota' => (string) $line['name'], 'jumlah' => (int) $line['net_paid'], 'tanggal' => $d->format('Y-m-d'), 'catatan' => $note,
            ], $pdo);
        });
    }

    // ------------------------------------------------------------------ membaca

    /**
     * Semua pencairan periode ini (terbaru dulu), termasuk yang dibatalkan.
     * @return array<int,array<string,mixed>>
     */
    public static function batches(int $periodId): array
    {
        return Database::select(
            'SELECT p.*, s.as_of_month, u.name AS created_name, v.reason AS void_reason, v.voided_at, vu.name AS void_name,
                    (SELECT COUNT(*) FROM final_payout_receipts r JOIN final_payout_lines l ON l.id = r.line_id WHERE l.payout_id = p.id) AS received_count,
                    (SELECT COUNT(*) FROM final_payout_lines l WHERE l.payout_id = p.id AND l.net_paid > 0) AS payable_count
             FROM final_payouts p
             JOIN profit_share_snapshots s ON s.id = p.snapshot_id
             LEFT JOIN users u ON u.id = p.created_by
             LEFT JOIN final_payout_voids v ON v.payout_id = p.id
             LEFT JOIN users vu ON vu.id = v.voided_by
             WHERE p.period_id = ? ORDER BY p.id DESC',
            [$periodId]
        );
    }

    /**
     * Satu pencairan lengkap dengan barisnya (terurut nomor anggota) dan status penerimaan; null bila tidak ada.
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $b = Database::select(
            'SELECT p.*, s.as_of_month, pr.name AS period_name, u.name AS created_name, v.reason AS void_reason, v.voided_at, vu.name AS void_name
             FROM final_payouts p
             JOIN profit_share_snapshots s ON s.id = p.snapshot_id
             JOIN periods pr ON pr.id = p.period_id
             LEFT JOIN users u ON u.id = p.created_by
             LEFT JOIN final_payout_voids v ON v.payout_id = p.id
             LEFT JOIN users vu ON vu.id = v.voided_by
             WHERE p.id = ?',
            [$id]
        )[0] ?? null;
        if ($b === null) {
            return null;
        }
        $teams = [];
        foreach (Database::select('SELECT id, name FROM team_leaders') as $t) {
            $teams[(int) $t['id']] = (string) $t['name'];
        }
        $lines = Database::select(
            'SELECT l.*, m.member_no, m.name, r.received_on, r.note AS received_note, ru.name AS received_by
             FROM final_payout_lines l
             JOIN members m ON m.id = l.member_id
             LEFT JOIN final_payout_receipts r ON r.line_id = l.id
             LEFT JOIN users ru ON ru.id = r.recorded_by
             WHERE l.payout_id = ?',
            [$id]
        );
        usort($lines, static fn (array $a, array $c): int => strnatcmp((string) $a['member_no'], (string) $c['member_no']));
        foreach ($lines as &$l) {
            $l['team_name'] = $l['team_id'] === null ? '-' : ($teams[(int) $l['team_id']] ?? '-');
        }
        unset($l);
        $b['lines'] = $lines;
        $b['voided'] = $b['void_reason'] !== null;
        return $b;
    }

    /**
     * Kebutuhan uang tunai per pecahan untuk seluruh baris yang dibayarkan (greedy dari pecahan terbesar). Sisa di bawah pecahan terkecil
     * (mungkin bila pembulatan 1) dilaporkan sebagai 'leftover'.
     * @param array<int,array<string,mixed>> $lines
     * @return array{counts:array<int,int>,total:int,leftover:int}
     */
    public static function denominations(array $lines): array
    {
        $counts = array_fill_keys(self::DENOMINATIONS, 0);
        $leftover = 0;
        $total = 0;
        foreach ($lines as $l) {
            $rest = (int) $l['net_paid'];
            $total += $rest;
            foreach (self::DENOMINATIONS as $d) {
                $n = intdiv($rest, $d);
                $counts[$d] += $n;
                $rest -= $n * $d;
            }
            $leftover += $rest;
        }
        return ['counts' => $counts, 'total' => $total, 'leftover' => $leftover];
    }

    /**
     * Struktur tabel rekap (sama dengan tabel laporan lain: dipakai HTML, cetak, dan Excel).
     * @param array<string,mixed> $p hasil find()
     * @return array<string,mixed>
     */
    public static function report(array $p): array
    {
        $columns = [
            ['key' => 'no', 'label' => 'No. Anggota', 'type' => 'text'], ['key' => 'name', 'label' => 'Nama', 'type' => 'text'], ['key' => 'team', 'label' => 'Regu', 'type' => 'text'],
            ['key' => 'savings', 'label' => 'Tabungan', 'type' => 'money'], ['key' => 'saver', 'label' => 'Bagi hasil penabung', 'type' => 'money'], ['key' => 'borrower', 'label' => 'Bagi hasil peminjam', 'type' => 'money'],
            ['key' => 'loan', 'label' => 'Sisa pinjaman', 'type' => 'money'], ['key' => 'arisan', 'label' => 'Potongan arisan', 'type' => 'money'], ['key' => 'other', 'label' => 'Potongan lain', 'type' => 'money'],
            ['key' => 'subtotal', 'label' => 'Sebelum pembulatan', 'type' => 'money'], ['key' => 'rounding', 'label' => 'Pembulatan', 'type' => 'money'], ['key' => 'shortfall', 'label' => 'Kekurangan', 'type' => 'money'],
            ['key' => 'net', 'label' => 'Diterima', 'type' => 'money'], ['key' => 'note', 'label' => 'Keterangan potongan', 'type' => 'text'],
            ['key' => 'received', 'label' => 'Diterima tanggal', 'type' => 'text'], ['key' => 'sign', 'label' => 'Tanda tangan', 'type' => 'text'],
        ];
        $rows = [];
        foreach ($p['lines'] as $l) {
            $rows[] = [
                'no' => (string) $l['member_no'], 'name' => (string) $l['name'], 'team' => (string) $l['team_name'],
                'savings' => (int) $l['savings'], 'saver' => (int) $l['saver_share'], 'borrower' => (int) $l['borrower_share'], 'loan' => (int) $l['loan_outstanding'],
                'arisan' => (int) $l['arisan'], 'other' => (int) $l['other'], 'subtotal' => (int) $l['subtotal'], 'rounding' => (int) $l['rounding'],
                'shortfall' => (int) $l['shortfall'], 'net' => (int) $l['net_paid'], 'note' => (string) ($l['deduction_note'] ?? ''),
                'received' => $l['received_on'] === null ? '' : date_id((string) $l['received_on']), 'sign' => '',
            ];
        }
        $sum = static fn (string $k): int => (int) array_sum(array_column($rows, $k));
        $totals = ['no' => 'Jumlah', 'name' => '', 'team' => '', 'note' => '', 'received' => '', 'sign' => ''];
        foreach (['savings', 'saver', 'borrower', 'loan', 'arisan', 'other', 'subtotal', 'rounding', 'shortfall', 'net'] as $k) {
            $totals[$k] = $sum($k);
        }
        $status = $p['voided'] ? 'DIBATALKAN' : 'Berlaku';
        return [
            'key' => 'pencairan', 'title' => 'Pencairan Akhir',
            'subtitle' => 'Periode ' . $p['period_name'] . ' · dasar snapshot data per ' . month_label((string) $p['as_of_month']) . ' · pembulatan ke bawah kelipatan ' . number_format((int) $p['rounding_unit'], 0, ',', '.')
                . ' · ' . $status . ' · disusun ' . date('d-m-Y H:i', (int) strtotime((string) $p['created_at'])) . ' oleh ' . ($p['created_name'] ?? '-') . ' · rupiah',
            'columns' => $columns, 'rows' => $rows, 'totals' => $rows === [] ? null : $totals,
            'note' => 'Diterima = tabungan + bagi hasil penabung + bagi hasil peminjam - sisa pinjaman (pokok + bunga) - potongan arisan - potongan lain, dibulatkan ke bawah. '
                . 'Bila hasilnya nol atau negatif, diterima 0 dan selisihnya tampil di kolom Kekurangan (anggota masih berutang sebesar itu). Sisa pembulatan tidak dibayarkan. '
                . 'Lembar ini rekap pembagian, bukan pembukuan: tidak mengubah saldo, kas, atau pinjaman.',
        ];
    }
}
