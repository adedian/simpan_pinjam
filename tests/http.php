<?php
declare(strict_types=1);

/**
 * Tes HTTP end-to-end. Menjalankan server bawaan PHP (127.0.0.1:8099) yang memakai DATABASE UJI
 * lewat .env.testing, lalu "browser" bercookie terpisah menjalani alur login, hak akses, dan keamanan.
 * Tidak menyentuh data sungguhan. Server dimatikan di akhir.
 *   C:\xampp\php\php.exe tests\http.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Migrator;
use App\Services\UserService;

const PORT = 8099;
const BASE = 'http://127.0.0.1:8099';
const PASS = 'Contoh-Uji-2026';

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];

// ---------- berkas env uji (tanpa rahasia: akun admin lokal ke DB uji) ----------
file_put_contents(BASE_PATH . '/.env.testing', implode("\n", [
    'APP_ENV=local', 'APP_DEBUG=false', 'APP_BASE_PATH=/', 'SESSION_IDLE_TIMEOUT=4',
    'DB_HOST=127.0.0.1', 'DB_PORT=3306', "DB_NAME={$testDb}", "DB_USER={$admin[0]}", "DB_PASS={$admin[1]}",
]) . "\n");

// ---------- database uji + fixture ----------
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $testDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
$m = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
$m->dropEverything();
$m->migrate();

$pdo->exec("INSERT INTO periods (name, start_date, end_date) VALUES ('Uji','2026-03-01','2027-02-28')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1,'2026-03-01'),(1,'2026-04-01'),(1,'2026-05-01'),(1,'2026-06-01'),(1,'2026-07-01'),(1,'2026-08-01'),(1,'2026-09-01'),(1,'2026-10-01'),(1,'2026-11-01'),(1,'2026-12-01'),(1,'2027-01-01'),(1,'2027-02-01')");
foreach ([1 => 'Ketua Regu Alfa', 2 => 'Anggota Alfa Dua', 3 => 'Ketua Regu Beta', 4 => 'Anggota Beta Dua'] as $n => $name) {
    $pdo->prepare("INSERT INTO members (member_no, name, active_from) VALUES (?, ?, '2026-03-01')")->execute([sprintf('AGT-%03d', $n), $name]);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'2026-03-01'),(2,1,'2026-03-01'),(3,2,'2026-03-01'),(4,2,'2026-03-01')");

UserService::create('alfa', 'Ketua Alfa', ['KETUA_REGU'], 'AGT-001', PASS);
UserService::create('beta', 'Ketua Beta', ['KETUA_REGU'], 'AGT-003', PASS);
UserService::create('kepala', 'Kepala Koperasi', ['HEAD'], null, PASS);               // tanpa tautan anggota
UserService::create('anggota4', 'Anggota Beta Dua', ['ANGGOTA'], 'AGT-004', PASS);
UserService::create('baru', 'Pengguna Baru', ['ANGGOTA'], 'AGT-002', PASS);   // masih kata sandi sementara
$pdo->exec("UPDATE users SET must_change_password = 0 WHERE username IN ('alfa','beta','anggota4','kepala')");

// ---------- jalankan server ----------
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . PORT, '-t', BASE_PATH . '/public', BASE_PATH . '/tests/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/adem-http.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/adem-http.log', 'a']],
    $pipes, BASE_PATH, array_merge(getenv(), ['APP_ENV_FILE' => '.env.testing'])
);
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        $status = proc_get_status($proc);
        @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        proc_terminate($proc);
    }
});
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', PORT, $en, $es, 0.2)) {
        break;
    }
    usleep(100000);
}

// ---------- klien "browser" ----------
final class Browser
{
    public string $jar;
    /** Bila diisi (lewat keepAlive()), GET yang dialihkan ke /login karena sesi kedaluwarsa (idle uji hanya 4 detik) otomatis masuk lagi. */
    public ?string $autoLogin = null;
    /** @var array{status:int,headers:array<string,string>,body:string,location:?string} */
    public array $last = ['status' => 0, 'headers' => [], 'body' => '', 'location' => null];

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function keepAlive(string $user): self
    {
        $this->login($user);
        $this->autoLogin = $user;
        return $this;
    }

    public function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        $result = $this->send($method, $path, $form, $headers);
        if ($this->autoLogin !== null && $method === 'GET' && $result['status'] === 302 && str_ends_with((string) $result['location'], '/login') && !str_starts_with($path, '/login')) {
            $user = $this->autoLogin;
            $this->autoLogin = null;
            $this->login($user);
            $this->autoLogin = $user;
            $result = $this->send($method, $path, $form, $headers);
        }
        return $result;
    }

    private function send(string $method, string $path, array $form = [], array $headers = []): array
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $raw  = (string) curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $hdr = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $hdr[strtolower(trim($k))] = ($hdr[strtolower(trim($k))] ?? '') . (isset($hdr[strtolower(trim($k))]) ? "\n" : '') . trim($v);
            }
        }
        return $this->last = ['status' => $status, 'headers' => $hdr, 'body' => substr($raw, $size), 'location' => $hdr['location'] ?? null];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $form = [], bool $withCsrf = true): array
    {
        if ($withCsrf && !isset($form['_token'])) {
            $form['_token'] = $this->csrf();
        }
        return $this->request('POST', $path, $form);
    }

    /** Token CSRF dari halaman terakhir (atau halaman beranda/login bila belum ada). */
    public function csrf(): string
    {
        if (preg_match('/name="csrf-token" content="([a-f0-9]{64})"|name="_token" value="([a-f0-9]{64})"/', $this->last['body'], $m) === 1) {
            return $m[1] !== '' ? $m[1] : $m[2];
        }
        $this->get('/login');
        preg_match('/name="_token" value="([a-f0-9]{64})"/', $this->last['body'], $m);
        return $m[1] ?? '';
    }

    public function cookie(string $name): ?string
    {
        foreach (explode("\n", (string) file_get_contents($this->jar)) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }
        return null;
    }

    public function login(string $user, string $pass = PASS): array
    {
        $this->get('/login');
        return $this->post('/login', ['username' => $user, 'password' => $pass]);
    }
}

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
$count = fn (string $sql, array $p = []): int => (function () use ($sql, $p): int {
    $s = Database::pdo()->prepare($sql);
    $s->execute($p);
    return (int) $s->fetchColumn();
})();
$mid = fn (int $n): int => $count('SELECT id FROM members WHERE member_no = ?', [sprintf('AGT-%03d', $n)]);
$loc = fn (array $r): string => (string) preg_replace('#^https?://[^/]+#', '', (string) $r['location']);

// ---------- 0. server hidup ----------
$b = new Browser();
check('server: /health hidup', $b->get('/health')['status'] === 200);

