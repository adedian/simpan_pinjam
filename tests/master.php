<?php
declare(strict_types=1);

/**
 * Tes master data: anggota, regu, pengguna, pengaturan, pencarian. MENGOSONGKAN database uji.
 *   C:\xampp\php\php.exe tests\master.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth;
use App\Services\MemberService;
use App\Services\Migrator;
use App\Services\PasswordPolicy;
use App\Services\RuleViolation;
use App\Services\SettingsService;
use App\Services\TeamService;
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
function one(string $sql, array $params = []): mixed
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
function req(): Request
{
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-master']);
}
function audits(string $action): int
{
    return (int) one('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
}
function lastAudit(string $action): array
{
    $row = Database::pdo()->prepare('SELECT * FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1');
    $row->execute([$action]);
    $r = $row->fetch() ?: [];
    $r['before'] = isset($r['before_data']) ? json_decode((string) $r['before_data'], true) : null;
    $r['after']  = isset($r['after_data']) ? json_decode((string) $r['after_data'], true) : null;
    return $r;
}
/** Jalankan aksi yang HARUS melanggar aturan; kembalikan galat per field atau null bila tidak melanggar. */
function violation(callable $fn): ?array
{
    try {
        $fn();
    } catch (RuleViolation $e) {
        return $e->errors;
    } catch (InvalidArgumentException $e) {
        return ['_form' => $e->getMessage()];
    }
    return null;
}
function data(array $over = []): array
{
    [$errors, $d] = MemberService::parse($over + ['name' => 'Bu Baru', 'address_block' => 'Z - 1', 'active_from' => '2026-04', 'status' => 'AKTIF', 'team_id' => 1]);
    if ($errors !== []) {
        throw new RuntimeException('fixture data tidak valid: ' . json_encode($errors));
    }
    return $d;
}

// ---------------- fixture ----------------
$pdo->exec("INSERT INTO periods (name, start_date, end_date) VALUES ('Uji','2026-03-01','2027-02-28')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1,'2026-03-01'),(1,'2026-04-01'),(1,'2026-05-01'),(1,'2026-06-01'),(1,'2026-07-01'),(1,'2026-08-01')");
foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Peminjam Beta'] as $n => $name) {
    $pdo->prepare("INSERT INTO members (member_no, name, address_block, active_from) VALUES (?, ?, ?, '2026-03-01')")->execute([sprintf('AGT-%03d', $n), $name, "B - {$n}"]);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'2026-03-01'),(2,1,'2026-03-01'),(3,2,'2026-03-01'),(4,2,'2026-03-01'),(5,2,'2026-03-01')");

// satu pinjaman DISETUJUI untuk anggota 5 (5.000.000, tenor 2) + simpanan 400.000 untuk anggota 4
$approve = function (int $id) use ($pdo): void {
    $pdo->exec("UPDATE transactions SET status='MENUNGGU_VALIDASI' WHERE id={$id}");
    $pdo->exec("UPDATE transactions SET status='DISETUJUI' WHERE id={$id}");
};
$pdo->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('T-S1','S-1','SIMPANAN',4,1,'2026-03-01',400000)");
$pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES (LAST_INSERT_ID(),'WAJIB')");
$approve((int) $pdo->query('SELECT MAX(id) FROM transactions')->fetchColumn());
$pdo->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('T-L1','P-1','PENCAIRAN_PINJAMAN',5,1,'2026-03-01',5000000)");
$ltrx = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES ({$ltrx},5,5000000,2,2.00,200000,'2026-03-01')");
$loanId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES ({$loanId},1,2,2600000),({$loanId},2,3,2600000)");
$approve($ltrx);

$head  = UserService::create('kepala', 'Kepala', ['HEAD'], null, 'Contoh-Uji-2026');
$ketuaUser = UserService::create('ketua.alfa', 'Ketua Alfa', ['KETUA_REGU'], 'AGT-001', 'Contoh-Uji-2026');
$actor = ['id' => $head['id'], 'username' => 'kepala', 'roles' => ['HEAD']];

