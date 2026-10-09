<?php
declare(strict_types=1);

/**
 * Tes ringan tanpa dependensi.  Jalankan:  C:\xampp\php\php.exe tests\run.php
 * Keluar dengan kode 1 bila ada yang gagal.
 */

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Csrf;
use App\Core\Env;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Middleware\AuthMiddleware;
use App\Middleware\CanMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Helpers\Money;
use App\Core\Validator;
use App\Services\Auth;
use App\Services\LoanService;
use App\Services\Navigation;
use App\Services\Pagination;
use App\Services\PasswordPolicy;
use App\Services\Scope;
use App\Services\SettingsService;

// Audit/DB yang mungkin tersentuh middleware diarahkan ke DATABASE UJI, tidak pernah ke data sungguhan.
App\Core\Database::configure([
    'name' => 'simpan_pinjam_adem_ayem_test',
    'user' => (string) App\Core\Config::get('database.admin_user'),
    'pass' => (string) App\Core\Config::get('database.admin_pass'),
]);

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

function throwsStatus(callable $fn, int $status): bool
{
    try {
        $fn();
    } catch (HttpException $e) {
        return $e->status === $status;
    }
    return false;
}

// ---- Money ----
check('format: ribuan titik', Money::format(1234567) === 'Rp 1.234.567');
check('format: nol', Money::format(0) === 'Rp 0');
check('format: negatif', Money::format(-50000) === '-Rp 50.000');
check('format: tanpa simbol', Money::format(2650000, false) === '2.650.000');
check('parse: titik ribuan', Money::parse('1.000.000') === 1000000);
check('parse: awalan Rp', Money::parse('Rp 25.000') === 25000);
check('parse: polos', Money::parse('1500000') === 1500000);
check('parse: tolak desimal koma', Money::parse('1.000,50') === null);
check('parse: tolak desimal titik', Money::parse('1000.5') === null);
check('parse: tolak huruf', Money::parse('10a') === null);
check('parse: tolak ribuan salah', Money::parse('1.00.000') === null);
check('parse: tolak kosong', Money::parse('') === null);
check('parse: tolak negatif', Money::parse('-1000') === null);

// ---- Env ----
Env::parse("TES_A=hello\n# komentar\nTES_B=\"dengan spasi\"\nTES_C=true\nTES_D=false\nTES_E = 42 \n");
check('env: string', Env::get('TES_A') === 'hello');
check('env: kutip', Env::get('TES_B') === 'dengan spasi');
check('env: bool true', Env::get('TES_C') === true);
check('env: bool false', Env::get('TES_D') === false);
check('env: trim', Env::get('TES_E') === '42');
check('env: default', Env::get('TIDAK_ADA', 'x') === 'x');

// ---- Request path ----
check('path: buang base', Request::normalizePath('/simpan_pinjam/anggota/', '/simpan_pinjam') === '/anggota');
check('path: root', Request::normalizePath('/simpan_pinjam', '/simpan_pinjam') === '/');
check('path: tanpa base', Request::normalizePath('/login', '') === '/login');
check('path: null byte dibuang', !str_contains(Request::normalizePath('/a%00b', ''), "\0"));

// ---- Router ----
$router = new Router();
$h = ['X', 'y'];
$router->get('/', $h);
$router->get('/anggota/{id:\d+}', $h, ['auth']);
$router->post('/anggota/{id:\d+}/hapus', $h);
$router->group(['prefix' => '/laporan', 'middleware' => ['auth', 'can:report.view.global']], static function (Router $r) use ($h): void {
    $r->get('/saldo', $h, ['extra']);
});
check('router: root', $router->match('GET', '/')['params'] === []);
check('router: parameter numerik', $router->match('GET', '/anggota/50')['params'] === ['id' => '50']);
check('router: middleware route', $router->match('GET', '/anggota/50')['middleware'] === ['auth']);
check('router: group prefix + middleware', $router->match('GET', '/laporan/saldo')['middleware'] === ['auth', 'can:report.view.global', 'extra']);
check('router: 404 parameter non-angka', throwsStatus(fn () => $router->match('GET', '/anggota/abc'), 404));
check('router: 404 path tak dikenal', throwsStatus(fn () => $router->match('GET', '/tidak-ada'), 404));
check('router: 405 method salah', throwsStatus(fn () => $router->match('GET', '/anggota/50/hapus'), 405));
check('router: HEAD diperlakukan GET', $router->match('HEAD', '/')['params'] === []);
check('router: titik di pola tidak jadi wildcard', throwsStatus(fn () => $router->match('GET', '/laporanXsaldo'), 404));

