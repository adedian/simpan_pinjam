<?php
declare(strict_types=1);

/**
 * Tes pencarian anggota untuk kotak pilih anggota (MemberLookup) dan saringan ?anggota= di daftar/laporan.
 * MENGOSONGKAN database uji.   C:\xampp\php\php.exe tests\lookup.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Member;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\Validation;
use App\Services\MemberLookup;
use App\Services\Migrator;
use App\Services\OverdueService;
use App\Services\ReportService;
use App\Services\UserService;

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
/** @return array<int,string> nomor anggota hasil pencarian */
function nos(?array $user, string $ctx, string $q = '', array $opts = [], int $offset = 0, int $limit = 30): array
{
    return array_column(MemberLookup::search($user, $ctx, $q, $opts, $offset, $limit)['items'], 'member_no');
}

// ---------------- fixture ----------------
$pdo->exec("INSERT INTO periods (name, start_date, end_date) VALUES ('Uji','2026-03-01','2027-02-28')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1,'2026-03-01'),(1,'2026-04-01')");
$names = [1 => 'Ketua Alfa', 2 => 'Siti Aminah', 3 => 'Ketua Beta', 4 => 'Siti Aminah', 5 => 'Budi Santoso'];   // 2 dan 4 sama-sama "Siti Aminah"
foreach ($names as $n => $name) {
    $pdo->prepare("INSERT INTO members (member_no, name, address_block, active_from) VALUES (?, ?, ?, '2026-03-01')")->execute([sprintf('AGT-%03d', $n), $name, "B - {$n}"]);
}
$pdo->exec("INSERT INTO members (member_no, name, status, active_from) VALUES ('AGT-006', 'Nonaktif Alfa', 'NONAKTIF', '2026-03-01')");
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'2026-03-01'),(2,1,'2026-03-01'),(3,2,'2026-03-01'),(4,2,'2026-03-01'),(5,2,'2026-03-01'),(6,1,'2026-03-01')");

$head   = ['id' => 1, 'roles' => ['HEAD'], 'member_id' => null, 'team_id' => null];
$alfa   = ['id' => 2, 'roles' => ['KETUA_REGU'], 'member_id' => 1, 'team_id' => 1];
$beta   = ['id' => 3, 'roles' => ['KETUA_REGU'], 'member_id' => 3, 'team_id' => 2];
$self   = ['id' => 4, 'roles' => ['ANGGOTA'], 'member_id' => 4, 'team_id' => null];
$noTeam = ['id' => 5, 'roles' => ['KETUA_REGU'], 'member_id' => 1, 'team_id' => null];

// ======================= hak akses per konteks =======================
check('akses: catat hanya untuk Ketua Regu yang punya regu', MemberLookup::allowed($alfa, 'catat') && !MemberLookup::allowed($head, 'catat') && !MemberLookup::allowed($self, 'catat') && !MemberLookup::allowed($noTeam, 'catat') && !MemberLookup::allowed(null, 'catat'));
check('akses: lihat untuk Superadmin dan Ketua Regu, bukan Anggota', MemberLookup::allowed($head, 'lihat') && MemberLookup::allowed($alfa, 'lihat') && !MemberLookup::allowed($self, 'lihat'));
check('akses: ketua dan akun hanya Superadmin', MemberLookup::allowed($head, 'ketua') && MemberLookup::allowed($head, 'akun') && !MemberLookup::allowed($alfa, 'ketua') && !MemberLookup::allowed($alfa, 'akun') && !MemberLookup::allowed($self, 'akun'));
check('akses: konteks tak dikenal ditolak', !MemberLookup::allowed($head, 'semua') && MemberLookup::search($head, 'semua')['items'] === []);
check('akses: ditolak -> hasil kosong, bukan galat', MemberLookup::search($self, 'lihat')['items'] === [] && MemberLookup::search($alfa, 'ketua')['items'] === [] && MemberLookup::find($self, 'lihat', 4) === null);