// ======================= ANGGOTA =======================
[$e] = MemberService::parse(['name' => '', 'active_from' => '2026-13', 'status' => 'X', 'team_id' => 0]);
check('anggota/parse: nama kosong, bulan salah, status salah, regu kosong -> galat semua', isset($e['name'], $e['active_from'], $e['status'], $e['team_id']));
[$e, $d] = MemberService::parse(['name' => "  Bu   Spasi \t Ganda  ", 'active_from' => '2026-05', 'team_id' => 1]);
check('anggota/parse: spasi dirapikan dan bulan menjadi tanggal 1', $e === [] && $d['name'] === 'Bu Spasi Ganda' && $d['active_from'] === '2026-05-01');
check('anggota/parse: nama 101 karakter ditolak', isset(MemberService::parse(['name' => str_repeat('a', 101), 'active_from' => '2026-05', 'team_id' => 1])[0]['name']));

$id1 = MemberService::create(req(), $actor, data());
$m1 = $pdo->query("SELECT * FROM members WHERE id = {$id1}")->fetch();
check('anggota/buat: nomor berlanjut dari data yang ada (AGT-006)', $m1['member_no'] === 'AGT-006');
check('anggota/buat: penugasan regu dibuat dari bulan aktif', one('SELECT valid_from FROM member_team_assignments WHERE member_id = ? AND valid_to IS NULL', [$id1]) === '2026-04-01');
check('anggota/buat: audit MEMBER_CREATED', audits('MEMBER_CREATED') === 1 && lastAudit('MEMBER_CREATED')['reference_no'] === 'AGT-006');
$id2 = MemberService::create(req(), $actor, data(['name' => 'Bu Baru', 'address_block' => 'Z - 2']));
check('anggota/buat: nama sama beda blok boleh, nomor berikutnya', one('SELECT member_no FROM members WHERE id = ?', [$id2]) === 'AGT-007');
check('anggota/buat: nama dan blok sama ditolak', isset(violation(fn () => MemberService::create(req(), $actor, data()))['name']));
check('anggota/buat: regu tidak ada ditolak', isset(violation(fn () => MemberService::create(req(), $actor, data(['name' => 'X', 'team_id' => 999])))['team_id']));
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id, is_active) VALUES ('Regu Mati', 2, 0)");
$deadTeam = (int) $pdo->lastInsertId();
check('anggota/buat: regu nonaktif ditolak', isset(violation(fn () => MemberService::create(req(), $actor, data(['name' => 'Y', 'team_id' => $deadTeam])))['team_id']));
check('anggota/buat: gagal tidak meninggalkan baris atau nomor yang terbuang', (int) one('SELECT COUNT(*) FROM members') === 7 && (int) one("SELECT last_value FROM number_sequences WHERE seq_key='MEMBER'") === 7);
$pdo->exec("DELETE FROM team_leaders WHERE id = {$deadTeam}");

$ver = fn (int $id) => (string) one('SELECT updated_at FROM members WHERE id = ?', [$id]);
// ubah: hanya kolom yang berubah masuk audit
$before = audits('MEMBER_UPDATED');
MemberService::update(req(), $actor, $id1, data(['address_block' => 'Z - 9', 'is_manager' => 1]), $ver($id1));
$au = lastAudit('MEMBER_UPDATED');
check('anggota/ubah: audit memuat HANYA kolom yang berubah', $au['before'] === ['address_block' => 'Z - 1', 'is_manager' => 0] && $au['after'] === ['address_block' => 'Z - 9', 'is_manager' => 1]);
check('anggota/ubah: nilai tersimpan', (int) one('SELECT is_manager FROM members WHERE id = ?', [$id1]) === 1);
$sameVer = $ver($id1);
MemberService::update(req(), $actor, $id1, data(['address_block' => 'Z - 9', 'is_manager' => 1]), $sameVer);
check('anggota/ubah: tanpa perubahan tidak menulis audit', audits('MEMBER_UPDATED') === $before + 1);
check('anggota/ubah: versi basi (diedit orang lain) ditolak', str_contains((violation(fn () => MemberService::update(req(), $actor, $id1, data(['address_block' => 'Z - 10']), '2000-01-01 00:00:00'))['_form'] ?? ''), 'diubah oleh pengguna lain'));
check('anggota/ubah: duplikat dengan anggota lain ditolak', isset(violation(fn () => MemberService::update(req(), $actor, $id2, data(['name' => 'Bu Baru', 'address_block' => 'Z - 9']), $ver($id2)))['name']));
check('anggota/ubah: id tidak ada ditolak', isset(violation(fn () => MemberService::update(req(), $actor, 99999, data(), '2026-01-01 00:00:00'))['_form']));

