<?php
declare(strict_types=1);

/**
 * Tes mesin bagi hasil mode Excel (ProfitShare::FOLLOW_EXCEL) dan alat penyesuaian rencana (plan_adjust.php).
 * Skenario kecil dengan angka dihitung tangan (komentar). MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\profit.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Migrator;
use App\Services\ProfitShare;

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $testDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
$migrator->dropEverything();
$migrator->migrate();

$passed = 0;
$failed = [];
function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $name;
    echo "  GAGAL  {$name}\n";
}
function trx(string $type, int $member, int $amount, int $monthId, string $date): int
{
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-PROFIT-{$n}", "PROFIT-{$n}", $type, $member, null, $monthId, $date, $amount]);
    return (int) $pdo->lastInsertId();
}
function approve(int $id): void
{
    $pdo = Database::pdo();
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
}
/** @param array<int,int> $dueIds @param array<int,int> $amounts @return array<int,int> id cicilan */
function loan(int $member, int $principal, int $tenor, int $interest, int $monthId, string $date, array $dueIds, array $amounts): array
{
    $pdo = Database::pdo();
    $id = trx('PENCAIRAN_PINJAMAN', $member, $principal, $monthId, $date);
    $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')->execute([$id, $member, $principal, $tenor, '2.00', $interest, $date]);
    $loanId = (int) $pdo->lastInsertId();
    $inst = [];
    foreach ($amounts as $i => $a) {
        $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)')->execute([$loanId, $i + 1, $dueIds[$i], $a]);
        $inst[] = (int) $pdo->lastInsertId();
    }
    approve($id);
    return $inst;
}
function cli(array $args): array
{
    global $testDb;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/database/tools/plan_adjust.php') . ' --db=' . escapeshellarg($testDb) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
}
function one(string $sql, array $params = []): mixed
{
    $st = Database::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

// ---------------- fixture ----------------
// Periode 12 bulan (Jan-Des 2027). X dan Y menabung di bulan 1: X 100.000, Y 300.000.
// Pinjaman A (anggota X): pokok 1.000.000, tenor 1, bunga 20.000, cair bulan 7, cicilan 1.020.000 jatuh tempo bulan 8.
// Pinjaman B (anggota Y): pokok 1.000.000, tenor 5, bunga 100.000, cair bulan 7, cicilan 220.000 jatuh tempo bulan 8..12.
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Panjang', '2027-01-01', '2027-12-31', 'AKTIF')");
for ($m = 1; $m <= 12; $m++) {
    $pdo->exec(sprintf("INSERT INTO period_months (period_id, month_date) VALUES (1, '2027-%02d-01')", $m));
}
$mo = [];
foreach (Database::select('SELECT id FROM period_months ORDER BY month_date') as $i => $r) {
    $mo[$i + 1] = (int) $r['id'];
}
$pdo->exec("INSERT INTO members (member_no, name, active_from, status) VALUES ('AGT-001', 'Anggota X', '2027-01-01', 'AKTIF'), ('AGT-002', 'Anggota Y', '2027-01-01', 'AKTIF')");
foreach ([[1, 100000], [2, 300000]] as [$mem, $amt]) {
    $s = trx('SIMPANAN', $mem, $amt, $mo[1], '2027-01-05');
    $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s}, 'WAJIB')");
    approve($s);
}
loan(1, 1000000, 1, 20000, $mo[7], '2027-07-05', [$mo[8]], [1020000]);
$instB = loan(2, 1000000, 5, 100000, $mo[7], '2027-07-05', [$mo[8], $mo[9], $mo[10], $mo[11], $mo[12]], [220000, 220000, 220000, 220000, 220000]);

/*
 * Hitungan tangan. Rencana: A bayar bulan 8 (bunga 20.000); B bayar bulan 8..12 (bunga 20.000 tiap bulan).
 *   bunga diterima: bln8 = 40.000, bln9..12 = 20.000. Pool bulan k memakai bunga bulan k+1 (40% penabung, 40% peminjam):
 *   k=7: 40.000 -> 16.000 + 16.000 | k=8..11: 20.000 -> 8.000 + 8.000 (k=10 pool penabung tidak dibagikan di mode Excel)
 * MODE EXCEL:
 *   penabung: pool dibagikan 16.000+8.000+8.000+8.000 = 40.000 (k=10 hilang 8.000): X 25% = 10.000, Y 75% = 30.000
 *             setelah cadangan 5%: X 9.500, Y 28.500
 *   peminjam (k>=4 dasar SISA BUNGA): akhir bulan 7: A 20.000, B 100.000 -> A 1/6, B 5/6 dari 16.000 = 2.666,67 / 13.333,33
 *             k=8: A lunas, B 100%: 8.000; k=9..11: B 8.000 -> A 2.666,67; B 13.333,33 + 32.000 = 45.333,33
 *             setelah cadangan: A 2.533 (2.533,33), B 43.067 (43.066,67)
 * MODE NORMAL (dasar sisa pokok, semua pool terbagi): penabung X 12.000 -> 11.400, Y 36.000 -> 34.200
 *   peminjam k=7: pokok sama -> A 8.000, B 8.000; k=8..11: B 8.000 -> A 8.000, B 40.000 -> A 7.600, B 38.000
 */