// ---- Gate ----
$head     = ['roles' => ['HEAD']];
$ketua    = ['roles' => ['KETUA_REGU']];
$anggota  = ['roles' => ['ANGGOTA']];
$periksa  = ['roles' => ['PEMERIKSA']];
$purwati  = ['roles' => ['HEAD', 'KETUA_REGU']];
check('gate: head validasi', Gate::allows($head, 'transaction.validate'));
check('gate: ketua regu TIDAK validasi', !Gate::allows($ketua, 'transaction.validate'));
check('gate: anggota TIDAK input transaksi', !Gate::allows($anggota, 'transaction.create'));
check('gate: ketua regu input transaksi', Gate::allows($ketua, 'transaction.create'));
check('gate: head tidak input langsung', !Gate::allows($head, 'transaction.create'));
check('gate: pemeriksa validasi', Gate::allows($periksa, 'transaction.validate'));
check('gate: pemeriksa TIDAK kelola pengaturan', !Gate::allows($periksa, 'settings.manage'));
check('gate: peran ganda digabung', Gate::allows($purwati, 'transaction.validate') && Gate::allows($purwati, 'transaction.create'));
check('gate: tamu ditolak', !Gate::allows(null, 'dashboard.view'));
check('gate: izin tak dikenal ditolak', !Gate::allows($head, 'izin.ngawur'));
check('gate: authorize melempar 403', throwsStatus(fn () => Gate::authorize($ketua, 'settings.manage'), 403));

// ---- Navigasi per peran ----
$labels = static function (?array $user): array {
    $out = [];
    foreach (Navigation::forUser($user, '/') as $g) {
        foreach ($g['items'] as $i) {
            $out[] = $i['label'];
        }
    }
    return $out;
};
check('nav: head melihat Data Ketua Regu', in_array('Data Ketua Regu', $labels($head), true));
check('nav: ketua regu TIDAK melihat Data Ketua Regu', !in_array('Data Ketua Regu', $labels($ketua), true));
check('nav: ketua regu TIDAK melihat Menunggu Validasi', !in_array('Menunggu Validasi', $labels($ketua), true));
check('nav: ketua regu TIDAK melihat Audit Log', !in_array('Audit Log', $labels($ketua), true));
check('nav: anggota TIDAK melihat Data Anggota', !in_array('Data Anggota', $labels($anggota), true));
check('nav: anggota melihat Riwayat Transaksi', in_array('Riwayat Transaksi', $labels($anggota), true));
check('nav: pemeriksa melihat Audit Log', in_array('Audit Log', $labels($periksa), true));
check('nav: pemeriksa TIDAK melihat Pengaturan', !in_array('Pengaturan', $labels($periksa), true));
check('nav: tamu tidak melihat apa pun', $labels(null) === []);

// ---- CSRF ----
$_SESSION = [];
$token = Csrf::token();
check('csrf: token 64 hex', preg_match('/^[a-f0-9]{64}$/', $token) === 1);
check('csrf: token stabil dalam sesi', Csrf::token() === $token);
check('csrf: field POST valid', Csrf::validate(new Request('POST', '/', [], ['_token' => $token])));
check('csrf: header valid', Csrf::validate(new Request('POST', '/', [], [], ['HTTP_X_CSRF_TOKEN' => $token])));
check('csrf: token salah ditolak', !Csrf::validate(new Request('POST', '/', [], ['_token' => str_repeat('0', 64)])));
check('csrf: tanpa token ditolak', !Csrf::validate(new Request('POST', '/')));
$_SESSION = [];
check('csrf: tanpa sesi ditolak', !Csrf::validate(new Request('POST', '/', [], ['_token' => ''])));

// ---- Middleware ----
$ok   = static fn (Request $r): Response => Response::html('lolos');
$pass = static fn (callable $fn): bool => $fn() === 'lolos';

$_SESSION = ['_csrf' => str_repeat('a', 64)];
$csrf = new CsrfMiddleware();
check('mw csrf: POST tanpa token -> 419', throwsStatus(fn () => $csrf->handle(new Request('POST', '/x'), $ok), 419));
check('mw csrf: DELETE tanpa token -> 419', throwsStatus(fn () => $csrf->handle(new Request('DELETE', '/x'), $ok), 419));
check('mw csrf: POST token salah -> 419', throwsStatus(fn () => $csrf->handle(new Request('POST', '/x', [], ['_token' => 'salah']), $ok), 419));
check('mw csrf: POST token benar lolos', $pass(fn () => $csrf->handle(new Request('POST', '/x', [], ['_token' => str_repeat('a', 64)]), $ok)->body));
check('mw csrf: GET tidak butuh token', $pass(fn () => $csrf->handle(new Request('GET', '/x'), $ok)->body));

