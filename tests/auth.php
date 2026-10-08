<?php
declare(strict_types=1);

/**
 * Tes autentikasi, penguncian, ganti kata sandi, dan cakupan data. MENGOSONGKAN database uji.
 *   C:\xampp\php\php.exe tests\auth.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\CanMiddleware;
use App\Models\Member;
use App\Services\Auth;
use App\Services\Migrator;
use App\Services\PasswordPolicy;
use App\Services\Scope;
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
function req(string $ip = '10.0.0.1'): Request
{
    return new Request('POST', '/login', [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'tes-otomatis']);
}
function audits(string $action): int
{
    return (int) one('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
}
function throws(callable $fn, string $class = InvalidArgumentException::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}
function freshSession(): void
{
    $_SESSION = [];
    Auth::reset();
}

// ---------------- fixture: 2 regu, 6 anggota ----------------
$pdo->exec("INSERT INTO periods (name, start_date, end_date) VALUES ('Uji','2026-03-01','2027-02-28')");
$periodId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES ({$periodId}, '2026-03-01')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES ({$periodId}, '2026-04-01')");
foreach ([1 => 'Ketua A', 2 => 'Anggota A2', 3 => 'Anggota A3', 4 => 'Ketua B', 5 => 'Anggota B2', 6 => 'Tanpa Regu'] as $n => $name) {
    $pdo->prepare("INSERT INTO members (member_no, name, active_from) VALUES (?, ?, '2026-03-01')")->execute([sprintf('AGT-%03d', $n), $name]);
}
$mid = fn (int $n): int => (int) one('SELECT id FROM members WHERE member_no = ?', [sprintf('AGT-%03d', $n)]);
$pdo->prepare('INSERT INTO team_leaders (name, leader_member_id) VALUES (?, ?)')->execute(['Regu A', $mid(1)]);
$teamA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO team_leaders (name, leader_member_id) VALUES (?, ?)')->execute(['Regu B', $mid(4)]);
$teamB = (int) $pdo->lastInsertId();
foreach ([[1, $teamA], [2, $teamA], [3, $teamA], [4, $teamB], [5, $teamB]] as [$n, $t]) {
    $pdo->prepare("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?, ?, '2026-03-01')")->execute([$mid($n), $t]);
}
// satu simpanan DISETUJUI untuk anggota 2 (agar saldo muncul)
$pdo->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('TRX-T','SMP-T','SIMPANAN',{$mid(2)},(SELECT MIN(id) FROM period_months),'2026-03-01',750000)");
$tid = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE transactions SET status='MENUNGGU_VALIDASI' WHERE id={$tid}");
$pdo->exec("UPDATE transactions SET status='DISETUJUI' WHERE id={$tid}");

// ---------------- UserService ----------------
$head = UserService::create('purwati', 'Purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001');
check('user: kata sandi sementara memenuhi kebijakan', PasswordPolicy::check($head['password'], 'purwati') === []);
$row = $pdo->query("SELECT * FROM users WHERE username='purwati'")->fetch();
check('user: wajib ganti kata sandi di login pertama', (int) $row['must_change_password'] === 1);
check('user: hash bcrypt, bukan plaintext', $row['password_hash'] !== $head['password'] && password_verify($head['password'], $row['password_hash']) && str_starts_with($row['password_hash'], '$2y$12$'));
check('user: dua peran tersimpan', (int) one('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$row['id']]) === 2);
check('user: audit USER_CREATED ada', audits('USER_CREATED') === 1);
check('user: kata sandi TIDAK ada di audit log', !str_contains((string) one("SELECT after_data FROM audit_logs WHERE action='USER_CREATED'"), $head['password']));
check('user: nama pengguna ganda ditolak', throws(fn () => UserService::create('purwati', 'X', ['ANGGOTA'])));
check('user: anggota yang sama tidak boleh punya dua akun', throws(fn () => UserService::create('lain', 'X', ['ANGGOTA'], 'AGT-001')));
check('user: nama pengguna tidak valid ditolak', throws(fn () => UserService::create('A B', 'X', ['ANGGOTA'])));
check('user: peran tak dikenal ditolak', throws(fn () => UserService::create('dummy1', 'X', ['DEWA'])));
check('user: anggota tak dikenal ditolak', throws(fn () => UserService::create('dummy2', 'X', ['ANGGOTA'], 'AGT-999')));
check('user: kata sandi lemah ditolak', throws(fn () => UserService::create('dummy3', 'X', ['ANGGOTA'], null, '12345678')));
check('user: gagal tidak meninggalkan baris (atomik)', (int) one("SELECT COUNT(*) FROM users WHERE username LIKE 'dummy%'") === 0);

$ketuaB  = UserService::create('ketua.b', 'Ketua B', ['KETUA_REGU'], 'AGT-004');
$ketuaA  = UserService::create('ketua.a', 'Ketua A', ['ANGGOTA'], 'AGT-002'); // anggota biasa; peran ketua regu ditambahkan lewat SQL (simulasi data lama / regu dinonaktifkan)
$pdo->exec("INSERT INTO user_roles (user_id, role_id) SELECT u.id, r.id FROM users u, roles r WHERE u.username='ketua.a' AND r.code='KETUA_REGU'");
$angg    = UserService::create('anggota5', 'Anggota B2', ['ANGGOTA'], 'AGT-005');
$periksa = UserService::create('periksa', 'Pemeriksa', ['PEMERIKSA']);

// ---------------- login ----------------
freshSession();
$_SESSION['_csrf'] = 'token-lama';
$r = Auth::attempt(req('10.0.1.1'), 'PURWATI ', $head['password']); // huruf besar & spasi
check('login: sukses (nama pengguna tak peka huruf besar, spasi dipangkas)', $r['ok'] === true && $r['error'] === null);
check('login: sesi menyimpan id pengguna', is_int($_SESSION['auth_id'] ?? null));
check('login: token CSRF dirotasi', !isset($_SESSION['_csrf']));
$u = Auth::user();
check('login: Auth::user memuat peran, anggota, dan regu', $u['roles'] === ['HEAD', 'KETUA_REGU'] && $u['member_id'] === $mid(1) && $u['team_id'] === $teamA);
check('login: must_change_password terbawa', $u['must_change_password'] === true);
check('login: tidak ada password_hash di profil sesi', !array_key_exists('password_hash', $u));
check('login: audit LOGIN_SUCCESS', audits('LOGIN_SUCCESS') === 1);
check('login: last_login_at terisi', one("SELECT last_login_at FROM users WHERE username='purwati'") !== null);
check('login: percobaan sukses tercatat', (int) one('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 1') === 1);

freshSession();
$bad1 = Auth::attempt(req('10.0.1.2'), 'purwati', 'salah-total-1');
$bad2 = Auth::attempt(req('10.0.1.2'), 'tidak.ada', 'salah-total-1');
check('login: kata sandi salah -> pesan generik', $bad1['ok'] === false && $bad1['error'] === Auth::GENERIC_ERROR);
check('login: pengguna tak ada -> pesan SAMA PERSIS (tanpa enumerasi)', $bad2['error'] === $bad1['error']);
check('login: gagal tidak membuat sesi', !isset($_SESSION['auth_id']));
check('login: penghitung gagal bertambah', (int) one("SELECT failed_logins FROM users WHERE username='purwati'") === 1);
check('login: audit LOGIN_FAILED', audits('LOGIN_FAILED') === 2);

// kunci akun setelah 5 gagal berturut-turut
$ip = '10.0.2.1';
for ($i = 0; $i < 3; $i++) { // gagal ke-1..3
    Auth::attempt(req($ip), 'ketua.b', 'salah-salah-9');
}
Auth::attempt(req($ip), 'ketua.b', 'salah-salah-9');  // gagal ke-4
$fifth = Auth::attempt(req($ip), 'ketua.b', 'salah-salah-9');
check('kunci: gagal ke-5 mengunci akun dan memberi pesan pembatasan', $fifth['error'] === Auth::THROTTLED_ERROR);
check('kunci: locked_until terisi', one("SELECT locked_until FROM users WHERE username='ketua.b'") !== null);
check('kunci: audit LOGIN_LOCKED', audits('LOGIN_LOCKED') === 1);
$whileLocked = Auth::attempt(req('10.0.2.2'), 'ketua.b', $ketuaB['password']);
check('kunci: kata sandi BENAR tetap ditolak saat terkunci', $whileLocked['ok'] === false && !isset($_SESSION['auth_id']));
$pdo->exec("UPDATE users SET locked_until = NOW() - INTERVAL 1 MINUTE WHERE username='ketua.b'");
$afterLock = Auth::attempt(req('10.0.2.3'), 'ketua.b', $ketuaB['password']);
check('kunci: setelah kunci habis, login benar berhasil', $afterLock['ok'] === true);
check('kunci: penghitung & kunci direset setelah sukses', (int) one("SELECT failed_logins FROM users WHERE username='ketua.b'") === 0 && one("SELECT locked_until FROM users WHERE username='ketua.b'") === null);

// nama yang TIDAK ADA tidak bisa dibedakan dari akun nyata: urutan jawaban 6 percobaan sama persis
$seqReal = [];
$seqGhost = [];
for ($i = 1; $i <= 6; $i++) {
    $seqReal[]  = Auth::attempt(req('10.0.6.' . $i), 'ketua.a', 'salah-salah-9')['error'];
    $seqGhost[] = Auth::attempt(req('10.0.7.' . $i), 'hantu.tak.ada', 'salah-salah-9')['error'];
}
check('enumerasi: urutan pesan untuk nama tak ada = urutan untuk akun nyata (pesan "terlalu banyak percobaan" muncul di gagal ke-5 untuk keduanya)', $seqReal === $seqGhost && $seqGhost[3] === Auth::GENERIC_ERROR && $seqGhost[4] === Auth::THROTTLED_ERROR && $seqGhost[5] === Auth::THROTTLED_ERROR);
check('enumerasi: audit nama tak ada tetap jujur (LOGIN_FAILED, bukan LOGIN_LOCKED) dan tanpa pengguna', (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_LOCKED' AND user_id IS NULL") === 0 && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILED' AND user_id IS NULL AND after_data LIKE '%hantu%'") === 6);
check('enumerasi: nama tak ada dengan huruf besar/kecil berbeda dihitung sebagai nama yang sama', Auth::attempt(req('10.0.7.9'), 'HANTU.Tak.Ada', 'salah-salah-9')['error'] === Auth::THROTTLED_ERROR);
$pdo->exec("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'ketua.a'");

// batas sesi mutlak: sesi yang terus dipakai pun berakhir
freshSession();
$okLogin = Auth::attempt(req('10.0.5.1'), 'ketua.b', $ketuaB['password']);
$_SESSION['auth_issued'] = time() - (int) Config::get('app.session.absolute_timeout') + 60;
Auth::reset();
check('sesi mutlak: sesi yang masih di dalam batas tetap sah', $okLogin['ok'] === true && Auth::user() !== null);
$_SESSION['auth_issued'] = time() - (int) Config::get('app.session.absolute_timeout') - 5;
Auth::reset();
check('sesi mutlak: sesi yang melewati batas maksimum dipaksa keluar walau aktif, dengan pesan', Auth::user() === null && !isset($_SESSION['auth_id']) && str_contains(json_encode($_SESSION['_flash'] ?? []), 'terlalu lama'));
check('sesi mutlak: batas bawaan 12 jam', (int) Config::get('app.session.absolute_timeout') === 43200);

// ganti kata sandi: tebakan kata sandi lama dibatasi walau sesi sudah masuk
freshSession();
Auth::attempt(req('10.0.5.2'), 'periksa', $periksa['password']);
$pu = Auth::user();
$results = [];
for ($i = 0; $i < 6; $i++) {
    $results[] = Auth::changePassword(req('10.0.5.2'), $pu, 'tebakan-salah-' . $i, 'KataSandiBaru-2026x');
}
check('ganti sandi: 5 tebakan salah pertama dijawab "salah", yang ke-6 dibatasi', ($results[4]['current_password'] ?? '') === 'Kata sandi saat ini salah.' && str_contains($results[5]['current_password'] ?? '', 'Terlalu banyak percobaan'));
check('ganti sandi: setelah dibatasi, kata sandi lama yang BENAR pun ditolak sementara (penebak tak bisa memastikan tebakannya)', str_contains(Auth::changePassword(req('10.0.5.2'), $pu, $periksa['password'], 'KataSandiBaru-2026x')['current_password'] ?? '', 'Terlalu banyak percobaan') && password_verify($periksa['password'], (string) one("SELECT password_hash FROM users WHERE username = 'periksa'")));
$throttleFails = audits('PASSWORD_CHANGE_FAILED');   // jejak audit tidak bisa dihapus; tes lain menghitung relatif terhadap ini

// akun nonaktif / terhapus
freshSession();
$pdo->exec("UPDATE users SET is_active = 0 WHERE username='anggota5'");
$off = Auth::attempt(req('10.0.3.1'), 'anggota5', $angg['password']);
check('nonaktif: ditolak dengan pesan generik walau kata sandi benar', $off['ok'] === false && $off['error'] === Auth::GENERIC_ERROR);
$pdo->exec("UPDATE users SET is_active = 1, deleted_at = NOW() WHERE username='anggota5'");
check('terhapus: ditolak', Auth::attempt(req('10.0.3.2'), 'anggota5', $angg['password'])['ok'] === false);
$pdo->exec("UPDATE users SET deleted_at = NULL WHERE username='anggota5'");

// pembatasan per IP
for ($i = 0; $i < Auth::IP_MAX_FAILURES; $i++) {
    $pdo->prepare("INSERT INTO login_attempts (username, ip_address, succeeded) VALUES ('x', '10.0.4.4', 0)")->execute();
}
freshSession();
check('throttle IP: IP yang terlalu sering gagal ditolak walau kredensial benar', Auth::attempt(req('10.0.4.4'), 'periksa', $periksa['password'])['error'] === Auth::THROTTLED_ERROR);
check('throttle IP: IP lain tidak terdampak', Auth::attempt(req('10.0.4.5'), 'periksa', $periksa['password'])['ok'] === true);
check('throttle IP: tercatat di audit', audits('LOGIN_THROTTLED') === 1);

// hash lama di-upgrade
$pdo->prepare("UPDATE users SET password_hash = ? WHERE username='periksa'")->execute([password_hash($periksa['password'], PASSWORD_BCRYPT, ['cost' => 10])]);
freshSession();
Auth::attempt(req('10.0.4.6'), 'periksa', $periksa['password']);
check('rehash: hash cost lama ditingkatkan ke cost 12 saat login', str_starts_with((string) one("SELECT password_hash FROM users WHERE username='periksa'"), '$2y$12$'));

// ---------------- validasi ulang per request ----------------
freshSession();
Auth::attempt(req('10.0.5.1'), 'periksa', $periksa['password']);
check('sesi: pengguna dikenali', Auth::user()['username'] === 'periksa');
$pdo->exec("UPDATE users SET is_active = 0 WHERE username='periksa'");
Auth::reset();
check('sesi: penonaktifan akun berlaku SEGERA (request berikutnya)', Auth::user() === null && !isset($_SESSION['auth_id']));
$pdo->exec("UPDATE users SET is_active = 1 WHERE username='periksa'");

freshSession();
Auth::attempt(req('10.0.5.2'), 'ketua.a', $ketuaA['password']);
check('sesi: peran awal memuat KETUA_REGU', in_array('KETUA_REGU', Auth::user()['roles'], true));
$pdo->exec("INSERT INTO user_roles (user_id, role_id) SELECT u.id, r.id FROM users u, roles r WHERE u.username='ketua.a' AND r.code='PEMERIKSA'");
Auth::reset();
check('sesi: perubahan peran berlaku seketika', in_array('PEMERIKSA', Auth::user()['roles'], true));
$pdo->exec("DELETE FROM user_roles WHERE user_id = (SELECT id FROM users WHERE username='ketua.a') AND role_id = (SELECT id FROM roles WHERE code='PEMERIKSA')");

// ---------------- ganti kata sandi ----------------
freshSession();
Auth::attempt(req('10.0.6.1'), 'purwati', $head['password']);
$user = Auth::user();
check('sandi: kata sandi saat ini salah ditolak', isset(Auth::changePassword(req(), $user, 'bukan-sandi-saya1', 'KataBaru-2026x')['current_password']));
check('sandi: sama dengan yang lama ditolak', isset(Auth::changePassword(req(), $user, $head['password'], $head['password'])['password']));
check('sandi: lemah ditolak', isset(Auth::changePassword(req(), $user, $head['password'], '12345678')['password']));
check('sandi: mengandung username ditolak', isset(Auth::changePassword(req(), $user, $head['password'], 'purwati-2026')['password']));
check('sandi: belum ada yang berubah setelah penolakan', (int) one("SELECT must_change_password FROM users WHERE username='purwati'") === 1);
check('sandi: kegagalan tercatat di audit (1 baru + yang dibuat tes pembatasan di atas)', audits('PASSWORD_CHANGE_FAILED') === 1 + $throttleFails);

$otherSession = ['auth_id' => $user['id'], 'auth_issued' => time() - 30]; // sesi lain, diterbitkan lebih awal
$errors = Auth::changePassword(req(), $user, $head['password'], 'Kopi-Susu-2026');
check('sandi: ganti berhasil', $errors === []);
check('sandi: hash berubah dan flag wajib-ganti padam', password_verify('Kopi-Susu-2026', (string) one("SELECT password_hash FROM users WHERE username='purwati'")) && (int) one("SELECT must_change_password FROM users WHERE username='purwati'") === 0);
check('sandi: sesi ini tetap sah', Auth::user() !== null && Auth::user()['must_change_password'] === false);
check('sandi: audit PASSWORD_CHANGED, tanpa kata sandi', audits('PASSWORD_CHANGED') === 1 && !str_contains((string) one("SELECT GROUP_CONCAT(COALESCE(after_data,'')) FROM audit_logs"), 'Kopi-Susu-2026'));
$_SESSION = $otherSession;
Auth::reset();
check('sandi: SESI LAIN yang lebih lama otomatis tidak sah', Auth::user() === null);
freshSession();
check('sandi: kata sandi lama tidak lagi bisa dipakai', Auth::attempt(req('10.0.6.2'), 'purwati', $head['password'])['ok'] === false);
check('sandi: kata sandi baru bisa dipakai', Auth::attempt(req('10.0.6.3'), 'purwati', 'Kopi-Susu-2026')['ok'] === true);

// ---------------- logout ----------------
Auth::logout(req());
check('logout: sesi kosong dan pengguna null', ($_SESSION['auth_id'] ?? null) === null && Auth::user() === null);
check('logout: audit LOGOUT', audits('LOGOUT') === 1);

// ---------------- cakupan data ----------------
$withScope = function (array $roles, ?int $memberNo, ?int $teamId): array {
    return ['id' => 99, 'username' => 't', 'roles' => $roles, 'member_id' => $memberNo === null ? null : $GLOBALS['mid']($memberNo), 'team_id' => $teamId];
};
$countVisible = function (?array $user): int {
    [$where, $params] = Scope::memberCondition($user, 'm.id');
    $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM members m WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$headU  = $withScope(['HEAD', 'KETUA_REGU'], 1, $teamA);
$ketuaU = $withScope(['KETUA_REGU'], 1, $teamA);
$ketuaBU = $withScope(['KETUA_REGU'], 4, $teamB);
$anggU  = $withScope(['ANGGOTA'], 5, null);
check('cakupan SQL: head melihat 6 anggota', $countVisible($headU) === 6);
check('cakupan SQL: pemeriksa melihat 6 anggota', $countVisible($withScope(['PEMERIKSA'], null, null)) === 6);
check('cakupan SQL: ketua regu A melihat 3 anggota regunya', $countVisible($ketuaU) === 3);
check('cakupan SQL: ketua regu B melihat 2 anggota regunya', $countVisible($ketuaBU) === 2);
check('cakupan SQL: anggota hanya melihat dirinya (1)', $countVisible($anggU) === 1);
check('cakupan SQL: tamu tidak melihat apa pun', $countVisible(null) === 0);

check('IDOR: ketua regu A membuka anggota regunya -> ada', Member::findScoped($ketuaU, $mid(2)) !== null);
check('IDOR: ketua regu A membuka anggota regu B -> null', Member::findScoped($ketuaU, $mid(5)) === null);
check('IDOR: ketua regu A membuka ketua regu B -> null', Member::findScoped($ketuaU, $mid(4)) === null);
check('IDOR: ketua regu A membuka anggota tanpa regu -> null', Member::findScoped($ketuaU, $mid(6)) === null);
check('IDOR: ketua regu B membuka anggota regu A -> null', Member::findScoped($ketuaBU, $mid(2)) === null);
check('IDOR: anggota membuka dirinya -> ada', Member::findScoped($anggU, $mid(5))['name'] === 'Anggota B2');
check('IDOR: anggota membuka rekan satu regu -> null', Member::findScoped($anggU, $mid(4)) === null);
check('IDOR: head membuka siapa saja -> ada', Member::findScoped($headU, $mid(5)) !== null && Member::findScoped($headU, $mid(6)) !== null);
check('IDOR: id tidak ada -> null (sama dengan di luar cakupan)', Member::findScoped($headU, 999999) === null);
check('IDOR: tamu -> null', Member::findScoped(null, $mid(1)) === null);
check('IDOR: exists() membedakan hanya untuk pencatatan audit', Member::exists($mid(5)) && !Member::exists(999999));
check('data: saldo tabungan anggota dari view', (int) Member::findScoped($headU, $mid(2))['savings_balance'] === 750000);

// anggota pindah regu: akses mengikuti keanggotaan SAAT INI
$pdo->prepare("UPDATE member_team_assignments SET valid_to = '2026-03-31' WHERE member_id = ? AND valid_to IS NULL")->execute([$mid(2)]);
$pdo->prepare("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?, ?, '2026-04-01')")->execute([$mid(2), $teamB]);
check('pindah regu: ketua lama kehilangan akses', Member::findScoped($ketuaU, $mid(2)) === null);
check('pindah regu: ketua baru mendapat akses', Member::findScoped($ketuaBU, $mid(2)) !== null);

// penolakan izin tercatat di audit
Auth::actingAs(['id' => 77, 'username' => 'penguji', 'roles' => ['ANGGOTA'], 'member_id' => null, 'team_id' => null, 'must_change_password' => false, 'credentials_ts' => 0]);
$before = audits('ACCESS_DENIED');
try {
    (new CanMiddleware('settings.manage'))->handle(new Request('GET', '/sistem/pengaturan', [], [], ['REMOTE_ADDR' => '10.0.9.9']), fn () => Response::html('x'));
} catch (HttpException $e) {
}
check('audit: penolakan izin (403) tercatat', audits('ACCESS_DENIED') === $before + 1);
check('audit: catatan memuat path, bukan data sensitif', str_contains((string) one("SELECT after_data FROM audit_logs WHERE action='ACCESS_DENIED' ORDER BY id DESC LIMIT 1"), '/sistem/pengaturan'));
Auth::actingAs(null);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