$period = (int) one('SELECT id FROM periods LIMIT 1');
$ex = ProfitShare::compute($period, true);
$no = ProfitShare::compute($period, false);
check('mode Excel: bendera mode, pool total = 80% x bunga (96.000)', $ex['excel'] === true && $no['excel'] === false && abs($ex['interest_pool'] - 96000.0) < 0.001 && abs($no['interest_pool'] - 96000.0) < 0.001);
check('mode Excel: pool penabung siklus ke-10 (Desember) tidak dibagikan (8.000 tersisa); mode normal membagi semuanya', abs($ex['undistributed'] - 8000.0) < 0.001 && abs($no['undistributed']) < 0.001);
check('mode Excel: bagi hasil penabung X 9.500 dan Y 28.500', $ex['members'][1]['saver'] === 9500 && $ex['members'][2]['saver'] === 28500);
check('mode Excel: dasar peminjam sisa BUNGA mulai Juni (A 2.533, B 43.067); BUKAN sisa pokok (8.000/40.000)', $ex['members'][1]['borrower'] === 2533 && $ex['members'][2]['borrower'] === 43067);
check('mode normal (aturan Q8): penabung X 11.400 Y 34.200; peminjam A 7.600 B 38.000', $no['members'][1]['saver'] === 11400 && $no['members'][2]['saver'] === 34200 && $no['members'][1]['borrower'] === 7600 && $no['members'][2]['borrower'] === 38000);
check('mode Excel: jumlah dibagikan + tak terbagi = pool (tidak ada rupiah hilang)', abs(array_sum(array_map(static fn (array $m): float => $m['saver_gross'] + $m['borrower_gross'], $ex['members'])) + $ex['undistributed'] - 96000.0) < 0.001);
check('mode bawaan = Excel (FOLLOW_EXCEL) dan compute() tanpa argumen sama dengan compute(.., true)', ProfitShare::FOLLOW_EXCEL === true && ProfitShare::compute($period)['members'] === $ex['members']);

// Pembayaran NYATA diabaikan di mode Excel (rencana saja), dan mempengaruhi mode normal.
$payId = trx('ANGSURAN', 2, 440000, $mo[8], '2027-08-05');
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$payId, $instB[0], 220000]);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$payId, $instB[1], 220000]);
approve($payId);
check('mode Excel: pembayaran nyata (B melunasi dua cicilan lebih awal) tidak mengubah angka', ProfitShare::compute($period, true)['members'] === $ex['members']);
check('mode normal: pembayaran nyata mengubah angka (bunga dan sisa pokok bergeser)', ProfitShare::compute($period, false)['members'] !== $no['members']);
$noAfter = ProfitShare::compute($period, false);