// ======================= catat (form transaksi) =======================
check('catat: Ketua Alfa hanya melihat anggota AKTIF regunya', nos($alfa, 'catat') === ['AGT-001', 'AGT-002']);
$sorted = static function (array $a): array { sort($a); return $a; };
check('catat: Ketua Beta hanya melihat regunya', $sorted(nos($beta, 'catat')) === ['AGT-003', 'AGT-004', 'AGT-005']);
check('catat: anggota nonaktif tidak muncul', !in_array('AGT-006', nos($alfa, 'catat'), true) && !in_array('AGT-006', nos($alfa, 'catat', 'Nonaktif'), true));
check('catat: mencari nama anggota regu lain -> kosong (tidak bocor)', nos($alfa, 'catat', 'Budi') === [] && nos($alfa, 'catat', 'AGT-005') === []);
check('catat: parameter regu/termasuk dari klien tidak memperluas hasil', nos($alfa, 'catat', '', ['team' => 2, 'include' => 5]) === ['AGT-001', 'AGT-002']);
check('catat: find id anggota regu lain = null', MemberLookup::find($alfa, 'catat', 5) === null && MemberLookup::find($alfa, 'catat', 2) !== null);
check('catat: find id nonaktif / nol / negatif = null', MemberLookup::find($alfa, 'catat', 6) === null && MemberLookup::find($alfa, 'catat', 0) === null && MemberLookup::find($alfa, 'catat', -1) === null && MemberLookup::find($alfa, 'catat', '') === null);

// ======================= pencarian =======================
check('cari: nama sebagian, tak peka huruf besar', nos($head, 'lihat', 'bUDi') === ['AGT-005']);
check('cari: nomor anggota persis', nos($head, 'lihat', 'AGT-004') === ['AGT-004']);
$pdo->exec("INSERT INTO members (member_no, name, active_from) VALUES ('ZZZ-001', 'AGT-001 Palsu', '2026-03-01')");
check('cari: nomor persis diurutkan di atas nama yang kebetulan berawalan sama', nos($head, 'lihat', 'AGT-001') === ['AGT-001', 'ZZZ-001']);
$pdo->exec("DELETE FROM members WHERE member_no = 'ZZZ-001'");
check('cari: banyak kata (semuanya harus cocok)', nos($head, 'lihat', 'ketua beta') === ['AGT-003'] && nos($head, 'lihat', 'ketua santoso') === []);
check('cari: "%" dan "_" dibaca harfiah', nos($head, 'lihat', '%') === [] && nos($head, 'lihat', '_') === []);
check('cari: injeksi SQL tidak berefek', nos($head, 'lihat', "' OR '1'='1") === [] && nos($head, 'lihat', 'x\\') === []);
check('cari: tanpa hasil', MemberLookup::search($head, 'lihat', 'tidak-ada-orang-ini') === ['items' => [], 'more' => false]);
$dupes = MemberLookup::search($head, 'lihat', 'siti aminah')['items'];
check('nama sama: kedua anggota muncul dengan nomor dan regu berbeda', count($dupes) === 2 && $dupes[0]['member_no'] !== $dupes[1]['member_no'] && array_column($dupes, 'team') !== [] && count(array_unique(array_column($dupes, 'team'))) === 2);
check('nama sama: label memuat nomor anggota untuk membedakan', $dupes[0]['label'] === 'Siti Aminah (' . $dupes[0]['member_no'] . ')');
check('hasil hanya memuat nama, nomor, status, regu, blok', array_keys($dupes[0]) === ['id', 'member_no', 'name', 'status', 'team', 'block', 'label']);

// ======================= lihat (saringan) =======================
check('lihat: Superadmin melihat semua anggota (termasuk nonaktif)', count(nos($head, 'lihat')) === 6);
check('lihat: Ketua Alfa hanya regunya (termasuk yang nonaktif)', $sorted(nos($alfa, 'lihat')) === ['AGT-001', 'AGT-002', 'AGT-006']);
check('lihat: Ketua Alfa tidak bisa mencari anggota Beta', nos($alfa, 'lihat', 'Budi') === [] && MemberLookup::find($alfa, 'lihat', 5) === null && MemberLookup::find($head, 'lihat', 5) !== null);
$nonaktif = MemberLookup::find($head, 'lihat', 6);
check('lihat: anggota nonaktif ditandai', $nonaktif !== null && $nonaktif['status'] === 'NONAKTIF');