// ---------- 1. tamu ----------
$b = new Browser();
check('tamu: / dialihkan ke /login', $b->get('/')['status'] === 302 && $loc($b->last) === '/login');
check('tamu: detail anggota dialihkan ke /login', $b->get('/anggota/' . $mid(2))['status'] === 302 && $loc($b->last) === '/login');
check('tamu: /profil dialihkan ke /login', $b->get('/profil')['status'] === 302);
check('tamu: AJAX mendapat 401 JSON, bukan redirect', $b->request('GET', '/anggota/' . $mid(2), [], ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'])['status'] === 401);
check('tamu: rute tak dikenal 404', $b->get('/tidak-ada')['status'] === 404);
check('tamu: GET /logout -> 405', $b->get('/logout')['status'] === 405);
check('tamu: .env tidak terlayani', $b->get('/.env')['status'] === 404);
check('tamu: aset statis terlayani', $b->get('/assets/css/app.css')['status'] === 200);

// ---------- 2. halaman login & header ----------
$b = new Browser();
$r = $b->get('/login');
check('login: halaman tampil', $r['status'] === 200 && str_contains($r['body'], 'name="password"'));
check('login: ada token CSRF di formulir', preg_match('/name="_token" value="[a-f0-9]{64}"/', $r['body']) === 1);
check('login: formulir tidak mengaktifkan autocomplete off pada password (pengelola sandi diizinkan)', str_contains($r['body'], 'autocomplete="current-password"'));
check('header: CSP ketat', str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'self'") && str_contains($r['headers']['content-security-policy'] ?? '', "frame-ancestors 'none'"));
check('header: nosniff + DENY + no-store', ($r['headers']['x-content-type-options'] ?? '') === 'nosniff' && ($r['headers']['x-frame-options'] ?? '') === 'DENY' && str_contains($r['headers']['cache-control'] ?? '', 'no-store'));
check('cookie sesi: HttpOnly dan SameSite=Lax', (bool) preg_match('/adem_ayem_sid=[^;]+;.*HttpOnly/i', $r['headers']['set-cookie'] ?? '') && str_contains($r['headers']['set-cookie'] ?? '', 'SameSite=Lax'));
check('login: tidak ada <script> atau handler inline', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|load)=/i', $r['body']));

// ---------- 3. CSRF ----------
check('csrf: POST /login tanpa token -> 419', $b->post('/login', ['username' => 'alfa', 'password' => PASS], false)['status'] === 419);
check('csrf: token palsu -> 419', $b->post('/login', ['username' => 'alfa', 'password' => PASS, '_token' => str_repeat('0', 64)], false)['status'] === 419);
check('csrf: 419 tidak membuat sesi login', $b->get('/')['status'] === 302);

// ---------- 4. kegagalan login ----------
$b = new Browser();
$b->get('/login');
$wrong = $b->post('/login', ['username' => 'alfa', 'password' => 'salah-salah-1']);
check('gagal: dialihkan kembali (PRG)', $wrong['status'] === 302 && $loc($wrong) === '/login');
$page = $b->get('/login');
check('gagal: pesan generik tampil', str_contains($page['body'], 'Nama pengguna atau kata sandi salah.'));
check('gagal: nama pengguna dipertahankan, kata sandi TIDAK', str_contains($page['body'], 'value="alfa"') && !str_contains($page['body'], 'salah-salah-1'));
$b->post('/login', ['username' => 'tidak.ada', 'password' => 'salah-salah-1']);
$page2 = $b->get('/login');
check('gagal: pengguna tak ada -> pesan SAMA (tanpa enumerasi)', str_contains($page2['body'], 'Nama pengguna atau kata sandi salah.'));
$b->post('/login', ['username' => "' OR '1'='1' -- ", 'password' => "' OR '1'='1"]);
check('SQLi: injeksi klasik tidak melewati login', !str_contains($b->get('/login')['body'], 'Keluar') && $b->get('/')['status'] === 302);
$b->post('/login', ['username' => '"><script>alert(1)</script>', 'password' => 'x']);
$xss = $b->get('/login')['body'];
check('XSS: nilai lama di-escape di formulir', !str_contains($xss, '<script>alert(1)</script>') && str_contains($xss, '&lt;script&gt;'));
$b->post('/login', ['username' => '', 'password' => '']);
check('validasi: kolom kosong menampilkan galat per field', str_contains($b->get('/login')['body'], 'wajib diisi'));

// ---------- 5. login sukses, sesi, redirect tujuan ----------
$b = new Browser();
$b->get('/anggota/' . $mid(2));                    // tamu: tujuan diingat
$b->get('/login');
$before = $b->cookie('adem_ayem_sid');
$ok = $b->post('/login', ['username' => 'alfa', 'password' => PASS, 'next' => '//evil.example']);
check('sukses: dialihkan ke tujuan semula, bukan parameter "next" luar', $ok['status'] === 302 && $loc($ok) === '/anggota/' . $mid(2) && !str_contains((string) $ok['location'], 'evil'));
check('sukses: ID sesi diganti (anti session fixation)', $b->cookie('adem_ayem_sid') !== null && $b->cookie('adem_ayem_sid') !== $before);
$page = $b->get('/anggota/' . $mid(2));
check('sukses: halaman tujuan tampil', $page['status'] === 200 && str_contains($page['body'], 'Anggota Alfa Dua'));
check('sukses: data keuangan tidak boleh di-cache', str_contains($page['headers']['cache-control'] ?? '', 'no-store'));
check('sukses: tombol keluar (POST + CSRF) tersedia', str_contains($page['body'], 'action="/logout"'));
check('sukses: /login bagi yang sudah masuk dialihkan', $b->get('/login')['status'] === 302);
check('sukses: beranda tampil', $b->get('/')['status'] === 200);

// ---------- 6. hak akses: IDOR antar regu ----------
$alfa = new Browser();
$alfa->login('alfa');
check('akses: ketua A melihat dirinya', $alfa->get('/anggota/' . $mid(1))['status'] === 200);
check('akses: ketua A melihat anggota regunya', $alfa->get('/anggota/' . $mid(2))['status'] === 200);
$b404 = $alfa->get('/anggota/' . $mid(4));
check('IDOR: ketua A -> anggota regu B = 404', $b404['status'] === 404);
check('IDOR: respons tidak membocorkan data regu B', !str_contains($b404['body'], 'Beta') && !str_contains($b404['body'], 'AGT-004'));
$none = $alfa->get('/anggota/999999');
$norm = static fn (string $html): string => (string) preg_replace('/csrf-token" content="[a-f0-9]+"/', '', $html);
check('IDOR: id tak ada -> respons identik dengan di luar cakupan (status dan isi sama)', $none['status'] === 404 && $norm($none['body']) === $norm($b404['body']));
check('IDOR: ketua A -> ketua regu B = 404', $alfa->get('/anggota/' . $mid(3))['status'] === 404);
check('IDOR: id non-angka -> 404', $alfa->get('/anggota/abc')['status'] === 404);
check('IDOR: percobaan lintas regu tercatat di audit', $count("SELECT COUNT(*) FROM audit_logs WHERE action='ACCESS_DENIED_SCOPE' AND entity_id = ?", [$mid(4)]) >= 1);
check('IDOR: id tak ada TIDAK dicatat sebagai pelanggaran', $count("SELECT COUNT(*) FROM audit_logs WHERE action='ACCESS_DENIED_SCOPE' AND entity_id = 999999") === 0);

$kepala = new Browser();
$kepala->login('kepala');
$ang = new Browser();
$ang->login('anggota4');
check('akses: anggota melihat dirinya', $ang->get('/anggota/' . $mid(4))['status'] === 200);
check('akses: anggota TIDAK melihat rekan sereguna', $ang->get('/anggota/' . $mid(3))['status'] === 404);
$beta = new Browser();
$beta->login('beta');
check('akses: ketua B melihat anggota regunya', $beta->get('/anggota/' . $mid(4))['status'] === 200);
check('akses: ketua B TIDAK melihat regu A', $beta->get('/anggota/' . $mid(2))['status'] === 404);

// ---------- 7. kunci akun ----------
$k = new Browser();
for ($i = 0; $i < 5; $i++) {
    $k->login('beta', 'salah-berulang-' . $i);
    $beta->get('/');   // sesi uji hanya 4 detik; hash kata sandi di mesin lambat bisa melewatinya
}
$k->login('beta', PASS);
check('kunci: kata sandi benar tetap ditolak saat terkunci', str_contains($k->get('/login')['body'], 'Terlalu banyak percobaan') && $k->get('/')['status'] === 302);
check('kunci: sesi lain beta yang sudah masuk tetap berfungsi (kunci hanya menahan login baru)', $beta->get('/anggota/' . $mid(4))['status'] === 200);
$pdo->exec("UPDATE users SET locked_until = NULL, failed_logins = 0 WHERE username = 'beta'");

// ---------- 8. wajib ganti kata sandi sementara ----------
$n = new Browser();
$n2 = new Browser();
$n2->login('baru');                                    // sesi lama, akan mati setelah ganti sandi
$r = $n->login('baru');
check('wajib-ganti: login diarahkan ke ganti kata sandi', $r['status'] === 302 && $loc($r) === '/profil/password');
check('wajib-ganti: beranda dialihkan kembali', $n->get('/')['status'] === 302 && $loc($n->last) === '/profil/password');
check('wajib-ganti: detail anggota juga dialihkan', $n->get('/anggota/' . $mid(2))['status'] === 302);
$nf = $n->get('/tidak-ada-halaman-ini');
check('wajib-ganti: halaman galat tampil sebagai kartu tamu (tanpa menu aplikasi)', $nf['status'] === 404 && !str_contains($nf['body'], 'class="sidebar"') && str_contains($nf['body'], 'guest__card'));
$p = $n->get('/profil/password');
check('wajib-ganti: halaman ganti kata sandi tampil tanpa menu', $p['status'] === 200 && !str_contains($p['body'], 'class="sidebar"'));
$n->post('/profil/password', ['current_password' => 'bukan-sandi-saya1', 'password' => 'Kopi-Susu-47x', 'password_confirmation' => 'Kopi-Susu-47x']);
check('wajib-ganti: kata sandi saat ini salah ditolak', str_contains($n->get('/profil/password')['body'], 'Kata sandi saat ini salah'));
$n->post('/profil/password', ['current_password' => PASS, 'password' => 'Kopi-Susu-47x', 'password_confirmation' => 'beda-beda-123']);
check('wajib-ganti: konfirmasi tidak sama ditolak', str_contains($n->get('/profil/password')['body'], 'tidak sama'));
$n->post('/profil/password', ['current_password' => PASS, 'password' => '12345678', 'password_confirmation' => '12345678']);
check('wajib-ganti: kata sandi lemah ditolak', str_contains($n->get('/profil/password')['body'], 'huruf dan angka') || str_contains($n->last['body'], 'umum'));
$leak = $n->get('/profil/password')['body'];
check('wajib-ganti: kata sandi tidak dikembalikan ke formulir', !str_contains($leak, '12345678') && !str_contains($leak, PASS));
$done = $n->post('/profil/password', ['current_password' => PASS, 'password' => 'Kopi-Susu-47x', 'password_confirmation' => 'Kopi-Susu-47x']);
check('wajib-ganti: berhasil, kembali ke beranda', $done['status'] === 302 && $loc($done) === '/');
$home = $n->get('/');
check('wajib-ganti: beranda kini terbuka dan menampilkan pesan sukses', $home['status'] === 200 && str_contains($home['body'], 'Kata sandi berhasil diganti'));
check('wajib-ganti: sesi LAIN pengguna yang sama otomatis keluar', $n2->get('/')['status'] === 302);
$again = new Browser();
check('wajib-ganti: kata sandi lama tidak berlaku lagi', $again->login('baru', PASS)['status'] === 302 && str_contains($again->get('/login')['body'], 'salah'));
check('wajib-ganti: kata sandi baru berlaku', (new Browser())->login('baru', 'Kopi-Susu-47x')['status'] === 302);

// ---------- 9. logout ----------
$o = new Browser();
$o->login('alfa');
$sid = $o->cookie('adem_ayem_sid');
check('logout: tanpa CSRF -> 419', $o->post('/logout', [], false)['status'] === 419);
check('logout: masih login setelah 419', $o->get('/')['status'] === 200);
$out = $o->post('/logout');
check('logout: sukses -> /login', $out['status'] === 302 && $loc($out) === '/login');
check('logout: pesan keluar tampil', str_contains($o->get('/login')['body'], 'telah keluar'));
check('logout: halaman terlindungi tertutup lagi', $o->get('/')['status'] === 302);
$thief = new Browser();
file_put_contents($thief->jar, "# Netscape HTTP Cookie File\n#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\tadem_ayem_sid\t{$sid}\n");
check('logout: cookie sesi lama yang dicuri TIDAK bisa dipakai lagi', $thief->get('/anggota/' . $mid(2))['status'] === 302);
check('audit: login, logout, dan kegagalan tercatat', $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGOUT'") >= 1 && $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILED'") >= 3);

// ---------- 10. timeout idle (SESSION_IDLE_TIMEOUT=4 detik di .env.testing) ----------
$t = new Browser();
$t->login('alfa');
check('idle: sebelum habis waktu, sesi aktif', $t->get('/')['status'] === 200);
sleep(5);
$r = $t->get('/');
check('idle: setelah melewati batas, diminta login lagi', $r['status'] === 302 && $loc($r) === '/login');
check('idle: pesan sesi berakhir tampil', str_contains($t->get('/login')['body'], 'Sesi berakhir'));

// ---------- 11. profil ----------
$pf = new Browser();
$pf->login('alfa');
$pr = $pf->get('/profil');
check('profil: menampilkan akun sendiri', $pr['status'] === 200 && str_contains($pr['body'], 'Ketua Alfa') && str_contains($pr['body'], 'Regu Alfa'));
check('profil: tidak membocorkan hash', !str_contains($pr['body'], '$2y$'));

// ======================= PHASE 5: MASTER DATA =======================
$has = static fn (array $r, string $s): bool => str_contains($r['body'], $s);
$audits = fn (string $action): int => $count('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
$secretOf = static function (Browser $b): ?string {
    return preg_match('/id="secret-value">([^<]+)</', $b->last['body'], $m) === 1 ? $m[1] : null;
};

// ---- 12a. matriks hak akses: Ketua Regu ----
$ka = new Browser();
$ka->login('alfa');
$list = $ka->get('/master/anggota');
check('master/akses: ketua regu melihat daftar anggota regunya', $list['status'] === 200 && $has($list, 'Ketua Regu Alfa') && $has($list, 'Anggota Alfa Dua'));
check('master/akses: daftar TIDAK memuat regu lain', !$has($list, 'Beta'));
check('master/akses: tombol "Tambah anggota" tidak ditampilkan untuk ketua regu', !$has($list, 'Tambah anggota'));
check('master/akses: filter regu tidak bisa dipakai melompat ke regu lain', !$has($ka->get('/master/anggota?team=2'), 'Beta'));
check('master/akses: pencarian tidak menembus cakupan', !$has($ka->get('/master/anggota?q=Beta'), 'Ketua Regu Beta'));
$beforeDenied = $audits('ACCESS_DENIED');
foreach (['/master/anggota/baru', '/master/ketua-regu', '/master/ketua-regu/baru', '/master/pengguna', '/master/pengguna/baru', '/sistem/pengaturan', '/master/anggota/2/ubah', '/master/pengguna/1/ubah'] as $p) {
    check("master/akses: ketua regu -> GET {$p} = 403", $ka->get($p)['status'] === 403);
}
$ka->get('/master/anggota');
check('master/akses: ketua regu -> POST tambah anggota = 403', $ka->post('/master/anggota', ['name' => 'Penyusup', 'team_id' => '1', 'active_from' => '2026-04'])['status'] === 403);
$ka->get('/master/anggota'); // segarkan token CSRF (halaman 403 tidak memuat token)
check('master/akses: ketua regu -> POST ubah anggota = 403', $ka->post('/master/anggota/2', ['name' => 'Diubah', 'team_id' => '1', 'active_from' => '2026-04', '_version' => 'x'])['status'] === 403);
$ka->get('/master/anggota'); // segarkan token CSRF (halaman 403 tidak memuat token)
check('master/akses: ketua regu -> POST buat pengguna = 403', $ka->post('/master/pengguna', ['username' => 'penyusup', 'name' => 'X', 'roles' => ['HEAD']])['status'] === 403);
$ka->get('/master/anggota'); // segarkan token CSRF (halaman 403 tidak memuat token)
check('master/akses: ketua regu -> POST reset sandi = 403', $ka->post('/master/pengguna/1/reset-password', ['_version' => 'x'])['status'] === 403);
$ka->get('/master/anggota'); // segarkan token CSRF (halaman 403 tidak memuat token)
check('master/akses: ketua regu -> POST pengaturan = 403', $ka->post('/sistem/pengaturan', ['s' => ['loan_tenor_max' => '12']])['status'] === 403);
check('master/akses: tidak ada data yang berubah akibat percobaan', $count("SELECT COUNT(*) FROM members WHERE name IN ('Penyusup','Diubah')") === 0 && $count("SELECT COUNT(*) FROM users WHERE username = 'penyusup'") === 0);
check('master/akses: penolakan tercatat di audit', $audits('ACCESS_DENIED') >= $beforeDenied + 13);
check('master/akses: halaman 403 memberi pesan yang jelas', str_contains($ka->get('/master/pengguna')['body'], 'tidak memiliki akses'));

$an = new Browser();
$an->login('anggota4');
check('master/akses: anggota biasa -> daftar anggota = 403', $an->get('/master/anggota')['status'] === 403);
$g = new Browser();
check('master/akses: tamu -> daftar anggota dialihkan ke login', $g->get('/master/anggota')['status'] === 302 && $loc($g->last) === '/login');
check('master/akses: tamu -> pengaturan dialihkan ke login', $g->get('/sistem/pengaturan')['status'] === 302);

// ---- 12b. Head: anggota ----
$hd = new Browser();
$hd->login('kepala');
$list = $hd->get('/master/anggota');
check('master/head: melihat semua regu dan tombol tambah', $list['status'] === 200 && $has($list, 'Ketua Regu Beta') && $has($list, 'Ketua Regu Alfa') && $has($list, 'Tambah anggota'));
check('master/head: pencarian menyaring', !$has($hd->get('/master/anggota?q=Beta'), 'Ketua Regu Alfa') && $has($hd->last, 'Anggota Beta Dua'));
check('master/head: pencarian "%" tidak jadi wildcard', $has($hd->get('/master/anggota?q=' . urlencode('%')), 'Tidak ada anggota yang cocok'));
check('master/head: parameter halaman liar tidak merusak', $hd->get('/master/anggota?page=abc&team=xx&status=zz')['status'] === 200);

$form = $hd->get('/master/anggota/baru');
check('master/head: formulir tambah anggota tampil dengan token CSRF', $form['status'] === 200 && preg_match('/name="_token" value="[a-f0-9]{64}"/', $form['body']) === 1 && $has($form, 'name="team_id"'));
check('master/head: POST tanpa token CSRF = 419', $hd->post('/master/anggota', ['name' => 'Tanpa Token', 'team_id' => '1', 'active_from' => '2026-04'], false)['status'] === 419);
$hd->get('/master/anggota/baru');
$bad = $hd->post('/master/anggota', ['name' => '', 'address_block' => 'Q - 77', 'team_id' => '', 'active_from' => '2026-13']);
check('master/head: isian salah dialihkan kembali ke formulir (PRG)', $bad['status'] === 302 && $loc($bad) === '/master/anggota/baru');
$page = $hd->get('/master/anggota/baru');
check('master/head: galat per field tampil', $has($page, 'Nama wajib diisi') && $has($page, 'Regu wajib dipilih') && $has($page, 'Aktif sejak tidak valid'));
check('master/head: isian lama (yang valid) dipertahankan', $has($page, 'value="Q - 77"'));
$hd->get('/master/anggota/baru');
$xss = $hd->post('/master/anggota', ['name' => '<img src=x onerror=alert(1)>', 'address_block' => 'X - 1', 'team_id' => '1', 'active_from' => '2026-04']);
check('master/head: tambah anggota berhasil -> halaman detail', $xss['status'] === 302 && preg_match('#^/anggota/(\d+)$#', $loc($xss), $mm) === 1);
$newId = (int) ($mm[1] ?? 0);
$detail = $hd->get('/anggota/' . $newId);
check('XSS: nama berbahaya di-escape di detail', $detail['status'] === 200 && !$has($detail, '<img src=x') && $has($detail, '&lt;img src=x'));
check('XSS: nama berbahaya di-escape di daftar', !$has($hd->get('/master/anggota'), '<img src=x') && $has($hd->last, '&lt;img'));
check('master/head: anggota baru mendapat nomor lanjutan dan audit', $count("SELECT COUNT(*) FROM members WHERE member_no = 'AGT-005' AND id = ?", [$newId]) === 1 && $audits('MEMBER_CREATED') === 1);
$hd->get('/master/anggota/baru');
$hd->post('/master/anggota', ['name' => '<img src=x onerror=alert(1)>', 'address_block' => 'X - 1', 'team_id' => '1', 'active_from' => '2026-04']);
check('master/head: duplikat nama + blok ditolak dengan pesan', $has($hd->get('/master/anggota/baru'), 'sudah ada (AGT-005)'));

$edit = $hd->get('/master/anggota/' . $newId . '/ubah');
preg_match('/name="_version" value="([^"]+)"/', $edit['body'], $vm);
check('master/head: formulir ubah memuat versi data', $edit['status'] === 200 && !empty($vm[1]) && $has($edit, 'value="X - 1"'));
$up = $hd->post('/master/anggota/' . $newId, ['name' => 'Bu Edit', 'address_block' => 'X - 2', 'team_id' => '1', 'active_from' => '2026-04', 'status' => 'AKTIF', 'is_manager' => '1', 'reserve_exempt' => '1', 'notes' => 'catatan rahasia', '_version' => $vm[1]]);
check('master/head: ubah anggota berhasil', $up['status'] === 302 && $loc($up) === '/anggota/' . $newId && $has($hd->get('/anggota/' . $newId), 'Bu Edit'));
check('master/head: ubah tercatat di audit sebelum/sesudah', $audits('MEMBER_UPDATED') === 1);
$hd->get('/master/anggota/' . $newId . '/ubah');
$stale = $hd->post('/master/anggota/' . $newId, ['name' => 'Bu Edit Lagi', 'address_block' => 'X - 2', 'team_id' => '1', 'active_from' => '2026-04', 'status' => 'AKTIF', '_version' => '2000-01-01 00:00:00']);
check('master/head: edit bersamaan (versi basi) ditolak, data lama utuh', $stale['status'] === 302 && $has($hd->get('/master/anggota/' . $newId . '/ubah'), 'diubah oleh pengguna lain') && $count("SELECT COUNT(*) FROM members WHERE name = 'Bu Edit Lagi'") === 0);
check('master/head: ubah anggota yang tidak ada = 404', $hd->get('/master/anggota/99999/ubah')['status'] === 404);
$ka2 = new Browser();
$ka2->login('alfa');
$kaDetail = $ka2->get('/anggota/2');
check('privasi: ketua regu tidak melihat tombol Ubah di detail', !$has($kaDetail, '/ubah'));
$edit2 = $hd->get('/master/anggota/2/ubah');
preg_match('/name="_version" value="([^"]+)"/', $edit2['body'], $vm2);
$hd->post('/master/anggota/2', ['name' => 'Anggota Alfa Dua', 'address_block' => '', 'team_id' => '1', 'active_from' => '2026-03', 'status' => 'AKTIF', 'reserve_exempt' => '1', 'notes' => 'rahasia pengelola', '_version' => $vm2[1]]);
$kaDetail = $ka2->get('/anggota/2');
check('privasi: ketua regu tidak melihat catatan dan status cadangan anggota', $kaDetail['status'] === 200 && !$has($kaDetail, 'rahasia pengelola') && !$has($kaDetail, 'Dikecualikan'));
check('privasi: Head melihat catatan tersebut', $has($hd->get('/anggota/2'), 'rahasia pengelola') && $has($hd->last, 'Dikecualikan'));

// ---- 12c. Head: regu ----
$tl = $hd->get('/master/ketua-regu');
check('master/regu: daftar regu dengan ketua dan jumlah anggota', $tl['status'] === 200 && $has($tl, 'Regu Alfa') && $has($tl, 'Regu Beta') && $has($tl, 'Ketua Regu Alfa'));
$tf = $hd->get('/master/ketua-regu/baru');
check('master/regu: calon ketua hanya anggota yang belum memimpin', $has($tf, 'Anggota Alfa Dua') && !$has($tf, '>Ketua Regu Alfa (') && !$has($tf, '>Ketua Regu Beta ('));
$r1 = $hd->post('/master/ketua-regu', ['name' => 'Regu Gamma', 'leader_member_id' => (string) $newId]);
check('master/regu: buat regu berhasil', $r1['status'] === 302 && $loc($r1) === '/master/ketua-regu' && $has($hd->get('/master/ketua-regu'), 'Regu Gamma'));
$hd->get('/master/ketua-regu/baru');
$r2 = $hd->post('/master/ketua-regu', ['name' => 'Regu Gamma', 'leader_member_id' => '2']);
check('master/regu: nama ganda ditolak', $r2['status'] === 302 && $has($hd->get('/master/ketua-regu/baru'), 'Sudah ada regu aktif'));

// ---- 12d. Head: pengguna (kata sandi sekali tampil) ----
$ul = $hd->get('/master/pengguna');
check('master/pengguna: daftar menampilkan peran dan status', $ul['status'] === 200 && $has($ul, 'Kepala Koperasi') && $has($ul, 'Ketua Alfa'));
check('master/pengguna: baris akun sendiri tidak punya tombol reset/nonaktif', (function () use ($ul): bool {
    // pecah per baris <tr>; baris yang memuat nama pengguna "kepala" dan label "Anda" tidak boleh memuat aksi berisiko
    foreach (explode('<tr>', $ul['body']) as $row) {
        if (str_contains($row, '>kepala<') && str_contains($row, '>Anda<')) {
            return !str_contains($row, 'reset-password') && !str_contains($row, '/status');
        }
    }
    return false;
})());
check('master/pengguna: baris akun orang lain punya tombol reset dan nonaktif', (function () use ($ul): bool {
    foreach (explode('<tr>', $ul['body']) as $row) {
        if (str_contains($row, '>alfa<')) {
            return str_contains($row, 'reset-password') && str_contains($row, '/status');
        }
    }
    return false;
})());
check('master/pengguna: daftar tidak membocorkan hash', !$has($ul, '$2y$'));
$hd->get('/master/pengguna/baru');
$bothRoles = $hd->post('/master/pengguna', ['username' => 'dobel', 'name' => 'Dobel', 'roles' => ['HEAD', 'PEMERIKSA'], 'member_no' => '']);
check('master/pengguna: Head+Pemeriksa ditolak dengan alasan', $bothRoles['status'] === 302 && $has($hd->get('/master/pengguna/baru'), 'tidak boleh dipegang orang yang sama') && $count("SELECT COUNT(*) FROM users WHERE username = 'dobel'") === 0);
$hd->get('/master/pengguna/baru');
$hd->post('/master/pengguna', ['username' => 'Bad Name!', 'name' => '', 'roles' => []]);
$pg = $hd->get('/master/pengguna/baru');
check('master/pengguna: galat nama pengguna, nama, dan peran tampil', $has($pg, 'Nama pengguna 3-30') && $has($pg, 'Nama wajib diisi') && $has($pg, 'minimal satu peran'));
$hd->get('/master/pengguna/baru');
$cr = $hd->post('/master/pengguna', ['username' => 'bu.edit', 'name' => 'Bu Edit', 'roles' => ['ANGGOTA'], 'member_no' => 'AGT-005']);
check('master/pengguna: buat akun berhasil -> kembali ke daftar', $cr['status'] === 302 && $loc($cr) === '/master/pengguna');
$ul = $hd->get('/master/pengguna');
$temp = $secretOf($hd);
check('master/pengguna: kata sandi sementara ditampilkan SEKALI', $temp !== null && strlen($temp) === 14 && $has($ul, 'satu-satunya kali'));
$ul2 = $hd->get('/master/pengguna');
check('master/pengguna: muat ulang -> kata sandi tidak tampil lagi', $secretOf($hd) === null && !$has($ul2, (string) $temp));
check('master/pengguna: kata sandi sementara tidak ada di audit/database mana pun', $count("SELECT COUNT(*) FROM audit_logs WHERE COALESCE(after_data,'') LIKE ?", ['%' . $temp . '%']) === 0);
$nb = new Browser();
$lr = $nb->login('bu.edit', (string) $temp);
check('master/pengguna: akun baru bisa masuk lalu WAJIB ganti kata sandi', $lr['status'] === 302 && $loc($lr) === '/profil/password');
check('master/pengguna: akun baru tidak bisa membuka halaman admin', $nb->get('/master/pengguna')['status'] === 302);

// reset kata sandi
$ang2 = new Browser();
$ang2->login('anggota4');
check('master/pengguna: anggota4 aktif sebelum reset', $ang2->get('/profil')['status'] === 200);
$ul = $hd->get('/master/pengguna');
$resetTarget = (int) $count("SELECT id FROM users WHERE username = 'anggota4'");
$verTarget = (string) Database::pdo()->query("SELECT updated_at FROM users WHERE id = {$resetTarget}")->fetchColumn();
$rs = $hd->post('/master/pengguna/' . $resetTarget . '/reset-password', ['_version' => $verTarget]);
$hd->get('/master/pengguna');
$temp2 = $secretOf($hd);
check('master/pengguna: reset menghasilkan kata sandi sementara baru (sekali tampil)', $rs['status'] === 302 && $temp2 !== null && $temp2 !== $temp);
check('master/pengguna: sesi anggota4 yang sedang aktif langsung dikeluarkan', $ang2->get('/profil')['status'] === 302);
$chk = new Browser();
$chk->login('anggota4', PASS);
check('master/pengguna: kata sandi lama ditolak (tetap di login)', $chk->get('/profil')['status'] === 302);
check('master/pengguna: kata sandi baru memaksa ganti sandi', (new Browser())->login('anggota4', (string) $temp2)['status'] === 302);
check('master/pengguna: reset akun sendiri ditolak server', (function () use ($hd, $count): bool {
    $me = $count("SELECT id FROM users WHERE username = 'kepala'");
    $v = (string) Database::pdo()->query("SELECT updated_at FROM users WHERE id = {$me}")->fetchColumn();
    $hd->get('/master/pengguna');
    $hd->post('/master/pengguna/' . $me . '/reset-password', ['_version' => $v]);
    return str_contains($hd->get('/master/pengguna')['body'], 'Ganti Kata Sandi di Profil');
})());

// nonaktifkan / aktifkan
$ang3 = new Browser();
$ang3->login('beta');
check('master/pengguna: beta aktif', $ang3->get('/profil')['status'] === 200);
$betaId = $count("SELECT id FROM users WHERE username = 'beta'");
$vb = (string) Database::pdo()->query("SELECT updated_at FROM users WHERE id = {$betaId}")->fetchColumn();
$hd->get('/master/pengguna');
$hd->post('/master/pengguna/' . $betaId . '/status', ['active' => '0', '_version' => $vb]);
check('master/pengguna: nonaktifkan -> sesi aktif langsung mati', $ang3->get('/profil')['status'] === 302);
check('master/pengguna: akun nonaktif tidak bisa masuk (pesan generik)', $has((function () { $b = new Browser(); $b->login('beta'); return $b->get('/login'); })(), 'Nama pengguna atau kata sandi salah.'));
$vb = (string) Database::pdo()->query("SELECT updated_at FROM users WHERE id = {$betaId}")->fetchColumn();
$hd->get('/master/pengguna');
$hd->post('/master/pengguna/' . $betaId . '/status', ['active' => '1', '_version' => $vb]);
check('master/pengguna: aktifkan kembali -> bisa masuk', (new Browser())->login('beta')['status'] === 302 && $count('SELECT is_active FROM users WHERE id = ?', [$betaId]) === 1);
$meId = $count("SELECT id FROM users WHERE username = 'kepala'");
$vm3 = (string) Database::pdo()->query("SELECT updated_at FROM users WHERE id = {$meId}")->fetchColumn();
$hd->get('/master/pengguna');
$hd->post('/master/pengguna/' . $meId . '/status', ['active' => '0', '_version' => $vm3]);
check('master/pengguna: menonaktifkan akun sendiri ditolak', $has($hd->get('/master/pengguna'), 'menonaktifkan akun Anda sendiri') && $hd->get('/')['status'] === 200);
check('master/pengguna: aksi pengubah via GET tidak ada (405/404)', in_array($hd->get('/master/pengguna/' . $betaId . '/status')['status'], [404, 405], true) && in_array($hd->get('/master/pengguna/' . $betaId . '/reset-password')['status'], [404, 405], true));

// ---- 12e. Head: pengaturan ----
$st = $hd->get('/sistem/pengaturan');
check('pengaturan: halaman tampil dengan nominal berformat', $st['status'] === 200 && $has($st, 'value="15.000.000"') && $has($st, 'Bunga per bulan'));
$hd->post('/sistem/pengaturan', ['s' => ['interest_rate_pct_month' => '2.00', 'loan_tenor_min' => '1', 'loan_tenor_max' => '5', 'loan_max_amount' => '15.000.000', 'loan_max_active_per_member' => '0', 'saving_pokok_amount' => '50.000',
    'profit_share_saver_pct' => '50', 'profit_share_borrower_pct' => '40', 'profit_share_shu_pct' => '20', 'reserve_pct' => '5', 'shu_member_pct' => '60', 'shu_manager_pct' => '40']]);
$bad = $hd->get('/sistem/pengaturan');
check('pengaturan: jumlah bagi hasil salah ditolak dengan pesan jelas', $has($bad, 'harus berjumlah tepat 100%'));
check('pengaturan: isian salah dipertahankan di formulir', $has($bad, 'value="50"'));
check('pengaturan: tidak ada yang tersimpan saat ada galat', $count("SELECT setting_value FROM settings WHERE setting_key = 'profit_share_saver_pct'") === 40);
$hd->post('/sistem/pengaturan', ['s' => ['interest_rate_pct_month' => '2,5', 'loan_tenor_min' => '1', 'loan_tenor_max' => '4', 'loan_max_amount' => '20.000.000', 'loan_max_active_per_member' => '0', 'saving_pokok_amount' => '50.000',
    'profit_share_saver_pct' => '40', 'profit_share_borrower_pct' => '40', 'profit_share_shu_pct' => '20', 'reserve_pct' => '5', 'shu_member_pct' => '60', 'shu_manager_pct' => '40', 'withdrawals_enabled' => '1']]);
$ok = $hd->get('/sistem/pengaturan');
check('pengaturan: perubahan valid tersimpan dan diberitahukan', $has($ok, '4 pengaturan disimpan') && $has($ok, 'value="2.50"') && $has($ok, 'value="20.000.000"'));
check('pengaturan: tercatat di audit per kunci', $audits('SETTING_UPDATED') === 4);
$hd->post('/sistem/pengaturan', ['s' => ['interest_rate_pct_month' => '2,5', 'loan_tenor_min' => '1', 'loan_tenor_max' => '4', 'loan_max_amount' => '20.000.000', 'loan_max_active_per_member' => '0', 'saving_pokok_amount' => '50.000',
    'profit_share_saver_pct' => '40', 'profit_share_borrower_pct' => '40', 'profit_share_shu_pct' => '20', 'reserve_pct' => '5', 'shu_member_pct' => '60', 'shu_manager_pct' => '40', 'withdrawals_enabled' => '1']]);
check('pengaturan: kirim ulang tanpa perubahan -> "Tidak ada perubahan", tanpa audit baru', $has($hd->get('/sistem/pengaturan'), 'Tidak ada perubahan') && $audits('SETTING_UPDATED') === 4);

// ---- 12g. transaksi simpanan (Phase 6) ----
$str = fn (string $sql, array $p = []): string => (function () use ($sql, $p): string {
    $s = Database::pdo()->prepare($sql);
    $s->execute($p);
    return (string) $s->fetchColumn();
})();
$tokenOf = static fn (array $r): string => preg_match('/name="_form_id" value="([a-f0-9]{32})"/', $r['body'], $m) === 1 ? $m[1] : '';
$versionOf = static fn (array $r): string => preg_match('/name="_version" value="([^"]+)"/', $r['body'], $m) === 1 ? html_entity_decode($m[1]) : '';
$today = date('Y-m-d');
// POST dengan token CSRF yang selalu segar (respons sebelumnya bisa berupa redirect tanpa token)
$post = function (Browser $br, string $path, array $form = []): array {
    $br->get('/transaksi/riwayat');
    return $br->post($path, $form + ['_token' => $br->csrf()]);
};
// akun anggota4 sudah di-reset di tes sebelumnya; pulihkan agar bisa dipakai sebagai peran Anggota
Database::pdo()->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, failed_logins = 0, locked_until = NULL, is_active = 1 WHERE username = 'anggota4'")
    ->execute([\App\Services\PasswordPolicy::hash(PASS)]);
$sa = (new Browser())->keepAlive('alfa');
$sb = (new Browser())->keepAlive('beta');
$sm = (new Browser())->keepAlive('anggota4');
$sh = (new Browser())->keepAlive('kepala');
$trxCount = fn (): int => $count("SELECT COUNT(*) FROM transactions WHERE type = 'SIMPANAN'");
$put = fn (Browser $br, array $over = []): array => $br->post('/transaksi/simpanan', $over + [
    '_form_id' => $tokenOf($br->get('/transaksi/simpanan/baru')), 'member_id' => (string) $mid(2), 'period_month_id' => '1', 'kind' => 'WAJIB',
    'amount' => '50.000', 'trx_date' => $today, 'description' => '', 'action' => 'draft',
]);

// akses menurut peran
check('simpanan/akses: tamu dialihkan ke login', (new Browser())->get('/transaksi/simpanan')['status'] === 302);
check('simpanan/akses: Head melihat daftar, tanpa tombol Catat', $sh->get('/transaksi/simpanan')['status'] === 200 && !$has($sh->last, 'Catat simpanan'));
check('simpanan/akses: Head TIDAK boleh membuka formulir catat (403)', $sh->get('/transaksi/simpanan/baru')['status'] === 403);
check('simpanan/akses: Anggota melihat daftar tapi formulir catat 403', $sm->get('/transaksi/simpanan')['status'] === 200 && $sm->get('/transaksi/simpanan/baru')['status'] === 403 && !$has($sm->get('/transaksi/simpanan'), 'Catat simpanan'));
check('simpanan/akses: Ketua Regu melihat tombol Catat', $has($sa->get('/transaksi/simpanan'), 'Catat simpanan'));
$headPost = $post($sh, '/transaksi/simpanan', ['_form_id' => 'x', 'member_id' => (string) $mid(2), 'period_month_id' => '1', 'kind' => 'WAJIB', 'amount' => '50.000', 'trx_date' => $today]);
check('simpanan/akses: Head POST catat = 403 dan tidak ada transaksi', $headPost['status'] === 403 && $trxCount() === 0);
check('simpanan/csrf: POST catat tanpa token = 419', $sa->post('/transaksi/simpanan', ['member_id' => (string) $mid(2)], false)['status'] === 419 && $trxCount() === 0);

check('simpanan/csrf: POST catat tanpa token = 419', $sa->post('/transaksi/simpanan', ['member_id' => (string) $mid(2)], false)['status'] === 419 && $trxCount() === 0);

// formulir
$f = $sa->get('/transaksi/simpanan/baru');
check('simpanan/formulir: tampil dengan token sekali pakai dan anggota regunya', $f['status'] === 200 && $tokenOf($f) !== '' && $has($f, 'Anggota Alfa Dua') && $has($f, 'Ketua Regu Alfa'));
check('simpanan/formulir: anggota regu lain TIDAK ada di pilihan', !$has($f, 'Anggota Beta Dua') && !$has($f, 'Ketua Regu Beta'));
check('simpanan/formulir: bulan tersedia berlabel Indonesia', $has($f, 'Maret 2026'));

// simpan draft
$r = $put($sa, ['description' => 'Setoran <script>alert(1)</script>']);
$id = $count("SELECT MAX(id) FROM transactions WHERE type = 'SIMPANAN'");
check('simpanan/draft: berhasil, dialihkan ke detail', $r['status'] === 302 && $loc($r) === '/transaksi/' . $id && $trxCount() === 1);
check('simpanan/draft: berstatus DRAFT dan dicatat atas nama pembuat + regunya', $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'DRAFT' && $count('SELECT created_by FROM transactions WHERE id = ?', [$id]) === $count("SELECT id FROM users WHERE username = 'alfa'") && $count('SELECT team_id FROM transactions WHERE id = ?', [$id]) === 1);
$d = $sa->get('/transaksi/' . $id);
check('simpanan/detail: tampil dengan nomor dokumen, status, dan penjelasan saldo', $d['status'] === 200 && $has($d, 'SMP-' . date('Y') . '-000001') && $has($d, 'Draft') && $has($d, 'Belum memengaruhi saldo'));
check('simpanan/detail: keterangan ber-HTML tampil sebagai teks (XSS)', $has($d, '&lt;script&gt;alert(1)&lt;/script&gt;') && !$has($d, '<script>alert(1)'));
check('simpanan/detail: pembuat melihat tombol Ubah, Ajukan, Batalkan', $has($d, '/ubah') && $has($d, 'Ajukan ke validasi') && $has($d, 'Batalkan transaksi'));
check('simpanan/draft: tercatat di audit', $audits('SAVING_CREATED') === 1);
check('simpanan/draft: saldo anggota belum berubah', $count('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$mid(2)]) === 0);

// kirim ganda
$f = $sa->get('/transaksi/simpanan/baru');
$tok = $tokenOf($f);
$payload = ['_form_id' => $tok, 'member_id' => (string) $mid(2), 'period_month_id' => '1', 'kind' => 'SUKARELA', 'amount' => '10.000', 'trx_date' => $today, 'action' => 'draft'];
$one = $post($sa, '/transaksi/simpanan', $payload);
$two = $post($sa, '/transaksi/simpanan', $payload);
check('simpanan/ganda: kiriman pertama membuat, kedua (token sama) tidak membuat apa-apa', $one['status'] === 302 && $trxCount() === 2 && $two['status'] === 302 && $loc($two) === '/transaksi/simpanan');
check('simpanan/ganda: pengguna diberi tahu', $has($sa->get('/transaksi/simpanan'), 'sudah pernah dikirim'));

// galat validasi mempertahankan isian
$bad = $put($sa, ['amount' => 'lima puluh ribu', 'description' => 'isian saya']);
$page = $sa->get($loc($bad));
check('simpanan/galat: kembali ke formulir dengan pesan per field', $bad['status'] === 302 && $loc($bad) === '/transaksi/simpanan/baru' && $has($page, 'angka bulat rupiah') && $has($page, 'value="isian saya"'));
check('simpanan/galat: tidak membuat transaksi', $trxCount() === 2);

// penulisan lintas regu (IDOR tulis)
$idor = $put($sa, ['member_id' => (string) $mid(4)]);
check('simpanan/IDOR: ketua A mencatat untuk anggota regu B ditolak, tidak ada transaksi', $idor['status'] === 302 && $has($sa->get($loc($idor)), 'bukan anggota regu Anda') && $trxCount() === 2);
check('simpanan/IDOR: pesan tidak membocorkan nama anggota regu B', !$has($sa->last, 'Beta Dua'));

// duplikat butuh konfirmasi
$dup = $put($sa, ['kind' => 'WAJIB', 'amount' => '50.000']);
$dupPage = $sa->get($loc($dup));
check('simpanan/duplikat: setoran identik diminta konfirmasi (checkbox muncul)', $has($dupPage, 'confirm_duplicate') && $has($dupPage, 'sudah ada') && $trxCount() === 2);
$conf = $put($sa, ['kind' => 'WAJIB', 'amount' => '50.000', 'confirm_duplicate' => '1']);
check('simpanan/duplikat: lolos bila dikonfirmasi', $conf['status'] === 302 && $trxCount() === 3);

// ajukan
$sa->get('/transaksi/' . $id);
$ver = $str('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
check('simpanan/ajukan: versi basi ditolak, status tetap', $post($sa, '/transaksi/' . $id . '/ajukan', ['_version' => '2000-01-01 00:00:00'])['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'DRAFT' && $has($sa->get('/transaksi/' . $id), 'baru saja berubah'));
check('simpanan/ajukan: Head tidak boleh (403)', $post($sh, '/transaksi/' . $id . '/ajukan', ['_version' => $ver])['status'] === 403 && $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'DRAFT');
check('simpanan/ajukan: ketua regu lain tidak bisa melihat apalagi mengajukan (404)', $post($sb, '/transaksi/' . $id . '/ajukan', ['_version' => $ver])['status'] === 404 && $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'DRAFT');
$ok = $post($sa, '/transaksi/' . $id . '/ajukan', ['_version' => $ver]);
check('simpanan/ajukan: berhasil menjadi MENUNGGU_VALIDASI + riwayat + audit', $ok['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'MENUNGGU_VALIDASI'
    && $count('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id]) === 1 && $audits('SAVING_SUBMITTED') === 1);
$d = $sa->get('/transaksi/' . $id);
check('simpanan/detail: setelah diajukan tombol Ubah/Ajukan hilang, Batalkan tetap ada, riwayat tampil', !$has($d, 'Ajukan ke validasi') && !$has($d, '/ubah') && $has($d, 'Batalkan transaksi') && $has($d, 'Menunggu Validasi') && $has($d, 'Riwayat status'));
$post($sa, '/transaksi/' . $id . '/ajukan', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$id])]);
check('simpanan/ajukan: diajukan ulang ditolak dengan pesan, tidak ada riwayat ganda', $has($sa->get('/transaksi/' . $id), 'tidak bisa diajukan lagi') && $count('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id]) === 1);
$edit = $sa->get('/transaksi/simpanan/' . $id . '/ubah');
check('simpanan/ubah: yang sudah diajukan dialihkan ke detail dengan peringatan', $edit['status'] === 302 && $loc($edit) === '/transaksi/' . $id);

// ubah draft
$draftId = $count("SELECT id FROM transactions WHERE type = 'SIMPANAN' AND status = 'DRAFT' ORDER BY id LIMIT 1");
$e = $sa->get('/transaksi/simpanan/' . $draftId . '/ubah');
check('simpanan/ubah: formulir terisi nilai lama dan membawa versi', $e['status'] === 200 && $versionOf($e) !== '' && $has($e, 'value="10.000"'));
check('simpanan/ubah: ketua regu B -> 404, Head -> 403', $sb->get('/transaksi/simpanan/' . $draftId . '/ubah')['status'] === 404 && $sh->get('/transaksi/simpanan/' . $draftId . '/ubah')['status'] === 403);
$up = $post($sa, '/transaksi/simpanan/' . $draftId, ['member_id' => (string) $mid(2), 'period_month_id' => '1', 'kind' => 'SUKARELA', 'amount' => '15.000', 'trx_date' => $today, 'description' => 'naik', 'action' => 'draft', '_version' => $versionOf($e)]);
check('simpanan/ubah: tersimpan dan tercatat di audit', $up['status'] === 302 && $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 15000 && $audits('SAVING_UPDATED') === 1);
$stale = $post($sa, '/transaksi/simpanan/' . $draftId, ['member_id' => (string) $mid(2), 'period_month_id' => '1', 'kind' => 'SUKARELA', 'amount' => '99.000', 'trx_date' => $today, 'action' => 'draft', '_version' => '2000-01-01 00:00:00']);
check('simpanan/ubah: versi basi ditolak, nominal tidak tertimpa', $stale['status'] === 302 && $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 15000 && $has($sa->get($loc($stale)), 'baru saja berubah'));
check('simpanan/ubah: ketua regu B tidak bisa POST ubah (404), Head 403', $post($sb, '/transaksi/simpanan/' . $draftId, ['amount' => '1', '_version' => 'x'])['status'] === 404 && $post($sh, '/transaksi/simpanan/' . $draftId, ['amount' => '1', '_version' => 'x'])['status'] === 403 && $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 15000);

// batalkan
$ver = $str('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
$post($sa, '/transaksi/' . $id . '/batal', ['_version' => $ver, 'note' => '  ']);
check('simpanan/batal: tanpa alasan ditolak', $has($sa->get('/transaksi/' . $id), 'Alasan pembatalan wajib') && $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'MENUNGGU_VALIDASI');
check('simpanan/batal: ketua regu B (404) dan Head (403) tidak bisa membatalkan', $post($sb, '/transaksi/' . $id . '/batal', ['_version' => $ver, 'note' => 'iseng'])['status'] === 404 && $post($sh, '/transaksi/' . $id . '/batal', ['_version' => $ver, 'note' => 'iseng'])['status'] === 403);
$post($sa, '/transaksi/' . $id . '/batal', ['_version' => $ver, 'note' => 'Salah anggota']);
check('simpanan/batal: berhasil, baris dan nomor tetap, alasan di riwayat', $str('SELECT status FROM transactions WHERE id = ?', [$id]) === 'DIBATALKAN' && $has($sa->get('/transaksi/' . $id), 'Salah anggota') && $has($sa->last, 'SMP-' . date('Y') . '-000001'));
check('simpanan/batal: aksi pengubah via GET tidak ada', in_array($sa->get('/transaksi/' . $id . '/batal')['status'], [404, 405], true) && in_array($sa->get('/transaksi/' . $id . '/ajukan')['status'], [404, 405], true));

// cakupan baca
$bt = $post($sb, '/transaksi/simpanan', ['_form_id' => $tokenOf($sb->get('/transaksi/simpanan/baru')), 'member_id' => (string) $mid(4), 'period_month_id' => '1', 'kind' => 'WAJIB', 'amount' => '30.000', 'trx_date' => $today, 'action' => 'submit']);
$betaId = $count("SELECT MAX(id) FROM transactions WHERE type = 'SIMPANAN'");
check('simpanan/ajukan langsung: tombol "Simpan dan ajukan" membuat MENUNGGU_VALIDASI', $bt['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$betaId]) === 'MENUNGGU_VALIDASI');
$deniedBefore = $audits('ACCESS_DENIED_SCOPE');
check('simpanan/IDOR baca: ketua A membuka transaksi regu B = 404', $sa->get('/transaksi/' . $betaId)['status'] === 404 && $sa->get('/transaksi/99999')['status'] === 404);
check('simpanan/IDOR baca: percobaan lintas regu tercatat, yang tidak ada tidak', $audits('ACCESS_DENIED_SCOPE') === $deniedBefore + 1);
check('simpanan/cakupan: Head membuka transaksi mana pun', $sh->get('/transaksi/' . $betaId)['status'] === 200 && $sh->get('/transaksi/' . $id)['status'] === 200);
check('simpanan/cakupan: Anggota membuka transaksi miliknya, bukan milik orang lain', $sm->get('/transaksi/' . $betaId)['status'] === 200 && $sm->get('/transaksi/' . $draftId)['status'] === 404);
check('simpanan/cakupan: Anggota tidak punya tombol aksi di detail miliknya', !$has($sm->get('/transaksi/' . $betaId), 'Batalkan transaksi'));
$listA = $sa->get('/transaksi/simpanan');
check('simpanan/daftar: ketua A hanya melihat transaksi regunya', $has($listA, 'SMP-' . date('Y') . '-000001') && !$has($listA, 'Anggota Beta Dua'));
$listM = $sm->get('/transaksi/simpanan');
check('simpanan/daftar: Anggota hanya melihat miliknya', $has($listM, 'Anggota Beta Dua') && !$has($listM, 'Anggota Alfa Dua'));
check('simpanan/daftar: Head melihat semua', $has($sh->get('/transaksi/simpanan'), 'Anggota Alfa Dua') && $has($sh->last, 'Anggota Beta Dua'));
check('simpanan/filter: status, bulan, jenis, cari bekerja tanpa galat', $sa->get('/transaksi/simpanan?status=DRAFT')['status'] === 200 && $sa->get('/transaksi/simpanan?bulan=1&jenis=WAJIB&q=alfa')['status'] === 200 && $has($sa->get('/transaksi/simpanan?status=DIBATALKAN'), 'SMP-' . date('Y') . '-000001'));
check('simpanan/filter: nilai ngawur tidak membuat 500', $sa->get('/transaksi/simpanan?bulan=abc&status=%27&page=-9&anggota=x')['status'] === 200);
check('simpanan/filter: "%" dibaca harfiah', $has($sa->get('/transaksi/simpanan?q=%25'), 'Belum ada simpanan yang cocok'));
check('simpanan/filter: ketua A melompat ke anggota regu B lewat ?anggota= tetap kosong', $has($sa->get('/transaksi/simpanan?anggota=' . $mid(4)), 'Belum ada simpanan yang cocok'));

// riwayat
$h = $sh->get('/transaksi/riwayat');
check('riwayat: Head melihat semua jenis dengan kolom Jenis', $h['status'] === 200 && $has($h, 'Riwayat transaksi') && $has($h, 'Simpanan') && $has($h, 'Anggota Beta Dua'));
check('riwayat: Ketua A hanya regunya, Anggota hanya miliknya', !$has($sa->get('/transaksi/riwayat'), 'Anggota Beta Dua') && !$has($sm->get('/transaksi/riwayat'), 'Anggota Alfa Dua'));
check('riwayat: filter jenis transaksi dan anggota', $sh->get('/transaksi/riwayat?type=SIMPANAN')['status'] === 200 && $has($sh->get('/transaksi/riwayat?type=PENCAIRAN_PINJAMAN'), 'Belum ada transaksi yang cocok') && $has($sh->get('/transaksi/riwayat?anggota=' . $mid(4)), 'Anggota Beta Dua'));
check('riwayat: tamu dialihkan ke login', (new Browser())->get('/transaksi/riwayat')['status'] === 302);
check('anggota/detail: tombol Riwayat transaksi membawa ke daftar anggota itu', $has($sh->get('/anggota/' . $mid(4)), '/transaksi/riwayat?anggota=' . $mid(4)));

// kebersihan halaman baru
$pages = '';
foreach ([['/transaksi/simpanan', $sa], ['/transaksi/riwayat', $sh], ['/transaksi/simpanan/baru', $sa], ['/transaksi/' . $draftId, $sa], ['/transaksi/simpanan/' . $draftId . '/ubah', $sa]] as [$path, $br]) {
    $pages .= $br->get($path)['body'];
}
check('simpanan/umum: tanpa <script>, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $pages));
check('simpanan/umum: jejak audit tidak memuat kata sandi atau hash', $count("SELECT COUNT(*) FROM audit_logs WHERE after_data LIKE '%\$2y\$%' OR before_data LIKE '%\$2y\$%'") === 0);
check('simpanan/umum: integritas basis data tetap bersih', $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);

// ---- 12h. transaksi pinjaman (Phase 7) ----
// tes pengaturan (12e) mengubah tarif, tenor, dan plafon; kembalikan ke nilai awal koperasi agar hitungan pinjaman bisa dipastikan
foreach (['interest_rate_pct_month' => '2.00', 'loan_tenor_min' => '1', 'loan_tenor_max' => '5', 'loan_max_amount' => '15000000'] as $k => $v) {
    Database::pdo()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$v, $k]);
}
$loanCount = fn (): int => $count("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'");
$putLoan = fn (Browser $br, array $over = []): array => $br->post('/transaksi/pinjaman', $over + [
    '_form_id' => $tokenOf($br->get('/transaksi/pinjaman/baru')), 'member_id' => (string) $mid(2), 'period_month_id' => '1', 'principal' => '5.000.000',
    'tenor' => '3', 'trx_date' => $today, 'description' => '', 'action' => 'draft',
]);
// kas koperasi untuk uji pengajuan: satu simpanan DISETUJUI Rp 10.000.000 (disisipkan langsung; persetujuan lewat aplikasi baru ada di Phase 9)
Database::pdo()->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, source) VALUES ('TRX-UJI-KAS','SMP-UJI-KAS','SIMPANAN'," . $mid(2) . ",1,1,'{$today}',10000000,'APLIKASI')");
$kasId = (int) Database::pdo()->lastInsertId();
Database::pdo()->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$kasId}, 'SUKARELA')");
Database::pdo()->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$kasId}");
Database::pdo()->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$kasId}");

// akses menurut peran
check('pinjaman/akses: tamu dialihkan ke login (daftar, simulasi, formulir)', (new Browser())->get('/transaksi/pinjaman')['status'] === 302 && (new Browser())->get('/transaksi/pinjaman/simulasi')['status'] === 302 && (new Browser())->get('/transaksi/pinjaman/baru')['status'] === 302);
check('pinjaman/akses: Head melihat daftar tanpa tombol Catat; formulir 403', $sh->get('/transaksi/pinjaman')['status'] === 200 && !$has($sh->last, 'Catat pinjaman') && $sh->get('/transaksi/pinjaman/baru')['status'] === 403);
check('pinjaman/akses: Anggota melihat daftar dan simulasi; formulir 403', $sm->get('/transaksi/pinjaman')['status'] === 200 && $sm->get('/transaksi/pinjaman/simulasi')['status'] === 200 && $sm->get('/transaksi/pinjaman/baru')['status'] === 403);
check('pinjaman/akses: Ketua Regu melihat tombol Catat dan Simulasi', $has($sa->get('/transaksi/pinjaman'), 'Catat pinjaman') && $has($sa->last, 'Simulasi'));
check('pinjaman/akses: Head POST catat = 403, tanpa transaksi', $post($sh, '/transaksi/pinjaman', ['_form_id' => 'x', 'member_id' => (string) $mid(2), 'period_month_id' => '1', 'principal' => '1.000.000', 'tenor' => '2', 'trx_date' => $today])['status'] === 403 && $loanCount() === 0);
check('pinjaman/csrf: POST catat tanpa token = 419', $sa->post('/transaksi/pinjaman', ['member_id' => (string) $mid(2)], false)['status'] === 419 && $loanCount() === 0);

// simulasi (tanpa efek samping)
$before = $count('SELECT COUNT(*) FROM transactions');
$sim = $sa->get('/transaksi/pinjaman/simulasi?pokok=1.000.000&tenor=5');
check('pinjaman/simulasi: Rp 1.000.000 tenor 5 -> bunga Rp 100.000, total Rp 1.100.000, cicilan Rp 220.000', $sim['status'] === 200 && $has($sim, 'Rp 100.000') && $has($sim, 'Rp 1.100.000') && substr_count($sim['body'], 'Rp 220.000') === 5);
$sim2 = $sa->get('/transaksi/pinjaman/simulasi?pokok=5000000&tenor=3');
check('pinjaman/simulasi: cicilan terakhir menyerap sisa (1.766.666 x2, 1.766.668)', $has($sim2, 'Rp 1.766.668') && substr_count($sim2['body'], 'Rp 1.766.666') === 2);
check('pinjaman/simulasi: masukan salah diberi pesan, bukan 500', $has($sa->get('/transaksi/pinjaman/simulasi?pokok=abc&tenor=3'), 'angka bulat rupiah') && $has($sa->get('/transaksi/pinjaman/simulasi?pokok=1000000&tenor=9'), 'Tenor harus antara 1 dan 5') && $has($sa->get('/transaksi/pinjaman/simulasi?pokok=99.000.000&tenor=2'), 'Melebihi plafon'));
check('pinjaman/simulasi: masukan ber-HTML tampil sebagai teks (XSS)', !$has($sa->get('/transaksi/pinjaman/simulasi?pokok=%3Cscript%3Ealert(1)%3C/script%3E&tenor=2'), '<script>alert(1)') && $has($sa->last, '&lt;script&gt;'));
check('pinjaman/simulasi: tidak menyimpan apa pun', $count('SELECT COUNT(*) FROM transactions') === $before && $loanCount() === 0);

// formulir
$f = $sa->get('/transaksi/pinjaman/baru');
check('pinjaman/formulir: tampil dengan token, tarif, rentang tenor, dan anggota regunya saja', $f['status'] === 200 && $tokenOf($f) !== '' && $has($f, '2,00% per bulan') && $has($f, 'Tenor 1–5 bulan') && $has($f, 'Anggota Alfa Dua') && !$has($f, 'Anggota Beta Dua'));

// simpan draft
$r = $putLoan($sa, ['description' => 'Modal <b>warung</b>']);
$lid = $count("SELECT MAX(id) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'");
check('pinjaman/draft: berhasil, dialihkan ke detail, status DRAFT, atas nama pembuat', $r['status'] === 302 && $loc($r) === '/transaksi/' . $lid && $loanCount() === 1 && $str('SELECT status FROM transactions WHERE id = ?', [$lid]) === 'DRAFT' && $count('SELECT created_by FROM transactions WHERE id = ?', [$lid]) === $count("SELECT id FROM users WHERE username = 'alfa'"));
$d = $sa->get('/transaksi/' . $lid);
check('pinjaman/detail: pokok, tenor, tarif, bunga di muka, total tagihan tampil', $d['status'] === 200 && $has($d, 'PJM-' . date('Y') . '-000001') && $has($d, 'Pencairan pinjaman') && $has($d, 'Rp 5.000.000') && $has($d, '3 bulan') && $has($d, '2,00% per bulan') && $has($d, 'Rp 300.000') && $has($d, 'Rp 5.300.000'));
check('pinjaman/detail: jadwal 3 cicilan dengan bulan jatuh tempo (April-Juni 2026), dan catatan belum berlaku', $has($d, 'Jadwal cicilan') && $has($d, 'April 2026') && $has($d, 'Mei 2026') && $has($d, 'Juni 2026') && $has($d, 'Rp 1.766.668') && $has($d, 'baru berlaku setelah pencairan disetujui'));
check('pinjaman/detail: keterangan ber-HTML tampil sebagai teks', $has($d, '&lt;b&gt;warung&lt;/b&gt;') && !$has($d, '<b>warung'));
check('pinjaman/detail: draft belum memengaruhi saldo, piutang, dan kas', $has($d, 'Belum memengaruhi saldo') && $count('SELECT piutang_beredar FROM v_global_summary') === 0 && $count('SELECT kas_tersedia FROM v_global_summary') === 10000000);
check('pinjaman/detail: pembuat melihat Ubah (ke formulir pinjaman), Ajukan, Batalkan', $has($d, '/transaksi/pinjaman/' . $lid . '/ubah') && $has($d, 'Ajukan ke validasi') && $has($d, 'Batalkan transaksi'));
check('pinjaman/draft: audit LOAN_CREATED; bunga dan jadwal tersimpan di database', $audits('LOAN_CREATED') === 1 && $count('SELECT total_interest FROM loans WHERE transaction_id = ?', [$lid]) === 300000 && $count('SELECT COUNT(*) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.transaction_id = ?', [$lid]) === 3);

// kirim ganda
$tok = $tokenOf($sa->get('/transaksi/pinjaman/baru'));
$payload = ['_form_id' => $tok, 'member_id' => (string) $mid(1), 'period_month_id' => '1', 'principal' => '1.000.000', 'tenor' => '2', 'trx_date' => $today, 'action' => 'draft'];
$one = $post($sa, '/transaksi/pinjaman', $payload);
$two = $post($sa, '/transaksi/pinjaman', $payload);
check('pinjaman/ganda: kiriman kedua dengan token sama tidak membuat apa-apa', $one['status'] === 302 && $loanCount() === 2 && $two['status'] === 302 && $loc($two) === '/transaksi/pinjaman' && $has($sa->get('/transaksi/pinjaman'), 'sudah pernah dikirim'));

// galat validasi
$bad = $putLoan($sa, ['principal' => 'lima juta', 'tenor' => '3', 'description' => 'isian saya']);
$pg = $sa->get($loc($bad));
check('pinjaman/galat: pesan per field dan isian dipertahankan', $loc($bad) === '/transaksi/pinjaman/baru' && $has($pg, 'angka bulat rupiah') && $has($pg, 'value="isian saya"') && $loanCount() === 2);
check('pinjaman/galat: plafon dan tenor ditegakkan server', $has($sa->get($loc($putLoan($sa, ['principal' => '16.000.000']))), 'Melebihi plafon') && $has($sa->get($loc($putLoan($sa, ['tenor' => '6']))), 'Tenor harus antara 1 dan 5') && $loanCount() === 2);
$curMonthId = (int) $str('SELECT id FROM period_months WHERE month_date = ?', [date('Y-m-01')]);
check('pinjaman/galat: tenor yang melewati akhir periode (Februari 2027) ditolak dengan sisa bulan', $curMonthId === 0 || $has($sa->get($loc($putLoan($sa, ['period_month_id' => (string) $curMonthId, 'tenor' => '5']))), 'melewati akhir periode') || date('Y-m') < '2026-10');
$idor = $putLoan($sa, ['member_id' => (string) $mid(4)]);
check('pinjaman/IDOR: ketua A mencatat untuk anggota regu B ditolak tanpa membocorkan nama', $has($sa->get($loc($idor)), 'bukan anggota regu Anda') && !$has($sa->last, 'Beta Dua') && $loanCount() === 2);
$dup = $putLoan($sa);
check('pinjaman/duplikat: pinjaman identik diminta konfirmasi', $has($sa->get($loc($dup)), 'confirm_duplicate') && $has($sa->last, 'sudah ada') && $loanCount() === 2);

// ajukan
$ver = $str('SELECT updated_at FROM transactions WHERE id = ?', [$lid]);
check('pinjaman/ajukan: Head 403, ketua regu B 404, status tetap DRAFT', $post($sh, '/transaksi/' . $lid . '/ajukan', ['_version' => $ver])['status'] === 403 && $post($sb, '/transaksi/' . $lid . '/ajukan', ['_version' => $ver])['status'] === 404 && $str('SELECT status FROM transactions WHERE id = ?', [$lid]) === 'DRAFT');
$post($sa, '/transaksi/' . $lid . '/ajukan', ['_version' => '2000-01-01 00:00:00']);
check('pinjaman/ajukan: versi basi ditolak', $str('SELECT status FROM transactions WHERE id = ?', [$lid]) === 'DRAFT' && $has($sa->get('/transaksi/' . $lid), 'baru saja berubah'));
// kas tidak cukup: draft 15.000.000 vs kas 10.000.000
$putLoan($sa, ['member_id' => (string) $mid(1), 'principal' => '15.000.000', 'tenor' => '1']);
$bigId = $count("SELECT MAX(id) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'");
$post($sa, '/transaksi/' . $bigId . '/ajukan', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$bigId])]);
check('pinjaman/ajukan: kas tidak cukup -> pesan jelas dengan angka kas, status tetap DRAFT', $str('SELECT status FROM transactions WHERE id = ?', [$bigId]) === 'DRAFT' && $has($sa->get('/transaksi/' . $bigId), 'Kas tersedia tidak cukup') && $has($sa->last, 'Rp 10.000.000'));
$ok = $post($sa, '/transaksi/' . $lid . '/ajukan', ['_version' => $ver]);
check('pinjaman/ajukan: berhasil MENUNGGU_VALIDASI + riwayat + audit LOAN_SUBMITTED', $ok['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$lid]) === 'MENUNGGU_VALIDASI' && $count('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$lid]) === 1 && $audits('LOAN_SUBMITTED') === 1);
$d = $sa->get('/transaksi/' . $lid);
check('pinjaman/detail: setelah diajukan Ubah/Ajukan hilang, Batalkan tetap, jadwal tetap tampil', !$has($d, 'Ajukan ke validasi') && !$has($d, '/ubah') && $has($d, 'Batalkan transaksi') && $has($d, 'Jadwal cicilan') && $has($d, 'Menunggu Validasi'));
check('pinjaman/ajukan: yang menunggu belum mengubah kas dan piutang', $count('SELECT kas_tersedia FROM v_global_summary') === 10000000 && $count('SELECT piutang_beredar FROM v_global_summary') === 0);

// ubah draft
$e = $sa->get('/transaksi/pinjaman/' . $bigId . '/ubah');
check('pinjaman/ubah: formulir terisi nilai lama, membawa versi', $e['status'] === 200 && $versionOf($e) !== '' && $has($e, 'value="15.000.000"'));
check('pinjaman/ubah: ketua regu B 404, Head 403', $sb->get('/transaksi/pinjaman/' . $bigId . '/ubah')['status'] === 404 && $sh->get('/transaksi/pinjaman/' . $bigId . '/ubah')['status'] === 403);
check('pinjaman/ubah: yang sudah diajukan dialihkan ke detail', $sa->get('/transaksi/pinjaman/' . $lid . '/ubah')['status'] === 302 && $loc($sa->last) === '/transaksi/' . $lid);
$up = $post($sa, '/transaksi/pinjaman/' . $bigId, ['member_id' => (string) $mid(1), 'period_month_id' => '1', 'principal' => '2.000.000', 'tenor' => '2', 'trx_date' => $today, 'description' => 'turun', 'action' => 'draft', '_version' => $versionOf($e)]);
check('pinjaman/ubah: tersimpan, bunga dan jadwal dihitung ulang (80.000; 2 cicilan 1.040.000)', $up['status'] === 302 && $count('SELECT amount FROM transactions WHERE id = ?', [$bigId]) === 2000000 && $count('SELECT total_interest FROM loans WHERE transaction_id = ?', [$bigId]) === 80000
    && $count('SELECT COUNT(*) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.transaction_id = ?', [$bigId]) === 2 && $audits('LOAN_UPDATED') === 1);
$stale = $post($sa, '/transaksi/pinjaman/' . $bigId, ['member_id' => (string) $mid(1), 'period_month_id' => '1', 'principal' => '9.000.000', 'tenor' => '2', 'trx_date' => $today, 'action' => 'draft', '_version' => '2000-01-01 00:00:00']);
check('pinjaman/ubah: versi basi ditolak, pokok tidak tertimpa', $count('SELECT amount FROM transactions WHERE id = ?', [$bigId]) === 2000000 && $has($sa->get($loc($stale)), 'baru saja berubah'));
check('pinjaman/ubah: ketua regu B (404) dan Head (403) tidak bisa POST ubah', $post($sb, '/transaksi/pinjaman/' . $bigId, ['principal' => '1', '_version' => 'x'])['status'] === 404 && $post($sh, '/transaksi/pinjaman/' . $bigId, ['principal' => '1', '_version' => 'x'])['status'] === 403 && $count('SELECT amount FROM transactions WHERE id = ?', [$bigId]) === 2000000);
$stillSimpanan = $post($sa, '/transaksi/pinjaman/' . $draftId, ['principal' => '1.000.000', 'tenor' => '1', '_version' => 'x']);
check('pinjaman/silang: rute ubah pinjaman tidak menyentuh transaksi simpanan', $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 15000 && $str('SELECT type FROM transactions WHERE id = ?', [$draftId]) === 'SIMPANAN');
check('pinjaman/silang: formulir ubah pinjaman untuk simpanan dialihkan, bukan error', $sa->get('/transaksi/pinjaman/' . $draftId . '/ubah')['status'] === 302);

// batalkan
$verB = $str('SELECT updated_at FROM transactions WHERE id = ?', [$bigId]);
$post($sa, '/transaksi/' . $bigId . '/batal', ['_version' => $verB, 'note' => ' ']);
check('pinjaman/batal: tanpa alasan ditolak', $has($sa->get('/transaksi/' . $bigId), 'Alasan pembatalan wajib') && $str('SELECT status FROM transactions WHERE id = ?', [$bigId]) === 'DRAFT');
check('pinjaman/batal: ketua regu B (404) dan Head (403) tidak bisa membatalkan', $post($sb, '/transaksi/' . $bigId . '/batal', ['_version' => $verB, 'note' => 'iseng'])['status'] === 404 && $post($sh, '/transaksi/' . $bigId . '/batal', ['_version' => $verB, 'note' => 'iseng'])['status'] === 403);
$post($sa, '/transaksi/' . $bigId . '/batal', ['_version' => $verB, 'note' => 'Tidak jadi']);
check('pinjaman/batal: DIBATALKAN, nomor, jadwal, dan alasan tetap tersimpan; audit LOAN_CANCELLED', $str('SELECT status FROM transactions WHERE id = ?', [$bigId]) === 'DIBATALKAN' && $has($sa->get('/transaksi/' . $bigId), 'Tidak jadi') && $has($sa->last, 'Jadwal cicilan') && $audits('LOAN_CANCELLED') === 1);

// cakupan baca + daftar
check('pinjaman/IDOR baca: ketua B membuka pinjaman regu A = 404', $sb->get('/transaksi/' . $lid)['status'] === 404);
check('pinjaman/cakupan: Head membuka dan melihat jadwal, tanpa tombol aksi', $sh->get('/transaksi/' . $lid)['status'] === 200 && $has($sh->last, 'Jadwal cicilan') && !$has($sh->last, 'Batalkan transaksi') && !$has($sh->last, 'Ajukan ke validasi'));
check('pinjaman/cakupan: Anggota 4 tidak bisa membuka pinjaman anggota lain (404)', $sm->get('/transaksi/' . $lid)['status'] === 404);
$ll = $sa->get('/transaksi/pinjaman');
check('pinjaman/daftar: kolom tenor, tarif, pokok; pinjaman menunggu belum punya sisa tagihan', $has($ll, 'PJM-' . date('Y') . '-000001') && $has($ll, '3 bulan') && $has($ll, '2,00%') && $has($ll, 'Rp 5.000.000') && !$has($ll, 'Rp 5.300.000'));
check('pinjaman/daftar: ketua B tidak melihat milik regu A; Head melihat; filter dan simbol aman', !$has($sb->get('/transaksi/pinjaman'), 'PJM-' . date('Y') . '-000001') && $has($sh->get('/transaksi/pinjaman'), 'PJM-' . date('Y') . '-000001')
    && $sa->get('/transaksi/pinjaman?status=DRAFT&bulan=1&q=alfa')['status'] === 200 && $has($sa->get('/transaksi/pinjaman?q=%25'), 'Belum ada pinjaman yang cocok') && $sa->get('/transaksi/pinjaman?bulan=x&status=%27&page=-3')['status'] === 200);
check('pinjaman/riwayat: tampil di Riwayat Transaksi sebagai Pencairan pinjaman', $has($sh->get('/transaksi/riwayat?type=PENCAIRAN_PINJAMAN'), 'Pencairan pinjaman') && $has($sh->last, 'PJM-' . date('Y') . '-000001'));

// setelah disetujui (disimulasikan lewat SQL; persetujuan lewat aplikasi di Phase 9)
Database::pdo()->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$lid}");
$da = $sa->get('/transaksi/' . $lid);
check('pinjaman/disetujui: detail menampilkan sisa tagihan Rp 5.300.000 dan kolom terbayar/sisa per cicilan', $has($da, 'Sisa tagihan') && $has($da, 'Rp 5.300.000') && $has($da, 'Terbayar') && !$has($da, 'baru berlaku setelah pencairan disetujui') && !$has($da, 'Batalkan transaksi'));
check('pinjaman/disetujui: daftar menampilkan sisa tagihan; kas dan piutang berubah sesuai', $has($sa->get('/transaksi/pinjaman'), 'Rp 5.300.000') && $count('SELECT kas_tersedia FROM v_global_summary') === 5000000 && $count('SELECT piutang_beredar FROM v_global_summary') === 5300000 && $count('SELECT selisih FROM v_global_summary') === 0);
check('pinjaman/disetujui: halaman anggota menautkan nomor pinjaman ke detail transaksinya', $has($sa->get('/anggota/' . $mid(2)), '/transaksi/' . $lid) && $has($sa->last, 'PJM-' . date('Y') . '-000001'));
check('pinjaman/disetujui: integritas basis data bersih (jadwal = pokok+bunga, bunga = rumus)', $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);
check('pinjaman/disetujui: yang sudah disetujui tidak bisa diubah/dibatalkan lewat aplikasi', $post($sa, '/transaksi/' . $lid . '/batal', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$lid]), 'note' => 'oops'])['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$lid]) === 'DISETUJUI');

// kebersihan halaman baru
$pages2 = '';
foreach ([['/transaksi/pinjaman', $sa], ['/transaksi/pinjaman/baru', $sa], ['/transaksi/pinjaman/simulasi?pokok=1000000&tenor=3', $sa], ['/transaksi/' . $lid, $sa], ['/transaksi/pinjaman/' . $count("SELECT MAX(id) FROM transactions WHERE status = 'DRAFT' AND type = 'PENCAIRAN_PINJAMAN'") . '/ubah', $sa]] as [$path, $br]) {
    $pages2 .= $br->get($path)['body'];
}
check('pinjaman/umum: tanpa <script>, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $pages2));

// ---- 12i. angsuran (Phase 8) ----
// pinjaman $lid (anggota 2, Rp 5.000.000 tenor 3, total Rp 5.300.000) sudah DISETUJUI di 12h; bulan pembayaran = bulan berjalan
$payMonth = (int) $str('SELECT id FROM period_months WHERE month_date = ?', [date('Y-m-01')]);
$payCount = fn (): int => $count("SELECT COUNT(*) FROM transactions WHERE type = 'ANGSURAN'");
$putPay = fn (Browser $br, array $over = []): array => $br->post('/transaksi/angsuran', $over + [
    '_form_id' => $tokenOf($br->get('/transaksi/angsuran/baru')), 'member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth,
    'amount' => '2.000.000', 'trx_date' => $today, 'description' => '', 'action' => 'draft',
]);
$loanDoc = $str('SELECT doc_no FROM transactions WHERE id = ?', [$lid]);

// akses menurut peran
check('angsuran/akses: tamu dialihkan ke login (daftar, tagihan, formulir)', (new Browser())->get('/transaksi/angsuran')['status'] === 302 && (new Browser())->get('/transaksi/angsuran/tagihan')['status'] === 302 && (new Browser())->get('/transaksi/angsuran/baru')['status'] === 302);
check('angsuran/akses: Head melihat daftar tanpa tombol Catat; formulir 403', $sh->get('/transaksi/angsuran')['status'] === 200 && !$has($sh->last, 'Catat angsuran') && $sh->get('/transaksi/angsuran/baru')['status'] === 403);
check('angsuran/akses: Anggota melihat daftar dan tagihan; formulir 403', $sm->get('/transaksi/angsuran')['status'] === 200 && $sm->get('/transaksi/angsuran/tagihan')['status'] === 200 && $sm->get('/transaksi/angsuran/baru')['status'] === 403);
check('angsuran/akses: Ketua Regu melihat tombol Catat dan Tagihan', $has($sa->get('/transaksi/angsuran'), 'Catat angsuran') && $has($sa->last, 'Tagihan'));
check('angsuran/akses: Head POST catat = 403, tanpa transaksi; tanpa token 419', $post($sh, '/transaksi/angsuran', ['_form_id' => 'x', 'member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth, 'amount' => '1.000.000', 'trx_date' => $today])['status'] === 403 && $payCount() === 0
    && $sa->post('/transaksi/angsuran', ['member_id' => (string) $mid(2)], false)['status'] === 419);

// tagihan
$tg = $sa->get('/transaksi/angsuran/tagihan');
check('angsuran/tagihan: ketua A melihat anggota 2 dengan sisa Rp 5.300.000 dan tunggakannya', $tg['status'] === 200 && $has($tg, 'Anggota Alfa Dua') && $has($tg, 'Rp 5.300.000') && $has($tg, 'Tunggakan'));
check('angsuran/tagihan: tombol Catat mengisi anggota dan jumlah jatuh tempo', $has($tg, '/transaksi/angsuran/baru?anggota=' . $mid(2) . '&amp;nominal=5300000'));
check('angsuran/tagihan: ketua B tidak melihat anggota regu A; Head melihat; Anggota 4 kosong', !$has($sb->get('/transaksi/angsuran/tagihan'), 'Anggota Alfa Dua') && $has($sh->get('/transaksi/angsuran/tagihan'), 'Anggota Alfa Dua') && $has($sm->get('/transaksi/angsuran/tagihan'), 'Tidak ada anggota yang masih punya sisa pinjaman'));

// formulir
$f = $sa->get('/transaksi/angsuran/baru?anggota=' . $mid(2) . '&nominal=1766666');
check('angsuran/formulir: terisi dari tagihan (anggota terpilih, jumlah 1.766.666), token, hanya anggota regunya', $f['status'] === 200 && $tokenOf($f) !== '' && $has($f, 'value="1.766.666"') && (bool) preg_match('/<option value="' . $mid(2) . '" selected>/', $f['body']) && !$has($f, 'Anggota Beta Dua'));
check('angsuran/formulir: masukan ?anggota=/nominal= ngawur tidak membuat error', $sa->get('/transaksi/angsuran/baru?anggota=abc&nominal=-5')['status'] === 200 && $sa->get('/transaksi/angsuran/baru?anggota=' . $mid(4))['status'] === 200);

// simpan draft
$r = $putPay($sa, ['description' => 'Bayar <i>pertemuan</i>']);
$pid = $count("SELECT MAX(id) FROM transactions WHERE type = 'ANGSURAN'");
check('angsuran/draft: berhasil, dialihkan ke detail, DRAFT, atas nama pembuat dan regunya', $r['status'] === 302 && $loc($r) === '/transaksi/' . $pid && $payCount() === 1 && $str('SELECT status FROM transactions WHERE id = ?', [$pid]) === 'DRAFT' && $count('SELECT team_id FROM transactions WHERE id = ?', [$pid]) === 1);
$d = $sa->get('/transaksi/' . $pid);
check('angsuran/detail: nomor ANG-, jumlah, dan pembagian ke cicilan paling tua dulu (1.766.666 + 233.334)', $d['status'] === 200 && $has($d, 'ANG-' . date('Y') . '-000001') && $has($d, 'Pembagian ke cicilan') && $has($d, 'Rp 1.766.666') && $has($d, 'Rp 233.334') && $has($d, 'April 2026') && $has($d, 'Mei 2026'));
check('angsuran/detail: pembagian menaut ke pinjaman, jumlah total 2.000.000, penjelasan belum memengaruhi saldo', $has($d, 'href="/transaksi/' . $lid . '"') && $has($d, $loanDoc) && $has($d, 'Belum memengaruhi saldo') && $has($d, 'Rp 2.000.000'));
check('angsuran/detail: keterangan ber-HTML tampil sebagai teks', $has($d, '&lt;i&gt;pertemuan&lt;/i&gt;') && !$has($d, '<i>pertemuan'));
check('angsuran/draft: alokasi di database berjumlah persis nominal; audit PAYMENT_CREATED', $count('SELECT SUM(amount) FROM installment_payments WHERE transaction_id = ?', [$pid]) === 2000000 && $audits('PAYMENT_CREATED') === 1);
check('angsuran/draft: draft tidak mengurangi sisa pinjaman', $count('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$lid]) === 5300000);

// kirim ganda dan galat
$tok = $tokenOf($sa->get('/transaksi/angsuran/baru'));
$payload = ['_form_id' => $tok, 'member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth, 'amount' => '300.000', 'trx_date' => $today, 'action' => 'draft'];
$one = $post($sa, '/transaksi/angsuran', $payload);
$two = $post($sa, '/transaksi/angsuran', $payload);
check('angsuran/ganda: kiriman kedua dengan token sama tidak membuat apa-apa', $one['status'] === 302 && $payCount() === 2 && $two['status'] === 302 && $loc($two) === '/transaksi/angsuran' && $has($sa->get('/transaksi/angsuran'), 'sudah pernah dikirim'));
$bad = $putPay($sa, ['amount' => 'dua juta', 'description' => 'isian saya']);
$pg = $sa->get($loc($bad));
check('angsuran/galat: pesan per field dan isian dipertahankan, tanpa transaksi', $loc($bad) === '/transaksi/angsuran/baru' && $has($pg, 'angka bulat rupiah') && $has($pg, 'value="isian saya"') && $payCount() === 2);
check('angsuran/galat: melebihi sisa tagihan ditolak dengan angka sisa (draft tidak mencadangkan ruang)', $has($sa->get($loc($putPay($sa, ['amount' => '5.300.001']))), 'Melebihi sisa tagihan') && $has($sa->last, 'Rp 5.300.000') && $payCount() === 2);
check('angsuran/galat: anggota tanpa pinjaman aktif ditolak', $has($sa->get($loc($putPay($sa, ['member_id' => (string) $mid(1)]))), 'tidak punya cicilan') && $payCount() === 2);
check('angsuran/IDOR: ketua A mencatat untuk anggota regu B ditolak tanpa membocorkan nama', $has($sa->get($loc($putPay($sa, ['member_id' => (string) $mid(4)]))), 'bukan anggota regu Anda') && !$has($sa->last, 'Beta Dua') && $payCount() === 2);
check('angsuran/duplikat: identik diminta konfirmasi; lolos bila dikonfirmasi', $has($sa->get($loc($putPay($sa, ['amount' => '2.000.000']))), 'confirm_duplicate') && $payCount() === 2 && $putPay($sa, ['amount' => '2.000.000', 'confirm_duplicate' => '1'])['status'] === 302 && $payCount() === 3);
// bersihkan: batalkan draft duplikat agar hitungan berikutnya rapi
$dupId = $count("SELECT MAX(id) FROM transactions WHERE type = 'ANGSURAN'");
$post($sa, '/transaksi/' . $dupId . '/batal', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$dupId]), 'note' => 'duplikat uji']);
check('angsuran/batal: draft dibatalkan dengan alasan; nomor tetap', $str('SELECT status FROM transactions WHERE id = ?', [$dupId]) === 'DIBATALKAN' && $audits('PAYMENT_CANCELLED') === 1);

// ajukan
$ver = $str('SELECT updated_at FROM transactions WHERE id = ?', [$pid]);
check('angsuran/ajukan: Head 403, ketua B 404, versi basi ditolak; status tetap DRAFT', $post($sh, '/transaksi/' . $pid . '/ajukan', ['_version' => $ver])['status'] === 403 && $post($sb, '/transaksi/' . $pid . '/ajukan', ['_version' => $ver])['status'] === 404
    && $post($sa, '/transaksi/' . $pid . '/ajukan', ['_version' => '2000-01-01 00:00:00'])['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$pid]) === 'DRAFT');
$post($sa, '/transaksi/' . $pid . '/ajukan', ['_version' => $ver]);
check('angsuran/ajukan: berhasil MENUNGGU_VALIDASI + riwayat + audit', $str('SELECT status FROM transactions WHERE id = ?', [$pid]) === 'MENUNGGU_VALIDASI' && $count('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$pid]) === 1 && $audits('PAYMENT_SUBMITTED') === 1);
$d = $sa->get('/transaksi/' . $pid);
check('angsuran/detail: setelah diajukan tombol Ubah/Ajukan hilang, Batalkan tetap, pembagian tetap tampil', !$has($d, 'Ajukan ke validasi') && !$has($d, '/ubah') && $has($d, 'Batalkan transaksi') && $has($d, 'Pembagian ke cicilan') && $has($d, 'Menunggu Validasi'));
check('angsuran/ajukan: yang menunggu belum mengurangi sisa pinjaman atau menambah kas', $count('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$lid]) === 5300000);
check('angsuran/tagihan: pembayaran menunggu tampil di kolom Menunggu validasi, sisa belum berkurang', $has($sa->get('/transaksi/angsuran/tagihan'), 'Rp 2.000.000') && $has($sa->last, 'Rp 5.300.000'));

// pencadangan: sisa ruang = 5.300.000 - 2.000.000 = 3.300.000 (draft 300.000 dari uji ganda tidak mencadangkan)
$tooMuch = $putPay($sa, ['amount' => '3.300.001', 'confirm_duplicate' => '1']);
check('angsuran/pencadangan: 1 rupiah di atas ruang (3.300.000) ditolak dengan angka ruang', $has($sa->get($loc($tooMuch)), 'Melebihi sisa tagihan') && $has($sa->last, 'Rp 3.300.000'));
$fits = $putPay($sa, ['amount' => '3.300.000', 'confirm_duplicate' => '1', 'action' => 'submit']);
$fitId = $count("SELECT MAX(id) FROM transactions WHERE type = 'ANGSURAN'");
check('angsuran/pencadangan: tepat sebesar ruang diterima dan langsung diajukan', $fits['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$fitId]) === 'MENUNGGU_VALIDASI');
check('angsuran/pencadangan: draft 300.000 yang dibuat sebelumnya kini tidak bisa diajukan (ruang habis), tetap DRAFT', (function () use ($post, $sa, $str, $count, $has): bool {
    $d3 = $count("SELECT id FROM transactions WHERE type = 'ANGSURAN' AND status = 'DRAFT' AND amount = 300000 ORDER BY id LIMIT 1");
    $post($sa, '/transaksi/' . $d3 . '/ajukan', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$d3])]);
    return $d3 > 0 && $str('SELECT status FROM transactions WHERE id = ?', [$d3]) === 'DRAFT' && $has($sa->get('/transaksi/' . $d3), 'tidak punya cicilan');
})());
check('angsuran/pencadangan: tidak ada cicilan yang dicadangkan/dibayar melebihi tagihannya', $count("SELECT COUNT(*) FROM (SELECT li.id FROM installment_payments ip JOIN transactions t ON t.id = ip.transaction_id AND t.status IN ('MENUNGGU_VALIDASI','DISETUJUI') JOIN loan_installments li ON li.id = ip.installment_id GROUP BY li.id, li.amount_due HAVING SUM(ip.amount) > li.amount_due) x") === 0);

// ubah draft
$draftId = $count("SELECT id FROM transactions WHERE type = 'ANGSURAN' AND status = 'DRAFT' AND amount = 300000 ORDER BY id LIMIT 1");
$e = $sa->get('/transaksi/angsuran/' . $draftId . '/ubah');
check('angsuran/ubah: formulir terisi nilai lama dan membawa versi', $e['status'] === 200 && $versionOf($e) !== '' && $has($e, 'value="300.000"'));
check('angsuran/ubah: ketua B 404, Head 403; yang sudah diajukan dialihkan', $sb->get('/transaksi/angsuran/' . $draftId . '/ubah')['status'] === 404 && $sh->get('/transaksi/angsuran/' . $draftId . '/ubah')['status'] === 403 && $sa->get('/transaksi/angsuran/' . $pid . '/ubah')['status'] === 302);
// batalkan pembayaran besar agar ada ruang, lalu ubah draft 300.000 -> 400.000
$post($sa, '/transaksi/' . $fitId . '/batal', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$fitId]), 'note' => 'melepas ruang']);
check('angsuran/batal: pembayaran menunggu yang dibatalkan melepas cadangan', $str('SELECT status FROM transactions WHERE id = ?', [$fitId]) === 'DIBATALKAN');
$up = $post($sa, '/transaksi/angsuran/' . $draftId, ['member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth, 'amount' => '400.000', 'trx_date' => $today, 'description' => 'naik', 'action' => 'draft', '_version' => $versionOf($e)]);
check('angsuran/ubah: tersimpan, alokasi dihitung ulang (400.000), audit PAYMENT_UPDATED', $up['status'] === 302 && $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 400000 && $count('SELECT SUM(amount) FROM installment_payments WHERE transaction_id = ?', [$draftId]) === 400000 && $audits('PAYMENT_UPDATED') === 1);
$stale = $post($sa, '/transaksi/angsuran/' . $draftId, ['member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth, 'amount' => '999.000', 'trx_date' => $today, 'action' => 'draft', '_version' => '2000-01-01 00:00:00']);
check('angsuran/ubah: versi basi ditolak, jumlah tidak tertimpa', $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 400000 && $has($sa->get($loc($stale)), 'baru saja berubah'));
check('angsuran/ubah: ketua B (404) dan Head (403) tidak bisa POST ubah', $post($sb, '/transaksi/angsuran/' . $draftId, ['amount' => '1', '_version' => 'x'])['status'] === 404 && $post($sh, '/transaksi/angsuran/' . $draftId, ['amount' => '1', '_version' => 'x'])['status'] === 403 && $count('SELECT amount FROM transactions WHERE id = ?', [$draftId]) === 400000);
check('angsuran/silang: rute ubah angsuran tidak menyentuh simpanan atau pinjaman', $post($sa, '/transaksi/angsuran/' . $lid, ['amount' => '1.000', '_version' => 'x'])['status'] === 302 && $count('SELECT amount FROM transactions WHERE id = ?', [$lid]) === 5000000);

// cakupan baca dan daftar
check('angsuran/IDOR baca: ketua B dan Anggota 4 tidak bisa membuka angsuran milik anggota regu A (404); Head bisa', $sb->get('/transaksi/' . $pid)['status'] === 404 && $sh->get('/transaksi/' . $pid)['status'] === 200 && $sm->get('/transaksi/' . $pid)['status'] === 404);
check('angsuran/cakupan: Head membuka angsuran tanpa tombol aksi', !$has($sh->get('/transaksi/' . $pid), 'Batalkan transaksi') && $has($sh->last, 'Pembagian ke cicilan'));
$lp = $sa->get('/transaksi/angsuran');
check('angsuran/daftar: nomor ANG- tampil dengan nominal dan status; tanpa kolom jenis simpanan', $has($lp, 'ANG-' . date('Y') . '-000001') && $has($lp, 'Rp 2.000.000') && !$has($lp, 'Simpanan</th>') && $has($lp, 'Nominal</th>'));
check('angsuran/daftar: ketua B kosong; filter dan simbol aman', $has($sb->get('/transaksi/angsuran'), 'Belum ada angsuran yang cocok') && $sa->get('/transaksi/angsuran?status=DRAFT&bulan=' . $payMonth . '&q=alfa')['status'] === 200 && $has($sa->get('/transaksi/angsuran?q=%25'), 'Belum ada angsuran yang cocok') && $sa->get('/transaksi/angsuran?bulan=x&status=%27&page=-3')['status'] === 200);
check('angsuran/riwayat: tampil di Riwayat Transaksi sebagai Angsuran', $has($sh->get('/transaksi/riwayat?type=ANGSURAN'), 'Angsuran') && $has($sh->last, 'ANG-' . date('Y') . '-000001'));

// setelah disetujui (disimulasikan lewat SQL)
$kas0 = $count('SELECT kas_tersedia FROM v_global_summary');
Database::pdo()->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$pid}");
check('angsuran/disetujui: sisa pinjaman turun 2.000.000 (5.300.000 -> 3.300.000), kas naik, selisih invarian 0', $count('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$lid]) === 3300000 && $count('SELECT kas_tersedia FROM v_global_summary') === $kas0 + 2000000 && $count('SELECT selisih FROM v_global_summary') === 0);
check('angsuran/disetujui: integritas basis data bersih (alokasi = nominal, tanpa kelebihan bayar)', $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);
$da = $sa->get('/transaksi/' . $lid);
check('angsuran/disetujui: detail pinjaman menampilkan terbayar Rp 2.000.000 dan sisa Rp 3.300.000, per cicilan', $has($da, 'Rp 3.300.000') && $has($da, 'Terbayar') && $has($da, 'Rp 1.766.666'));
check('angsuran/disetujui: tagihan menampilkan sisa baru dan menunggu validasi 0', $has($sa->get('/transaksi/angsuran/tagihan'), 'Rp 3.300.000'));
check('angsuran/disetujui: yang sudah disetujui tidak bisa dibatalkan lewat aplikasi', $post($sa, '/transaksi/' . $pid . '/batal', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$pid]), 'note' => 'oops'])['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$pid]) === 'DISETUJUI');

// kebersihan halaman baru
$pages3 = '';
foreach ([['/transaksi/angsuran', $sa], ['/transaksi/angsuran/tagihan', $sa], ['/transaksi/angsuran/baru?anggota=' . $mid(2) . '&nominal=1000', $sa], ['/transaksi/' . $pid, $sa], ['/transaksi/angsuran/' . $draftId . '/ubah', $sa]] as [$path, $br]) {
    $pages3 .= $br->get($path)['body'];
}
check('angsuran/umum: tanpa <script>, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $pages3));

// ---- 12j. validasi (Phase 9) ----
UserService::create('periksa', 'Pemeriksa Uji', ['PEMERIKSA'], null, PASS);
Database::pdo()->exec("UPDATE users SET must_change_password = 0 WHERE username = 'periksa'");
// anggota "Head": anggota baru di regu Alfa yang tertaut ke akun Head lain (kepala3), agar transaksinya terkait Head
Database::pdo()->exec("INSERT INTO members (member_no, name, active_from) VALUES ('AGT-090', 'Anggota Tertaut Head', '2026-03-01')");
$headMember = (int) Database::pdo()->lastInsertId();
Database::pdo()->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES ({$headMember}, 1, '2026-03-01')");
UserService::create('kepala3', 'Kepala Tiga', ['HEAD'], 'AGT-090', PASS);
Database::pdo()->exec("UPDATE users SET must_change_password = 0 WHERE username = 'kepala3'");
$sp = (new Browser())->keepAlive('periksa');
$sh = (new Browser())->keepAlive('kepala');
$sh3 = (new Browser())->keepAlive('kepala3');
$mkSaving = function (Browser $br, int $memberId, string $amount) use ($post, $tokenOf, $payMonth, $today, $count): int {
    $br->get('/transaksi/simpanan/baru');
    $br->post('/transaksi/simpanan', ['_form_id' => $tokenOf($br->last), 'member_id' => (string) $memberId, 'period_month_id' => (string) $payMonth, 'kind' => 'SUKARELA', 'amount' => $amount, 'trx_date' => $today, 'description' => '', 'action' => 'submit']);
    return $count("SELECT MAX(id) FROM transactions WHERE type = 'SIMPANAN'");
};
$v1 = $mkSaving($sa, $mid(2), '12.345');     // biasa (regu Alfa)
$v2 = $mkSaving($sb, $mid(4), '23.456');     // biasa (regu Beta)
$v3 = $mkSaving($sa, $headMember, '34.567'); // atas nama anggota tertaut Head
$vDraft = $count("SELECT id FROM transactions WHERE type = 'SIMPANAN' AND status = 'DRAFT' ORDER BY id LIMIT 1");
check('validasi/fixture: tiga simpanan menunggu validasi', $str('SELECT status FROM transactions WHERE id = ?', [$v1]) === 'MENUNGGU_VALIDASI' && $str('SELECT status FROM transactions WHERE id = ?', [$v2]) === 'MENUNGGU_VALIDASI' && $str('SELECT status FROM transactions WHERE id = ?', [$v3]) === 'MENUNGGU_VALIDASI');

// akses menurut peran
check('validasi/akses: tamu dialihkan ke login', (new Browser())->get('/validasi')['status'] === 302 && (new Browser())->get('/validasi/riwayat')['status'] === 302);
check('validasi/akses: Ketua Regu 403 di antrean, riwayat, dan semua aksi', $sa->get('/validasi')['status'] === 403 && $sa->get('/validasi/riwayat')['status'] === 403 && $post($sa, '/validasi/' . $v2 . '/setujui', ['_version' => 'x'])['status'] === 403 && $post($sa, '/validasi/' . $v2 . '/tolak', ['_version' => 'x', 'note' => 'x'])['status'] === 403);
check('validasi/akses: Anggota 403', $sm->get('/validasi')['status'] === 403 && $post($sm, '/validasi/' . $v2 . '/setujui', ['_version' => 'x'])['status'] === 403);
check('validasi/akses: Head dan Pemeriksa membuka antrean dan riwayat', $sh->get('/validasi')['status'] === 200 && $sp->get('/validasi')['status'] === 200 && $sh->get('/validasi/riwayat')['status'] === 200 && $sp->get('/validasi/riwayat')['status'] === 200);
check('validasi/akses: menu Validasi tampil untuk Head dan Pemeriksa, tidak untuk Ketua Regu', $has($sh->get('/'), 'href="/validasi"') && $has($sp->get('/'), 'href="/validasi"') && !$has($sa->get('/'), 'href="/validasi"'));
check('validasi/csrf: POST tanpa token = 419; aksi via GET tidak ada', $sp->post('/validasi/' . $v2 . '/setujui', ['_version' => 'x'], false)['status'] === 419 && in_array($sp->get('/validasi/' . $v2 . '/setujui')['status'], [404, 405], true) && in_array($sp->get('/validasi/' . $v2 . '/tolak')['status'], [404, 405], true));
check('validasi/akses: id yang tidak ada = 404', $post($sp, '/validasi/999999/setujui', ['_version' => 'x'])['status'] === 404);

// antrean
$qh = $sh->get('/validasi');
check('validasi/antrean: memuat transaksi yang menunggu, bukan draft', $has($qh, $str('SELECT doc_no FROM transactions WHERE id = ?', [$v1])) && $has($qh, $str('SELECT doc_no FROM transactions WHERE id = ?', [$v2])) && !$has($qh, $str('SELECT doc_no FROM transactions WHERE id = ?', [$vDraft])) && $has($qh, 'Kas tersedia sekarang'));
check('validasi/antrean: transaksi terkait Head ditandai "Hanya Pemeriksa" dan Head melihat tidak bisa', $has($qh, 'Hanya Pemeriksa') && $has($qh, 'Tidak bisa') && $has($qh, 'Bisa divalidasi'));
check('validasi/antrean: Pemeriksa bisa memvalidasi yang terkait Head', substr_count($sp->get('/validasi')['body'], 'Bisa divalidasi') >= 3 && !$has($sp->last, '>Tidak bisa<'));
check('validasi/antrean: filter jenis, pencarian, simbol aman', $sh->get('/validasi?jenis=SIMPANAN&q=Alfa')['status'] === 200 && $has($sh->get('/validasi?jenis=ANGSURAN'), 'Tidak ada transaksi yang menunggu validasi') && $has($sh->get('/validasi?q=%25'), 'Tidak ada transaksi yang menunggu validasi') && $sh->get('/validasi?jenis=x&page=-4&q=%27')['status'] === 200);
check('validasi/antrean: tanpa akun Pemeriksa, Head diberi peringatan (diuji dengan menonaktifkan sementara)', (function () use ($sh, $has): bool {
    Database::pdo()->exec("UPDATE users SET is_active = 0 WHERE username = 'periksa'");
    $warn = $has($sh->get('/validasi'), 'Belum ada akun') && $has($sh->get('/transaksi/' . (int) Database::pdo()->query("SELECT MAX(id) FROM transactions WHERE type = 'SIMPANAN' AND status = 'MENUNGGU_VALIDASI'")->fetchColumn()), 'Belum ada akun Pemeriksa');
    Database::pdo()->exec("UPDATE users SET is_active = 1 WHERE username = 'periksa'");
    return $warn;
})());

// panel di detail
$dh = $sh->get('/transaksi/' . $v1);
check('validasi/detail: Head melihat tombol Setujui dan Tolak untuk transaksi biasa', $has($dh, 'id="validasi"') && $has($dh, '/validasi/' . $v1 . '/setujui') && $has($dh, '/validasi/' . $v1 . '/tolak') && $has($dh, 'Alasan penolakan'));
check('validasi/detail: Ketua Regu pembuat tidak melihat panel validasi', !$has($sa->get('/transaksi/' . $v1), 'id="validasi"') && !$has($sa->last, '/validasi/'));
$d3h = $sh->get('/transaksi/' . $v3);
check('validasi/detail: transaksi terkait Head: Head melihat penjelasan, tanpa tombol', $has($d3h, 'hanya Pemeriksa') && $has($d3h, 'Anda tidak bisa memvalidasi') && !$has($d3h, '/validasi/' . $v3 . '/setujui'));
check('validasi/detail: Pemeriksa melihat tombol untuk transaksi terkait Head', $has($sp->get('/transaksi/' . $v3), '/validasi/' . $v3 . '/setujui'));

// setuju oleh Head (transaksi biasa) + kewenangan Head ditegakkan server
$kas0 = $count('SELECT kas_tersedia FROM v_global_summary');
$headTry = $post($sh, '/validasi/' . $v3 . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$v3])]);
check('validasi/setuju: Head memaksa POST untuk transaksi terkait Head ditolak server, status tetap', $headTry['status'] === 302 && $loc($headTry) === '/transaksi/' . $v3 && $has($sh->get('/transaksi/' . $v3), 'hanya boleh divalidasi Pemeriksa') && $str('SELECT status FROM transactions WHERE id = ?', [$v3]) === 'MENUNGGU_VALIDASI');
check('validasi/setuju: versi basi ditolak', $has($sh->get($loc($post($sh, '/validasi/' . $v1 . '/setujui', ['_version' => '2000-01-01 00:00:00']))), 'baru saja berubah') && $str('SELECT status FROM transactions WHERE id = ?', [$v1]) === 'MENUNGGU_VALIDASI');
$ok = $post($sh, '/validasi/' . $v1 . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$v1]), 'note' => 'OK sesuai buku']);
check('validasi/setuju: Head menyetujui transaksi biasa; dialihkan ke antrean dengan pemberitahuan', $ok['status'] === 302 && $loc($ok) === '/validasi' && $has($sh->get('/validasi'), 'disetujui dan kini dihitung dalam saldo'));
check('validasi/setuju: status DISETUJUI, kas naik 12.345, saldo anggota bertambah, selisih 0', $str('SELECT status FROM transactions WHERE id = ?', [$v1]) === 'DISETUJUI' && $count('SELECT kas_tersedia FROM v_global_summary') === $kas0 + 12345 && $count('SELECT selisih FROM v_global_summary') === 0);
check('validasi/setuju: validator, catatan, dan audit TRX_APPROVED tercatat', $count('SELECT COUNT(*) FROM transaction_validations v JOIN users u ON u.id = v.actor_user_id WHERE v.transaction_id = ? AND v.to_status = ? AND u.username = ? AND v.note = ?', [$v1, 'DISETUJUI', 'kepala', 'OK sesuai buku']) === 1 && $audits('TRX_APPROVED') === 1);
check('validasi/setuju: sekali lagi (kirim ulang) ditolak, tidak menggandakan', $has($sh->get($loc($post($sh, '/validasi/' . $v1 . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$v1])]))), 'sudah disetujui') && $count('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ? AND to_status = ?', [$v1, 'DISETUJUI']) === 1);
$dv = $sh->get('/transaksi/' . $v1);
check('validasi/setuju: detail setelah disetujui tanpa panel, riwayat menyebut validator dan catatan', !$has($dv, 'id="validasi"') && $has($dv, 'OK sesuai buku') && $has($dv, 'Kepala Koperasi'));
check('validasi/setuju: pembuat melihat transaksinya disetujui dan tak bisa lagi dibatalkan', $has($sa->get('/transaksi/' . $v1), 'Disetujui') && !$has($sa->last, 'Batalkan transaksi'));

$h3 = $post($sh3, '/validasi/' . $v3 . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$v3])]);
check('validasi/setuju: Head yang menjadi pemilik anggota itu pun ditolak ("atas nama Anda sendiri"), status tetap', $has($sh3->get($loc($h3)), 'atas nama Anda sendiri') && $str('SELECT status FROM transactions WHERE id = ?', [$v3]) === 'MENUNGGU_VALIDASI');

// Pemeriksa menyetujui transaksi terkait Head
$pm = $post($sp, '/validasi/' . $v3 . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$v3])]);
check('validasi/setuju: Pemeriksa menyetujui transaksi terkait Head', $pm['status'] === 302 && $str('SELECT status FROM transactions WHERE id = ?', [$v3]) === 'DISETUJUI');

