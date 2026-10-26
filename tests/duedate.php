<?php
declare(strict_types=1);

/**
 * Tes tanggal jatuh tempo per cicilan (migrasi 012): aturan tanggal (hari sama tiap bulan, akhir bulan pendek), status
 * dinamis (Belum / Hari Ini / Terlambat / Lunas), penetapan SEKALI saat pencairan disetujui, penjagaan trigger, dan
 * tunggakan yang memakai tanggal nyata (pinjaman lama tanpa tanggal tetap memakai akhir bulan).
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\duedate.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\Dashboard;
use App\Models\User;
use App\Services\DueSchedule;
use App\Services\LoanService;
use App\Services\Migrator;
use App\Services\OverdueService;
use App\Services\SavingService;
use App\Services\UserService;
use App\Services\ValidationService;

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $testDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
$migrator->dropEverything();
$migrator->migrate();

$_SESSION = [];
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
function one(string $sql, array $params = []): mixed
{
    $st = Database::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}
function req(): Request
{
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-jatuhtempo']);
}
function ver(int $id): string
{
    return (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
}
function sqlFails(callable $fn): bool
{
    try {
        $fn();
    } catch (PDOException $e) {
        return true;
    }
    return false;
}

// ======================= aturan tanggal (murni) =======================
check('tanggal: terima 15 Jan -> 15 Feb, 15 Mar, 15 Apr', DueSchedule::dates('2026-01-15', 3) === ['2026-02-15', '2026-03-15', '2026-04-15']);
check('tanggal: 31 Jan -> akhir Feb, lalu 31 Mar, 30 Apr, 31 Mei (tidak terseret ke tanggal 28)', DueSchedule::dates('2026-01-31', 4) === ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']);
check('tanggal: tahun kabisat memakai 29 Feb', DueSchedule::dates('2028-01-31', 1) === ['2028-02-29'] && DueSchedule::dates('2028-01-30', 1) === ['2028-02-29']);
check('tanggal: pergantian tahun (31 Des -> 31 Jan, 28 Feb)', DueSchedule::dates('2026-12-31', 2) === ['2027-01-31', '2027-02-28']);
check('tanggal: 30 Nov -> 30 Des, 30 Jan', DueSchedule::dates('2026-11-30', 2) === ['2026-12-30', '2027-01-30']);
check('tanggal: tenor 1 menghasilkan satu tanggal', count(DueSchedule::dates('2026-05-10', 1)) === 1);
$bad = false;
try {
    DueSchedule::dates('2026-02-30', 2);
} catch (InvalidArgumentException $e) {
    $bad = true;
}
check('tanggal: tanggal persetujuan tidak valid ditolak', $bad);

// ======================= status dinamis (murni) =======================
$today = '2026-06-15';
check('status: sisa 0 = Lunas, walau tanggalnya sudah lewat', DueSchedule::status('2026-05-01', 0, $today)['key'] === 'LUNAS');
check('status: lewat tanggal dan masih ada sisa = Terlambat (hari dihitung)', DueSchedule::status('2026-06-10', 1000, $today) === ['key' => 'TERLAMBAT', 'label' => 'Terlambat', 'days' => 5]);
check('status: tepat hari ini = Jatuh Tempo Hari Ini (bukan terlambat)', DueSchedule::status('2026-06-15', 1000, $today)['key'] === 'HARI_INI');
check('status: besok = Belum Jatuh Tempo', DueSchedule::status('2026-06-16', 1000, $today)['key'] === 'BELUM' && DueSchedule::status('2026-06-16', 1000, $today)['days'] === -1);
check('status: dibayar sebagian tetap berstatus menurut tanggal (sisa > 0)', DueSchedule::status('2026-06-01', 50, $today)['key'] === 'TERLAMBAT');
check('lencana: terlambat merah, hari ini kuning, lunas hijau', str_contains(due_badge(DueSchedule::status('2026-06-10', 1, $today)), 'badge--ditolak') && str_contains(due_badge(DueSchedule::status('2026-06-15', 1, $today)), 'badge--menunggu-validasi') && str_contains(due_badge(DueSchedule::status('2026-06-10', 0, $today)), 'badge--disetujui'));

// ======================= fixture =======================
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$later = [];
for ($i = 1; $i <= 6; $i++) {
    $later[] = date('Y-m-01', strtotime("+{$i} month", strtotime($m0)));
}
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji jatuh tempo', '{$m1}', '" . date('Y-m-t', strtotime($later[5])) . "', 'AKTIF')");
foreach ([$m1, $m0, ...$later] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid0 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m0]);
$todayDate = date('Y-m-d');
foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m1, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m1}'),(2,1,'{$m1}'),(3,2,'{$m1}'),(4,2,'{$m1}')");
$ids = [];
foreach ([['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala', ['HEAD'], null]] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$beta = User::findActive($ids['ketua.beta']);
$head = User::findActive($ids['kepala']);

// kas awal
[$e, $d] = SavingService::parse(['member_id' => 4, 'period_month_id' => $mid0, 'kind' => 'SUKARELA', 'amount' => '20.000.000', 'trx_date' => $todayDate, 'confirm_duplicate' => '1']);
$sv = SavingService::create(req(), $beta, $d, true);
ValidationService::approve(req(), $head, $sv, ver($sv), null);

// ======================= penetapan saat disetujui =======================
[$e, $d] = LoanService::parse(['member_id' => 4, 'period_month_id' => $mid0, 'principal' => '3.000.000', 'tenor' => '3', 'trx_date' => $todayDate, 'confirm_duplicate' => '1']);
$loanTrx = LoanService::create(req(), $beta, $d, true);
$loanId  = (int) one('SELECT id FROM loans WHERE transaction_id = ?', [$loanTrx]);
check('draft/menunggu: due_date belum terisi (belum diterima)', (int) one('SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND due_date IS NULL', [$loanId]) === 3);
check('menunggu: jadwal tetap tak bisa diubah lewat SQL (trigger lama tetap berlaku)', sqlFails(fn () => $pdo->exec("UPDATE loan_installments SET due_date = '2030-01-01' WHERE loan_id = {$loanId}")));
ValidationService::approve(req(), $head, $loanTrx, ver($loanTrx), null);
$expect = DueSchedule::dates($todayDate, 3);
$got    = array_column(Database::select('SELECT due_date FROM loan_installments WHERE loan_id = ? ORDER BY seq', [$loanId]), 'due_date');
check('disetujui: due_date = tanggal disetujui + n bulan untuk tiap cicilan', $got === $expect);
$a = Database::select("SELECT * FROM audit_logs WHERE action = 'LOAN_SCHEDULE_DATED' ORDER BY id DESC LIMIT 1")[0] ?? null;
$after = $a === null ? [] : (array) json_decode((string) $a['after_data'], true);
check('disetujui: audit LOAN_SCHEDULE_DATED memuat tanggal disetujui, jatuh tempo pertama dan terakhir', $a !== null && ($after['approved_on'] ?? '') === $todayDate && ($after['first_due'] ?? '') === $expect[0] && ($after['last_due'] ?? '') === $expect[2]);
check('disetujui: due_month_id (bulan siklus) tidak berubah', (int) one('SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND due_month_id IS NOT NULL', [$loanId]) === 3);
check('disetujui: tanggal sekali terisi tidak bisa diubah lagi', sqlFails(fn () => $pdo->exec("UPDATE loan_installments SET due_date = '2030-01-01' WHERE loan_id = {$loanId}")));
check('disetujui: nominal cicilan tetap terkunci', sqlFails(fn () => $pdo->exec("UPDATE loan_installments SET amount_due = amount_due + 1 WHERE loan_id = {$loanId}")));
check('disetujui: stamp kedua tidak menimpa dan tidak mengisi ulang', DueSchedule::stamp($pdo, $loanTrx, '2031-01-01') === [] && array_column(Database::select('SELECT due_date FROM loan_installments WHERE loan_id = ? ORDER BY seq', [$loanId]), 'due_date') === $expect);
check('view status: due_date efektif = tanggal nyata', array_column(Database::select('SELECT due_date FROM v_installment_status WHERE loan_id = ? ORDER BY seq', [$loanId]), 'due_date') === $expect);
check('belum jatuh tempo: pinjaman baru tidak menunggak', OverdueService::items($head) === []);

// ======================= tunggakan memakai tanggal nyata =======================
$tid = static function (string $type, int $member, int $team, int $amount, string $date): int {
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    global $mid0;
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-DD-{$n}", "DD-{$n}", $type, $member, $team, $mid0, $date, $amount]);
    return (int) $pdo->lastInsertId();
};
/** Pinjaman dengan due_date eksplisit per cicilan (null = pinjaman lama: akhir bulan due_month). */
$mkLoan = static function (int $member, int $team, array $dueDates, array $monthIds, ?string $trxDate = null) use ($tid): array {
    $pdo = Database::pdo();
    $trxDate ??= date('Y-m-d');
    $id  = $tid('PENCAIRAN_PINJAMAN', $member, $team, 100000, $trxDate);
    $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $member, 100000, count($dueDates), '2.00', 0, $trxDate]);
    $loanId = (int) $pdo->lastInsertId();
    $inst = [];
    foreach ($dueDates as $i => $dd) {
        $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, due_date, amount_due) VALUES (?,?,?,?,?)')->execute([$loanId, $i + 1, $monthIds[$i], $dd, intdiv(100000, count($dueDates))]);
        $inst[] = (int) $pdo->lastInsertId();
    }
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
    return ['trx' => $id, 'loan' => $loanId, 'inst' => $inst];
};
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$mid1 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m1]);
// anggota 2 (regu Alfa): cicilan kemarin (terlambat), hari ini, besok
$l = $mkLoan(2, 1, [$yesterday, $todayDate, $tomorrow], [$mid0, $mid0, $mid0]);
$items = OverdueService::items($head);
$mine  = array_values(array_filter($items, static fn (array $i): bool => $i['member_id'] === 2));
check('tunggakan: hanya cicilan yang jatuh tempo KEMARIN menunggak; hari ini dan besok tidak', count($mine) === 1 && $mine[0]['seq'] === 1 && $mine[0]['due_date'] === $yesterday);
check('tunggakan: terlambat 1 hari dihitung dari tanggal nyata', $mine !== [] && $mine[0]['days_late'] === 1);
check('status cicilan hari ini = Jatuh Tempo Hari Ini, besok = Belum, kemarin = Terlambat',
    DueSchedule::status($todayDate, 33334)['key'] === 'HARI_INI' && DueSchedule::status($tomorrow, 33333)['key'] === 'BELUM' && DueSchedule::status($yesterday, 33333)['key'] === 'TERLAMBAT');

