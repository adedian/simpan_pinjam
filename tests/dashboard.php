<?php
declare(strict_types=1);

/**
 * Tes angka dashboard (Phase 11): ringkasan global, arus bulanan (bersih dari pembalik), per regu, per anggota,
 * ringkasan pribadi, transaksi terbaru, dan pembatasan cakupan data.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\dashboard.php
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
use App\Services\InstallmentService;
use App\Services\LoanService;
use App\Services\Migrator;
use App\Services\ReversalService;
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
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
function req(): Request
{
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-dashboard']);
}
function ver(int $id): string
{
    return (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
}

// ---------------- fixture ----------------
$m2 = date('Y-m-01', strtotime('-2 month', strtotime(date('Y-m-01'))));
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$future = date('Y-m-01', strtotime('+1 month', strtotime($m0)));
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m2}', '{$future}', 'AKTIF')");
foreach ([$m2, $m1, $m0, $future] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid = [];
foreach ([$m2, $m1, $m0] as $d) {
    $mid[$d] = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$d]);
}
foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Beta 2'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m2, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m2}'),(2,1,'{$m2}'),(3,2,'{$m2}'),(4,2,'{$m2}'),(5,2,'{$m2}')");
$ids = [];
foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala2', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota4', ['ANGGOTA'], 'AGT-004'], ['anggota5', ['ANGGOTA'], 'AGT-005']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$purwati = User::findActive($ids['purwati']);
$beta    = User::findActive($ids['ketua.beta']);
$head    = User::findActive($ids['kepala2']);
$periksa = User::findActive($ids['periksa']);
$ang4    = User::findActive($ids['anggota4']);
$ang5    = User::findActive($ids['anggota5']);
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'loan_max_amount'");

function saving(array $actor, int $member, string $amount, int $monthId, string $date, string $kind = 'WAJIB'): int
{
    [$e, $d] = SavingService::parse(['member_id' => $member, 'period_month_id' => $monthId, 'kind' => $kind, 'amount' => $amount, 'trx_date' => $date, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return SavingService::create(req(), $actor, $d, true);
}
function ok(array $validator, int $id): int
{
    ValidationService::approve(req(), $validator, $id, ver($id), null);
    return $id;
}

// Beta: anggota 4 menabung 300.000 (m2), 200.000 (m1); anggota 5 menabung 100.000 (m0). Alfa: anggota 2 menabung 500.000 (m1, oleh Purwati; disetujui Pemeriksa)
ok($head, saving($beta, 4, '300.000', $mid[$m2], $m2));
$s4b = ok($head, saving($beta, 4, '200.000', $mid[$m1], $m1, 'SUKARELA'));
ok($head, saving($beta, 5, '100.000', $mid[$m0], $m0));
ok($periksa, saving($purwati, 2, '500.000', $mid[$m1], $m1));
// satu simpanan menunggu validasi (tidak boleh terhitung)
$pending = saving($beta, 5, '9.000.000', $mid[$m0], $m0);

// pinjaman anggota 4: 1.000.000 tenor 2 dicairkan bulan lalu, dibayar sekali bulan ini
[$e, $d] = LoanService::parse(['member_id' => 4, 'period_month_id' => $mid[$m1], 'principal' => '1.000.000', 'tenor' => '2', 'trx_date' => $m1, 'confirm_duplicate' => '1']);
$loan = ok($head, LoanService::create(req(), $beta, $d, true));
[$e, $d] = InstallmentService::parse(['member_id' => 4, 'period_month_id' => $mid[$m0], 'amount' => '520.000', 'trx_date' => $m0, 'confirm_duplicate' => '1']);
$pay = ok($head, InstallmentService::create(req(), $beta, $d, true));

// ======================= ringkasan global =======================
$sum = Dashboard::summary();
$view = Database::select('SELECT * FROM v_global_summary')[0];
check('ringkasan: sama dengan v_global_summary (tanpa angka tersimpan); simpanan yang menunggu tidak terhitung', $sum['kas_tersedia'] === (int) $view['kas_tersedia'] && $sum['saldo_tabungan'] === 1100000 && $sum['selisih'] === 0);
check('ringkasan: piutang dan bunga dari pinjaman yang berlaku (1.040.000 - 520.000 dibayar = 520.000; bunga 40.000)', $sum['piutang_beredar'] === 520000 && $sum['bunga_dibukukan'] === 40000);
check('ringkasan: integritas bersih (0 temuan)', Dashboard::integrityIssues() === 0);

// ---- cache pemeriksaan integritas (Phase 17: pemeriksaan penuh ±1 detik pada 34.000 transaksi)
$dbName = (string) Database::pdo()->query('SELECT DATABASE()')->fetchColumn();
$cacheFile = BASE_PATH . '/storage/cache/integrity-' . md5($dbName) . '.json';
@unlink($cacheFile);
Config::set('app.integrity_cache_ttl', 60);
$cv = \App\Services\LiveFeed::version();
check('cache integritas: hitungan pertama menulis berkas cache (0 temuan)', Dashboard::integrityIssues() === 0 && is_file($cacheFile) && (json_decode((string) file_get_contents($cacheFile), true)['n'] ?? -1) === 0);
file_put_contents($cacheFile, json_encode(['v' => $cv, 't' => time(), 'n' => 7]));
check('cache integritas: dipakai ulang bila penanda perubahan sama dan umur < TTL (terbukti: nilai palsu 7 dikembalikan)', Dashboard::integrityIssues() === 7);
file_put_contents($cacheFile, json_encode(['v' => $cv, 't' => time() - 120, 'n' => 7]));
check('cache integritas: lewat TTL dihitung ulang (perubahan di luar aplikasi tetap terdeteksi paling lambat dalam TTL) dan cache diperbarui', Dashboard::integrityIssues() === 0 && (json_decode((string) file_get_contents($cacheFile), true)['n'] ?? -1) === 0);
file_put_contents($cacheFile, json_encode(['v' => $cv - 1, 't' => time(), 'n' => 7]));
check('cache integritas: penanda perubahan data berbeda = dihitung ulang walau masih segar', Dashboard::integrityIssues() === 0);
file_put_contents($cacheFile, 'bukan json {{{');
check('cache integritas: berkas rusak tidak menjatuhkan halaman; dihitung ulang', Dashboard::integrityIssues() === 0);
file_put_contents($cacheFile, json_encode(['v' => $cv, 't' => time(), 'n' => 7]));
Config::set('app.integrity_cache_ttl', 0);
check('cache integritas: TTL 0 mematikan cache (selalu hitung ulang)', Dashboard::integrityIssues() === 0);
@unlink($cacheFile);
check('ringkasan: periode aktif terbaca', Dashboard::activePeriod() === 'Uji');

// ======================= arus bulanan =======================
$flow = Dashboard::monthly($head);
$byMonth = array_column($flow['months'], null, 'month');
check('arus: hanya bulan periode aktif sampai bulan berjalan (bulan depan tidak ikut)', array_keys($byMonth) === [$m2, $m1, $m0]);
check('arus: simpanan per bulan (disetujui saja): m2 300.000, m1 700.000, m0 100.000', $byMonth[$m2]['simpanan'] === 300000 && $byMonth[$m1]['simpanan'] === 700000 && $byMonth[$m0]['simpanan'] === 100000);
check('arus: pencairan di bulan dicairkan, angsuran di bulan dibayar', $byMonth[$m1]['pencairan'] === 1000000 && $byMonth[$m0]['angsuran'] === 520000 && $byMonth[$m2]['pencairan'] === 0);
check('arus: perubahan tabungan per bulan dan jumlah = saldo tabungan', $byMonth[$m1]['tabungan'] === 700000 && array_sum(array_column($flow['months'], 'tabungan')) + $flow['base'] === 1100000);
$teamFlow = Dashboard::monthly($beta);
$teamBy = array_column($teamFlow['months'], null, 'month');
check('arus: Ketua Regu hanya melihat regunya (Beta: m1 simpanan 200.000, tanpa simpanan Alfa)', $teamBy[$m1]['simpanan'] === 200000 && $teamBy[$m1]['pencairan'] === 1000000);
$selfFlow = array_column(Dashboard::monthly($ang5)['months'], null, 'month');
check('arus: Anggota hanya melihat dirinya (anggota 5: hanya m0 100.000)', $selfFlow[$m0]['simpanan'] === 100000 && $selfFlow[$m1]['simpanan'] === 0 && $selfFlow[$m1]['pencairan'] === 0);
check('arus: pengguna tanpa cakupan tidak melihat apa pun', array_sum(array_column(Dashboard::monthly(['roles' => ['ANGGOTA'], 'member_id' => null])['months'], 'simpanan')) === 0);

// pembalik mengurangi bulan asalnya
$kasBefore = $sum['kas_tersedia'];
$rev = ReversalService::request(req(), $beta, $s4b, 'Salah anggota');
check('arus: pembalik yang menunggu belum memengaruhi angka', array_column(Dashboard::monthly($head)['months'], null, 'month')[$m1]['simpanan'] === 700000);
ok($head, $rev);
$after = array_column(Dashboard::monthly($head)['months'], null, 'month');
check('arus: pembalik disetujui mengurangi bulan asal (m1 700.000 -> 500.000), tabungan global turun 200.000', $after[$m1]['simpanan'] === 500000 && Dashboard::summary()['saldo_tabungan'] === 900000 && Dashboard::summary()['kas_tersedia'] === $kasBefore - 200000);
check('arus: setelah koreksi, jumlah perubahan tabungan tetap = saldo tabungan', array_sum(array_column(Dashboard::monthly($head)['months'], 'tabungan')) + Dashboard::monthly($head)['base'] === 900000);

// ======================= per regu dan per anggota =======================
$teams = array_column(Dashboard::teams(), null, 'name');
check('regu: Beta tabungan 400.000 (anggota 4: 300.000 + 5: 100.000 - koreksi 200.000 sudah dihitung), sisa pinjaman 520.000, 3 anggota', $teams['Regu Beta']['savings'] === 400000 && $teams['Regu Beta']['outstanding'] === 520000 && $teams['Regu Beta']['members'] === 3);
check('regu: Alfa tabungan 500.000 tanpa pinjaman; jumlah semua regu = saldo global', $teams['Regu Alfa']['savings'] === 500000 && $teams['Regu Alfa']['outstanding'] === 0 && array_sum(array_column($teams, 'savings')) === Dashboard::summary()['saldo_tabungan']);
$mem = array_column(Dashboard::teamMembers(2), null, 'name');
check('anggota regu: hanya anggota regu itu, terurut tabungan terbesar', array_keys($mem) === ['Anggota Beta', 'Anggota Beta 2', 'Ketua Beta']);
check('anggota regu: tabungan dan sisa pinjaman per anggota', $mem['Anggota Beta']['savings'] === 300000 && $mem['Anggota Beta']['outstanding'] === 520000 && $mem['Anggota Beta 2']['savings'] === 100000 && !isset($mem['Anggota Alfa']));

// ======================= ringkasan pribadi =======================
$me = Dashboard::member(4);
check('pribadi: tabungan, rincian per jenis (koreksi mengurangi SUKARELA), pinjaman, sisa', $me['savings'] === 300000 && ($me['byKind']['WAJIB'] ?? 0) === 300000 && ($me['byKind']['SUKARELA'] ?? 0) === 0
    && count($me['loans']) === 1 && $me['outstanding'] === 520000 && $me['loans'][0]['loan_status'] === 'AKTIF');
check('pribadi: cicilan berikutnya = cicilan kedua; belum ada tunggakan', $me['next'] !== null && $me['next']['remaining'] === 520000 && $me['overdue'] === 0);
$meNone = Dashboard::member(5);
check('pribadi: anggota tanpa pinjaman: kosong dan tanpa cicilan berikutnya', $meNone['loans'] === [] && $meNone['next'] === null && $meNone['outstanding'] === 0 && $meNone['savings'] === 100000);

// tunggakan: pinjaman Beta jatuh tempo bulan lalu-nya menunggak bila belum dibayar -> buat pinjaman bulan lalu-2 untuk anggota 5
[$e, $d] = LoanService::parse(['member_id' => 5, 'period_month_id' => $mid[$m2], 'principal' => '300.000', 'tenor' => '2', 'trx_date' => $m2, 'confirm_duplicate' => '1']);
ok($head, LoanService::create(req(), $beta, $d, true));
check('tunggakan: cicilan yang jatuh tempo sebelum bulan ini belum dibayar tampil sebagai tunggakan', Dashboard::member(5)['overdue'] === 156000 && Dashboard::teams()[1]['overdue'] === 156000 && array_column(Dashboard::teamMembers(2), null, 'name')['Anggota Beta 2']['overdue'] === 156000);

// ======================= transaksi terbaru dan pekerjaan sendiri =======================
$recentHead = Dashboard::recent($head, 5);
check('terbaru: Head melihat semua (termasuk yang menunggu), terbaru dulu, paling banyak sesuai batas', count($recentHead) === 5 && (int) $recentHead[0]['id'] > (int) $recentHead[4]['id'] && in_array($pending, array_map(static fn ($r) => (int) $r['id'], Dashboard::recent($head, 20)), true));
$recentBeta = array_map(static fn ($r) => (int) $r['id'], Dashboard::recent($beta, 20));
check('terbaru: Ketua Regu Beta hanya transaksi anggota regunya (atau buatannya sendiri), bukan milik Alfa buatan Purwati', in_array($pending, $recentBeta, true) && count(array_filter(Dashboard::recent($beta, 20), static fn ($r) => $r['member_name'] === 'Anggota Alfa')) === 0);
$recent5 = Dashboard::recent($ang5, 20);
check('terbaru: Anggota hanya transaksinya sendiri', $recent5 !== [] && count(array_filter($recent5, static fn ($r) => $r['member_name'] !== 'Anggota Beta 2')) === 0);
check('terbaru: pembalik ditandai lewat reverses_id', count(array_filter(Dashboard::recent($head, 20), static fn ($r) => $r['reverses_id'] !== null)) === 1);
$work = Dashboard::ownWork($beta['id']);
check('pekerjaan: transaksi buatan sendiri yang masih menunggu dihitung; draft dan ditolak 0', $work['MENUNGGU_VALIDASI'] === 1 && $work['DRAFT'] === 0 && $work['DITOLAK'] === 0);
ValidationService::reject(req(), $head, $pending, ver($pending), 'Nominal salah');
$draft = saving($beta, 4, '1.000', $mid[$m0], $m0);
$pdo->exec("UPDATE transactions SET status = 'DIBATALKAN' WHERE id = {$draft}");   // dibatalkan tidak dihitung
$work = Dashboard::ownWork($beta['id']);
check('pekerjaan: ditolak 30 hari terakhir dihitung, yang dibatalkan tidak, menunggu berkurang', $work['DITOLAK'] === 1 && $work['MENUNGGU_VALIDASI'] === 0);
$pdo->exec("UPDATE transactions SET status_changed_at = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id = {$pending}");
check('pekerjaan: yang ditolak lebih dari 30 hari lalu tidak lagi dihitung', Dashboard::ownWork($beta['id'])['DITOLAK'] === 0);

check('akhir: invarian tetap 0 dan integritas bersih', Dashboard::summary()['selisih'] === 0 && Dashboard::integrityIssues() === 0);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