// ---------------- alat penyesuaian rencana ----------------
$tmp = (string) tempnam(sys_get_temp_dir(), 'adj');
$write = static function (array $items) use ($tmp): void {
    file_put_contents($tmp, json_encode($items));
};
$item = ['member_no' => 'AGT-002', 'principal' => 1000000, 'disbursed_month' => '2027-07-01', 'month' => '2027-08-01', 'amount' => -220000, 'note' => 'uji'];
$item2 = ['member_no' => 'AGT-002', 'principal' => 1000000, 'disbursed_month' => '2027-07-01', 'month' => '2027-09-01', 'amount' => 220000, 'note' => 'uji'];
$write([$item, $item2]);
$r = cli(['--file=' . $tmp]);
check('alat: tanpa --apply hanya rencana (tidak menulis)', $r['code'] === 0 && str_contains($r['out'], 'Rencana saja') && (int) one('SELECT COUNT(*) FROM loan_plan_adjustments') === 0);
$r = cli(['--file=' . $tmp, '--apply']);
check('alat: --apply menulis 2 baris dalam satu transaksi dan mencatat audit PLAN_ADJUSTED', $r['code'] === 0 && (int) one('SELECT COUNT(*) FROM loan_plan_adjustments') === 2 && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PLAN_ADJUSTED'") === 1);
$r = cli(['--file=' . $tmp, '--apply']);
check('alat: dijalankan ulang melewati baris yang sudah ada (idempoten)', $r['code'] === 0 && str_contains($r['out'], 'dilewati') && (int) one('SELECT COUNT(*) FROM loan_plan_adjustments') === 2);
$r = cli(['--list']);
check('alat: --list menampilkan penyesuaian dengan nama, pokok, bulan, dan jumlah', $r['code'] === 0 && str_contains($r['out'], 'AGT-002') && str_contains($r['out'], '-220000') && str_contains($r['out'], '2 penyesuaian'));

/*
 * Rencana B digeser: bulan 8 -220.000, bulan 9 +220.000 -> bunga bln8 = 20.000, bln9 = 40.000; bunga bulan 10..12 = 20.000
 *   pool: k=7: 20.000 (8.000+8.000) | k=8: 40.000 (16.000+16.000) | k=9: 8.000+8.000 | k=10: penabung hilang 8.000, peminjam 8.000 | k=11: 8.000+8.000
 *   penabung terbagi 8.000+16.000+8.000+8.000 = 40.000 -> X 9.500, Y 28.500 (tetap)
 *   peminjam: akhir bln7 A 20.000 vs B 100.000: A 1/6 x 8.000 = 1.333,33; B 6.666,67; k=8: B 100% (A lunas) 16.000; k=9..11: 8.000 x 3
 *   A 1.333,33 -> 1.267; B 6.666,67 + 16.000 + 24.000 = 46.666,67 -> 44.333
 */
$adj = ProfitShare::compute($period, true);
check('penyesuaian: rencana B digeser memengaruhi mode Excel (A 1.267, B 44.333; penabung tetap 9.500 / 28.500)', $adj['members'][1]['borrower'] === 1267 && $adj['members'][2]['borrower'] === 44333 && $adj['members'][1]['saver'] === 9500 && $adj['members'][2]['saver'] === 28500 && abs($adj['undistributed'] - 8000.0) < 0.001);
check('penyesuaian: mode normal tidak memakai penyesuaian', ProfitShare::compute($period, false)['members'] === $noAfter['members']);

$bad = static fn (array $over) => $write([array_merge($item, $over)]);
$bad(['member_no' => 'AGT-999']);
$r = cli(['--file=' . $tmp, '--apply']);
check('alat: pinjaman tidak ditemukan ditolak (kode 1) tanpa menulis apa pun', $r['code'] === 1 && str_contains($r['out'], 'tidak ditemukan') && (int) one('SELECT COUNT(*) FROM loan_plan_adjustments') === 2);
$bad(['amount' => -100000]);
$r = cli(['--file=' . $tmp, '--apply']);
check('alat: jumlah berbeda untuk pinjaman dan bulan yang sama ditolak', $r['code'] === 1 && str_contains($r['out'], 'sudah ada penyesuaian berbeda') && (int) one('SELECT amount FROM loan_plan_adjustments ORDER BY id LIMIT 1') === -220000);
foreach ([['amount' => 0], ['month' => '2027-08-15'], ['principal' => '1000000']] as $over) {
    $bad($over);
    $r = cli(['--file=' . $tmp, '--apply']);
    check('alat: isian tidak sah ditolak (' . json_encode($over) . ')', $r['code'] === 1);
}
file_put_contents($tmp, '[bukan json');
check('alat: berkas JSON rusak ditolak', cli(['--file=' . $tmp])['code'] === 1);
check('alat: tanpa argumen ditolak dengan petunjuk', cli([])['code'] === 1);

check('skema: penyesuaian tidak boleh nol, unik per pinjaman+bulan, dan mengikuti pinjaman (FK)', (function () use ($pdo, $instB): bool {
    $loanId = (int) one('SELECT loan_id FROM loan_installments WHERE id = ?', [$instB[0]]);
    $m = (int) one('SELECT id FROM period_months WHERE month_date = ?', ['2027-10-01']);
    foreach ([[$loanId, $m, 0], [$loanId, (int) one('SELECT id FROM period_months WHERE month_date = ?', ['2027-08-01']), 5], [999999, $m, 5]] as [$l, $mm, $a]) {
        try {
            $pdo->prepare('INSERT INTO loan_plan_adjustments (loan_id, period_month_id, amount) VALUES (?,?,?)')->execute([$l, $mm, $a]);
            return false;
        } catch (PDOException $e) {
            // diharapkan
        }
    }
    return true;
})());

$r = cli(['--remove-all']);
check('alat: --remove-all tanpa --apply hanya rencana', $r['code'] === 0 && (int) one('SELECT COUNT(*) FROM loan_plan_adjustments') === 2);
$r = cli(['--remove-all', '--apply']);
check('alat: --remove-all --apply menghapus semua; angka kembali seperti semula', $r['code'] === 0 && (int) one('SELECT COUNT(*) FROM loan_plan_adjustments') === 0 && ProfitShare::compute($period, true)['members'] === $ex['members']);
unlink($tmp);

echo "\n";
if ($failed === []) {
    echo "Bagi hasil mode Excel dan alat penyesuaian: {$passed} lulus, 0 gagal.\n";
    exit(0);
}
echo "Bagi hasil mode Excel dan alat penyesuaian: {$passed} lulus, " . count($failed) . " gagal.\n";
exit(1);