// tolak
$vv = $str('SELECT updated_at FROM transactions WHERE id = ?', [$v2]);
$post($sp, '/validasi/' . $v2 . '/tolak', ['_version' => $vv, 'note' => '  ']);
check('validasi/tolak: tanpa alasan ditolak, status tetap', $has($sp->get('/transaksi/' . $v2), 'Alasan penolakan wajib diisi') && $str('SELECT status FROM transactions WHERE id = ?', [$v2]) === 'MENUNGGU_VALIDASI');
$post($sp, '/validasi/' . $v2 . '/tolak', ['_version' => $vv, 'note' => 'Bukti setoran tidak ada']);
check('validasi/tolak: DITOLAK dengan alasan; saldo tidak berubah; audit TRX_REJECTED', $str('SELECT status FROM transactions WHERE id = ?', [$v2]) === 'DITOLAK' && $audits('TRX_REJECTED') === 1 && $count('SELECT COUNT(*) FROM v_ledger WHERE transaction_id = ?', [$v2]) === 0);
$dr = $sb->get('/transaksi/' . $v2);
check('validasi/tolak: pembuat melihat alasan penolakan; tidak ada tombol aksi; tak bisa dibatalkan', $has($dr, 'Bukti setoran tidak ada') && $has($dr, 'ditolak') && !$has($dr, 'Batalkan transaksi') && !$has($dr, 'Ajukan ke validasi'));
check('validasi/tolak: yang ditolak final: tidak bisa disetujui atau diajukan lagi', $has($sp->get($loc($post($sp, '/validasi/' . $v2 . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$v2])]))), 'sudah ditolak') && $str('SELECT status FROM transactions WHERE id = ?', [$v2]) === 'DITOLAK');

