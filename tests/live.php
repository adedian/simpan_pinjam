<?php
declare(strict_types=1);

/**
 * Tes penanda jumlah di menu (Phase 14): LiveFeed::badges() menghitung HANYA transaksi menunggu yang boleh
 * divalidasi pengguna itu, sama dengan penilaian di antrean; pengguna tanpa izin tidak mendapat apa pun.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\live.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Models\Validation;
use App\Services\LiveFeed;
use App\Services\Migrator;
use App\Services\Navigation;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-live']);
}
function saving(array $actor, int $member, string $amount, bool $submit = true): int
{
    global $mid0, $today;
    [$e, $d] = SavingService::parse(['member_id' => $member, 'period_month_id' => $mid0, 'kind' => 'SUKARELA', 'amount' => $amount, 'trx_date' => $today, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return SavingService::create(req(), $actor, $d, $submit);
}
/** Simpanan mentah berstatus MENUNGGU_VALIDASI (Ketua Regu hanya boleh mencatat untuk regunya, jadi kasus lintas regu disisipkan langsung). */
function rawPending(int $memberId, int $teamId, int $createdBy, int $amount): int
{
    static $n = 0;
    global $mid0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute(["T-RAW-{$n}", "RAW-{$n}", 'SIMPANAN', $memberId, $teamId, $mid0, date('Y-m-d'), $amount, $createdBy]);
    $id = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$id}, 'SUKARELA')");
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI', status_changed_at = NOW() WHERE id = {$id}");
    return $id;
}
/** Angka antrean menurut penilaian yang sama dengan layar antrean (pembanding independen dari LiveFeed). */
function queueEligible(array $user): int
{
    $result = Validation::queue($user, [], 1);
    return count(array_filter($result['rows'], static fn (array $r): bool => $r['verdict']['eligible']));
}

// ---------------- fixture (sama dengan tes validasi) ----------------
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m1}', '" . date('Y-m-01', strtotime('+3 month', strtotime($m0))) . "', 'AKTIF')");
foreach ([$m1, $m0] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid0  = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m0]);
$today = date('Y-m-d');
foreach ([1 => 'Ketua Alfa (Head)', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Pemeriksa'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m1, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m1}'),(2,1,'{$m1}'),(3,2,'{$m1}'),(4,2,'{$m1}'),(5,2,'{$m1}')");

$ids = [];
foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala2', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota4', ['ANGGOTA'], 'AGT-004']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$ids['periksa.m'] = UserService::create('periksa.m', 'Periksa Anggota', ['PEMERIKSA'], null, 'Contoh-Uji-2026')['id'];
$pdo->exec("UPDATE users SET member_id = 5 WHERE username = 'periksa.m'");   // Pemeriksa yang juga anggota (member 5)
$purwati  = User::findActive($ids['purwati']);
$beta     = User::findActive($ids['ketua.beta']);
$head     = User::findActive($ids['kepala2']);
$periksa  = User::findActive($ids['periksa']);
$periksaM = User::findActive($ids['periksa.m']);
$anggota4 = User::findActive($ids['anggota4']);
$validators = ['purwati' => $purwati, 'kepala2' => $head, 'periksa' => $periksa, 'periksa.m' => $periksaM];

$badge = static fn (array $u): ?int => LiveFeed::badges($u)['validasi'] ?? null;

// ======================= izin =======================
check('izin: pengguna tanpa izin validasi (Ketua Regu, Anggota) dan tanpa sesi tidak mendapat penanda apa pun', LiveFeed::badges($beta) === [] && LiveFeed::badges($anggota4) === [] && LiveFeed::badges(null) === []);
check('izin: antrean kosong = 0 (bukan tanpa penanda) untuk Head dan Pemeriksa', LiveFeed::badges($head) === ['validasi' => 0] && LiveFeed::badges($periksa) === ['validasi' => 0] && LiveFeed::badges($purwati) === ['validasi' => 0]);

// ======================= penghitungan menurut kewenangan =======================
$byPurwati = saving($purwati, 2, '10.000');   // dibuat Head, regu Head: terkait Head
check('hitung: transaksi buatan Head — pembuat tidak menghitungnya, Head lain tidak boleh (terkait Head), Pemeriksa boleh',
    $badge($purwati) === 0 && $badge($head) === 0 && $badge($periksa) === 1 && $badge($periksaM) === 1);

$byBeta = saving($beta, 4, '20.000');          // biasa (regu Beta)
check('hitung: transaksi biasa menambah untuk semua validator (pembuat Ketua Beta bukan validator)',
    $badge($purwati) === 1 && $badge($head) === 1 && $badge($periksa) === 2 && $badge($periksaM) === 2);

$forPeriksaM = saving($beta, 5, '30.000');     // atas nama anggota yang tertaut ke Pemeriksa periksa.m
check('hitung: atas nama anggota yang tertaut ke validator tidak dihitung untuk validator itu saja',
    $badge($periksaM) === 2 && $badge($periksa) === 3 && $badge($purwati) === 2 && $badge($head) === 2);

$draft = saving($beta, 4, '40.000', false);    // hanya draft
check('hitung: draft tidak dihitung', $badge($head) === 2 && $badge($periksa) === 3);