// ======================= banyak anggota: halaman =======================
for ($i = 1; $i <= 60; $i++) {
    $pdo->prepare("INSERT INTO members (member_no, name, active_from) VALUES (?, ?, '2026-03-01')")->execute([sprintf('MSL-%03d', $i), sprintf('Massal %02d', $i)]);
    $pdo->prepare('INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?, 1, ?)')->execute([(int) $pdo->lastInsertId(), '2026-03-01']);
}
$p1 = MemberLookup::search($head, 'lihat', 'Massal', [], 0, 20);
$p2 = MemberLookup::search($head, 'lihat', 'Massal', [], 20, 20);
$p3 = MemberLookup::search($head, 'lihat', 'Massal', [], 40, 20);
check('halaman: 60 hasil -> 20 + 20 + 20, "more" benar', count($p1['items']) === 20 && $p1['more'] && count($p2['items']) === 20 && $p2['more'] && count($p3['items']) === 20 && !$p3['more']);
check('halaman: tanpa tumpang tindih dan berurutan', array_intersect(array_column($p1['items'], 'id'), array_column($p2['items'], 'id')) === [] && $p1['items'][0]['name'] === 'Massal 01' && $p3['items'][19]['name'] === 'Massal 60');
check('halaman: batas ukuran dijepit (limit 9999 -> 30)', count(MemberLookup::search($head, 'lihat', 'Massal', [], 0, 9999)['items']) === MemberLookup::MAX_LIMIT);
check('halaman: offset di luar data -> kosong', MemberLookup::search($head, 'lihat', 'Massal', [], 500, 20) === ['items' => [], 'more' => false]);
check('halaman: kata kunci dipotong 60 karakter tanpa galat', is_array(MemberLookup::search($head, 'lihat', str_repeat('a', 500))['items']));

// ======================= ketua regu (form regu) =======================
$pdo->exec("INSERT INTO members (member_no, name, active_from) VALUES ('AGT-900', 'Calon Bebas', '2026-03-01')");
$pdo->exec('INSERT INTO member_team_assignments (member_id, team_id, valid_from) SELECT id, 1, \'2026-03-01\' FROM members WHERE member_no = \'AGT-900\'');
check('ketua/baru: yang sudah memimpin regu tidak muncul', !in_array('AGT-001', nos($head, 'ketua'), true) && !in_array('AGT-003', nos($head, 'ketua'), true) && in_array('AGT-900', nos($head, 'ketua'), true));
$all = static function (string $ctx, array $opts) use ($head): array {
    $out = [];
    for ($o = 0; $o < 300; $o += MemberLookup::MAX_LIMIT) {
        $page = MemberLookup::search($head, $ctx, '', $opts, $o, MemberLookup::MAX_LIMIT);
        $out = array_merge($out, array_column($page['items'], 'member_no'));
        if (!$page['more']) {
            break;
        }
    }
    sort($out);
    return $out;
};
$cand = array_column(Team::leaderCandidates(null), 'member_no');
sort($cand);
check('ketua/baru: sama persis dengan Team::leaderCandidates(null), dibaca per halaman', $all('ketua', []) === $cand && count($cand) > MemberLookup::MAX_LIMIT);
check('ketua/ubah: hanya anggota regu itu, tanpa ketua regu lain', in_array('AGT-001', nos($head, 'ketua', 'AGT', ['team' => 1]), true) && !in_array('AGT-003', nos($head, 'ketua', 'AGT', ['team' => 1]), true) && !in_array('AGT-005', nos($head, 'ketua', 'AGT', ['team' => 1]), true));
$cand2 = array_column(Team::leaderCandidates(2), 'member_no');
sort($cand2);
check('ketua/ubah: sama persis dengan Team::leaderCandidates(regu)', $cand2 !== [] && $all('ketua', ['team' => 2]) === $cand2);
check('ketua/ubah: anggota nonaktif tidak muncul', !in_array('AGT-006', nos($head, 'ketua', '', ['team' => 1]), true));

// ======================= akun (form pengguna) =======================
UserService::create('alfa', 'Ketua Alfa', ['KETUA_REGU'], 'AGT-001', 'Contoh-Uji-2026');
check('akun: yang sudah punya akun tidak muncul', !in_array('AGT-001', nos($head, 'akun', 'AGT'), true) && in_array('AGT-002', nos($head, 'akun', 'AGT'), true));
check('akun: anggota yang sedang diedit (termasuk) tetap muncul', in_array('AGT-001', nos($head, 'akun', 'AGT', ['include' => 1]), true));
check('akun: anggota nonaktif tidak muncul', !in_array('AGT-006', nos($head, 'akun', 'AGT'), true));
$byNo = MemberLookup::find($head, 'akun', 'AGT-002');
check('akun: find memakai nomor anggota', $byNo !== null && $byNo['id'] === 2 && MemberLookup::find($head, 'akun', 'AGT-001') === null && MemberLookup::find($head, 'akun', 'AGT-001', ['include' => 1]) !== null);
check('akun: find nomor tak dikenal / berisi tanda kutip = null', MemberLookup::find($head, 'akun', 'XXX') === null && MemberLookup::find($head, 'akun', "AGT-002' OR '1'='1") === null);