// pencairan: kas diperiksa saat setuju
Database::pdo()->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'loan_max_amount'");
$kasNow = $count('SELECT kas_tersedia FROM v_global_summary');
Database::pdo()->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'allow_negative_cash'");   // longgar HANYA agar draft yang melebihi kas bisa diajukan
$sa->get('/transaksi/pinjaman/baru');
$sa->post('/transaksi/pinjaman', ['_form_id' => $tokenOf($sa->last), 'member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth, 'principal' => number_format($kasNow + 1000, 0, ',', '.'), 'tenor' => '2', 'trx_date' => $today, 'description' => '', 'action' => 'submit', 'confirm_duplicate' => '1']);
Database::pdo()->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'allow_negative_cash'");
$bigLoan = $count("SELECT MAX(id) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'");
check('validasi/pencairan: pinjaman melebihi kas (kas + 1.000) menunggu validasi', $str('SELECT status FROM transactions WHERE id = ?', [$bigLoan]) === 'MENUNGGU_VALIDASI' && $count('SELECT amount FROM transactions WHERE id = ?', [$bigLoan]) === $kasNow + 1000);
$dl = $sh->get('/transaksi/' . $bigLoan);
check('validasi/pencairan: panel menampilkan kas, pencairan, kas sesudahnya, dan peringatan kas tidak cukup', $has($dl, 'Kas tersedia') && $has($dl, 'Kas sesudahnya') && $has($dl, 'Kas tidak cukup') && $has($dl, '/validasi/' . $bigLoan . '/setujui'));
$post($sh, '/validasi/' . $bigLoan . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$bigLoan])]);
check('validasi/pencairan: setuju ditolak karena kas tidak cukup, pesan memuat angka, status tetap, kas utuh', $has($sh->get('/transaksi/' . $bigLoan), 'Kas tersedia tidak cukup') && $str('SELECT status FROM transactions WHERE id = ?', [$bigLoan]) === 'MENUNGGU_VALIDASI' && $count('SELECT kas_tersedia FROM v_global_summary') === $kasNow);
// pinjaman yang muat: tolak yang besar, ajukan kecil lalu setujui
$post($sp, '/validasi/' . $bigLoan . '/tolak', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$bigLoan]), 'note' => 'Kas belum cukup']);
$sa->get('/transaksi/pinjaman/baru');
$sa->post('/transaksi/pinjaman', ['_form_id' => $tokenOf($sa->last), 'member_id' => (string) $mid(2), 'period_month_id' => (string) $payMonth, 'principal' => '1.000.000', 'tenor' => '2', 'trx_date' => $today, 'description' => '', 'action' => 'submit', 'confirm_duplicate' => '1']);
$okLoan = $count("SELECT MAX(id) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'");
$kasL = $count('SELECT kas_tersedia FROM v_global_summary');
$post($sh, '/validasi/' . $okLoan . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$okLoan])]);
check('validasi/pencairan: pinjaman yang muat disetujui: kas turun 1.000.000, piutang naik 1.040.000, selisih 0, integritas bersih', $str('SELECT status FROM transactions WHERE id = ?', [$okLoan]) === 'DISETUJUI' && $count('SELECT kas_tersedia FROM v_global_summary') === $kasL - 1000000 && $count('SELECT selisih FROM v_global_summary') === 0 && $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);
check('validasi/pencairan: pinjaman disetujui muncul di daftar Pinjaman dengan sisa tagihan', $has($sa->get('/transaksi/pinjaman'), 'Rp 1.040.000'));