// pinjaman lama (due_date NULL): akhir bulan due_month. Bulan lalu = menunggak, bulan ini = belum.
$old = $mkLoan(1, 1, [null, null], [$mid1, $mid0]);
$oldItems = array_values(array_filter(OverdueService::items($head), static fn (array $i): bool => $i['member_id'] === 1));
check('pinjaman lama: bulan lalu menunggak dengan tanggal = akhir bulan lalu; bulan ini belum', count($oldItems) === 1 && $oldItems[0]['due_date'] === date('Y-m-t', strtotime($m1)));
check('pinjaman lama: view status mengisi due_date = LAST_DAY(bulan)', (string) one('SELECT due_date FROM v_installment_status WHERE installment_id = ?', [$old['inst'][1]]) === date('Y-m-t', strtotime($m0)));

// pembayaran: sebagian mengurangi sisa; yang menunggu validasi tidak mengurangi
$p = $tid('ANGSURAN', 2, 1, 20000, $todayDate);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$p, $l['inst'][0], 20000]);
$pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$p}");
$rem = (int) one('SELECT remaining_amount FROM v_overdue_installments WHERE installment_id = ?', [$l['inst'][0]]);
check('pembayaran menunggu validasi tidak mengurangi tunggakan', $rem === 33333);
$pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$p}");
check('pembayaran sebagian yang disetujui menyisakan selisihnya', (int) one('SELECT remaining_amount FROM v_overdue_installments WHERE installment_id = ?', [$l['inst'][0]]) === 13333);
$p2 = $tid('ANGSURAN', 2, 1, 13333, $todayDate);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$p2, $l['inst'][0], 13333]);
$pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$p2}");
$pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$p2}");
check('lunas: cicilan yang sudah dibayar penuh tidak lagi menunggak', (int) one('SELECT COUNT(*) FROM v_overdue_installments WHERE installment_id = ?', [$l['inst'][0]]) === 0);