$_SESSION = [];
Auth::actingAs(null);
$auth = new AuthMiddleware();
$r302 = $auth->handle(new Request('GET', '/anggota/5'), $ok);
check('mw auth: tamu -> redirect login', $r302->status === 302 && str_ends_with($r302->headers['Location'], '/login'));
check('mw auth: tujuan semula diingat untuk GET', ($_SESSION['intended'] ?? '') === '/anggota/5');
unset($_SESSION['intended']);
$auth->handle(new Request('POST', '/x'), $ok);
check('mw auth: POST tidak diingat sebagai tujuan', !isset($_SESSION['intended']));
$r401 = $auth->handle(new Request('GET', '/api/x'), $ok);
check('mw auth: tamu AJAX -> 401 JSON', $r401->status === 401);

$mkUser = static fn (array $roles, bool $must = false): array => [
    'id' => 1, 'username' => 'x', 'name' => 'X', 'roles' => $roles, 'member_id' => null, 'team_id' => null,
    'must_change_password' => $must, 'credentials_ts' => 0,
];
Auth::actingAs($mkUser(['KETUA_REGU']));
check('mw auth: user login lolos', $pass(fn () => $auth->handle(new Request('GET', '/'), $ok)->body));
check('mw guest: user login -> redirect', (new GuestMiddleware())->handle(new Request('GET', '/login'), $ok)->status === 302);
check('mw can: izin ada lolos', $pass(fn () => (new CanMiddleware('transaction.create'))->handle(new Request('GET', '/'), $ok)->body));
check('mw can: salah satu dari beberapa izin lolos', $pass(fn () => (new CanMiddleware('transaction.validate|transaction.create'))->handle(new Request('GET', '/'), $ok)->body));
check('mw can: izin tidak ada -> 403', throwsStatus(fn () => (new CanMiddleware('transaction.validate'))->handle(new Request('GET', '/'), $ok), 403));
check('mw can: tak satu pun dari beberapa izin -> 403', throwsStatus(fn () => (new CanMiddleware('transaction.validate|settings.manage'))->handle(new Request('GET', '/'), $ok), 403));
check('mw can: tanpa argumen -> 403 (gagal aman)', throwsStatus(fn () => (new CanMiddleware())->handle(new Request('GET', '/'), $ok), 403));

Auth::actingAs($mkUser(['HEAD'], true));
$forced = $auth->handle(new Request('GET', '/'), $ok);
check('mw auth: kata sandi sementara -> dialihkan ke ganti kata sandi', $forced->status === 302 && str_ends_with($forced->headers['Location'], '/profil/password'));
check('mw auth: halaman ganti kata sandi tetap boleh', $pass(fn () => $auth->handle(new Request('GET', '/profil/password'), $ok)->body));
check('mw auth: logout tetap boleh', $pass(fn () => $auth->handle(new Request('POST', '/logout'), $ok)->body));
check('mw auth: AJAX saat wajib ganti kata sandi -> 403', $auth->handle(new Request('GET', '/api/x'), $ok)->status === 403);

Auth::actingAs(null);
check('mw can: tamu -> 403', throwsStatus(fn () => (new CanMiddleware('dashboard.view'))->handle(new Request('GET', '/'), $ok), 403));

// ---- Auth: pengalihan aman ----
check('redirect: path internal diterima', Auth::safeRedirect('/anggota/5') === '/anggota/5');
check('redirect: kosong -> beranda', Auth::safeRedirect('') === '/' && Auth::safeRedirect(null) === '/');
check('redirect: //evil.com ditolak', Auth::safeRedirect('//evil.com') === '/');
check('redirect: URL absolut ditolak', Auth::safeRedirect('https://evil.com') === '/');
check('redirect: backslash ditolak', Auth::safeRedirect('/\\evil.com') === '/');
check('redirect: karakter kontrol ditolak', Auth::safeRedirect("/a\r\nSet-Cookie: x") === '/');