$forPurwati = saving($beta, 3, '50.000');      // atas nama Ketua Beta: biasa, bukan terkait Head, bukan atas nama Purwati
$byBeta2    = rawPending(2, 1, (int) $beta['id'], 60000);   // anggota regu Alfa (dipimpin Purwati): Purwati tak boleh (regu yang dipimpin) dan terkait Head
check('hitung: regu yang dipimpin validator tidak dihitung untuk validator itu; terkait Head hanya Pemeriksa',
    $badge($purwati) === 3 && $badge($head) === 3 && $badge($periksa) === 5 && $badge($periksaM) === 4);

// ======================= sama dengan layar antrean =======================
$same = true;
foreach ($validators as $user) {
    $same = $same && $badge($user) === queueEligible($user);
}
check('konsisten: angka tiap validator sama dengan jumlah baris "Bisa divalidasi" di antrean', $same);

// ======================= keluar dari antrean =======================
ValidationService::approve(req(), $periksa, $byBeta, (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$byBeta]), null);
check('turun: transaksi yang disetujui keluar dari angka semua validator', $badge($purwati) === 2 && $badge($head) === 2 && $badge($periksa) === 4 && $badge($periksaM) === 3);
ValidationService::reject(req(), $periksa, $byBeta2, (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$byBeta2]), 'tes');
$stillSame = true;
foreach ($validators as $user) {
    $stillSame = $stillSame && $badge($user) === queueEligible($user);
}
check('turun: ditolak keluar dari angka; angka tetap sama dengan antrean setelah keputusan', $stillSame && $badge($periksa) === 3);
$pdo->exec("UPDATE transactions SET status = 'DIBATALKAN' WHERE id = {$forPurwati}");
check('turun: dibatalkan pembuat juga keluar dari angka', $badge($head) === queueEligible($head) && $badge($periksa) === 2);

// ======================= bentuk dan menu =======================
$b = LiveFeed::badges($periksa);
check('bentuk: hanya kunci "validasi" bernilai bilangan bulat tak negatif (tanpa nomor dokumen atau nominal)', array_keys($b) === ['validasi'] && is_int($b['validasi']) && $b['validasi'] >= 0);

$menuKeys = [];
foreach ((array) Config::get('menu', []) as $group) {
    foreach ($group['items'] as $item) {
        if (!empty($item['badge'])) {
            $menuKeys[] = (string) $item['badge'];
        }
    }
}
check('menu: setiap kunci "badge" di config/menu.php dikenal LiveFeed::badges()', $menuKeys !== [] && array_diff($menuKeys, array_keys(LiveFeed::badges($head))) === []);
$navBadges = [];
foreach (Navigation::forUser($head, '/') as $g) {
    foreach ($g['items'] as $i) {
        if ($i['badge'] !== null) {
            $navBadges[$i['path']] = $i['badge'];
        }
    }
}
check('menu: hanya "Menunggu Validasi" membawa penanda, dan Ketua Regu tidak melihat item itu sama sekali', $navBadges === ['/validasi' => 'validasi']
    && !in_array('/validasi', array_map(static fn (array $i): string => $i['path'], array_merge(...array_column(Navigation::forUser($beta, '/'), 'items'))), true));

// ======================= tampilan menu =======================
$navHead = Navigation::forUser($head, '/');
$side0   = \App\Core\View::include('partials/sidebar', ['nav' => $navHead, 'badges' => ['validasi' => 0]]);
$side7   = \App\Core\View::include('partials/sidebar', ['nav' => $navHead, 'badges' => ['validasi' => 7]]);
$sideNone = \App\Core\View::include('partials/sidebar', ['nav' => $navHead, 'badges' => []]);
check('tampil: angka 0 = penanda ada tetapi tersembunyi (agar bisa muncul tanpa muat ulang)', preg_match('/<b class="nav__badge" data-badge="validasi"[^>]*\shidden>\s*<bdi data-badge-n>0</', $side0) === 1);
check('tampil: angka 7 = penanda terlihat dengan teks bantu pembaca layar', preg_match('/<b class="nav__badge" data-badge="validasi"[^>]*>\s*<bdi data-badge-n>7<\/bdi><bdi class="sr-only"> menunggu tindakan Anda/', $side7) === 1 && !preg_match('/data-badge="validasi"[^>]*\shidden/', $side7));
check('tampil: tanpa data penanda dianggap 0; hanya satu penanda di seluruh menu', preg_match('/data-badge="validasi"[^>]*\shidden>\s*<bdi data-badge-n>0</', $sideNone) === 1 && substr_count($side7, 'data-badge="') === 1);
check('tampil: nama kunci dipantulkan aman dan angka bulat (tanpa HTML dari luar)', !str_contains($side7, '<script') && preg_match('/<bdi data-badge-n>\d+<\/bdi>/', $side7) === 1);

echo "\n" . ($failed === [] ? "Semua {$passed} pemeriksaan lulus.\n" : count($failed) . " GAGAL dari " . ($passed + count($failed)) . ".\n");
exit($failed === [] ? 0 : 1);