// tanggal pemeriksaan (per tanggal): keadaan pada tanggal itu, bukan sekarang
$d60 = date('Y-m-d', strtotime('-60 days'));
$d40 = date('Y-m-d', strtotime('-40 days'));
$d10 = date('Y-m-d', strtotime('-10 days'));
$d20 = date('Y-m-d', strtotime('-20 days'));
$asLoan = $mkLoan(3, 2, [$d40, $d10], [$mid1, $mid1], $d60);
$inspect = static fn (?string $asof): array => array_values(array_filter(OverdueService::items($head, $asof === null ? [] : ['asof' => $asof]), static fn (array $i): bool => $i['member_id'] === 3));
check('per tanggal: keadaan sekarang -> kedua cicilan menunggak (10 dan 40 hari)', array_column($inspect(null), 'days_late') === [40, 10]);
$past = $inspect($d20);
check('per tanggal: 20 hari lalu hanya cicilan jatuh tempo 40 hari lalu yang menunggak, terlambat 20 hari', count($past) === 1 && $past[0]['seq'] === 1 && $past[0]['days_late'] === 20);
check('per tanggal: sebelum pinjaman dicairkan tidak ada tunggakan', $inspect(date('Y-m-d', strtotime('-70 days'))) === []);
check('per tanggal: hari ini atau tanggal masa depan diperlakukan sebagai keadaan sekarang', count($inspect($todayDate)) === 2 && count($inspect($tomorrow)) === 2);
$payPast = $tid('ANGSURAN', 3, 2, 50000, $d20);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$payPast, $asLoan['inst'][0], 50000]);
$pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$payPast}");
$pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$payPast}");
$d30 = date('Y-m-d', strtotime('-30 days'));
check('per tanggal: pembayaran SETELAH tanggal pemeriksaan tidak mengurangi tunggakan pada tanggal itu (-30 hari: sisa 50.000)', ($inspect($d30)[0]['remaining'] ?? -1) === 50000);
check('per tanggal: pembayaran pada atau sebelum tanggal pemeriksaan (inklusif) melunasi cicilan pada tanggal itu (-20 hari: tidak menunggak)', $inspect($d20) === []);
check('per tanggal: keadaan sekarang setelah pembayaran hanya cicilan ke-2 (10 hari)', array_column($inspect(null), 'days_late') === [10]);

