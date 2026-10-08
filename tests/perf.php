<?php
declare(strict_types=1);

/**
 * Uji kinerja dan skala (Phase 17). Membuat database SEMENTARA berisi puluhan ribu transaksi disetujui (jauh lebih besar dari
 * data nyata: 607 transaksi dalam 7 bulan), menjalankan server uji, lalu mengukur waktu respons halaman, laporan, ekspor,
 * dan view saldo, serta beban 20 permintaan serentak. Database sementara dihapus di akhir.
 * Tidak termasuk suite biasa (butuh ±1 menit dan menulis banyak data ke database uji):
 *   C:\xampp\php\php.exe tests\perf.php [--scale=1]      (scale 1 = ±36.000 transaksi; 3 = ±108.000)
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Migrator;
use App\Services\UserService;

$scale = 1;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--scale=(\d+)$/', $a, $m)) {
        $scale = max(1, min(10, (int) $m[1]));
    }
}
$perfDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test') . '_perf';
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
$server = Database::connect($admin[0], $admin[1], '');
$server->exec("DROP DATABASE IF EXISTS `{$perfDb}`");
$server->exec("CREATE DATABASE `{$perfDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $perfDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
(new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->migrate();

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
function one(string $sql): mixed
{
    return Database::pdo()->query($sql)->fetchColumn();
}

// ======================= data =======================
$t0 = microtime(true);
$nSav = 30000 * $scale;
$nLoan = 1200 * $scale;
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '2026-03-01', '2027-02-01', 'AKTIF')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) SELECT 1, DATE_ADD('2026-03-01', INTERVAL seq - 1 MONTH) FROM seq_1_to_12");
$pdo->exec("INSERT INTO members (member_no, name, address_block, active_from) SELECT CONCAT('AGT-', LPAD(seq, 4, '0')), CONCAT('Anggota Uji ', seq), CONCAT('E - ', seq), '2026-03-01' FROM seq_1_to_" . (120 * $scale));
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu 1', 1), ('Regu 2', 41), ('Regu 3', 81)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) SELECT id, LEAST(3, 1 + FLOOR((id - 1) / " . (40 * $scale) . ")), '2026-03-01' FROM members");
UserService::create('kepala', 'Kepala Uji', ['HEAD'], null, 'Contoh-Uji-2026');
$pdo->exec('UPDATE users SET must_change_password = 0');
$members = (int) one('SELECT COUNT(*) FROM members');

$ins = "INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, source, created_by)";
$pdo->exec("{$ins} SELECT CONCAT('TP-S-', seq), CONCAT('SMP-P-', LPAD(seq, 7, '0')), 'SIMPANAN', 1 + (seq % {$members}), (SELECT team_id FROM member_team_assignments WHERE member_id = 1 + (seq % {$members})), 1 + (seq % 12), DATE_ADD('2026-03-01', INTERVAL (seq % 12) MONTH), 50000 + (seq % 20) * 10000, 'DRAFT', 'IMPOR_EXCEL', NULL FROM seq_1_to_{$nSav}");
$pdo->exec("INSERT INTO savings (transaction_id, kind) SELECT id, 'WAJIB' FROM transactions WHERE type = 'SIMPANAN'");
$pdo->exec("{$ins} SELECT CONCAT('TP-L-', seq), CONCAT('PJM-P-', LPAD(seq, 7, '0')), 'PENCAIRAN_PINJAMAN', 1 + ((seq * 7) % {$members}), (SELECT team_id FROM member_team_assignments WHERE member_id = 1 + ((seq * 7) % {$members})), 1, '2026-03-01', 500000 + (seq % 10) * 100000, 'DRAFT', 'IMPOR_EXCEL', NULL FROM seq_1_to_{$nLoan}");
$pdo->exec("INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) SELECT id, member_id, amount, 3, 2.00, ROUND(amount * 0.02 * 3), trx_date FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'");
$pdo->exec("INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) SELECT l.id, s.seq, 1 + s.seq, IF(s.seq < 3, FLOOR((l.principal + l.total_interest) / 3), (l.principal + l.total_interest) - 2 * FLOOR((l.principal + l.total_interest) / 3)) FROM loans l JOIN seq_1_to_3 s");
$pdo->exec("{$ins} SELECT CONCAT('TP-A-', li.id), CONCAT('ANG-P-', li.id), 'ANGSURAN', l.member_id, (SELECT team_id FROM member_team_assignments WHERE member_id = l.member_id), li.due_month_id, DATE_ADD('2026-03-01', INTERVAL li.due_month_id - 1 MONTH), li.amount_due, 'DRAFT', 'IMPOR_EXCEL', NULL FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.seq <= 2");
$pdo->exec("INSERT INTO installment_payments (transaction_id, installment_id, amount) SELECT t.id, li.id, li.amount_due FROM loan_installments li JOIN transactions t ON t.doc_no = CONCAT('ANG-P-', li.id)");
$pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE status = 'DRAFT'");
$pdo->exec("UPDATE transactions SET status = 'DISETUJUI', status_changed_at = NOW() WHERE status = 'MENUNGGU_VALIDASI'");
$total = (int) one("SELECT COUNT(*) FROM transactions WHERE status = 'DISETUJUI'");
printf("Data uji: %d transaksi disetujui (%d simpanan, %d pinjaman, %d angsuran), %d anggota, dimuat dalam %.1f detik.\n", $total, $nSav, $nLoan, (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'ANGSURAN'"), $members, microtime(true) - $t0);
check('data: skala uji tercapai dan keuangan sehat (selisih 0, tanpa masalah integritas)', $total >= 30000 * $scale && (int) one('SELECT selisih FROM v_global_summary') === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);

// ======================= server =======================
$envFile = '.env.perf';
file_put_contents(dirname(__DIR__) . '/' . $envFile, implode("\n", ['APP_ENV=production', 'APP_DEBUG=false', 'APP_BASE_PATH=/', 'SESSION_IDLE_TIMEOUT=3000', 'DB_HOST=127.0.0.1', 'DB_PORT=3306', "DB_NAME={$perfDb}", "DB_USER={$admin[0]}", "DB_PASS={$admin[1]}", "DB_ADMIN_USER={$admin[0]}", "DB_ADMIN_PASS={$admin[1]}"]) . "\n");
$port = 8092;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/tests/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/adem-perf.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/adem-perf.log', 'a']], $pp, dirname(__DIR__), array_merge(getenv(), ['APP_ENV_FILE' => $envFile]));
$cleanup = static function () use ($proc, $envFile, $perfDb, $admin): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (is_resource($proc)) {
        $status = proc_get_status($proc);
        @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        proc_terminate($proc);
    }
    @unlink(dirname(__DIR__) . '/' . $envFile);
    @unlink(sys_get_temp_dir() . '/adem-perf.log');
    try {
        Database::connect($admin[0], $admin[1], '')->exec("DROP DATABASE IF EXISTS `{$perfDb}`");
    } catch (Throwable $e) {
    }
};
register_shutdown_function($cleanup);
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port, $en, $es, 0.2)) {
        break;
    }
    usleep(100000);
}
$jar = tempnam(sys_get_temp_dir(), 'jar');
$http = static function (string $path, bool $post = false, array $form = []) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $t = microtime(true);
    $raw = (string) curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'ms' => $ms, 'bytes' => strlen($raw) - $size, 'body' => substr($raw, $size)];
};
$login = $http('/login');
preg_match('/name="_token" value="([a-f0-9]{64})"/', $login['body'], $tm);
$http('/login', true, ['_token' => $tm[1] ?? '', 'username' => 'kepala', 'password' => 'Contoh-Uji-2026']);

// ======================= waktu respons =======================
$pages = ['Dashboard' => '/', 'Riwayat transaksi (hal. 1)' => '/transaksi/riwayat', 'Riwayat transaksi (hal. dalam)' => '/transaksi/riwayat?page=300', 'Riwayat: cari anggota' => '/transaksi/riwayat?q=Anggota+Uji+77',
    'Simpanan' => '/transaksi/simpanan', 'Pinjaman' => '/transaksi/pinjaman', 'Angsuran' => '/transaksi/angsuran', 'Tagihan jatuh tempo' => '/transaksi/angsuran/tagihan', 'Data anggota' => '/master/anggota',
    'Laporan: rekap simpanan' => '/laporan/simpanan', 'Laporan: rekap pinjaman' => '/laporan/pinjaman', 'Laporan: rekap angsuran' => '/laporan/angsuran', 'Laporan: rekap saldo' => '/laporan/saldo',
    'Laporan: transaksi' => '/laporan/transaksi', 'Laporan: per regu' => '/laporan/regu', 'Laporan: per anggota' => '/laporan/anggota', 'Kartu anggota' => '/anggota/50', 'Laporan: kartu anggota' => '/laporan/anggota/50',
    'Antrean validasi' => '/validasi', 'Audit log' => '/sistem/audit', 'Denyut /live/tick' => '/live/tick', 'Unduh CSV transaksi' => '/laporan/transaksi/unduh', 'Unduh CSV simpanan' => '/laporan/simpanan/unduh'];
$limits = ['Dashboard' => 2000, 'Unduh CSV transaksi' => 6000, 'Unduh CSV simpanan' => 4000, 'Denyut /live/tick' => 150];
$slow = [];
$table = [];
foreach ($pages as $label => $path) {
    $http($path);   // pemanasan (cache InnoDB)
    $times = [];
    $r = null;
    for ($k = 0; $k < 3; $k++) {
        $r = $http($path);
        $times[] = $r['ms'];
    }
    sort($times);
    $med = $times[1];
    $limit = $limits[$label] ?? 2500;
    $ok = $r['status'] === 200 && $med <= $limit;
    $table[] = sprintf('  %-34s %7.0f ms  %7.0f KB  %s', $label, $med, $r['bytes'] / 1024, $r['status'] !== 200 ? 'STATUS ' . $r['status'] : ($med > $limit ? "LAMBAT (> {$limit})" : 'ok'));
    if (!$ok) {
        $slow[] = "{$label} " . round($med) . ' ms (status ' . $r['status'] . ')';
    }
}
echo implode("\n", $table), "\n";
check('kinerja: setiap halaman, laporan, dan ekspor menjawab 200 dalam batas waktu pada ' . $total . ' transaksi' . ($slow ? ' [' . implode('; ', $slow) . ']' : ''), $slow === []);

// ======================= view saldo langsung =======================
$viewMs = [];
foreach (['v_global_summary', 'v_member_savings', 'v_loan_balances', 'v_installment_status', 'v_overdue_installments', 'v_integrity_issues'] as $view) {
    $t = microtime(true);
    $pdo->query("SELECT * FROM {$view}")->fetchAll();
    $viewMs[$view] = (microtime(true) - $t) * 1000;
}
echo '  view: ' . implode(', ', array_map(static fn (string $k, float $v): string => sprintf('%s %.0f ms', $k, $v), array_keys($viewMs), $viewMs)) . "\n";
check('kinerja: setiap view saldo selesai dalam 2 detik pada skala uji (saldo dihitung dari transaksi, bukan disimpan)', max($viewMs) < 2000);

// ======================= profil dashboard (bagian mana yang menentukan waktu) =======================
$_SESSION = [];
$head = App\Models\User::findActive((int) one("SELECT id FROM users WHERE username = 'kepala'"));
$prof = [
    'Dashboard::summary (v_global_summary)' => static fn () => App\Models\Dashboard::summary(),
    'Dashboard::integrityIssues (v_integrity_issues)' => static fn () => App\Models\Dashboard::integrityIssues(),
    'Dashboard::monthly (arus per bulan)' => static fn () => App\Models\Dashboard::monthly($head),
    'Dashboard::teams' => static fn () => App\Models\Dashboard::teams(),
    'Dashboard::recent' => static fn () => App\Models\Dashboard::recent($head, 6),
    'Transaction::dueByMember (tagihan)' => static fn () => App\Models\Transaction::dueByMember($head),
];
$profRows = [];
foreach ($prof as $label => $fn) {
    $fn();
    $t = microtime(true);
    $fn();
    $profRows[] = sprintf('%s %.0f ms', $label, (microtime(true) - $t) * 1000);
}
echo "  profil dashboard: " . implode("\n                    ", $profRows) . "\n";
// ======================= beban serentak =======================
$mh = curl_multi_init();
$hs = [];
for ($k = 0; $k < 20; $k++) {
    $ch = curl_init("http://127.0.0.1:{$port}" . ($k % 2 === 0 ? '/' : '/transaksi/riwayat'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120]);
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
$t = microtime(true);
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
} while ($running > 0);
$wall = microtime(true) - $t;
$codes = [];
foreach ($hs as $ch) {
    $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
printf("  20 permintaan serentak (dashboard dan riwayat): %.1f detik total, status %s\n", $wall, implode(',', array_unique($codes)));
check('beban: 20 permintaan serentak semuanya 200 (server bawaan PHP satu proses: waktu total dilaporkan, bukan dibatasi)', $codes === array_fill(0, 20, 200));

$cleanup();
echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