// pindah regu dengan riwayat
$oldRows = (int) one('SELECT COUNT(*) FROM member_team_assignments WHERE member_id = 2');
MemberService::update(req(), $actor, 2, data(['name' => 'Anggota Alfa', 'address_block' => 'B - 2', 'active_from' => '2026-03', 'team_id' => 2]), $ver(2));
$hist = $pdo->query('SELECT team_id, valid_from, valid_to FROM member_team_assignments WHERE member_id = 2 ORDER BY id')->fetchAll();
check('pindah regu: penugasan lama ditutup H-1, yang baru dibuka hari ini', count($hist) === $oldRows + 1 && $hist[0]['valid_to'] === date('Y-m-d', strtotime('-1 day')) && $hist[1]['valid_from'] === date('Y-m-d') && $hist[1]['valid_to'] === null && (int) $hist[1]['team_id'] === 2);
check('pindah regu: audit MEMBER_TEAM_CHANGED', lastAudit('MEMBER_TEAM_CHANGED')['after']['team_id'] === 2 && lastAudit('MEMBER_TEAM_CHANGED')['before']['team_id'] === 1);
check('pindah regu: tetap satu penugasan aktif', (int) one('SELECT COUNT(*) FROM member_team_assignments WHERE member_id = 2 AND valid_to IS NULL') === 1);
check('pindah regu: akses ketua lama hilang, ketua baru bertambah', Member::findScoped(['roles' => ['KETUA_REGU'], 'team_id' => 1, 'member_id' => 1], 2) === null && Member::findScoped(['roles' => ['KETUA_REGU'], 'team_id' => 2, 'member_id' => 3], 2) !== null);
// koreksi di hari yang sama: baris yang sama diubah, riwayat tidak menggelembung
MemberService::update(req(), $actor, 2, data(['name' => 'Anggota Alfa', 'address_block' => 'B - 2', 'active_from' => '2026-03', 'team_id' => 1]), $ver(2));
check('pindah regu: koreksi di hari yang sama tidak menambah riwayat', (int) one('SELECT COUNT(*) FROM member_team_assignments WHERE member_id = 2') === $oldRows + 1 && (int) one('SELECT team_id FROM member_team_assignments WHERE member_id = 2 AND valid_to IS NULL') === 1);

// aturan penonaktifan dan ketua regu
check('anggota/nonaktif: ketua regu tidak bisa dinonaktifkan', isset(violation(fn () => MemberService::update(req(), $actor, 1, data(['name' => 'Ketua Alfa', 'address_block' => 'B - 1', 'active_from' => '2026-03', 'status' => 'NONAKTIF', 'team_id' => 1]), $ver(1)))['status']));
check('anggota/pindah: ketua regu tidak bisa dipindahkan', isset(violation(fn () => MemberService::update(req(), $actor, 1, data(['name' => 'Ketua Alfa', 'address_block' => 'B - 1', 'active_from' => '2026-03', 'team_id' => 2]), $ver(1)))['team_id']));
$v5 = violation(fn () => MemberService::update(req(), $actor, 5, data(['name' => 'Peminjam Beta', 'address_block' => 'B - 5', 'active_from' => '2026-03', 'status' => 'NONAKTIF', 'team_id' => 2]), $ver(5)));
check('anggota/nonaktif: pinjaman berjalan memblokir (menyebut sisa)', isset($v5['status']) && str_contains($v5['status'], 'Rp 5.200.000'));
check('anggota/nonaktif: gagal tidak mengubah apa pun', one('SELECT status FROM members WHERE id = 5') === 'AKTIF');
MemberService::update(req(), $actor, $id2, data(['name' => 'Bu Baru', 'address_block' => 'Z - 2', 'status' => 'NONAKTIF']), $ver($id2));
check('anggota/nonaktif: anggota tanpa pinjaman bisa dinonaktifkan', one('SELECT status FROM members WHERE id = ?', [$id2]) === 'NONAKTIF');