// jatuh tempo hari ini: pengingat terpisah dari tunggakan, hormati cakupan regu
$dt = OverdueService::dueToday($head);
check('hari ini: cicilan yang jatuh tempo hari ini dihitung terpisah (>= 1 cicilan, >= 33.333) dan BUKAN tunggakan', $dt['installments'] >= 1 && $dt['amount'] >= 33333 && $dt['members'] >= 1
    && count(array_filter(OverdueService::items($head), static fn (array $i): bool => $i['due_date'] === $todayDate)) === 0);
check('hari ini: Ketua Regu Beta tidak melihat jatuh tempo hari ini milik regu Alfa', OverdueService::dueToday($beta)['installments'] === 0);
check('hari ini: panel dashboard membawa ringkasan hari ini', OverdueService::panel($head)['today']['installments'] === $dt['installments']);

// dashboard anggota: cicilan berikutnya memakai tanggal nyata
$card = Dashboard::member(2);
check('dashboard anggota: cicilan berikutnya membawa due_date', isset($card['next']['due_date']) && $card['next']['due_date'] === $todayDate);

echo "\n";
if ($failed === []) {
    echo "Semua {$passed} pemeriksaan lulus.\n";
    exit(0);
}
echo "{$passed} lulus, " . count($failed) . " gagal.\n";
exit(1);