// ---- Validator & kebijakan kata sandi ----
check('validator: wajib', Validator::validate(['a' => ''], ['a' => 'required']) !== []);
check('validator: opsional kosong lolos', Validator::validate(['a' => ''], ['a' => 'min:5']) === []);
check('validator: min', Validator::validate(['a' => 'abc'], ['a' => 'required|min:5']) !== []);
check('validator: same', Validator::validate(['a' => 'x', 'b' => 'y'], ['b' => 'same:a']) !== []);
check('validator: lolos semua', Validator::validate(['a' => 'abcde', 'b' => 'abcde'], ['a' => 'required|min:5|max:10', 'b' => 'same:a']) === []);
check('validator: aturan tak dikenal melempar', (function (): bool {
    try {
        Validator::validate(['a' => 'x'], ['a' => 'ngawur']);
    } catch (LogicException $e) {
        return true;
    }
    return false;
})());
check('sandi: pendek ditolak', PasswordPolicy::check('abc123') !== []);
check('sandi: tanpa angka ditolak', PasswordPolicy::check('hanyahuruf') !== []);
check('sandi: tanpa huruf ditolak', PasswordPolicy::check('1234567890') !== []);
check('sandi: umum ditolak', PasswordPolicy::check('password123') !== []);
check('sandi: mengandung username ditolak', PasswordPolicy::check('purwati2026x', 'purwati') !== []);
check('sandi: > 72 byte ditolak (batas bcrypt)', PasswordPolicy::check(str_repeat('a1', 37)) !== []);
check('sandi: valid diterima', PasswordPolicy::check('kopi-susu-47') === []);
check('sandi: hash bcrypt cost 12 dan verifikasi', (function (): bool {
    $h = PasswordPolicy::hash('kopi-susu-47');
    return str_starts_with($h, '$2y$12$') && password_verify('kopi-susu-47', $h) && !password_verify('kopi-susu-48', $h) && !PasswordPolicy::needsRehash($h);
})());
check('sandi: pembangkit memenuhi kebijakan 200x', (function (): bool {
    for ($i = 0; $i < 200; $i++) {
        if (PasswordPolicy::check(PasswordPolicy::generate()) !== []) {
            return false;
        }
    }
    return true;
})());