// riwayat
$rh = $sh->get('/validasi/riwayat');
check('validasi/riwayat: memuat keputusan (setuju dan tolak), validator, dan catatan', $has($rh, 'Disetujui') && $has($rh, 'Ditolak') && $has($rh, 'Kepala Koperasi') && $has($rh, 'Pemeriksa Uji') && $has($rh, 'Bukti setoran tidak ada') && $has($rh, 'OK sesuai buku'));
check('validasi/riwayat: filter keputusan, jenis, cari; wildcard dan nilai ngawur aman', $has($sh->get('/validasi/riwayat?keputusan=DITOLAK'), 'Bukti setoran tidak ada') && !$has($sh->last, 'OK sesuai buku') && $sh->get('/validasi/riwayat?jenis=PENCAIRAN_PINJAMAN&q=Alfa')['status'] === 200 && $has($sh->get('/validasi/riwayat?q=%25'), 'Belum ada keputusan validasi') && $sh->get('/validasi/riwayat?keputusan=x&jenis=x&page=-2')['status'] === 200);

// kebersihan halaman baru
$pages4 = '';
foreach ([['/validasi', $sh], ['/validasi/riwayat', $sh], ['/transaksi/' . $count("SELECT MAX(id) FROM transactions WHERE status = 'MENUNGGU_VALIDASI' AND type = 'SIMPANAN'") . '', $sp]] as [$path, $br]) {
    $pages4 .= $br->get($path)['body'];
}
$pages4 .= $sh->get('/transaksi/' . $v3)['body'];
check('validasi/umum: tanpa <script>, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $pages4));
check('validasi/umum: integritas basis data tetap bersih', $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);
Database::pdo()->exec("UPDATE settings SET setting_value = '15000000' WHERE setting_key = 'loan_max_amount'");

// ---- 12j2. koreksi dengan transaksi pembalik (Phase 10) ----
$revCount = fn (): int => $count('SELECT COUNT(*) FROM transactions WHERE reverses_id IS NOT NULL');
$cashNow = fn (): int => $count('SELECT kas_tersedia FROM v_global_summary');
$verOf = fn (int $id): string => $str('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
$revOf = fn (int $id): int => $count("SELECT COALESCE(MAX(id), 0) FROM transactions WHERE reverses_id = ? AND status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI')", [$id]);

// akses
check('koreksi/akses: tamu dialihkan ke login; tanpa token CSRF = 419', (new Browser())->post('/transaksi/' . $v1 . '/koreksi', ['note' => 'x'], false)['status'] === 419 && $revCount() === 0);
check('koreksi/akses: Head 403 (tidak punya izin mencatat), Anggota 403', $post($sh, '/transaksi/' . $v1 . '/koreksi', ['note' => 'x'])['status'] === 403 && $post($sm, '/transaksi/' . $v1 . '/koreksi', ['note' => 'x'])['status'] === 403 && $post($sp, '/transaksi/' . $v1 . '/koreksi', ['note' => 'x'])['status'] === 403 && $revCount() === 0);
check('koreksi/akses: Ketua Regu lain 404 (di luar cakupan, sama dengan tidak ada), tidak ada pembalik', $post($sb, '/transaksi/' . $v1 . '/koreksi', ['note' => 'iseng'])['status'] === 404 && $post($sa, '/transaksi/999999/koreksi', ['note' => 'x'])['status'] === 404 && $revCount() === 0);
check('koreksi/akses: GET ke alamat koreksi tidak ada (hanya POST)', in_array($sa->get('/transaksi/' . $v1 . '/koreksi')['status'], [404, 405], true));

// tampilan di transaksi yang sudah disetujui
$d1 = $sa->get('/transaksi/' . $v1);
check('koreksi/tampil: Ketua Regu pemilik melihat kartu Koreksi dengan formulir alasan', $has($d1, 'id="koreksi"') && $has($d1, '/transaksi/' . $v1 . '/koreksi') && $has($d1, 'Alasan koreksi') && $has($d1, 'transaksi pembalik'));
check('koreksi/tampil: Head, Pemeriksa, dan Anggota tidak melihat formulir koreksi', !$has($sh->get('/transaksi/' . $v1), '/koreksi') && !$has($sp->get('/transaksi/' . $v1), '/koreksi') && !$has($sm->get('/transaksi/' . $v1), '/koreksi'));
check('koreksi/tampil: transaksi yang belum disetujui (draft/menunggu/ditolak) tidak menawarkan koreksi', !$has($sb->get('/transaksi/' . $v2), '/koreksi'));

// ajukan: alasan wajib
$post($sa, '/transaksi/' . $v1 . '/koreksi', ['note' => '   ']);
check('koreksi/ajukan: tanpa alasan ditolak dengan pesan, tidak ada pembalik', $has($sa->get('/transaksi/' . $v1), 'Alasan koreksi wajib diisi') && $revCount() === 0);
$kasBeforeRev = $cashNow();
$savBefore = $count('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$mid(2)]);
$rq = $post($sa, '/transaksi/' . $v1 . '/koreksi', ['note' => 'Salah anggota <b>x</b>']);
$rv1 = $revOf($v1);
check('koreksi/ajukan: berhasil, dialihkan ke detail pembalik yang MENUNGGU_VALIDASI', $rq['status'] === 302 && $rv1 > 0 && $loc($rq) === '/transaksi/' . $rv1 && $str('SELECT status FROM transactions WHERE id = ?', [$rv1]) === 'MENUNGGU_VALIDASI' && $revCount() === 1);
$drv = $sa->get('/transaksi/' . $rv1);
check('koreksi/ajukan: detail pembalik menautkan transaksi asal, memuat alasan sebagai teks, nominal negatif, dan pesan sukses', $has($drv, 'Koreksi diajukan ke validasi') && $has($drv, '/transaksi/' . $v1) && $has($drv, 'Membalik') && $has($drv, 'Salah anggota &lt;b&gt;x&lt;/b&gt;') && $has($drv, '-Rp 12.345'));
check('koreksi/ajukan: saldo dan transaksi asal tidak berubah selama menunggu', $cashNow() === $kasBeforeRev && $count('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$mid(2)]) === $savBefore && $str('SELECT status FROM transactions WHERE id = ?', [$v1]) === 'DISETUJUI');
$d1b = $sa->get('/transaksi/' . $v1);
check('koreksi/ajukan: transaksi asal memberitahu ada koreksi yang menunggu dan tidak lagi menawarkan formulir', $has($d1b, 'sedang menunggu validasi') && $has($d1b, $str('SELECT doc_no FROM transactions WHERE id = ?', [$rv1])) && !$has($d1b, 'Ajukan koreksi (pembalik)'));
$again = $post($sa, '/transaksi/' . $v1 . '/koreksi', ['note' => 'Lagi']);
check('koreksi/ajukan: kirim ulang tidak menggandakan (pembalik hidup hanya satu)', $has($sa->get($loc($again)), 'sudah punya pembalik') && $revCount() === 1);
check('koreksi/audit: REVERSAL_CREATED dan REVERSAL_REQUESTED tercatat', $audits('REVERSAL_CREATED') === 1 && $audits('REVERSAL_REQUESTED') === 1);

// validasi
check('koreksi/validasi: pembuat tidak melihat panel validasi dan tidak bisa menyetujui', !$has($drv, 'id="validasi"') && $post($sa, '/validasi/' . $rv1 . '/setujui', ['_version' => $verOf($rv1)])['status'] === 403);
check('koreksi/validasi: antrean Head memuat pembalik', $has($sh->get('/validasi'), $str('SELECT doc_no FROM transactions WHERE id = ?', [$rv1])));
$dh1 = $sh->get('/transaksi/' . $rv1);
check('koreksi/validasi: Head melihat panel dengan alasan, dampak kas dan tabungan, dan tombol Setujui/Tolak', $has($dh1, 'id="validasi"') && $has($dh1, 'transaksi pembalik') && $has($dh1, 'Perubahan kas') && $has($dh1, 'Kas sesudahnya') && $has($dh1, 'Saldo tabungan anggota') && $has($dh1, 'Salah anggota &lt;b&gt;x&lt;/b&gt;') && $has($dh1, '/validasi/' . $rv1 . '/setujui'));
$post($sh, '/validasi/' . $rv1 . '/setujui', ['_version' => $verOf($rv1), 'note' => 'Benar']);
check('koreksi/setuju: DISETUJUI; tabungan anggota dan kas turun 12.345; selisih 0; integritas bersih', $str('SELECT status FROM transactions WHERE id = ?', [$rv1]) === 'DISETUJUI' && $cashNow() === $kasBeforeRev - 12345
    && $count('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$mid(2)]) === $savBefore - 12345 && $count('SELECT selisih FROM v_global_summary') === 0 && $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);
$d1c = $sa->get('/transaksi/' . $v1);
check('koreksi/setuju: transaksi asal menyatakan sudah dibalik (tetap tersimpan), tanpa formulir; pembalik menyatakan mengurangi saldo', $has($d1c, 'sudah dibalik') && $has($d1c, 'Disetujui') && !$has($d1c, 'Ajukan koreksi (pembalik)') && $has($sa->get('/transaksi/' . $rv1), 'sudah disetujui dan mengurangi saldo'));
check('koreksi/setuju: transaksi asal di daftar simpanan tetap ada; pembalik tampil bertanda Pembalik', $has($sa->get('/transaksi/riwayat'), $str('SELECT doc_no FROM transactions WHERE id = ?', [$v1])) && $has($sa->last, 'Pembalik'));
check('koreksi/setuju: audit TRX_APPROVED untuk pembalik; pembalik tidak bisa dibalik lagi', $audits('TRX_APPROVED') >= 3 && $has($sa->get($loc($post($sa, '/transaksi/' . $rv1 . '/koreksi', ['note' => 'x']))), 'tidak bisa dibalik'));

// terkait Head: hanya Pemeriksa; ditolak lalu diajukan ulang
$post($sa, '/transaksi/' . $v3 . '/koreksi', ['note' => 'Anggota salah']);
$rv3 = $revOf($v3);
$dh3 = $sh->get('/transaksi/' . $rv3);
check('koreksi/head: pembalik transaksi atas nama anggota tertaut Head: Head tanpa tombol, Pemeriksa dengan tombol', $rv3 > 0 && $has($dh3, 'hanya Pemeriksa') && !$has($dh3, '/validasi/' . $rv3 . '/setujui') && $has($sp->get('/transaksi/' . $rv3), '/validasi/' . $rv3 . '/setujui'));
$post($sp, '/validasi/' . $rv3 . '/tolak', ['_version' => $verOf($rv3), 'note' => 'Belum perlu dikoreksi']);
check('koreksi/tolak: pembalik DITOLAK; transaksi asal tetap berlaku', $str('SELECT status FROM transactions WHERE id = ?', [$rv3]) === 'DITOLAK' && $str('SELECT status FROM transactions WHERE id = ?', [$v3]) === 'DISETUJUI' && $revOf($v3) === 0);
$d3a = $sa->get('/transaksi/' . $v3);
check('koreksi/tolak: asal menampilkan percobaan yang ditolak beserta alasannya dan menawarkan formulir lagi', $has($d3a, 'Ditolak') && $has($d3a, 'Anggota salah') && $has($d3a, 'Ajukan koreksi (pembalik)'));
$post($sa, '/transaksi/' . $v3 . '/koreksi', ['note' => 'Ajukan ulang']);
$rv3b = $revOf($v3);
check('koreksi/tolak: bisa diajukan ulang dan kali ini disetujui Pemeriksa', $rv3b > 0 && $rv3b !== $rv3);
$post($sp, '/validasi/' . $rv3b . '/setujui', ['_version' => $verOf($rv3b)]);
check('koreksi/tolak: pembalik kedua disetujui; selisih 0, integritas bersih', $str('SELECT status FROM transactions WHERE id = ?', [$rv3b]) === 'DISETUJUI' && $count('SELECT selisih FROM v_global_summary') === 0 && $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);

// batalkan pembalik yang menunggu (oleh pembuat), lalu transaksi asal bebas lagi
$vNew = $mkSaving($sa, $mid(2), '4.567');
$post($sh, '/validasi/' . $vNew . '/setujui', ['_version' => $verOf($vNew)]);
$post($sa, '/transaksi/' . $vNew . '/koreksi', ['note' => 'Coba']);
$rvNew = $revOf($vNew);
$post($sa, '/transaksi/' . $rvNew . '/batal', ['_version' => $verOf($rvNew), 'note' => 'Tidak jadi']);
check('koreksi/batal: pembuat membatalkan pembalik yang menunggu; asal bisa dikoreksi lagi', $str('SELECT status FROM transactions WHERE id = ?', [$rvNew]) === 'DIBATALKAN' && $has($sa->get('/transaksi/' . $vNew), 'Ajukan koreksi (pembalik)'));

// kas tidak cukup menahan koreksi dengan penjelasan
$sa->get('/');
$vBig = $mkSaving($sa, $mid(2), '2.000.000');
$post($sh, '/validasi/' . $vBig . '/setujui', ['_version' => $verOf($vBig)]);
$drain = $cashNow() - 1000;
Database::pdo()->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, created_by) VALUES ('T-DRAIN','DRAIN-1','BIAYA',NULL,1,{$payMonth},CURDATE(),{$drain},1)");
$drainId = (int) Database::pdo()->lastInsertId();
Database::pdo()->exec("UPDATE transactions SET status='MENUNGGU_VALIDASI' WHERE id={$drainId}");
Database::pdo()->exec("UPDATE transactions SET status='DISETUJUI' WHERE id={$drainId}");
$dBig = $sa->get('/transaksi/' . $vBig);
check('koreksi/kas: kas hanya Rp 1.000 -> formulir diganti penjelasan "Kas tersedia", pengajuan ditolak server', $has($dBig, 'Koreksi belum bisa diajukan') && $has($dBig, 'Kas tersedia') && !$has($dBig, 'Ajukan koreksi (pembalik)')
    && $has($sa->get($loc($post($sa, '/transaksi/' . $vBig . '/koreksi', ['note' => 'x']))), 'Kas tersedia') && $revOf($vBig) === 0);

// kebersihan
$pagesR = $sa->get('/transaksi/' . $v1)['body'] . $sh->get('/transaksi/' . $rv1)['body'] . $sa->get('/transaksi/' . $v3)['body'] . $sp->get('/transaksi/' . $rv3b)['body'];
check('koreksi/umum: tanpa <script>, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $pagesR));

// ---- 12j3. dashboard per peran (Phase 11) ----
$rp = static fn (int $n): string => ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.');
$dashPending = $mkSaving($sa, $mid(2), '1.111');
$dHead = $sh->get('/');
$g = Database::pdo()->query('SELECT * FROM v_global_summary')->fetch();
check('dashboard/head: ringkasan koperasi dengan angka dari view saldo (kas, tabungan, piutang)', $dHead['status'] === 200 && $has($dHead, 'Ringkasan koperasi') && $has($dHead, $rp((int) $g['kas_tersedia'])) && $has($dHead, $rp((int) $g['saldo_tabungan'])) && $has($dHead, $rp((int) $g['piutang_beredar'])) && $has($dHead, 'Selisih Rp 0'));
check('dashboard/head: grafik (canvas bertabel), skrip Chart.js lokal, tanpa CDN', substr_count($dHead['body'], 'data-chart="') >= 3 && $has($dHead, 'Lihat angka') && $has($dHead, '/assets/vendor/chart.umd.min.js') && $has($dHead, '/assets/js/charts.js') && !preg_match('#https?://(?!localhost|127\.0\.0\.1)#', preg_replace('#<a [^>]*>#', '', $dHead['body'])));
check('dashboard/head: antrean validasi diberitahukan dengan tautan; ringkasan per regu; transaksi terbaru', $has($dHead, 'menunggu validasi') && $has($dHead, 'href="/validasi"') && $has($dHead, 'Regu Alfa') && $has($dHead, 'Regu Beta') && $has($dHead, 'Transaksi terbaru') && $has($dHead, $str('SELECT doc_no FROM transactions WHERE id = ?', [$dashPending])));
check('dashboard/head: tidak ada bagian Regu saya, tombol catat, atau cakupan lain', !$has($dHead, 'id="regu-saya"') && !$has($dHead, 'Catat simpanan') && !$has($dHead, 'Tabungan dan pinjaman saya'));
$dPer = $sp->get('/');
check('dashboard/pemeriksa: ringkasan global yang sama, tanpa tombol catat', $dPer['status'] === 200 && $has($dPer, 'Ringkasan koperasi') && !$has($dPer, 'Catat simpanan') && !$has($dPer, 'id="regu-saya"'));
$dAlfa = $sa->get('/');
check('dashboard/ketua regu: hanya regunya (anggota Alfa), tombol catat, pekerjaan sendiri; tanpa ringkasan koperasi', $dAlfa['status'] === 200 && $has($dAlfa, 'id="regu-saya"') && $has($dAlfa, 'Anggota Alfa Dua') && $has($dAlfa, 'Catat simpanan') && $has($dAlfa, 'menunggu validasi') && !$has($dAlfa, 'Ringkasan koperasi') && !$has($dAlfa, 'Kas tersedia'));
check('dashboard/ketua regu: anggota dan transaksi regu lain TIDAK tampil (Alfa tak melihat Beta)', !$has($dAlfa, 'Anggota Beta Dua') && !$has($dAlfa, 'Ketua Regu Beta') && !$has($dAlfa, 'Regu Beta'));
$dBeta = $sb->get('/');
check('dashboard/ketua regu: Beta melihat regunya sendiri dan tidak melihat Alfa', $has($dBeta, 'Anggota Beta Dua') && !$has($dBeta, 'Anggota Alfa Dua') && !$has($dBeta, $str('SELECT doc_no FROM transactions WHERE id = ?', [$dashPending])));
$dMe = $sm->get('/');
check('dashboard/anggota: tabungan dan pinjaman sendiri saja, tanpa regu atau ringkasan koperasi', $dMe['status'] === 200 && $has($dMe, 'Tabungan dan pinjaman saya') && $has($dMe, 'Saldo tabungan') && !$has($dMe, 'id="regu-saya"') && !$has($dMe, 'Ringkasan koperasi') && !$has($dMe, 'Anggota Alfa Dua') && !$has($dMe, 'Catat simpanan'));
$post($sa, '/transaksi/' . $dashPending . '/batal', ['_version' => $verOf($dashPending), 'note' => 'Hanya untuk uji dashboard']);

// Head + Ketua Regu (seperti Purwati): ringkasan global ditambah bagian Regu saya
Database::pdo()->exec("INSERT INTO user_roles (user_id, role_id) SELECT u.id, r.id FROM users u, roles r WHERE u.username = 'alfa' AND r.code = 'HEAD'");
$dBoth = $sa->get('/');
Database::pdo()->exec("DELETE ur FROM user_roles ur JOIN users u ON u.id = ur.user_id JOIN roles r ON r.id = ur.role_id WHERE u.username = 'alfa' AND r.code = 'HEAD'");
check('dashboard/head+ketua regu: ringkasan global dan Regu saya sekaligus', $has($dBoth, 'Ringkasan koperasi') && $has($dBoth, 'id="regu-saya"') && $has($dBoth, 'Anggota Alfa Dua') && $has($dBoth, 'Regu Beta'));
check('dashboard/head+ketua regu: peran dikembalikan, tampilan Ketua Regu biasa lagi', !$has($sa->get('/'), 'Ringkasan koperasi'));

// aset grafik
$vendor = $sh->get('/assets/vendor/chart.umd.min.js');
$charts = $sh->get('/assets/js/charts.js');
check('dashboard/aset: Chart.js 4.4.7 disajikan lokal dan utuh (hash dipatok), charts.js tersedia tanpa kode inline', $vendor['status'] === 200 && str_contains($vendor['body'], 'Chart.js v4.4.7')
    && hash('sha256', file_get_contents(BASE_PATH . '/public/assets/vendor/chart.umd.min.js')) === '2812cb8825fdc57469eb2f7bb055e9429244e599920511ee477e828499b632cb' && $charts['status'] === 200 && $has($charts, 'data-chart'));
$dashPages = $dHead['body'] . $dPer['body'] . $dAlfa['body'] . $dMe['body'];
check('dashboard/umum: tanpa <script> inline, event handler, atau style inline (CSP); nama regu aman dari HTML', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $dashPages));
check('dashboard/umum: integritas basis data tetap bersih', $count('SELECT COUNT(*) FROM v_integrity_issues') === 0);

// ---- 12j4. laporan (Phase 12) ----
$repPages = ['/laporan/simpanan', '/laporan/pinjaman', '/laporan/angsuran', '/laporan/saldo', '/laporan/transaksi'];
$repKeys  = ['simpanan', 'pinjaman', 'angsuran', 'saldo', 'transaksi', 'regu'];
$guestRep = new Browser();
check('laporan/akses: tamu dialihkan ke login (halaman dan unduhan)', $guestRep->get('/laporan/simpanan')['status'] === 302 && $guestRep->get('/laporan/simpanan/unduh')['status'] === 302 && $guestRep->get('/laporan/anggota/' . $mid(2))['status'] === 302);
$okHead = true;
foreach (array_merge($repPages, ['/laporan/regu', '/laporan/anggota', '/laporan/anggota/' . $mid(2)]) as $path) {
    $okHead = $okHead && $sh->get($path)['status'] === 200 && $sp->get($path)['status'] === 200;
}
check('laporan/akses: Head dan Pemeriksa membuka semua laporan', $okHead);
$okKr = true;
foreach (array_merge($repPages, ['/laporan/anggota', '/laporan/anggota/' . $mid(2)]) as $path) {
    $okKr = $okKr && $sa->get($path)['status'] === 200;
}
check('laporan/akses: Ketua Regu membuka laporan regunya, tidak laporan per regu (403) dan tidak unduhan (403)', $okKr && $sa->get('/laporan/regu')['status'] === 403 && $sa->get('/laporan/simpanan/unduh')['status'] === 403 && $sa->get('/laporan/transaksi/unduh')['status'] === 403 && $sa->get('/laporan/anggota/unduh?anggota=' . $mid(2))['status'] === 403);
$okAng = true;
foreach ($repPages as $path) {
    $okAng = $okAng && $sm->get($path)['status'] === 200;
}
check('laporan/akses: Anggota hanya lima laporan dasar; per regu, per anggota, dan semua unduhan 403', $okAng && $sm->get('/laporan/regu')['status'] === 403 && $sm->get('/laporan/anggota')['status'] === 403 && $sm->get('/laporan/anggota/' . $mid(4))['status'] === 403 && $sm->get('/laporan/simpanan/unduh')['status'] === 403);
check('laporan/akses: laporan hanya GET (POST tidak ada)', in_array($post($sh, '/laporan/simpanan', [])['status'], [404, 405], true));
check('laporan/menu: item laporan aktif (tanpa penanda "belum dibangun")', $has($sh->get('/'), 'href="/laporan/simpanan"') && !$has($sh->last, 'P12') && $has($sa->get('/'), 'href="/laporan/anggota"') && !$has($sa->last, 'href="/laporan/regu"') && $has($sm->get('/'), 'href="/laporan/saldo"') && !$has($sm->last, 'href="/laporan/anggota"'));

// isi dan cakupan
$simH = $sh->get('/laporan/simpanan');
check('laporan/simpanan: Head melihat semua regu; judul, periode, cap cetak, tombol cetak dan unduh', $has($simH, 'Rekap Simpanan') && $has($simH, 'Anggota Alfa Dua') && $has($simH, 'Anggota Beta Dua') && $has($simH, 'Periode Uji') && $has($simH, 'Dicetak') && $has($simH, 'data-print') && $has($simH, '/laporan/simpanan/unduh'));
$simA = $sa->get('/laporan/simpanan');
check('laporan/simpanan: Ketua Regu Alfa hanya anggota regunya; tanpa tombol unduh', $has($simA, 'Anggota Alfa Dua') && !$has($simA, 'Anggota Beta Dua') && !$has($simA, 'Ketua Regu Beta') && !$has($simA, '/unduh') && $has($simA, 'data-print'));
$simM = $sm->get('/laporan/simpanan');
check('laporan/simpanan: Anggota hanya dirinya, tanpa filter regu', $has($simM, 'Anggota Beta Dua') && !$has($simM, 'Anggota Alfa Dua') && !$has($simM, 'Ketua Regu') && !$has($simM, 'name="regu"'));
check('laporan/saringan: pemilihan regu hanya untuk Head; Ketua Regu tidak memaksa regu lain lewat URL', $has($simH, 'name="regu"') && !$has($simA, 'name="regu"') && !$has($sa->get('/laporan/simpanan?regu=' . $count("SELECT id FROM team_leaders WHERE name = 'Regu Beta'")), 'Anggota Beta Dua'));
check('laporan/saringan: nilai ngawur aman (periode, halaman, status, tanggal) dan pencarian tidak menjadi wildcard', $sh->get('/laporan/simpanan?periode=999&page=-5&status=zzz&dari=bukan-tanggal')['status'] === 200 && $sh->get('/laporan/transaksi?page=9999&jenis=x')['status'] === 200 && $has($sh->get('/laporan/simpanan?q=%25'), 'Tidak ada data') && $has($sh->get("/laporan/simpanan?q=%27%20OR%20%271%27%3D%271"), 'Tidak ada data'));
check('laporan/saringan: isian pencarian dipantulkan sebagai teks (XSS)', !$has($sh->get('/laporan/simpanan?q=' . rawurlencode('<script>alert(1)</script>')), '<script>alert(1)') && $has($sh->last, '&lt;script&gt;alert(1)&lt;/script&gt;'));
check('laporan/transaksi: default hanya Disetujui; filter jenis dan tanggal; memuat simpanan yang disetujui', $has($sh->get('/laporan/transaksi'), 'Laporan Transaksi') && $has($sh->last, 'Disetujui') && !$has($sh->last, '<td>Menunggu Validasi</td>') && $has($sh->get('/laporan/transaksi?jenis=SIMPANAN&dari=2026-01-01&sampai=2099-01-01&status='), 'Simpanan'));
check('laporan/pinjaman dan regu: memuat pinjaman yang berlaku dan ringkasan per regu', $has($sh->get('/laporan/pinjaman'), 'Rekap Pinjaman') && $has($sh->get('/laporan/regu'), 'Regu Alfa') && $has($sh->last, 'Regu Beta') && $has($sh->last, 'Ketua regu'));

// kartu anggota
$kartuH = $sh->get('/laporan/anggota/' . $mid(2));
check('laporan/anggota: daftar dengan pencarian; kartu memuat saldo, per bulan, transaksi, tombol cetak dan unduh', $has($sh->get('/laporan/anggota?q=Alfa'), 'Anggota Alfa Dua') && !$has($sh->last, 'Anggota Beta Dua') && $has($kartuH, 'Kartu anggota') && $has($kartuH, 'Saldo tabungan') && $has($kartuH, 'Semua transaksi') && $has($kartuH, 'data-print') && $has($kartuH, '/laporan/anggota/unduh?anggota=' . $mid(2)));
$auditDenied = $audits('ACCESS_DENIED_SCOPE');
check('laporan/anggota: Ketua Regu Alfa tidak bisa membuka anggota Beta (404 sama dengan tidak ada, tercatat); anggota tidak ada 404', $sa->get('/laporan/anggota/' . $mid(4))['status'] === 404 && $sa->get('/laporan/anggota/99999999')['status'] === 404 && $audits('ACCESS_DENIED_SCOPE') === $auditDenied + 1);
check('laporan/anggota: Ketua Regu Alfa hanya mendaftar anggota regunya', $has($sa->get('/laporan/anggota'), 'Anggota Alfa Dua') && !$has($sa->last, 'Anggota Beta Dua'));

// unduhan CSV
$auditExport = $audits('REPORT_EXPORTED');
$csvH = $sh->get('/laporan/simpanan/unduh');
check('laporan/unduh: CSV UTF-8 ber-BOM, lampiran bernama laporan-simpanan-tanggal.csv, tidak di-cache, berisi anggota', $csvH['status'] === 200 && str_contains($csvH['headers']['content-type'] ?? '', 'text/csv') && str_contains($csvH['headers']['content-disposition'] ?? '', 'attachment; filename="laporan-simpanan-' . date('Y-m-d') . '.csv"')
    && str_contains(strtolower($csvH['headers']['cache-control'] ?? ''), 'no-store') && str_starts_with($csvH['body'], "\xEF\xBB\xBF") && $has($csvH, 'AGT-002') && $has($csvH, ';'));
check('laporan/unduh: tercatat di audit log (siapa, laporan apa, jumlah baris)', $audits('REPORT_EXPORTED') === $auditExport + 1 && $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'REPORT_EXPORTED' AND username = 'kepala' AND reference_no = 'simpanan' AND after_data LIKE '%\"rows\":%'") === 1);
$allCsv = true;
foreach ($repKeys as $k) {
    $r = $sp->get('/laporan/' . $k . '/unduh');
    $allCsv = $allCsv && $r['status'] === 200 && str_starts_with($r['body'], "\xEF\xBB\xBF");
}
check('laporan/unduh: Pemeriksa mengunduh keenam laporan; kunci laporan tidak dikenal 404; kartu anggota tanpa anggota 404', $allCsv && $sh->get('/laporan/rahasia/unduh')['status'] === 404 && $sh->get('/laporan/anggota/unduh')['status'] === 404 && $sh->get('/laporan/anggota/unduh?anggota=99999999')['status'] === 404);
$csvK = $sh->get('/laporan/anggota/unduh?anggota=' . $mid(2));
check('laporan/unduh: transaksi satu anggota; nama berkas memuat nomor anggota', $csvK['status'] === 200 && str_contains($csvK['headers']['content-disposition'] ?? '', 'laporan-anggota-AGT-002-') && $has($csvK, 'Dokumen'));
check('laporan/unduh: saringan ikut terbawa (jenis=ANGSURAN hanya baris angsuran)', (function () use ($sh): bool {
    $r = $sh->get('/laporan/transaksi/unduh?jenis=ANGSURAN&status=');
    $rows = array_slice(explode("\n", rtrim($r['body'], "\n")), 1);
    foreach ($rows as $line) {
        if ($line !== '' && !str_contains($line, 'Angsuran') && !str_contains($line, 'Jumlah')) {
            return false;
        }
    }
    return $r['status'] === 200;
})());
$repHtml = $simH['body'] . $simA['body'] . $kartuH['body'] . $sh->get('/laporan/transaksi')['body'] . $sh->get('/laporan/regu')['body'];
check('laporan/umum: tanpa <script> inline, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $repHtml));
check('laporan/umum: laporan tidak mengubah data (integritas bersih, selisih 0)', $count('SELECT COUNT(*) FROM v_integrity_issues') === 0 && $count('SELECT selisih FROM v_global_summary') === 0);

// ---- 12j5. audit log (Phase 13) ----
check('audit/akses: tamu dialihkan ke login', (new Browser())->get('/sistem/audit')['status'] === 302 && (new Browser())->get('/sistem/audit/1')['status'] === 302 && (new Browser())->get('/sistem/audit/unduh')['status'] === 302);
$auditId = $count("SELECT MAX(id) FROM audit_logs WHERE action = 'TRX_APPROVED'");
check('audit/akses: Head dan Pemeriksa membuka daftar, rincian, dan unduhan', $sh->get('/sistem/audit')['status'] === 200 && $sp->get('/sistem/audit')['status'] === 200 && $sh->get('/sistem/audit/' . $auditId)['status'] === 200 && $sp->get('/sistem/audit/' . $auditId)['status'] === 200 && $sp->get('/sistem/audit/unduh')['status'] === 200);
$denied = $audits('ACCESS_DENIED');
check('audit/akses: Ketua Regu dan Anggota 403 di daftar, rincian, dan unduhan; penolakannya tercatat', $sa->get('/sistem/audit')['status'] === 403 && $sa->get('/sistem/audit/' . $auditId)['status'] === 403 && $sa->get('/sistem/audit/unduh')['status'] === 403 && $sm->get('/sistem/audit')['status'] === 403 && $sm->get('/sistem/audit/unduh')['status'] === 403 && $audits('ACCESS_DENIED') >= $denied + 5);
check('audit/akses: layar hanya GET (POST tidak ada); rincian yang tidak ada 404', in_array($post($sh, '/sistem/audit', [])['status'], [404, 405], true) && $sh->get('/sistem/audit/99999999')['status'] === 404 && $sh->get('/sistem/audit/abc')['status'] === 404);
check('audit/menu: Head dan Pemeriksa melihat Audit Log aktif (tanpa penanda belum dibangun); Ketua Regu dan Anggota tidak', $has($sh->get('/'), 'href="/sistem/audit"') && !$has($sh->last, 'P13') && $has($sp->get('/'), 'href="/sistem/audit"') && !$has($sa->get('/'), 'href="/sistem/audit"') && !$has($sm->get('/'), 'href="/sistem/audit"'));

$aList = $sh->get('/sistem/audit');
check('audit/daftar: judul, kartu ringkasan, label Indonesia, kode aksi, pengguna, tautan rincian, tombol unduh', $has($aList, 'Audit Log') && $has($aList, 'Seluruh catatan') && $has($aList, 'Gagal masuk (24 jam)') && $has($aList, 'Transaksi disetujui') && $has($aList, 'TRX_APPROVED') && $has($aList, 'kepala') && $has($aList, '/sistem/audit/' . $auditId) && $has($aList, 'Unduh CSV'));
check('audit/daftar: akses ditolak tampil sebagai peristiwa keamanan (lencana)', $has($aList, 'Akses ditolak (tanpa izin)') && $has($aList, 'badge--ditolak'));
check('audit/saringan: aksi, kelompok, pengguna, tanggal; nilai ngawur dan halaman di luar batas aman', $has($sh->get('/sistem/audit?aksi=LOGIN_SUCCESS'), 'Masuk berhasil') && !$has($sh->last, '<span class="muted">TRX_APPROVED</span>') && $has($sh->get('/sistem/audit?kelompok=keamanan'), 'Akses ditolak') && !$has($sh->last, '<span class="muted">LOGIN_SUCCESS</span>')
    && $sh->get('/sistem/audit?aksi=%27+OR+1%3D1&kelompok=x&entitas=y&dari=bukan&ip=!!&page=-9')['status'] === 200 && $sh->get('/sistem/audit?page=99999')['status'] === 200 && $has($sh->get('/sistem/audit?dari=2099-01-01'), 'Tidak ada catatan'));
check('audit/saringan: pencarian bukan wildcard dan isian dipantulkan sebagai teks (XSS)', $has($sh->get('/sistem/audit?pengguna=%25'), 'Tidak ada catatan') && !$has($sh->get('/sistem/audit?pengguna=' . rawurlencode('<script>alert(1)</script>')), '<script>alert(1)') && $has($sh->last, '&lt;script&gt;alert(1)&lt;/script&gt;'));
$aShow = $sh->get('/sistem/audit/' . $auditId);
check('audit/rincian: waktu, pengguna, kode aksi, referensi menaut ke transaksi, sebelum/sesudah status', $has($aShow, 'Transaksi disetujui') && $has($aShow, 'TRX_APPROVED') && $has($aShow, 'Sebelum') && $has($aShow, 'Sesudah') && $has($aShow, 'MENUNGGU_VALIDASI') && $has($aShow, 'DISETUJUI') && $has($aShow, 'href="/transaksi/'));
$userAudit = $count("SELECT MAX(id) FROM audit_logs WHERE action = 'USER_CREATED'");
$uShow = $sh->get('/sistem/audit/' . $userAudit);
check('audit/rincian: catatan pembuatan pengguna tampil tanpa hash kata sandi', $uShow['status'] === 200 && !$has($uShow, '$2y$') && $has($uShow, 'username'));
check('audit/rincian: tidak ada satu pun halaman rincian yang memuat hash kata sandi (400 catatan terbaru diperiksa)', (function () use ($sh, $count): bool {
    $max = $count('SELECT MAX(id) FROM audit_logs');
    for ($i = max(1, $max - 400); $i <= $max; $i++) {
        if (str_contains($sh->get('/sistem/audit/' . $i)['body'], '$2y$')) {
            return false;
        }
    }
    return true;
})());

$beforeExport = $audits('AUDIT_EXPORTED');
$aCsv = $sh->get('/sistem/audit/unduh?kelompok=keamanan');
check('audit/unduh: CSV ber-BOM bernama audit-log-tanggal.csv, tidak di-cache, memuat judul kolom dan saringan terbawa', $aCsv['status'] === 200 && str_contains($aCsv['headers']['content-type'] ?? '', 'text/csv') && str_contains($aCsv['headers']['content-disposition'] ?? '', 'attachment; filename="audit-log-' . date('Y-m-d') . '.csv"')
    && str_contains(strtolower($aCsv['headers']['cache-control'] ?? ''), 'no-store') && str_starts_with($aCsv['body'], "\xEF\xBB\xBF") && $has($aCsv, 'Kode aksi') && $has($aCsv, 'ACCESS_DENIED') && !$has($aCsv, 'TRX_APPROVED'));
check('audit/unduh: unduhan itu sendiri tercatat (AUDIT_EXPORTED dengan jumlah baris dan saringan)', $audits('AUDIT_EXPORTED') === $beforeExport + 1 && $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'AUDIT_EXPORTED' AND username = 'kepala' AND after_data LIKE '%keamanan%' AND after_data LIKE '%\"rows\":%'") === 1);
check('audit/unduh: tanpa hash kata sandi', !$has($sh->get('/sistem/audit/unduh'), '$2y$'));
$auditPages = $aList['body'] . $aShow['body'] . $uShow['body'];
check('audit/umum: tanpa <script> inline, event handler, atau style inline (CSP)', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=|\sstyle="/i', $auditPages));
check('audit/umum: catatan audit tidak bisa dihapus lewat SQL (trigger)', (function (): bool {
    try {
        Database::pdo()->exec('DELETE FROM audit_logs WHERE id = 1');
        return false;
    } catch (PDOException $e) {
        return str_contains($e->getMessage(), 'append-only');
    }
})());

// ---- 12k. pembaruan langsung antar-pengguna ----
$live  = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'X-Live: 1'];
$tick  = fn (Browser $br): array => $br->request('GET', '/live/tick', [], $live);
$auditMax = fn (): int => $count('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
$mainOf = static fn (array $r): string => preg_match('#<main class="content">(.*)</main>#s', $r['body'], $m) === 1 ? $m[1] : '';
$bg = fn (Browser $br, string $path): array => $br->request('GET', $path, [], ['X-Live: 1', 'Accept: text/html']);

// denyut: hanya untuk yang login, hanya angka
$guestTick = (new Browser())->request('GET', '/live/tick', [], $live);
check('live/tick: tamu mendapat 401 JSON, bukan data', $guestTick['status'] === 401 && !str_contains($guestTick['body'], '"v"'));
foreach ([$sh, $sa, $sb, $sm, $sp] as $warm) {
    $warm->get('/');   // sesi uji hanya 4 detik: segarkan sebelum memakai denyut
}
$t0 = $tick($sh);
$t0data = (array) json_decode($t0['body'], true);
check('live/tick: pengguna login mendapat penanda "v" sama dengan penanda audit terbaru, plus "b" (angka menu) saja', $t0['status'] === 200 && ($t0data['v'] ?? null) === $auditMax() && array_keys($t0data) === ['v', 'b']);
check('live/tick: Anggota dan Ketua Regu mendapat {"v":angka,"b":{}} tanpa data lain; Pemeriksa pun boleh', $tick($sm)['body'] === '{"v":' . $auditMax() . ',"b":{}}' && $tick($sa)['body'] === '{"v":' . $auditMax() . ',"b":{}}' && $tick($sp)['status'] === 200);
check('live/tick: tidak di-cache', str_contains(strtolower($t0['headers']['cache-control'] ?? ''), 'no-store'));

// perubahan oleh pengguna lain menaikkan penanda dan muncul di halaman pengguna lain
$vBefore = (int) json_decode($tick($sh)['body'], true)['v'];
$newId   = $mkSaving($sa, $mid(2), '33.333');
$newDoc  = $str('SELECT doc_no FROM transactions WHERE id = ?', [$newId]);
$vAfter  = (int) json_decode($tick($sh)['body'], true)['v'];
check('live/tick: penanda naik setelah pengguna lain mengajukan transaksi', $vAfter > $vBefore);
check('live/tick: penanda "v" yang sama terbaca semua pengguna (tanpa tergantung cakupan data)', $tick($sb)['body'] === '{"v":' . $vAfter . ',"b":{}}');
check('live/muat: Head (pengguna lain) langsung melihat transaksi baru di antrean tanpa refresh manual', $has($bg($sh, '/validasi'), $newDoc));
check('live/muat: yang terlihat tetap mengikuti cakupan data (Ketua Beta tidak melihat milik regu Alfa)', !$has($bg($sb, '/transaksi/simpanan'), $newDoc));

// penanda halaman dan daftar halaman yang hidup
$liveVersion = static fn (array $r): int => preg_match('/data-live-version="(\d+)"/', $r['body'], $m) === 1 ? (int) $m[1] : -1;
$page = $sh->get('/validasi');
check('live/halaman: daftar baca-saja membawa wilayah live, versi, dan alamat denyut', $has($page, 'data-live-region') && $liveVersion($page) === $auditMax() && $has($page, 'data-live-tick="/live/tick"'));
$liveOk = true;
$notLiveOk = true;
foreach ([[$sh, '/'], [$sh, '/validasi'], [$sh, '/validasi/riwayat'], [$sh, '/master/anggota'], [$sh, '/master/ketua-regu'], [$sh, '/master/pengguna'], [$sh, '/transaksi/simpanan'], [$sh, '/transaksi/pinjaman'], [$sh, '/transaksi/angsuran'], [$sh, '/transaksi/angsuran/tagihan'], [$sh, '/transaksi/riwayat'], [$sh, '/transaksi/' . $newId], [$sh, '/anggota/2']] as [$br, $path]) {
    $r = $br->get($path);
    $liveOk = $liveOk && $r['status'] === 200 && $has($r, 'data-live-region');
}
foreach ([[$sa, '/transaksi/simpanan/baru'], [$sa, '/transaksi/pinjaman/baru'], [$sa, '/transaksi/angsuran/baru'], [$sa, '/transaksi/pinjaman/simulasi'], [$sh, '/sistem/pengaturan'], [$sh, '/master/pengguna/baru'], [$sh, '/master/anggota/baru'], [$sh, '/master/ketua-regu/baru'], [$sh, '/profil'], [$sh, '/profil/password']] as [$br, $path]) {
    $r = $br->get($path);
    $notLiveOk = $notLiveOk && $r['status'] === 200 && !$has($r, 'data-live-region');
}
check('live/halaman: semua halaman baca-saja memakai wilayah live', $liveOk);
check('live/halaman: formulir, simulasi, profil, dan pengaturan TIDAK live (isian tak boleh tertimpa)', $notLiveOk);
check('live/halaman: halaman error dan tamu tanpa wilayah live', !$has($sh->get('/transaksi/99999999'), 'data-live-region') && !$has((new Browser())->get('/login'), 'data-live-region'));

// dua render berturut-turut identik (jika tidak, halaman akan berganti terus-menerus)
$stable = true;
$unstable = '';
foreach ([[$sh, '/'], [$sh, '/validasi'], [$sh, '/validasi/riwayat'], [$sh, '/master/anggota'], [$sh, '/master/ketua-regu'], [$sh, '/master/pengguna'], [$sh, '/transaksi/simpanan'], [$sh, '/transaksi/pinjaman'], [$sh, '/transaksi/angsuran'], [$sh, '/transaksi/angsuran/tagihan'], [$sh, '/transaksi/riwayat'], [$sh, '/transaksi/' . $newId], [$sh, '/anggota/2'], [$sa, '/transaksi/simpanan'], [$sa, '/anggota/2'], [$sm, '/']] as [$br, $path]) {
    $one = $mainOf($br->get($path));
    $two = $mainOf($bg($br, $path));
    if ($one === '' || $one !== $two) {
        $stable = false;
        $unstable .= ' ' . $path;
    }
}
check('live/stabil: render ulang tanpa perubahan data menghasilkan isi yang sama persis' . $unstable, $stable);

// permintaan latar belakang tidak mengambil pesan sekali-tampil
$mkSaving($sa, $mid(2), '11.111');
$sa->request('GET', '/transaksi/simpanan', [], ['X-Live: 1', 'Accept: text/html']);
$firstNormal = $sa->get('/transaksi/simpanan');
$secondNormal = $sa->get('/transaksi/simpanan');
check('live/pesan: pesan sukses tetap tampil setelah permintaan latar belakang, lalu hilang sesudah dilihat', $has($firstNormal, 'alert--success') && !$has($secondNormal, 'alert--success'));

// sesi: permintaan latar belakang tidak memperpanjang sesi (idle uji 4 detik)
$idle = new Browser();
$idle->login('alfa');
$idle->get('/');
sleep(3);
$mid1 = $tick($idle);
sleep(2);
$mid2 = $tick($idle);
check('live/sesi: denyut tidak memperpanjang sesi idle (sesi tetap berakhir 4 detik setelah aktivitas terakhir)', $mid1['status'] === 200 && $mid2['status'] === 401);
$idle2 = new Browser();
$idle2->login('alfa');
$idle2->get('/');
sleep(3);
$idle2->get('/profil');
sleep(2);
check('live/sesi: aktivitas pengguna biasa tetap memperpanjang sesi (pembanding)', $tick($idle2)['status'] === 200);

// logout di tempat lain mematikan denyut
$out = (new Browser())->keepAlive('alfa');
$out->get('/');
$out->post('/logout');
check('live/sesi: setelah keluar, denyut ditolak 401', $tick($out)['status'] === 401);

// aset
$js  = $sh->get('/assets/js/app.js');
$css = $sh->get('/assets/css/app.css');
check('live/aset: skrip memuat dropdown pencarian dan pembaruan langsung; CSS memuat gayanya', $js['status'] === 200 && $has($js, 'data-live-region') && $has($js, 'ss__search') && $css['status'] === 200 && $has($css, '.ss__panel') && $has($css, '.toast'));

// ---- 12l. penanda jumlah di menu (Phase 14) ----
foreach ([$sh, $sh3, $sp, $sa, $sm] as $warm) {
    $warm->get('/');
}
$badgeOf     = fn (Browser $br): ?int => ((array) (json_decode($tick($br)['body'], true)['b'] ?? []))['validasi'] ?? null;
$eligibleIn  = static fn (array $r): int => (int) preg_match_all('/badge--disetujui">Bisa divalidasi</', $r['body']);
$menuBadge   = static fn (array $r): ?int => preg_match('/data-badge="validasi"[^>]*>\s*<bdi data-badge-n>(\d+)</', $r['body'], $m) === 1 ? (int) $m[1] : null;

check('badge/akses: Anggota dan Ketua Regu tidak mendapat penanda validasi (denyut kosong, menu tanpa penanda)', $badgeOf($sm) === null && $badgeOf($sa) === null && !$has($sa->get('/'), 'data-badge=') && !$has($sm->get('/'), 'data-badge='));
check('badge/akses: Head dan Pemeriksa mendapat angka bulat', is_int($badgeOf($sh)) && is_int($badgeOf($sp)) && is_int($badgeOf($sh3)));
$qSql = "SELECT COUNT(*) FROM transactions WHERE status = 'MENUNGGU_VALIDASI' AND deleted_at IS NULL";
check('badge/angka: sama dengan jumlah baris "Bisa divalidasi" di antrean masing-masing (Head, Head tertaut anggota, Pemeriksa)', $count($qSql) <= 20 && $badgeOf($sh) === $eligibleIn($sh->get('/validasi')) && $badgeOf($sh3) === $eligibleIn($sh3->get('/validasi')) && $badgeOf($sp) === $eligibleIn($sp->get('/validasi')));
check('badge/menu: angka di menu halaman biasa sama dengan angka denyut (terisi sejak render awal, tanpa menunggu skrip)', $menuBadge($sh->get('/')) === $badgeOf($sh) && $menuBadge($sp->get('/profil')) === $badgeOf($sp));

// transaksi biasa menambah untuk semua validator; transaksi terkait Head hanya menambah Pemeriksa
[$bh0, $bh30, $bp0] = [$badgeOf($sh), $badgeOf($sh3), $badgeOf($sp)];
$plain = $mkSaving($sa, $mid(2), '14.141');
[$bh1, $bh31, $bp1] = [$badgeOf($sh), $badgeOf($sh3), $badgeOf($sp)];
check('badge/naik: transaksi biasa baru menambah 1 untuk Head, Head tertaut anggota, dan Pemeriksa', $bh1 === $bh0 + 1 && $bh31 === $bh30 + 1 && $bp1 === $bp0 + 1);
$ofHead = $mkSaving($sa, $headMember, '15.151');
[$bh2, $bh32, $bp2] = [$badgeOf($sh), $badgeOf($sh3), $badgeOf($sp)];
check('badge/aturan: transaksi atas nama anggota tertaut Head hanya menambah Pemeriksa (Head tidak boleh; kepala3 atas nama sendiri)', $bh2 === $bh1 && $bh32 === $bh31 && $bp2 === $bp1 + 1);
check('badge/aturan: angka tetap sama dengan jumlah "Bisa divalidasi" di antrean setelah dua transaksi baru', $count($qSql) <= 20 && $bh2 === $eligibleIn($sh->get('/validasi')) && $bp2 === $eligibleIn($sp->get('/validasi')));

// diputuskan (disetujui atau ditolak) = turun; draft dan pembatalan tidak dihitung
$post($sp, '/validasi/' . $ofHead . '/setujui', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$ofHead])]);
check('badge/turun: transaksi yang disetujui keluar dari angka Pemeriksa; angka Head tak berubah', $badgeOf($sp) === $bp2 - 1 && $badgeOf($sh) === $bh2);
$post($sh, '/validasi/' . $plain . '/tolak', ['_version' => $str('SELECT updated_at FROM transactions WHERE id = ?', [$plain]), 'note' => 'uji penanda']);
check('badge/turun: transaksi yang ditolak keluar dari angka semua validator', $badgeOf($sh) === $bh2 - 1 && $badgeOf($sh3) === $bh32 - 1 && $badgeOf($sp) === $bp2 - 2);

// permintaan latar belakang membawa penanda tapi tidak menghabiskan pesan sekali-tampil, dan angka tidak bocor lewat halaman lain
check('badge/halaman: penanda tampil di menu semua halaman aplikasi (juga formulir) dan mengarah ke antrean', $has($sh->get('/transaksi/simpanan'), 'data-badge="validasi"') && $has($sh->get('/profil'), 'data-badge="validasi"') && $has($sh->get('/'), 'href="/validasi"'));
$zero = $sh->get('/');
check('badge/nol: bila 0, penanda ada tetapi tersembunyi (atribut hidden) agar bisa muncul tanpa muat ulang', ($menuBadge($zero) ?? -1) === ($badgeOf($sh) ?? -2) && (($badgeOf($sh) ?? 1) > 0 || (bool) preg_match('/data-badge="validasi"[^>]*\shidden/', $zero['body'])));
check('badge/denyut: tanpa login tidak ada angka (401); tidak di-cache', !str_contains((new Browser())->request('GET', '/live/tick', [], $live)['body'], '"b"') && str_contains(strtolower($tick($sh)['headers']['cache-control'] ?? ''), 'no-store'));
$js  = $sh->get('/assets/js/app.js');
$css = $sh->get('/assets/css/app.css');
check('badge/aset: skrip mengenal penanda menu, judul tab, dan status koneksi; CSS memuat .nav__badge', $has($js, 'data-badge') && $has($js, 'Koneksi terputus') && $has($js, 'data-live-tick') && $has($css, '.nav__badge'));
check('badge/halaman: denyut dibaca dari <body> sehingga jalan juga di halaman tanpa wilayah live', $has($sa->get('/transaksi/simpanan/baru'), '<body data-live-tick="/live/tick">') && !$has($sa->get('/transaksi/simpanan/baru'), 'data-live-region'));

// ---- 12m. halaman galat dalam kerangka aplikasi (Phase 15) ----
$framed = static fn (array $r): bool => str_contains($r['body'], 'class="sidebar"') && str_contains($r['body'], 'error-card') && str_contains($r['body'], 'data-back');
$e403 = $sa->get('/validasi');
check('galat/403: pengguna login tetap di kerangka aplikasi (menu, bilah atas), dengan petunjuk dan tombol Kembali/Dashboard', $e403['status'] === 403 && $framed($e403) && $has($e403, 'Anda tidak memiliki akses') && $has($e403, 'hubungi Head koperasi') && $has($e403, 'Ke Dashboard') && $has($e403, 'topbar'));
check('galat/403: menu hanya memuat item sesuai peran (Ketua Regu tidak melihat Validasi/Audit) dan nama di bilah atas', !$has($e403, 'href="/validasi"') && !$has($e403, 'href="/sistem/audit"') && $has($e403, 'Ketua Alfa'));
$e404 = $sa->get('/tidak-ada/sama-sekali');
check('galat/404: kerangka aplikasi, petunjuk cakupan data, tanpa jejak tumpukan', $e404['status'] === 404 && $framed($e404) && $has($e404, 'tidak ditemukan') && $has($e404, 'cakupan data') && !$has($e404, '<pre'));
$e404b = $sa->get('/transaksi/99999999');
check('galat/404: id transaksi tak ada juga dalam kerangka', $e404b['status'] === 404 && $framed($e404b));
$e405 = $sa->request('POST', '/validasi', ['_token' => $tokenOf($sa->get('/'))], []);
check('galat/405: metode salah pada route yang ada, dalam kerangka', $e405['status'] === 405 && $framed($e405));
$e419 = $sa->post('/transaksi/simpanan', ['amount' => '1'], false);
check('galat/419: formulir kedaluwarsa dalam kerangka dengan petunjuk muat ulang', $e419['status'] === 419 && $framed($e419) && $has($e419, 'muat ulang'));
$gst404 = (new Browser())->get('/tidak-ada/sama-sekali');
check('galat/tamu: tanpa login tetap kartu tamu sederhana tanpa menu dan tanpa data aplikasi', $gst404['status'] === 404 && !$has($gst404, 'class="sidebar"') && $has($gst404, 'guest__card') && !$has($gst404, 'data-live-tick'));
$ajax = $sa->request('GET', '/validasi', [], ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
check('galat/json: permintaan AJAX tetap mendapat JSON, bukan halaman', $ajax['status'] === 403 && str_contains($ajax['headers']['content-type'] ?? '', 'application/json') && $has($ajax, '"error"') && !$has($ajax, '<html'));
check('galat/xss: jalur yang dikirim tidak dipantulkan ke halaman galat', !$has($sa->get('/%3Cscript%3Ealert(1)%3C/script%3E'), '<script>alert(1)'));
check('galat/audit: akses ditolak tetap tercatat; halaman galat tidak mengubah penanda audit', $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'ACCESS_DENIED'") >= 1);
$beforeV = $auditMax();
$sa->get('/tidak-ada/sama-sekali');
check('galat/404: membuka halaman 404 tidak menulis audit (hanya penolakan izin yang dicatat)', $auditMax() === $beforeV);
$aset = $sa->get('/assets/js/app.js');
check('galat/aset: skrip mengenal tombol Kembali; CSS memuat .error-card', $has($aset, 'data-back') && $has($sa->get('/assets/css/app.css'), '.error-card'));

// ---- 12n. responsif (Phase 15) ----
$domOf = static function (string $html): DOMXPath {
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new DOMXPath($d);
};
$respPages = ['/', '/master/ketua-regu', '/master/anggota', '/master/pengguna', '/transaksi/simpanan', '/transaksi/pinjaman', '/transaksi/angsuran', '/transaksi/angsuran/tagihan', '/transaksi/riwayat', '/transaksi/' . $newId, '/validasi', '/validasi/riwayat', '/laporan/simpanan', '/laporan/pinjaman', '/laporan/angsuran', '/laporan/saldo', '/laporan/transaksi', '/laporan/regu', '/laporan/anggota', '/anggota/2', '/sistem/audit'];
$noWrap = [];
$noLabel = [];
$noViewport = [];
foreach ($respPages as $p) {
    $r = $sh->get($p);
    if ($r['status'] !== 200) {
        $noViewport[] = $p . ' (status ' . $r['status'] . ')';
        continue;
    }
    $x = $domOf($r['body']);
    if (!preg_match('/<meta name="viewport" content="width=device-width, initial-scale=1">/', $r['body'])) {
        $noViewport[] = $p;
    }
    if ($x->query("//table[not(ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' table-wrap ')])]")->length > 0) {
        $noWrap[] = $p;
    }
    if ($x->query("//table[contains(@class,'table--stack')]/tbody/tr/td[not(@data-label) and not(contains(@class,'cell-actions'))]")->length > 0) {
        $noLabel[] = $p;
    }
}
$activeCount = static fn (array $r): int => substr_count($r['body'], 'nav__item is-active');
check('responsif/menu: tepat SATU item menu aktif di tiap halaman (riwayat validasi tidak ikut menyalakan menunggu validasi)', $activeCount($sh->get('/validasi/riwayat')) === 1 && $activeCount($sh->get('/validasi')) === 1 && $activeCount($sh->get('/laporan/anggota')) === 1 && $activeCount($sh->get('/')) === 1 && $has($sh->get('/validasi/riwayat'), 'is-active" href="/validasi/riwayat"'));
check('responsif/viewport: semua halaman aplikasi memuat meta viewport tanpa user-scalable=no atau maximum-scale (zoom pengguna tidak diblokir)' . ($noViewport ? ' [' . implode(', ', $noViewport) . ']' : ''), $noViewport === []);
$layoutsOk = true;
foreach (['app', 'auth', 'guest'] as $layout) {
    $src = (string) file_get_contents(BASE_PATH . '/views/layouts/' . $layout . '.php');
    $layoutsOk = $layoutsOk && str_contains($src, 'name="viewport" content="width=device-width, initial-scale=1"') && !preg_match('/user-scalable|maximum-scale/i', $src);
}
check('responsif/viewport: ketiga layout (aplikasi, masuk, tamu) memuat viewport yang benar', $layoutsOk);
check('responsif/tabel: setiap <table> berada di dalam .table-wrap (menggulir ke samping, tak melebarkan halaman)' . ($noWrap ? ' [' . implode(', ', $noWrap) . ']' : ''), $noWrap === []);
check('responsif/tabel: setiap sel tabel bertumpuk punya data-label (label tetap tampil di ponsel)' . ($noLabel ? ' [' . implode(', ', $noLabel) . ']' : ''), $noLabel === []);

$cssSrc = str_replace("\r\n", "\n", (string) $css['body']);
$mq = static function (string $src, string $query): string {
    $p = strpos($src, '@media ' . $query . ' {');
    if ($p === false) {
        return '';
    }
    $depth = 0;
    for ($i = strpos($src, '{', $p); $i < strlen($src); $i++) {
        $depth += $src[$i] === '{' ? 1 : ($src[$i] === '}' ? -1 : 0);
        if ($depth === 0) {
            return substr($src, $p, $i - $p + 1);
        }
    }
    return '';
};
$m960 = $mq($cssSrc, '(max-width: 960px)');
$m860 = $mq($cssSrc, '(max-width: 860px)');
check('responsif/css: kolom isi memakai minmax(0,1fr) (anak lebar tidak melebarkan halaman) dan grafik tidak menahan lebar', (bool) preg_match('/\.content \{[^}]*grid-template-columns: minmax\(0, 1fr\)/', $cssSrc) && (bool) preg_match('/\.chart \{[^}]*grid-template-columns: minmax\(0, 1fr\); min-width: 0/s', $cssSrc) && (bool) preg_match('/\.chart__plot \{[^}]*min-width: 0/', $cssSrc));
check('responsif/css: sidebar menjadi drawer di <=960px dengan scrim', str_contains($m960, '.sidebar') && str_contains($m960, 'translateX(-100%)') && str_contains($m960, 'drawer-open'));
check('responsif/css: kolom isian 16px di layar sentuh (mencegah zoom otomatis iOS) dan tombol >=44px/40px', (bool) preg_match('/\.input[^{]*\{ font-size: 16px; \}/', $m960) && str_contains($m960, '.btn { min-height: 44px; }') && str_contains($m960, '.btn--sm { min-height: 40px; }'));
check('responsif/css: tabel bertumpuk sampai 860px (tablet potret), ringkasan pembaca layar tetap', str_contains($m860, '.table--stack td::before { content: attr(data-label)') && str_contains($m860, '.table--stack thead { position: absolute; left: -9999px; }'));
check('responsif/css: .table-wrap menggulir ke samping; tombol ikon 44x44; label menu >=12px', (bool) preg_match('/\.table-wrap \{ overflow-x: auto/', $cssSrc) && (bool) preg_match('/\.icon-btn \{[^}]*width: 44px; height: 44px/s', $cssSrc) && (bool) preg_match('/\.nav__label \{[^}]*font-size: 12px/s', $cssSrc));
$screenCss = (string) preg_replace('#/\*.*?\*/#s', '', $cssSrc);
while (($printBlock = $mq($screenCss, 'print')) !== '') {
    $screenCss = str_replace($printBlock, '', $screenCss);
}   // cetak laporan sengaja 10,5px agar muat A4 landscape
check('responsif/css: tidak ada ukuran huruf di bawah 12px untuk tampilan layar', !preg_match('/font-size: (?:[0-9]|1[01](?:\.\d+)?)px/', $screenCss));
// ---- 12f. kebersihan umum ----
$all = '';
foreach (['/master/anggota', '/master/ketua-regu', '/master/pengguna', '/master/pengguna/baru', '/master/anggota/baru', '/sistem/pengaturan', '/anggota/2'] as $p) {
    $all .= $hd->get($p)['body'];
}
check('umum: halaman master tidak memuat <script> atau event handler inline', !preg_match('/<script(?![^>]*\bsrc=)|\son(click|submit|change|load)=/i', $all));
check('umum: tidak ada atribut style inline (CSP)', !preg_match('/\sstyle="/i', $all));
check('umum: tidak ada kata sandi atau hash di halaman admin', !str_contains($all, '$2y$'));

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
@unlink(BASE_PATH . '/.env.testing');
exit($failed === [] ? 0 : 1);
