<?php
declare(strict_types=1);

/**
 * Tes konkurensi (Phase 17): beberapa PROSES PHP terpisah memukul database yang sama pada saat yang sama.
 * Yang dibuktikan: kas tidak pernah negatif walau banyak pencairan disetujui serentak; transaksi yang sama tidak
 * pernah disetujui dua kali; setuju dan tolak yang bersamaan menghasilkan tepat satu keputusan; kirim ganda formulir
 * hanya membuat satu transaksi. MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\concurrency.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Services\LoanService;
use App\Services\Migrator;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.3.3.3', 'HTTP_USER_AGENT' => 'tes-konkurensi']);
}

/**
 * Jalankan beberapa pekerja SERENTAK terhadap satu transaksi; kembalikan hasil tiap pekerja.
 * @param array<int,array{0:string,1:int,2:string}> $jobs [aksi, id transaksi, username]
 * @return array<int,array{ok:bool,error:?string}>
 */
function race(array $jobs): array
{
    $start = microtime(true) + 2.5;
    $procs = [];
    foreach ($jobs as $i => [$action, $id, $user]) {
        $procs[$i] = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/workers/validate.php', $action, (string) $id, $user, sprintf('%.6F', $start)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], dirname(__DIR__));
    }
    $out = [];
    foreach ($procs as $i => $p) {
        $text = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($p);
        $json = json_decode(trim((string) strrchr("\n" . trim($text), "\n")), true);
        $out[$i] = is_array($json) ? $json : ['ok' => false, 'error' => 'KELUARAN TAK TERBACA: ' . substr($text, 0, 200)];
    }
    return $out;
}

// ---------------- fixture ----------------
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m1}', '" . date('Y-m-01', strtotime('+3 month', strtotime($m0))) . "', 'AKTIF')");
foreach ([$m1, $m0, date('Y-m-01', strtotime('+1 month', strtotime($m0))), date('Y-m-01', strtotime('+2 month', strtotime($m0))), date('Y-m-01', strtotime('+3 month', strtotime($m0)))] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid1 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m1]);
$mid0 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m0]);
for ($n = 1; $n <= 8; $n++) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), "Anggota {$n}", $m1, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu A', 1), ('Regu B', 5)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m1}'),(2,1,'{$m1}'),(3,1,'{$m1}'),(4,1,'{$m1}'),(5,2,'{$m1}'),(6,2,'{$m1}'),(7,2,'{$m1}'),(8,2,'{$m1}')");
$ids = [];
foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['beta', ['KETUA_REGU'], 'AGT-005'], ['periksa2', ['PEMERIKSA'], null], ['periksa', ['PEMERIKSA'], null]] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$purwati = User::findActive($ids['purwati']);
$beta    = User::findActive($ids['beta']);
$kepala2 = User::findActive($ids['periksa']);   // Pemeriksa: transaksi regu A dibuat Head (Purwati), jadi hanya Pemeriksa yang boleh memvalidasi
$today   = date('Y-m-d');
$leader  = [1 => $purwati, 2 => $beta];