// ======================= REGU =======================
[$e] = TeamService::parse(['name' => '', 'leader_member_id' => 0]);
check('regu/parse: nama dan ketua wajib', isset($e['name'], $e['leader_member_id']));
$mk = fn (array $o = []) => TeamService::parse($o + ['name' => 'Regu Gamma', 'leader_member_id' => $id1])[1];
check('regu/buat: ketua yang sudah memimpin regu ditolak', str_contains(violation(fn () => TeamService::create(req(), $actor, $mk(['leader_member_id' => 1])))['leader_member_id'] ?? '', 'sudah menjadi ketua'));
check('regu/buat: ketua nonaktif ditolak', isset(violation(fn () => TeamService::create(req(), $actor, $mk(['leader_member_id' => $id2])))['leader_member_id']));
check('regu/buat: nama regu aktif yang sama ditolak', isset(violation(fn () => TeamService::create(req(), $actor, $mk(['name' => 'Regu Alfa'])))['name']));
$tid = TeamService::create(req(), $actor, $mk());
check('regu/buat: ketua otomatis dipindah menjadi anggota regu baru', MemberService::currentTeamId($pdo, $id1) === $tid);
check('regu/buat: audit TEAM_CREATED', audits('TEAM_CREATED') === 1);
check('regu/calon ketua (baru): yang sudah memimpin tidak muncul', !in_array('AGT-001', array_column(Team::leaderCandidates(null), 'member_no'), true) && !in_array('AGT-006', array_column(Team::leaderCandidates(null), 'member_no'), true));

$tv = fn (int $id) => (string) one('SELECT updated_at FROM team_leaders WHERE id = ?', [$id]);
$tdata = fn (array $o = []) => TeamService::parse($o + ['name' => 'Regu Gamma', 'leader_member_id' => $id1, 'is_active' => 1])[1];
check('regu/ubah: versi basi ditolak', isset(violation(fn () => TeamService::update(req(), $actor, $tid, $tdata(), '2000-01-01 00:00:00'))['_form']));
check('regu/ubah: ketua baru harus anggota regu ini', str_contains(violation(fn () => TeamService::update(req(), $actor, $tid, $tdata(['leader_member_id' => 4]), $tv($tid)))['leader_member_id'] ?? '', 'belum menjadi anggota'));
check('regu/ubah: calon ketua (ubah) hanya anggota regu itu', array_column(Team::leaderCandidates($tid), 'member_no') === ['AGT-006']);
TeamService::update(req(), $actor, $tid, $tdata(['name' => 'Regu Gamma Baru']), $tv($tid));
check('regu/ubah: nama berubah + audit sebelum/sesudah', one('SELECT name FROM team_leaders WHERE id = ?', [$tid]) === 'Regu Gamma Baru' && lastAudit('TEAM_UPDATED')['before'] === ['name' => 'Regu Gamma'] && lastAudit('TEAM_UPDATED')['after'] === ['name' => 'Regu Gamma Baru']);
// regu dengan anggota lain tidak bisa dinonaktifkan; regu hanya berisi ketua bisa
MemberService::update(req(), $actor, 2, data(['name' => 'Anggota Alfa', 'address_block' => 'B - 2', 'active_from' => '2026-03', 'team_id' => $tid]), $ver(2));
check('regu/nonaktif: masih ada anggota selain ketua -> ditolak (menyebut jumlah)', str_contains(violation(fn () => TeamService::update(req(), $actor, $tid, $tdata(['name' => 'Regu Gamma Baru', 'is_active' => 0]), $tv($tid)))['is_active'] ?? '', '1 anggota'));
MemberService::update(req(), $actor, 2, data(['name' => 'Anggota Alfa', 'address_block' => 'B - 2', 'active_from' => '2026-03', 'team_id' => 1]), $ver(2));
TeamService::update(req(), $actor, $tid, $tdata(['name' => 'Regu Gamma Baru', 'is_active' => 0]), $tv($tid));
check('regu/nonaktif: regu hanya berisi ketua bisa dinonaktifkan', (int) one('SELECT is_active FROM team_leaders WHERE id = ?', [$tid]) === 0);
check('regu/daftar: statistik anggota, tabungan, dan sisa pinjaman', (function (): bool {
    $beta = array_values(array_filter(Team::all(), fn ($t) => $t['name'] === 'Regu Beta'))[0];
    return (int) $beta['member_count'] === 3 && (int) $beta['savings_total'] === 400000 && (int) $beta['outstanding_total'] === 5200000;
})());