// Validasi ulang saat menyimpan (tidak hanya bergantung pada kotak pilih)
$err = null;
try {
    UserService::create('baru1', 'Baru Satu', ['ANGGOTA'], 'AGT-006', 'Contoh-Uji-2026');   // nonaktif
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
check('akun/simpan: menautkan anggota nonaktif ditolak server', $err !== null && str_contains($err, 'tidak aktif'));
$err = null;
try {
    UserService::create('baru2', 'Baru Dua', ['ANGGOTA'], 'AGT-777', 'Contoh-Uji-2026');   // tidak ada
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
check('akun/simpan: nomor anggota ngawur ditolak server', $err !== null && str_contains($err, 'tidak ditemukan'));
$uid = (int) $pdo->query("SELECT id FROM users WHERE username = 'alfa'")->fetchColumn();
$pdo->exec("UPDATE members SET status = 'NONAKTIF' WHERE member_no = 'AGT-001'");
$ver = (string) $pdo->query("SELECT updated_at FROM users WHERE id = {$uid}")->fetchColumn();
$err = null;
try {
    UserService::update(new App\Core\Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1']), ['id' => 1, 'username' => 'kepala', 'roles' => ['HEAD']], $uid, 'Ketua Alfa Baru', ['KETUA_REGU'], 'AGT-001', $ver);
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
check('akun/ubah: tautan yang sudah ada tetap boleh meski anggotanya kini nonaktif', $err === null);
$pdo->exec("UPDATE members SET status = 'AKTIF' WHERE member_no = 'AGT-001'");

// ======================= saringan ?anggota= =======================
$ids = array_column($pdo->query("SELECT id, member_no FROM members WHERE member_no IN ('AGT-002','AGT-004','AGT-005')")->fetchAll(), 'id', 'member_no');
check('saringan/anggota: Member::search dengan id', array_column(Member::search($head, ['member' => (int) $ids['AGT-004']], 1)['rows'], 'member_no') === ['AGT-004']);
check('saringan/anggota: Member::search Ketua Alfa + id anggota Beta = kosong (cakupan menang)', Member::search($alfa, ['member' => (int) $ids['AGT-004']], 1)['pager']['total'] === 0);
$f = ReportService::filters(['anggota' => (string) $ids['AGT-005']], $head);
check('saringan/anggota: ReportService::members dengan id', array_column(ReportService::members($head, $f), 'member_no') === ['AGT-005']);
check('saringan/anggota: ReportService::members Ketua Alfa + id anggota Beta = kosong', ReportService::members($alfa, ReportService::filters(['anggota' => (string) $ids['AGT-005']], $alfa)) === []);
check('saringan/anggota: tanpa id, semua anggota cakupan', count(ReportService::members($head, ReportService::filters([], $head))) === count($pdo->query('SELECT 1 FROM members')->fetchAll()));
check('saringan/anggota: id ngawur (teks/negatif) -> diabaikan', ReportService::filters(['anggota' => 'abc'], $head)['anggota'] === 0 && ReportService::filters(['anggota' => '-5'], $head)['anggota'] === 0);
check('saringan/anggota: Transaction::search dengan id yang tidak punya transaksi = kosong', Transaction::search($head, ['member' => (int) $ids['AGT-004']], 1)['pager']['total'] === 0);
check('saringan/anggota: OverdueService::items menerima anggota', OverdueService::items($head, ['anggota' => (int) $ids['AGT-004']]) === []);
check('saringan/anggota: Validation::queue dan decisions menerima member', Validation::queue($head, ['member' => (int) $ids['AGT-004']], 1)['rows'] === [] && Validation::decisions(['member' => (int) $ids['AGT-004']], 1)['rows'] === []);
foreach (['simpanan', 'pinjaman', 'angsuran', 'saldo', 'transaksi', 'bagihasil'] as $key) {
    $r = ReportService::build($key, $head, ReportService::filters(['anggota' => (string) $ids['AGT-005']], $head), 1);
    check("saringan/anggota: laporan {$key} dibangun dengan saringan anggota", is_array($r) && isset($r['rows']) && count($r['rows']) <= 2);
}

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