$saving = function (int $team, int $member, int $amount) use ($leader, $mid0, $today): int {
    [$e, $d] = SavingService::parse(['member_id' => $member, 'period_month_id' => $mid0, 'kind' => 'SUKARELA', 'amount' => (string) $amount, 'trx_date' => $today, 'confirm_duplicate' => '1']);
    return SavingService::create(req(), $leader[$team], $d, true);
};
$loan = function (int $team, int $member, int $principal) use ($leader, $mid1, $m1): int {
    [$e, $d] = LoanService::parse(['member_id' => $member, 'period_month_id' => $mid1, 'principal' => (string) $principal, 'tenor' => '3', 'trx_date' => $m1, 'confirm_duplicate' => '1']);
    return LoanService::create(req(), $leader[$team], $d, true);
};
$ver = static fn (int $id): string => (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
$cash = static fn (): int => (int) one('SELECT kas_tersedia FROM v_global_summary');
$sound = static fn (): bool => (int) one('SELECT selisih FROM v_global_summary') === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0;

// modal kas awal 1.000.000 (lima simpanan disetujui berurutan)
foreach ([[1, 2], [1, 3], [1, 4], [2, 6], [2, 7]] as [$team, $member]) {
    $id = $saving($team, $member, 200000);
    ValidationService::approve(req(), $kepala2, $id, $ver($id), null);
}
check('fixture: kas awal Rp 1.000.000', $cash() === 1000000);

// ======================= 1. banyak pencairan serentak berebut kas =======================
// Pengaturan "kas boleh negatif" dinyalakan hanya agar lima pinjaman bisa DIAJUKAN (penolakan dini memperhitungkan cadangan);
// dimatikan lagi sebelum pekerja berebut, jadi yang menentukan adalah kunci atomik saat persetujuan.
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'allow_negative_cash'");
$loans = [];
foreach ([[1, 2], [1, 3], [1, 4], [2, 6], [2, 7]] as [$team, $member]) {
    $loans[] = $loan($team, $member, 400000);
}
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'allow_negative_cash'");
check('pencairan: lima pinjaman Rp 400.000 menunggu (total Rp 2.000.000 > kas Rp 1.000.000)', (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN' AND status = 'MENUNGGU_VALIDASI'") === 5);
$jobs = [];
foreach ($loans as $i => $id) {
    $jobs[] = ['setujui', $id, $i % 2 === 0 ? 'periksa2' : 'periksa'];
}
$res = race($jobs);
$okCount = count(array_filter($res, static fn (array $r): bool => $r['ok']));
$crash = array_filter($res, static fn (array $r): bool => str_contains((string) $r['error'], 'GALAT') || str_contains((string) $r['error'], 'TAK TERBACA'));
check('pencairan: 5 pekerja serentak: tepat 2 pinjaman disetujui (kas hanya cukup untuk dua), 3 ditolak karena kas ' . json_encode(array_column($res, 'ok')), $okCount === 2);
check('pencairan: tidak ada galat tak terduga (deadlock, SQL) pada pekerja mana pun' . ($crash ? ' ' . json_encode(array_values($crash)) : ''), $crash === []);
check('pencairan: kas tidak pernah negatif (Rp 200.000 tersisa) dan invarian terjaga', $cash() === 200000 && $sound());
check('pencairan: yang ditolak menyebut alasan kas', count(array_filter($res, static fn (array $r): bool => !$r['ok'] && str_contains((string) $r['error'], 'Kas'))) === 3);
check('pencairan: tepat 2 transaksi disetujui, 3 tetap menunggu (tidak ada yang setengah jadi)', (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN' AND status = 'DISETUJUI'") === 2 && (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN' AND status = 'MENUNGGU_VALIDASI'") === 3);

// ======================= 2. transaksi yang sama disetujui banyak pihak sekaligus =======================
$rounds = 0;
$doubleApproved = 0;
$bothOk = true;
for ($round = 1; $round <= 3; $round++) {
    $before = (int) one("SELECT saldo_tabungan FROM v_global_summary");
    $trx = $saving(1, 2, 50000);
    $r = race([['setujui', $trx, 'periksa2'], ['setujui', $trx, 'periksa'], ['setujui', $trx, 'periksa2'], ['setujui', $trx, 'periksa']]);
    $ok = count(array_filter($r, static fn (array $x): bool => $x['ok']));
    $rounds++;
    $approvals = (int) one("SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ? AND to_status = 'DISETUJUI'", [$trx]);
    $audits = (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'TRX_APPROVED' AND entity_id = ?", [$trx]);
    $after = (int) one("SELECT saldo_tabungan FROM v_global_summary");
    if ($ok !== 1 || $approvals !== 1 || $audits !== 1 || $after - $before !== 50000) {
        $doubleApproved++;
        $bothOk = false;
        echo "    putaran {$round}: sukses={$ok}, catatan validasi={$approvals}, audit={$audits}, selisih saldo=" . ($after - $before) . "\n";
    }
}
check("setuju ganda: {$rounds} putaran x 4 pekerja pada transaksi yang sama: tepat 1 sukses, 1 catatan validasi, 1 audit, saldo bertambah sekali saja", $bothOk && $doubleApproved === 0 && $sound());

// ======================= 3. setuju dan tolak bersamaan =======================
$conflictOk = true;
for ($round = 1; $round <= 3; $round++) {
    $before = (int) one("SELECT saldo_tabungan FROM v_global_summary");
    $trx = $saving(2, 6, 30000);
    $r = race([['setujui', $trx, 'periksa2'], ['tolak', $trx, 'periksa'], ['setujui', $trx, 'periksa'], ['tolak', $trx, 'periksa2']]);
    $ok = count(array_filter($r, static fn (array $x): bool => $x['ok']));
    $status = (string) one('SELECT status FROM transactions WHERE id = ?', [$trx]);
    $terminal = (int) one("SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ? AND to_status IN ('DISETUJUI','DITOLAK')", [$trx]);
    $after = (int) one("SELECT saldo_tabungan FROM v_global_summary");
    $expected = $status === 'DISETUJUI' ? 30000 : 0;
    if ($ok !== 1 || $terminal !== 1 || !in_array($status, ['DISETUJUI', 'DITOLAK'], true) || $after - $before !== $expected) {
        $conflictOk = false;
        echo "    putaran {$round}: sukses={$ok}, status={$status}, keputusan={$terminal}, selisih saldo=" . ($after - $before) . "\n";
    }
}
check('setuju vs tolak: 3 putaran x 4 pekerja: tepat 1 keputusan akhir, status konsisten, saldo sesuai keputusan (disetujui = +nominal, ditolak = 0)', $conflictOk && $sound());

// ======================= 4. kirim ganda formulir lewat HTTP =======================
$envFile = '.env.concur';
file_put_contents(dirname(__DIR__) . '/' . $envFile, implode("\n", ['APP_ENV=local', 'APP_DEBUG=false', 'APP_BASE_PATH=/', 'SESSION_IDLE_TIMEOUT=600', 'DB_HOST=127.0.0.1', 'DB_PORT=3306', "DB_NAME={$testDb}", "DB_USER={$admin[0]}", "DB_PASS={$admin[1]}"]) . "\n");
$pdo->exec('UPDATE users SET must_change_password = 0');
$port = 8093;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/tests/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/adem-concur.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/adem-concur.log', 'a']], $sp, dirname(__DIR__), array_merge(getenv(), ['APP_ENV_FILE' => $envFile]));
register_shutdown_function(static function () use ($server, $envFile): void {
    if (is_resource($server)) {
        $status = proc_get_status($server);
        @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        proc_terminate($server);
    }
    @unlink(dirname(__DIR__) . '/' . $envFile);
    @unlink(sys_get_temp_dir() . '/adem-concur.log');
});
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port, $en, $es, 0.2)) {
        break;
    }
    usleep(100000);
}
$jar = tempnam(sys_get_temp_dir(), 'jar');
$curl = static function (string $method, string $path, array $form = []) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => substr($raw, $size)];
};
$page = $curl('GET', '/login');
preg_match('/name="_token" value="([a-f0-9]{64})"/', $page['body'], $tm);
$curl('POST', '/login', ['_token' => $tm[1] ?? '', 'username' => 'beta', 'password' => 'Contoh-Uji-2026']);
$dupOk = true;
for ($round = 1; $round <= 3; $round++) {
    $form = $curl('GET', '/transaksi/simpanan/baru');
    preg_match('/name="_token" value="([a-f0-9]{64})"/', $form['body'], $tt);
    preg_match('/name="_form_id" value="([a-f0-9]{32})"/', $form['body'], $fm);
    $data = http_build_query(['_token' => $tt[1] ?? '', '_form_id' => $fm[1] ?? '', 'member_id' => '6', 'period_month_id' => (string) $mid0, 'kind' => 'SUKARELA', 'amount' => (string) (11111 + $round), 'trx_date' => $today, 'description' => "kirim ganda {$round}", 'action' => 'submit', 'confirm_duplicate' => '1']);
    $mh = curl_multi_init();
    $handles = [];
    for ($k = 0; $k < 5; $k++) {
        $ch = curl_init("http://127.0.0.1:{$port}/transaksi/simpanan");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_TIMEOUT => 20]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
    } while ($running > 0);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    $made = (int) one('SELECT COUNT(*) FROM transactions WHERE description = ?', ["kirim ganda {$round}"]);
    if ($made !== 1) {
        $dupOk = false;
        echo "    putaran {$round}: {$made} transaksi dibuat dari 5 kiriman serentak\n";
    }
}
check('kirim ganda: 3 putaran x 5 kiriman serentak dengan ID formulir yang sama: tepat 1 transaksi tercipta per putaran', $dupOk && $sound());

// ======================= 5. akhir: keadaan keuangan tetap sehat =======================
check('akhir: invarian kas + piutang = tabungan + bunga - biaya, tanpa masalah integritas, kas tidak negatif', $sound() && $cash() >= 0);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