// ======================= PENGGUNA =======================
check('pengguna: Head + Pemeriksa dilarang', str_contains(violation(fn () => UserService::create('dobel', 'X', ['HEAD', 'PEMERIKSA']))['_form'] ?? '', 'tidak boleh dipegang orang yang sama'));
check('pengguna: Anggota tanpa tautan ditolak', isset(violation(fn () => UserService::create('tanpa1', 'X', ['ANGGOTA']))['_form']));
check('pengguna: Ketua Regu tanpa tautan ditolak', isset(violation(fn () => UserService::create('tanpa2', 'X', ['KETUA_REGU']))['_form']));
check('pengguna: Ketua Regu tertaut ke anggota biasa ditolak', str_contains(violation(fn () => UserService::create('tanpa3', 'X', ['KETUA_REGU'], 'AGT-002'))['_form'] ?? '', 'belum menjadi ketua regu'));
check('pengguna: tidak ada baris tertinggal dari penolakan', (int) one("SELECT COUNT(*) FROM users WHERE username LIKE 'tanpa%' OR username = 'dobel'") === 0);
$pem = UserService::create('pemeriksa1', 'Pemeriksa Satu', ['PEMERIKSA'], null, 'Contoh-Uji-2026');
$ang = UserService::create('anggota2', 'Anggota Alfa', ['ANGGOTA'], 'AGT-002', 'Contoh-Uji-2026');
check('pengguna: pilihan anggota hanya yang belum punya akun', !in_array('AGT-001', array_column(User::memberOptions(null), 'member_no'), true) && in_array('AGT-004', array_column(User::memberOptions(null), 'member_no'), true));
check('pengguna: pilihan anggota menyertakan yang sedang diedit', in_array('AGT-002', array_column(User::memberOptions(2), 'member_no'), true));
$uv = fn (int $id) => (string) one('SELECT updated_at FROM users WHERE id = ?', [$id]);

check('pengguna/ubah: peran sendiri tidak boleh diubah', str_contains(violation(fn () => UserService::update(req(), $actor, $head['id'], 'Kepala', ['HEAD', 'KETUA_REGU'], null, $uv($head['id'])))['_form'] ?? '', 'akun Anda sendiri'));
check('pengguna/ubah: Head terakhir tidak bisa dicopot oleh siapa pun', (function () use ($actor, $head, $uv, $pdo): bool {
    // actor pura-pura admin lain (id berbeda) mencoba mencopot satu-satunya Head
    $other = ['id' => 9999, 'username' => 'x', 'roles' => ['HEAD']];
    $v = violation(fn () => UserService::update(req(), $other, $head['id'], 'Kepala', ['PEMERIKSA'], null, $uv($head['id'])));
    return $v !== null && str_contains($v['_form'], 'minimal satu Head');
})());
check('pengguna/ubah: versi basi ditolak', str_contains(violation(fn () => UserService::update(req(), $actor, $ang['id'], 'Baru', ['ANGGOTA'], 'AGT-002', '2000-01-01 00:00:00'))['_form'] ?? '', 'pengguna lain'));
UserService::update(req(), $actor, $ang['id'], 'Anggota Alfa Dua', ['ANGGOTA', 'PEMERIKSA'], 'AGT-002', $uv($ang['id']));
check('pengguna/ubah: nama dan peran berubah + audit sebelum/sesudah', in_array('PEMERIKSA', User::findForAdmin($ang['id'])['roles'], true) && lastAudit('USER_UPDATED')['before']['name'] === 'Anggota Alfa' && lastAudit('USER_UPDATED')['after']['name'] === 'Anggota Alfa Dua');
check('pengguna/ubah: Head dan Pemeriksa tetap dilarang saat ubah', isset(violation(fn () => UserService::update(req(), $actor, $ang['id'], 'X', ['HEAD', 'PEMERIKSA'], 'AGT-002', $uv($ang['id'])))['_form']));
$head2 = UserService::create('kepala2', 'Kepala Dua', ['HEAD'], null, 'Contoh-Uji-2026');
check('pengguna/ubah: Head boleh dicopot bila masih ada Head lain', violation(fn () => UserService::update(req(), $actor, $head2['id'], 'Kepala Dua', ['PEMERIKSA'], null, $uv($head2['id']))) === null);