// ---- Cakupan data (Scope), logika tanpa database ----
check('scope: head = all', Scope::level(['roles' => ['HEAD']]) === 'all');
check('scope: pemeriksa = all', Scope::level(['roles' => ['PEMERIKSA']]) === 'all');
check('scope: ketua regu = team', Scope::level(['roles' => ['KETUA_REGU'], 'team_id' => 3, 'member_id' => 9]) === 'team');
check('scope: ketua regu tanpa regu turun ke self', Scope::level(['roles' => ['KETUA_REGU'], 'team_id' => null, 'member_id' => 9]) === 'self');
check('scope: anggota = self', Scope::level(['roles' => ['ANGGOTA'], 'member_id' => 9]) === 'self');
check('scope: anggota tanpa tautan anggota = none', Scope::level(['roles' => ['ANGGOTA'], 'member_id' => null]) === 'none');
check('scope: tamu = none', Scope::level(null) === 'none' && Scope::memberCondition(null)[0] === '1 = 0');
check('scope: head+ketua regu = all (peran tertinggi)', Scope::level(['roles' => ['HEAD', 'KETUA_REGU'], 'team_id' => 3, 'member_id' => 9]) === 'all');
check('scope: SQL team berparameter', Scope::memberCondition(['roles' => ['KETUA_REGU'], 'team_id' => 3, 'member_id' => 9]) === ['m.id IN (SELECT a.member_id FROM member_team_assignments a WHERE a.team_id = ? AND a.valid_to IS NULL)', [3]]);
check('scope: nama kolom berbahaya ditolak', (function (): bool {
    try {
        Scope::memberCondition(['roles' => ['HEAD']], 'm.id; DROP TABLE members');
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
})());

// ---- Penjaga struktural: setiap route harus terlindungi ----
$guard = new Router();
(require BASE_PATH . '/config/routes.php')($guard);
$publicOk = ['#^/login$#' => ['guest'], '#^/health$#' => [], '#^/styleguide$#' => []]; // pengecualian yang disengaja
$unprotected = [];
$noPermission = [];
foreach ($guard->all() as $route) {
    $mw   = $route['middleware'];
    $name = $route['method'] . ' ' . $route['regex'];
    $isPublic = false;
    foreach ($publicOk as $pattern => $needs) {
        if (str_contains($route['regex'], trim($pattern, '#')) || $route['regex'] === $pattern) {
            $isPublic = true;
        }
    }
    if ($isPublic) {
        continue;
    }
    if (!in_array('auth', $mw, true)) {
        $unprotected[] = $name;
    }
    $needsPermission = str_contains($route['regex'], '/master/') || str_contains($route['regex'], '/sistem/') || $route['method'] !== 'GET' && !str_contains($route['regex'], '/logout') && !str_contains($route['regex'], '/profil');
    if ($needsPermission && count(array_filter($mw, static fn (string $m): bool => str_starts_with($m, 'can:'))) === 0) {
        $noPermission[] = $name;
    }
}
check('struktur: semua route non-publik memakai "auth" (' . count($guard->all()) . ' route diperiksa)' . ($unprotected ? ' -> ' . implode(', ', $unprotected) : ''), $unprotected === []);
check('struktur: semua route master/sistem dan pengubah-data memakai "can:" (kecuali logout & profil sendiri)' . ($noPermission ? ' -> ' . implode(', ', $noPermission) : ''), $noPermission === []);
check('struktur: tidak ada route yang mengubah data dengan method GET', (function () use ($guard): bool {
    foreach ($guard->all() as $route) {
        if ($route['method'] === 'GET' && preg_match('#/(hapus|reset-password|buka-kunci|status)(\$|/)#', $route['regex'])) {
            return false;
        }
    }
    return true;
})());

// ---- Kebijakan route transaksi (Phase 6): tulis hanya untuk pencipta transaksi, baca untuk semua pemilik izin lihat ----
$trxRoutes = [];
foreach ($guard->all() as $route) {
    if (str_starts_with($route['regex'], '#^/transaksi')) {
        $trxRoutes[] = $route;
    }
}
$writeOk = true;
$readOk  = true;
foreach ($trxRoutes as $route) {
    $can = array_values(array_filter($route['middleware'], static fn (string $m): bool => str_starts_with($m, 'can:')));
    if ($route['method'] === 'POST' || str_ends_with($route['regex'], 'baru$#') || str_ends_with($route['regex'], 'ubah$#')) {
        $writeOk = $writeOk && $can === ['can:transaction.create'];
    } else {
        $readOk = $readOk && $can === ['can:transaction.view.all|transaction.view.team|transaction.view.self'];
    }
}
check('struktur: ' . count($trxRoutes) . ' route transaksi; tulis/ubah HANYA can:transaction.create (Head/Pemeriksa/Anggota tidak bisa)', count($trxRoutes) === 22 && $writeOk);
check('struktur: route baca transaksi memakai izin lihat (all|team|self), tanpa izin tulis', $readOk);
check('route: /transaksi/riwayat tidak tertelan oleh /transaksi/{id}', $guard->match('GET', '/transaksi/riwayat')['handler'][1] === 'history' && $guard->match('GET', '/transaksi/12')['params']['id'] === '12');
check('route: /transaksi/pinjaman/simulasi dan /baru tidak tertelan rute lain', $guard->match('GET', '/transaksi/pinjaman/simulasi')['handler'][1] === 'loanSimulation' && $guard->match('GET', '/transaksi/pinjaman/baru')['handler'][1] === 'createLoan' && $guard->match('POST', '/transaksi/pinjaman/7')['handler'][1] === 'updateLoan');
check('kalkulator: Rp 1.000.000 tenor 5 tarif 2% -> bunga 100.000, cicilan 220.000 x5 (contoh Excel)', LoanService::quote(1000000, 5, 200) === ['interest' => 100000, 'total' => 1100000, 'installments' => [220000, 220000, 220000, 220000, 220000]]);
check('kalkulator: Rp 5.000.000 tenor 3 -> cicilan terakhir menyerap sisa (jumlah tetap persis)', LoanService::quote(5000000, 3, 200)['installments'] === [1766666, 1766666, 1766668] && array_sum(LoanService::quote(5000000, 3, 200)['installments']) === 5300000);
check('kalkulator: bunga dibulatkan setengah ke atas seperti ROUND() database', LoanService::quote(1000005, 5, 200)['interest'] === 100001 && LoanService::quote(1000001, 5, 200)['interest'] === 100000 && LoanService::quote(1000004, 5, 200)['interest'] === 100000);
check('kalkulator: tarif desimal 2,50%', LoanService::quote(1000000, 4, 250)['interest'] === 100000 && LoanService::quote(1000000, 3, 250)['interest'] === 75000);
check('kalkulator: jumlah jadwal selalu = pokok + bunga untuk 2000 kombinasi acak', (function (): bool {
    mt_srand(7);
    for ($i = 0; $i < 2000; $i++) {
        $p = mt_rand(1, 20000) * 500 + mt_rand(0, 1) * mt_rand(1, 499);
        $t = mt_rand(1, 12);
        $q = LoanService::quote($p, $t, mt_rand(1, 1000));
        if (array_sum($q['installments']) !== $q['total'] || count($q['installments']) !== $t || min($q['installments']) <= 0 || $q['total'] !== $p + $q['interest']) {
            return false;
        }
    }
    return true;
})());
check('kalkulator: masukan tidak sah ditolak', (function (): bool {
    foreach ([[0, 3, 200], [1000, 0, 200], [-5, 3, 200], [1000, 3, -1]] as [$p, $t, $r]) {
        try {
            LoanService::quote($p, $t, $r);
            return false;
        } catch (InvalidArgumentException $e) {
        }
    }
    return true;
})());
check('route: /transaksi/angsuran/tagihan dan /baru tidak tertelan rute lain', $guard->match('GET', '/transaksi/angsuran/tagihan')['handler'][1] === 'dues' && $guard->match('GET', '/transaksi/angsuran/baru')['handler'][1] === 'createPayment' && $guard->match('POST', '/transaksi/angsuran/9')['handler'][1] === 'updatePayment' && $guard->match('GET', '/transaksi/angsuran/9/ubah')['handler'][1] === 'editPayment');
$valRoutes = array_values(array_filter($guard->all(), static fn (array $r): bool => str_contains($r['regex'], '/validasi')));
check('struktur: 4 route validasi, SEMUA hanya can:transaction.validate (Ketua Regu dan Anggota tidak bisa)', count($valRoutes) === 4 && count(array_filter($valRoutes, static fn (array $r): bool => array_values(array_filter($r['middleware'], static fn (string $m): bool => str_starts_with($m, 'can:'))) === ['can:transaction.validate'])) === 4);
check('struktur: aksi setujui dan tolak hanya POST', count(array_filter($valRoutes, static fn (array $r): bool => $r['method'] === 'POST')) === 2 && count(array_filter($valRoutes, static fn (array $r): bool => $r['method'] === 'POST' && (str_contains($r['regex'], 'setujui') || str_contains($r['regex'], 'tolak')))) === 2);
check('izin: hanya Head dan Pemeriksa yang punya transaction.validate; pembuat transaksi (Ketua Regu) tidak', Gate::allows(['roles' => ['HEAD']], 'transaction.validate') && Gate::allows(['roles' => ['PEMERIKSA']], 'transaction.validate') && !Gate::allows(['roles' => ['KETUA_REGU']], 'transaction.validate') && !Gate::allows(['roles' => ['ANGGOTA']], 'transaction.validate') && !Gate::allows(['roles' => ['PEMERIKSA']], 'transaction.create') && !Gate::allows(['roles' => ['HEAD']], 'transaction.create'));
// ---- Laporan (Phase 12): hanya baca; unduhan hanya report.export ----
$repRoutes = array_values(array_filter($guard->all(), static fn (array $r): bool => str_starts_with($r['regex'], '#^/laporan')));
$canOf = static fn (array $r): array => array_values(array_filter($r['middleware'], static fn (string $m): bool => str_starts_with($m, 'can:')));
check('struktur: 11 route laporan, semuanya GET, dengan auth dan tepat satu can:', count($repRoutes) === 11 && count(array_filter($repRoutes, static fn (array $r): bool => $r['method'] === 'GET' && in_array('auth', $r['middleware'], true) && count(array_filter($r['middleware'], static fn (string $m): bool => str_starts_with($m, 'can:'))) === 1)) === 11);
check('struktur: unduhan HANYA can:report.export; laporan per regu HANYA global; kartu anggota global|team', (function () use ($repRoutes, $canOf): bool {
    foreach ($repRoutes as $r) {
        $c = $canOf($r)[0];
        if (str_contains($r['regex'], 'unduh') && $c !== 'can:report.export') {
            return false;
        }
        if (str_contains($r['regex'], '/laporan/regu') && $c !== 'can:report.view.global') {
            return false;
        }
        if (str_contains($r['regex'], '/laporan/anggota') && !str_contains($r['regex'], 'unduh') && $c !== 'can:report.view.global|report.view.team') {
            return false;
        }
    }
    return true;
})());
check('izin: report.export hanya Head dan Pemeriksa; Ketua Regu dan Anggota tidak', Gate::allows(['roles' => ['HEAD']], 'report.export') && Gate::allows(['roles' => ['PEMERIKSA']], 'report.export') && !Gate::allows(['roles' => ['KETUA_REGU']], 'report.export') && !Gate::allows(['roles' => ['ANGGOTA']], 'report.export'));
check('route: /laporan/anggota/unduh tidak tertelan /laporan/anggota/{id} dan sebaliknya', $guard->match('GET', '/laporan/anggota/unduh')['handler'][1] === 'download' && $guard->match('GET', '/laporan/anggota/12')['handler'][1] === 'member' && $guard->match('GET', '/laporan/simpanan/unduh')['params']['key'] === 'simpanan');
check('route: formulir cetak tidak bentrok dengan kartu anggota, unduhan, dan satu sama lain', $guard->match('GET', '/laporan/anggota/12/pinjaman')['handler'][1] === 'sheet' && $guard->match('GET', '/laporan/anggota/12/tabungan')['params']['kind'] === 'tabungan' && $guard->match('GET', '/laporan/anggota/cetak/pinjaman')['handler'][1] === 'sheets' && $guard->match('GET', '/laporan/anggota/12')['handler'][1] === 'member' && throwsStatus(fn () => $guard->match('GET', '/laporan/anggota/12/lain'), 404) && throwsStatus(fn () => $guard->match('GET', '/laporan/anggota/cetak/lain'), 404) && throwsStatus(fn () => $guard->match('POST', '/laporan/anggota/12/pinjaman'), 405));
// ---- Audit log (Phase 13): hanya baca, hanya audit.view ----
$auditRoutes = array_values(array_filter($guard->all(), static fn (array $r): bool => str_starts_with($r['regex'], '#^/sistem/audit')));
check('struktur: 3 route audit, semuanya GET, dengan auth dan HANYA can:audit.view', count($auditRoutes) === 3 && count(array_filter($auditRoutes, static fn (array $r): bool => $r['method'] === 'GET' && in_array('auth', $r['middleware'], true) && array_values(array_filter($r['middleware'], static fn (string $m): bool => str_starts_with($m, 'can:'))) === ['can:audit.view'])) === 3);
check('izin: audit.view hanya Head dan Pemeriksa; Ketua Regu dan Anggota tidak', Gate::allows(['roles' => ['HEAD']], 'audit.view') && Gate::allows(['roles' => ['PEMERIKSA']], 'audit.view') && !Gate::allows(['roles' => ['KETUA_REGU']], 'audit.view') && !Gate::allows(['roles' => ['ANGGOTA']], 'audit.view'));
check('route: /sistem/audit/unduh tidak tertelan /sistem/audit/{id}', $guard->match('GET', '/sistem/audit/unduh')['handler'][1] === 'download' && $guard->match('GET', '/sistem/audit/15')['handler'][1] === 'show' && $guard->match('GET', '/sistem/audit/15')['params']['id'] === '15');
check('route: /transaksi/abc bukan angka -> 404', (function () use ($guard): bool {
    try {
        $guard->match('GET', '/transaksi/abc');
    } catch (HttpException $e) {
        return $e->getCode() === 404 || $e->status === 404;
    }
    return false;
})());
check('helper: month_label dalam Bahasa Indonesia', month_label('2026-03-01') === 'Maret 2026' && month_label('2027-02-01') === 'Februari 2027' && month_label('2026-12-15') === 'Desember 2026');
check('helper: date_id dan nilai kosong', date_id('2026-03-07') === '07-03-2026' && date_id(null) === '-' && date_id('bukan tanggal') === '-');

// ---- Paginasi, pencarian, teks, validator (regex dengan "|") ----
$pg = Pagination::make(37, 2);
check('paginasi: 37 data, hal. 2 -> 12 baris dari 26 sampai 37', $pg['pages'] === 2 && $pg['offset'] === 25 && $pg['from'] === 26 && $pg['to'] === 37);
check('paginasi: kosong -> 1 halaman, from=0', Pagination::make(0, 1)['pages'] === 1 && Pagination::make(0, 1)['from'] === 0);
check('paginasi: halaman dijepit ke rentang', Pagination::make(10, 99)['page'] === 1 && Pagination::make(100, -3)['page'] === 1);
check('paginasi: "%" dan "_" dan "\\" di-escape', Pagination::likeEscape('a%b_c\\d') === 'a\\%b\\_c\\\\d');
check('teks: spasi ganda, tab, dan karakter kontrol dirapikan', clean_text("  Bu \t Sari\x00\x07  ") === 'Bu Sari');
check('teks: unicode dipertahankan', clean_text('  Ibu  Sri   Wahyuningsih ') === 'Ibu Sri Wahyuningsih');
check('validator: regex dengan "|" di dalam pola tidak terpotong', Validator::validate(['m' => '2026-05'], ['m' => 'required|regex:/^\d{4}-(0[1-9]|1[0-2])$/']) === []);
check('validator: regex dengan "|" menolak yang salah', Validator::validate(['m' => '2026-13'], ['m' => 'required|regex:/^\d{4}-(0[1-9]|1[0-2])$/']) !== []);

// ---- Pengaturan: validasi (tanpa database) ----
$base = [
    'interest_rate_pct_month' => '2.00', 'loan_tenor_min' => '1', 'loan_tenor_max' => '5', 'loan_max_amount' => '15.000.000',
    'loan_max_active_per_member' => '0', 'saving_pokok_amount' => '50000', 'profit_share_saver_pct' => '40', 'profit_share_borrower_pct' => '40',
    'profit_share_shu_pct' => '20', 'reserve_pct' => '5', 'shu_member_pct' => '60', 'shu_manager_pct' => '40',
];
check('pengaturan: nilai awal valid', SettingsService::validate($base)[0] === []);
check('pengaturan: bool tak dicentang = 0, dicentang = 1', SettingsService::validate($base)[1]['withdrawals_enabled'] === '0' && SettingsService::validate($base + ['withdrawals_enabled' => '1'])[1]['withdrawals_enabled'] === '1');
check('pengaturan: bagi hasil 40+40+21 ditolak', isset(SettingsService::validate(['profit_share_shu_pct' => '21'] + $base)[0]['profit_share_shu_pct']));
check('pengaturan: SHU 60+39 ditolak', isset(SettingsService::validate(['shu_manager_pct' => '39'] + $base)[0]['shu_manager_pct']));
check('pengaturan: nominal Rp dinormalkan (15.000.000 -> 15000000)', SettingsService::validate($base)[1]['loan_max_amount'] === '15000000');
check('pengaturan: cadangan 5 -> "5.00"', SettingsService::validate($base)[1]['reserve_pct'] === '5.00');
check('pengaturan: kunci liar di input diabaikan', !array_key_exists('hacker', SettingsService::validate($base + ['hacker' => 'x'])[1]));

// ---- View ----
check('view: tolak path traversal', (function (): bool {
    try {
        View::include('../config/database', []);
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
})());

$activeOf = static function (array $user, string $path): array {
    $on = [];
    foreach (Navigation::forUser($user, $path) as $g) {
        foreach ($g['items'] as $i) {
            if ($i['active']) {
                $on[] = $i['path'];
            }
        }
    }
    return $on;
};
$headUser = $mkUser(['HEAD']);
check('menu aktif: /validasi hanya menyalakan "Menunggu Validasi"', $activeOf($headUser, '/validasi') === ['/validasi']);
check('menu aktif: /validasi/riwayat hanya menyalakan "Riwayat Validasi" (bukan keduanya: jalur awalan tidak ikut aktif)', $activeOf($headUser, '/validasi/riwayat') === ['/validasi/riwayat']);
check('menu aktif: halaman turunan (/transaksi/angsuran/tagihan) menyalakan induknya saja; Dashboard hanya di "/"', $activeOf($headUser, '/transaksi/angsuran/tagihan') === ['/transaksi/angsuran'] && $activeOf($headUser, '/') === ['/'] && $activeOf($headUser, '/laporan/anggota/5') === ['/laporan/anggota']);
check('menu aktif: halaman di luar menu tidak menyalakan apa pun', $activeOf($headUser, '/tidak-ada') === [] && $activeOf($headUser, '/transaksi/12') === []);
use App\Controllers\ErrorController;
Auth::actingAs($mkUser(['KETUA_REGU']));
check('galat: pengguna login mendapat kerangka aplikasi untuk 403, 404, 405, 419', array_reduce([403, 404, 405, 419], static fn (bool $c, int $s): bool => $c && ErrorController::framedUser($s) !== null, true));
check('galat: 500 dan status lain TIDAK memakai kerangka walau pengguna login (500 bisa disebabkan database)', ErrorController::framedUser(500) === null && ErrorController::framedUser(200) === null && ErrorController::framedUser(401) === null);
Auth::actingAs($mkUser(['KETUA_REGU'], true));
check('galat: pengguna wajib ganti kata sandi tidak mendapat kerangka (menu tidak boleh dipakai)', ErrorController::framedUser(404) === null);
Auth::actingAs(null);
check('galat: tamu tidak mendapat kerangka', ErrorController::framedUser(404) === null && ErrorController::framedUser(403) === null);

// ---- Hasil ----
echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