check('pengguna/status: akun sendiri tidak boleh dinonaktifkan', str_contains(violation(fn () => UserService::setActive(req(), $actor, $head['id'], false, $uv($head['id'])))['_form'] ?? '', 'sendiri'));
UserService::setActive(req(), $actor, $pem['id'], false, $uv($pem['id']));
check('pengguna/status: dinonaktifkan + audit', (int) one('SELECT is_active FROM users WHERE id = ?', [$pem['id']]) === 0 && audits('USER_DEACTIVATED') === 1);
check('pengguna/status: akun nonaktif tidak bisa login', Auth::attempt(req(), 'pemeriksa1', 'Contoh-Uji-2026')['ok'] === false);
UserService::setActive(req(), $actor, $pem['id'], true, $uv($pem['id']));
check('pengguna/status: diaktifkan kembali', (int) one('SELECT is_active FROM users WHERE id = ?', [$pem['id']]) === 1 && audits('USER_ACTIVATED') === 1);
check('pengguna/status: Head terakhir tidak bisa dinonaktifkan', (function () use ($head, $head2, $actor, $uv, $pdo): bool {
    // kepala2 sudah bukan Head; kepala adalah satu-satunya Head aktif
    $other = ['id' => 9999, 'username' => 'x', 'roles' => ['HEAD']];
    $v = violation(fn () => UserService::setActive(req(), $other, $head['id'], false, $uv($head['id'])));
    return $v !== null && str_contains($v['_form'], 'minimal satu Head');
})());

check('pengguna/reset: akun sendiri ditolak', isset(violation(fn () => UserService::resetPassword(req(), $actor, $head['id'], $uv($head['id'])))['_form']));
$_SESSION = ['auth_id' => $pem['id'], 'auth_issued' => time() - 60];
Auth::reset();
check('pengguna/reset: sesi aktif sebelum reset', Auth::user() !== null);
$oldHash = (string) one('SELECT password_hash FROM users WHERE id = ?', [$pem['id']]);
$temp = UserService::resetPassword(req(), $actor, $pem['id'], $uv($pem['id']));
check('pengguna/reset: kata sandi sementara memenuhi kebijakan', PasswordPolicy::check($temp, 'pemeriksa1') === []);
check('pengguna/reset: hash berubah, wajib ganti, kunci/gagal bersih', (string) one('SELECT password_hash FROM users WHERE id = ?', [$pem['id']]) !== $oldHash && (int) one('SELECT must_change_password FROM users WHERE id = ?', [$pem['id']]) === 1 && password_verify($temp, (string) one('SELECT password_hash FROM users WHERE id = ?', [$pem['id']])));
Auth::reset();
check('pengguna/reset: SESI LAMA akun itu langsung tidak sah', Auth::user() === null);
check('pengguna/reset: kata sandi lama tidak berlaku, yang baru berlaku', Auth::attempt(req(), 'pemeriksa1', 'Contoh-Uji-2026')['ok'] === false && Auth::attempt(req(), 'pemeriksa1', $temp)['ok'] === true);
check('pengguna/reset: kata sandi TIDAK tercatat di audit', !str_contains((string) one("SELECT GROUP_CONCAT(COALESCE(after_data,'')) FROM audit_logs"), $temp) && audits('USER_PASSWORD_RESET') === 1);
$pdo->exec("UPDATE users SET locked_until = NOW() + INTERVAL 10 MINUTE, failed_logins = 3 WHERE id = {$ang['id']}");
UserService::unlock(req(), $actor, $ang['id']);
check('pengguna/buka kunci: kunci dan penghitung dibersihkan + audit', one('SELECT locked_until FROM users WHERE id = ?', [$ang['id']]) === null && (int) one('SELECT failed_logins FROM users WHERE id = ?', [$ang['id']]) === 0 && audits('USER_UNLOCKED') === 1);
check('pengguna/daftar: tidak membawa password_hash', !array_key_exists('password_hash', User::all()[0]) && !array_key_exists('password_hash', User::findForAdmin($pem['id'])));

// ======================= PENGATURAN =======================
$vals = SettingsService::values();
[$e, $clean] = SettingsService::validate($vals);
check('pengaturan: nilai awal lolos validasi', $e === []);
check('pengaturan: tipe nilai terbaca benar', SettingsService::get('interest_rate_pct_month') === 2.0 && SettingsService::get('loan_tenor_max') === 5 && SettingsService::get('withdrawals_enabled') === false && SettingsService::get('loan_max_amount') === 15000000);
check('pengaturan: kunci tak dikenal ditolak', (function (): bool {
    try {
        SettingsService::get('ngawur');
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
})());
$try = fn (array $o) => SettingsService::validate($o + $vals)[0];
check('pengaturan: bagi hasil harus berjumlah 100', isset($try(['profit_share_saver_pct' => '50'])['profit_share_shu_pct']));
check('pengaturan: SHU harus berjumlah 100', isset($try(['shu_member_pct' => '70'])['shu_manager_pct']));
check('pengaturan: tenor min > max ditolak', isset($try(['loan_tenor_min' => '6'])['loan_tenor_max']));
check('pengaturan: tenor maks > 12 ditolak', isset($try(['loan_tenor_max' => '13'])['loan_tenor_max']));
check('pengaturan: bunga 0 ditolak, 10,5 ditolak', isset($try(['interest_rate_pct_month' => '0'])['interest_rate_pct_month']) && isset($try(['interest_rate_pct_month' => '10.5'])['interest_rate_pct_month']));
check('pengaturan: huruf pada angka ditolak', isset($try(['loan_tenor_max' => 'lima'])['loan_tenor_max']) && isset($try(['loan_max_amount' => '15jt'])['loan_max_amount']));
check('pengaturan: nominal bertitik ribuan diterima', SettingsService::validate(['loan_max_amount' => '20.000.000'] + $vals)[1]['loan_max_amount'] === '20000000');
check('pengaturan: desimal berkoma diterima dan dinormalkan', SettingsService::validate(['interest_rate_pct_month' => '2,5'] + $vals)[1]['interest_rate_pct_month'] === '2.50');
check('pengaturan: plafon negatif ditolak', isset($try(['loan_max_amount' => '-1'])['loan_max_amount']));
$mutated = $vals;
$mutated['interest_rate_pct_month'] = '2.0';  // sama secara numerik dengan 2.00
$mutated['loan_tenor_max'] = '4';
$mutated['withdrawals_enabled'] = '1';
[$e, $clean] = SettingsService::validate($mutated);
$beforeCount = audits('SETTING_UPDATED');
$changed = SettingsService::save(req(), $actor, $clean);
check('pengaturan/simpan: hanya yang benar-benar berubah (2.0 = 2.00 bukan perubahan)', $changed === ['loan_tenor_max', 'withdrawals_enabled']);
check('pengaturan/simpan: satu audit per perubahan dengan sebelum/sesudah', audits('SETTING_UPDATED') === $beforeCount + 2 && lastAudit('SETTING_UPDATED')['after'] === ['value' => '1'] && lastAudit('SETTING_UPDATED')['before'] === ['value' => '0']);
check('pengaturan/simpan: nilai tersimpan dan updated_by terisi', SettingsService::get('loan_tenor_max') === 4 && SettingsService::get('withdrawals_enabled') === true && (int) one("SELECT updated_by FROM settings WHERE setting_key='loan_tenor_max'") === $head['id']);
check('pengaturan/simpan: tanpa perubahan -> tidak ada audit baru', SettingsService::save(req(), $actor, SettingsService::validate(SettingsService::values())[1]) === [] && audits('SETTING_UPDATED') === $beforeCount + 2);

// ======================= PENCARIAN & PAGINASI =======================
$headU  = ['roles' => ['HEAD'], 'member_id' => null, 'team_id' => null];
$ketuaB = ['roles' => ['KETUA_REGU'], 'member_id' => 3, 'team_id' => 2];
$res = Member::search($headU, [], 1);
check('cari: Head melihat semua anggota aktif dan nonaktif', $res['pager']['total'] === 7);
check('cari: ketua regu hanya melihat regunya', array_column(Member::search($ketuaB, [], 1)['rows'], 'member_no') === ['AGT-003', 'AGT-004', 'AGT-005']);
check('cari: nama sebagian, tak peka huruf besar', array_column(Member::search($headU, ['q' => 'peminjam'], 1)['rows'], 'member_no') === ['AGT-005']);
check('cari: nomor anggota', array_column(Member::search($headU, ['q' => 'AGT-004'], 1)['rows'], 'member_no') === ['AGT-004']);
check('cari: blok', array_column(Member::search($headU, ['q' => 'Z - 9'], 1)['rows'], 'member_no') === ['AGT-006']);
check('cari: "%" dibaca harfiah, bukan wildcard', Member::search($headU, ['q' => '%'], 1)['pager']['total'] === 0);
check('cari: "_" dibaca harfiah', Member::search($headU, ['q' => '_'], 1)['pager']['total'] === 0);
check('cari: injeksi SQL tidak berefek', Member::search($headU, ['q' => "' OR '1'='1"], 1)['pager']['total'] === 0);
check('cari: filter status', Member::search($headU, ['status' => 'NONAKTIF'], 1)['pager']['total'] === 1);
check('cari: filter regu', Member::search($headU, ['team' => 2], 1)['pager']['total'] === 3);
check('cari: filter status tidak valid diabaikan', Member::search($headU, ['status' => 'ngawur'], 1)['pager']['total'] === 7);
check('cari: ketua regu tidak bisa "melompat" regu lewat filter team', Member::search($ketuaB, ['team' => 1], 1)['pager']['total'] === 0);
$row4 = array_values(array_filter(Member::search($headU, [], 1)['rows'], fn ($r) => $r['member_no'] === 'AGT-004'))[0];
$row5 = array_values(array_filter(Member::search($headU, [], 1)['rows'], fn ($r) => $r['member_no'] === 'AGT-005'))[0];
check('cari: saldo dan sisa pinjaman per baris dari view', (int) $row4['savings_balance'] === 400000 && (int) $row5['outstanding'] === 5200000 && (int) $row4['outstanding'] === 0);
for ($i = 1; $i <= 30; $i++) {
    $pdo->prepare("INSERT INTO members (member_no, name, active_from) VALUES (?, ?, '2026-03-01')")->execute([sprintf('AGT-%03d', 100 + $i), "Massal {$i}"]);
}
$p1 = Member::search($headU, [], 1);
$p2 = Member::search($headU, [], 2);
check('paginasi: 37 anggota -> 2 halaman, 25 + 12', $p1['pager']['pages'] === 2 && count($p1['rows']) === 25 && count($p2['rows']) === 12 && $p2['pager']['from'] === 26 && $p2['pager']['to'] === 37);
check('paginasi: halaman di luar batas dijepit', Member::search($headU, [], 99)['pager']['page'] === 2 && Member::search($headU, [], -5)['pager']['page'] === 1);
check('paginasi: tanpa tumpang tindih', array_intersect(array_column($p1['rows'], 'id'), array_column($p2['rows'], 'id')) === []);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
