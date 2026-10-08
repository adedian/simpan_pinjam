<?php
declare(strict_types=1);

/**
 * Tes operasional (Phase 16): proxy tepercaya, paksa HTTPS, cadangan, pemulihan, dan pemeriksaan kesiapan produksi.
 * MENGOSONGKAN database uji dan membuat database sementara berawalan "_ops"/"_verify"; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\ops.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Https;
use App\Core\Request;
use App\Models\User;
use App\Services\Backup;
use App\Services\Migrator;
use App\Services\Preflight;
use App\Services\SavingService;
use App\Services\UserService;

$testDb = (string) Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-ops']);
}
/** @return array{code:int,out:string} */
function cli(string $script, array $args, string $envFile): array
{
    $cmd  = array_merge([PHP_BINARY, dirname(__DIR__) . '/database/tools/' . $script], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['APP_ENV_FILE' => $envFile]));
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => $out];
}
function writeEnv(string $name, array $vars): void
{
    $lines = [];
    foreach ($vars as $k => $v) {
        $lines[] = $k . '=' . $v;
    }
    file_put_contents(dirname(__DIR__) . '/' . $name, implode("\n", $lines) . "\n");
}
function tmpFiles(): int
{
    return count(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adem*') ?: []);
}

$envFiles = ['.env.opsa', '.env.opsb', '.env.opsc', '.env.opsd', '.env.opse', '.env.opsf'];
register_shutdown_function(static function () use ($envFiles): void {
    foreach ($envFiles as $f) {
        @unlink(dirname(__DIR__) . '/' . $f);
    }
});

// ======================= proxy tepercaya (unit) =======================
$mk = static fn (array $server): Request => new Request('GET', '/', [], [], $server);
Request::useTrustedProxies([]);
$spoof = $mk(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https']);
check('proxy: tanpa proxy tepercaya, X-Forwarded-For dan -Proto DIABAIKAN (tak bisa dipalsukan)', $spoof->ip() === '8.8.8.8' && $spoof->isSecure() === false);
Request::useTrustedProxies(['10.0.0.1', '10.0.0.2']);
check('proxy: dari proxy tepercaya, IP klien = X-Forwarded-For', $mk(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'])->ip() === '1.2.3.4');
check('proxy: klien memalsukan sisi kiri daftar: yang dipakai alamat pertama non-proxy dari KANAN', $mk(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 1.2.3.4'])->ip() === '1.2.3.4');
check('proxy: rantai proxy tepercaya dilewati', $mk(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.1'])->ip() === '1.2.3.4');
check('proxy: rantai rusak atau tanpa header memakai REMOTE_ADDR (tidak menebak)', $mk(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'bukan-ip'])->ip() === '10.0.0.1' && $mk(['REMOTE_ADDR' => '10.0.0.1'])->ip() === '10.0.0.1');
check('proxy: alamat yang BUKAN proxy tepercaya tetap tak dipercaya walau mengirim header', $mk(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'])->ip() === '8.8.8.8');
check('proxy: HTTPS lewat X-Forwarded-Proto hanya dari proxy tepercaya', $mk(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'])->isSecure() === true
    && $mk(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_PROTO' => 'https'])->isSecure() === false && $mk(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http'])->isSecure() === false);
check('proxy: HTTPS langsung tetap dikenali; HTTPS=off bukan HTTPS', $mk(['REMOTE_ADDR' => '8.8.8.8', 'HTTPS' => 'on'])->isSecure() === true && $mk(['REMOTE_ADDR' => '8.8.8.8', 'HTTPS' => 'off'])->isSecure() === false);
Request::useTrustedProxies(null);

// ======================= paksa HTTPS (unit) =======================
Config::set('app.url', 'https://simpin.example.id');
Config::set('app.force_https', true);
Request::useTrustedProxies([]);
$http = static fn (string $method, string $uri, array $extra = []): Request => new Request($method, '/', [], [], $extra + ['REMOTE_ADDR' => '8.8.8.8', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'evil.example']);
$r = Https::enforce($http('GET', '/laporan/simpanan?bulan=2026-03'));
check('https: GET lewat http dialihkan 308 ke APP_URL (bukan Host yang dikirim klien), jalur dan query dipertahankan', $r !== null && $r->status === 308 && $r->headers['Location'] === 'https://simpin.example.id/laporan/simpanan?bulan=2026-03');
check('https: HEAD juga dialihkan', Https::enforce($http('HEAD', '/'))?->status === 308);
$post = Https::enforce($http('POST', '/login'));
check('https: POST lewat http DITOLAK 403 (tidak dialihkan; isinya sudah terkirim tanpa enkripsi)', $post !== null && $post->status === 403 && !isset($post->headers['Location']));
check('https: permintaan HTTPS lolos tanpa pengalihan', Https::enforce($http('GET', '/', ['HTTPS' => 'on'])) === null);
Request::useTrustedProxies(['8.8.8.8']);
check('https: HTTPS dari proxy tepercaya lolos; tanpa proxy tepercaya header palsu tidak menolong', Https::enforce($http('GET', '/', ['HTTP_X_FORWARDED_PROTO' => 'https'])) === null);
Request::useTrustedProxies([]);
check('https: header palsu dari alamat tak tepercaya tetap dialihkan', Https::enforce($http('GET', '/', ['HTTP_X_FORWARDED_PROTO' => 'https']))?->status === 308);
$evil = ['//evil.example/phish', "/a\r\nSet-Cookie: x=1", '/a\\evil', 'http://evil.example/', ''];
$allSafe = true;
foreach ($evil as $uri) {
    $loc = Https::enforce($http('GET', $uri))?->headers['Location'] ?? '';
    $allSafe = $allSafe && $loc === 'https://simpin.example.id/' && !str_contains($loc, "\n");
}
check('https: target berbahaya (//host, CRLF, backslash, URL penuh, kosong) selalu menjadi "/" di APP_URL (tak ada open redirect)', $allSafe);
Config::set('app.url', 'https://simpin.example.id:8443/subfolder');
check('https: APP_URL hanya dipakai asalnya (skema+host+port), bukan jalurnya', Https::enforce($http('GET', '/x'))?->headers['Location'] === 'https://simpin.example.id:8443/x');
Config::set('app.url', 'http://simpin.example.id');
check('https: APP_URL tanpa https:// tidak pernah memaksa (tak ada pengalihan ke http)', Https::enforce($http('GET', '/')) === null);
Config::set('app.url', '');
check('https: tanpa APP_URL tidak memaksa', Https::enforce($http('GET', '/')) === null);
Config::set('app.url', 'https://simpin.example.id');
Config::set('app.force_https', false);
check('https: APP_FORCE_HTTPS=false tidak memaksa', Https::enforce($http('GET', '/')) === null);
Config::set('app.url', '');

// ======================= fixture data uji =======================
$m0 = date('Y-m-01');
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m0}', '" . date('Y-m-01', strtotime('+3 month')) . "', 'AKTIF')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$m0}')");
$mid0 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m0]);
foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m0, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m0}'),(2,1,'{$m0}')");
$ids = [];
foreach ([['alfa', ['KETUA_REGU'], 'AGT-001'], ['kepala', ['HEAD'], null]] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$pdo->exec('UPDATE users SET must_change_password = 0');
$alfa = User::findActive($ids['alfa']);
$mkSaving = function (string $amount) use ($alfa, $mid0): int {
    [$e, $d] = SavingService::parse(['member_id' => 2, 'period_month_id' => $mid0, 'kind' => 'SUKARELA', 'amount' => $amount, 'trx_date' => date('Y-m-d'), 'confirm_duplicate' => '1']);
    return SavingService::create(req(), $alfa, $d, true);
};
$approve = static function (int $id): void {
    Database::pdo()->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
};
$t1 = $mkSaving('100.000');
$approve($t1);
$t2 = $mkSaving('250.000');
$approve($t2);
$pending = $mkSaving('75.000');   // menunggu validasi: ikut tercadang tetapi tidak masuk saldo

$base = ['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_BASE_PATH' => '/', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
         'DB_NAME' => $testDb, 'DB_USER' => $admin[0], 'DB_PASS' => $admin[1], 'DB_ADMIN_USER' => $admin[0], 'DB_ADMIN_PASS' => $admin[1]];
writeEnv('.env.opsc', $base);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adem-ops-' . bin2hex(random_bytes(3));
mkdir($dir);

// ======================= cadangan =======================
$tmpBefore = tmpFiles();
$made = Backup::create($testDb, $dir);
check('cadangan: berkas .sql.gz dan .sha256 dibuat, tanpa sisa .part', is_file($made['file']) && is_file($made['file'] . '.sha256') && $made['bytes'] > 1000 && glob($dir . '/*.part') === []);
check('cadangan: berkas kredensial sementara dan berkas galat dihapus (kata sandi tidak tertinggal di disk)', tmpFiles() === $tmpBefore);
$chk = Backup::check($made['file']);
check('cadangan: pemeriksaan berkas lolos (utuh, lengkap, tabel inti, trigger)', $chk['ok'] && $chk['problems'] === []);
check('cadangan: nama berkas berpola adem-ayem-*.sql.gz', (bool) preg_match('/adem-ayem-\d{8}-\d{6}\.sql\.gz$/', $made['file']));

$fp = Backup::fingerprint($testDb);
check('cadangan: sidik jari sumber sesuai fixture (2 disetujui + 1 menunggu, saldo 350.000, selisih 0)', $fp['transactions'] === 3 && $fp['approved'] === 2 && $fp['saldo'] === 350000 && $fp['selisih'] === 0 && $fp['issues'] === 0);

// ---- bukti pemulihan
$proof = Backup::verify($made['file'], $testDb);
check('verify: cadangan terbukti bisa dipulihkan (saldo, invarian, integritas, struktur)', $proof['ok'] && $proof['problems'] === [] && $proof['restored']['saldo'] === 350000 && $proof['restored']['transactions'] === 3);
check('verify: database sementara dihapus setelah pembuktian', Backup::admin('')->query("SHOW DATABASES LIKE '{$testDb}\\_verify\\_%'")->fetchAll() === []);

// ---- pemulihan menyalin SEMUA, termasuk trigger/view/rutin
$restoreDb = $testDb . '_ops';
Backup::freshDatabase($restoreDb, true);
Backup::restore($made['file'], $restoreDb);
$rp = Backup::fingerprint($restoreDb);
check('restore: seluruh isi kembali sama (tabel, transaksi, audit, saldo, kas, piutang)', $rp === $fp);
$rpdo = Backup::admin($restoreDb);
$trg = (int) $rpdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$restoreDb}'")->fetchColumn();
$trgSrc = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$testDb}'")->fetchColumn();
check('restore: trigger pengaman ikut dipulihkan dan masih bekerja (audit append-only ditolak)', $trg === $trgSrc && $trg > 0 && (function () use ($rpdo): bool {
    try {
        $rpdo->exec('UPDATE audit_logs SET action = \'X\' WHERE id = 1');
        return false;
    } catch (\PDOException $e) {
        return str_contains($e->getMessage(), 'append-only');
    }
})());
$views = (int) $rpdo->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '{$restoreDb}'")->fetchColumn();
check('restore: view saldo ikut dipulihkan dan tak memuat DEFINER akun asal yang bisa hilang', $views >= 5 && (int) $rpdo->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '{$restoreDb}' AND DEFINER = ''")->fetchColumn() === 0);

// ---- cadangan rusak ditolak
$corrupt = $dir . DIRECTORY_SEPARATOR . 'adem-ayem-20000101-000000.sql.gz';
copy($made['file'], $corrupt);
copy($made['file'] . '.sha256', $corrupt . '.sha256');
$raw = (string) file_get_contents($corrupt);
file_put_contents($corrupt, substr($raw, 0, 100) . chr(ord($raw[100]) ^ 0xFF) . substr($raw, 101));
$c1 = Backup::check($corrupt);
check('rusak: satu byte berubah terdeteksi lewat SHA-256', !$c1['ok'] && str_contains(implode(' ', $c1['problems']), 'SHA-256'));
$trunc = $dir . DIRECTORY_SEPARATOR . 'adem-ayem-20000102-000000.sql.gz';
file_put_contents($trunc, substr($raw, 0, (int) (strlen($raw) / 2)));
$c2 = Backup::check($trunc);
check('rusak: berkas terpotong terdeteksi (tanpa .sha256 sekalipun), cadangan tak selesai/tak lengkap', !$c2['ok'] && count($c2['problems']) >= 2);
$noDump = $dir . DIRECTORY_SEPARATOR . 'adem-ayem-20000103-000000.sql.gz';
$g = gzopen($noDump, 'wb');
gzwrite($g, "-- bukan cadangan\nSELECT 1;\n");
gzclose($g);
check('rusak: berkas gzip valid tetapi bukan cadangan ditolak; berkas kosong dan tidak ada ditolak', !Backup::check($noDump)['ok'] && !Backup::check($dir . DIRECTORY_SEPARATOR . 'tidak-ada.sql.gz')['ok']);
$vFail = Backup::verify($corrupt, $testDb);
check('rusak: verify cadangan rusak gagal (pulih tidak berhasil) dan database sementara tetap dibersihkan', !$vFail['ok'] && Backup::admin('')->query("SHOW DATABASES LIKE '{$testDb}\\_verify\\_%'")->fetchAll() === []);

// ---- cadangan dari database lain
$otherDb = $testDb . '_ops2';
Backup::freshDatabase($otherDb, true);
(new Migrator(Backup::admin($otherDb), dirname(__DIR__) . '/database/migrations'))->migrate();
$vOther = Backup::verify($made['file'], $otherDb);
check('verify: cadangan yang memuat LEBIH banyak data daripada sumber dinyatakan gagal (cadangan salah alamat)', !$vOther['ok'] && str_contains(implode(' ', $vOther['problems']), 'LEBIH banyak'));
Backup::admin('')->exec("DROP DATABASE `{$otherDb}`");

// ---- aturan pembanding hasil pemulihan (kasus yang tak bisa dibuat lewat data nyata karena trigger menjaga invarian)
$healthy = $fp;
check('compare: hasil sehat sama dengan sumber = tanpa masalah; sumber boleh lebih baru (data hanya bertambah)', Backup::compare($healthy, $healthy) === []
    && Backup::compare($healthy, array_merge($healthy, ['transactions' => 9, 'approved' => 8, 'audit' => $healthy['audit'] + 5, 'saldo' => 999])) === []);
check('compare: selisih invarian ≠ 0 pada hasil pemulihan dinyatakan masalah', str_contains(implode(' ', Backup::compare(array_merge($healthy, ['selisih' => 500]), $healthy)), 'Invarian'));
check('compare: tabel berbeda, masalah integritas baru, skema lebih baru, dan saldo berbeda pada data sama banyak dinyatakan masalah', count(Backup::compare(array_merge($healthy, ['tables' => ['x']]), $healthy)) === 1
    && count(Backup::compare(array_merge($healthy, ['issues' => 3]), $healthy)) === 1
    && count(Backup::compare(array_merge($healthy, ['migrations' => $healthy['migrations'] + 1]), $healthy)) === 1
    && count(Backup::compare(array_merge($healthy, ['saldo' => $healthy['saldo'] + 1]), $healthy)) === 1);
// ---- rotasi
$rot = $dir . DIRECTORY_SEPARATOR . 'rot';
mkdir($rot);
$names = [];
foreach ([5, 4, 3, 2, 1] as $i => $daysAgo) {
    $n = $rot . DIRECTORY_SEPARATOR . 'adem-ayem-2026010' . $daysAgo . '-010101.sql.gz';
    file_put_contents($n, 'x');
    file_put_contents($n . '.sha256', 'x');
    touch($n, time() - $daysAgo * 86400);
    $names[$daysAgo] = $n;
}
file_put_contents($rot . DIRECTORY_SEPARATOR . 'catatan-penting.txt', 'jangan hapus');
file_put_contents($rot . DIRECTORY_SEPARATOR . 'adem-ayem-salinan.txt', 'bukan cadangan');
$gone = Backup::rotate($rot, 2);
check('rotasi: tersisa 2 terbaru; yang lama dihapus beserta .sha256-nya', count($gone) === 3 && is_file($names[1]) && is_file($names[2]) && !is_file($names[3]) && !is_file($names[3] . '.sha256') && !is_file($names[5]));
check('rotasi: berkas lain (catatan, bukan berpola cadangan) tidak pernah disentuh', is_file($rot . DIRECTORY_SEPARATOR . 'catatan-penting.txt') && is_file($rot . DIRECTORY_SEPARATOR . 'adem-ayem-salinan.txt'));
check('rotasi: keep=0 atau negatif tetap menyisakan 1 terbaru (tak pernah menghapus semuanya)', (function () use ($rot, $names): bool {
    Backup::rotate($rot, 0);
    return is_file($names[1]) && !is_file($names[2]);
})());

// ======================= alat CLI =======================
$cliDir = $dir . DIRECTORY_SEPARATOR . 'cli';
$r = cli('backup.php', ['--prove', '--dir=' . $cliDir, '--keep=2'], '.env.opsc');
check('cli backup: --prove sukses (kode 0), melaporkan pembuktian dan saldo', $r['code'] === 0 && str_contains($r['out'], 'Terbukti bisa dipulihkan') && str_contains($r['out'], '350.000') && count(Backup::listBackups($cliDir)) === 1);
sleep(1);
cli('backup.php', ['--dir=' . $cliDir, '--keep=2'], '.env.opsc');
sleep(1);
$r3 = cli('backup.php', ['--dir=' . $cliDir, '--keep=2'], '.env.opsc');
check('cli backup: --keep=2 menghapus yang lama, tersisa 2', $r3['code'] === 0 && count(Backup::listBackups($cliDir)) === 2 && str_contains($r3['out'], 'Cadangan lama dihapus'));
$latest = Backup::listBackups($cliDir)[0];
check('cli backup: --check berkas sehat kode 0; berkas rusak kode 1', cli('backup.php', ['--check=' . $latest], '.env.opsc')['code'] === 0 && cli('backup.php', ['--check=' . $corrupt], '.env.opsc')['code'] === 1);
check('cli backup: nama database tak valid ditolak tanpa membuat berkas', cli('backup.php', ['--db=x;DROP', '--dir=' . $dir . DIRECTORY_SEPARATOR . 'z'], '.env.opsc')['code'] === 1 && !is_dir($dir . DIRECTORY_SEPARATOR . 'z'));

// restore CLI
$rr = cli('restore.php', ['--file=' . $latest], '.env.opsc');
check('cli restore: bawaan memulihkan ke database BARU <nama>_restore, bukan produksi', $rr['code'] === 0 && str_contains($rr['out'], $testDb . '_restore') && Backup::fingerprint($testDb . '_restore') === $fp);
$again = cli('restore.php', ['--file=' . $latest], '.env.opsc');
check('cli restore: tujuan yang sudah ada ditolak tanpa --replace (tidak menimpa diam-diam)', $again['code'] === 1 && str_contains($again['out'], '--replace'));
check('cli restore: --replace menimpa database tujuan non-produksi', cli('restore.php', ['--file=' . $latest, '--replace'], '.env.opsc')['code'] === 0);
Backup::admin('')->exec("DROP DATABASE IF EXISTS `{$testDb}_restore`");
$noConfirm = cli('restore.php', ['--file=' . $latest, '--db=' . $testDb, '--replace'], '.env.opsc');
check('cli restore: menimpa PRODUKSI tanpa --confirm ditolak dan tidak mengubah apa pun', $noConfirm['code'] === 1 && str_contains($noConfirm['out'], '--confirm') && Backup::fingerprint($testDb) === $fp);
$wrongConfirm = cli('restore.php', ['--file=' . $latest, '--db=' . $testDb, '--replace', '--confirm=salah'], '.env.opsc');
check('cli restore: --confirm yang salah ditolak', $wrongConfirm['code'] === 1 && Backup::fingerprint($testDb) === $fp);
check('cli restore: berkas rusak ditolak sebelum menyentuh apa pun', cli('restore.php', ['--file=' . $corrupt, '--db=' . $testDb, '--replace', '--confirm=' . $testDb], '.env.opsc')['code'] === 1 && Backup::fingerprint($testDb) === $fp);
check('cli restore: tanpa --file atau berkas tak ada ditolak', cli('restore.php', [], '.env.opsc')['code'] === 1 && cli('restore.php', ['--file=tidak-ada.sql.gz'], '.env.opsc')['code'] === 1);

// pemulihan produksi sungguhan: data SETELAH cadangan harus hilang, cadangan pra-pemulihan harus dibuat
$later = $mkSaving('999.000');
$approve($later);
check('cli restore: (prasyarat) saldo kini 1.349.000 setelah transaksi baru', Backup::fingerprint($testDb)['saldo'] === 1349000);
$prod = cli('restore.php', ['--file=' . $latest, '--db=' . $testDb, '--replace', '--confirm=' . $testDb], '.env.opsc');
check('cli restore: produksi dengan --replace --confirm sukses', $prod['code'] === 0 && str_contains($prod['out'], 'pra-pemulihan'));
$after = Backup::fingerprint($testDb);
check('cli restore: isi produksi kembali ke saat cadangan (saldo 350.000; transaksi setelahnya hilang)', $after === $fp);
$pre = array_values(array_filter(Backup::listBackups($cliDir), static fn (string $f): bool => str_contains($f, 'pra-pemulihan')));
check('cli restore: cadangan PRA-pemulihan dibuat dan memuat transaksi terakhir (bisa dibatalkan)', count($pre) === 1 && Backup::check($pre[0])['ok'] && Backup::verify($pre[0], $testDb)['restored']['saldo'] === 1349000);
$pdo = Database::pdo();

// ======================= preflight =======================
// akun aplikasi terbatas untuk uji (meniru create_app_user.php; nama berbeda agar akun sungguhan tak tersentuh)
$opsUser = 'adem_ops_test';
$opsPass = 'Ops-Uji-' . bin2hex(random_bytes(4));
$srv = Backup::admin('');
$srv->exec("DROP USER IF EXISTS '{$opsUser}'@'localhost'");
$srv->exec("CREATE USER '{$opsUser}'@'localhost' IDENTIFIED BY '{$opsPass}'");
$grantApp = static function () use ($srv, $opsUser, $testDb): void {
    $db = Backup::admin($testDb);
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
        $priv = in_array($t, ['audit_logs', 'transaction_validations'], true) ? 'SELECT, INSERT' : (in_array($t, ['schema_migrations', 'roles'], true) ? 'SELECT' : 'SELECT, INSERT, UPDATE, DELETE');
        $srv->exec("GRANT {$priv} ON `{$testDb}`.`{$t}` TO '{$opsUser}'@'localhost'");
    }
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(\PDO::FETCH_COLUMN) as $v) {
        $srv->exec("GRANT SELECT ON `{$testDb}`.`{$v}` TO '{$opsUser}'@'localhost'");
    }
};
$grantApp();
$good = array_merge($base, ['APP_ENV' => 'production', 'APP_URL' => 'https://simpin.example.id', 'APP_FORCE_HTTPS' => 'true', 'DB_USER' => $opsUser, 'DB_PASS' => $opsPass, 'SESSION_IDLE_TIMEOUT' => '1800']);
writeEnv('.env.opse', $good);
$ok = cli('preflight.php', [], '.env.opse');
if (getenv('OPS_DEBUG')) {
    echo $ok['out'];
}
check('preflight: konfigurasi produksi yang benar tidak punya GAGAL (kode 0)', $ok['code'] === 0 && !str_contains($ok['out'], '[ GAGAL'));
check('preflight: hak akun aplikasi dinilai minimal, migrasi dan integritas OK', str_contains($ok['out'], 'Hak akun aplikasi — minimal') && str_contains($ok['out'], 'Migrasi database — ') && str_contains($ok['out'], 'Integritas keuangan — invarian nol'));

$badEnv = $base;   // APP_ENV=local, akun aplikasi = admin
writeEnv('.env.opsd', $badEnv);
$bad = cli('preflight.php', [], '.env.opsd');
check('preflight: lingkungan local, APP_URL kosong, aplikasi memakai akun admin = GAGAL (kode 1)', $bad['code'] === 1 && str_contains($bad['out'], '[ GAGAL    ] APP_ENV') && str_contains($bad['out'], '[ GAGAL    ] Akun database aplikasi') && str_contains($bad['out'], '[ GAGAL    ] APP_URL / HTTPS'));
$http = cli('preflight.php', ['--allow-http'], '.env.opse');
writeEnv('.env.opsd', array_merge($good, ['APP_URL' => 'http://192.168.1.5', 'APP_FORCE_HTTPS' => 'false']));
check('preflight: APP_URL http = GAGAL; dengan --allow-http hanya PERINGATAN', cli('preflight.php', [], '.env.opsd')['code'] === 1 && cli('preflight.php', ['--allow-http'], '.env.opsd')['code'] === 0);
writeEnv('.env.opsd', array_merge($good, ['APP_DEBUG' => 'true']));
check('preflight: APP_DEBUG menyala = GAGAL', cli('preflight.php', [], '.env.opsd')['code'] === 1 && str_contains(cli('preflight.php', [], '.env.opsd')['out'], '[ GAGAL    ] APP_DEBUG'));

$srv->exec("GRANT UPDATE ON `{$testDb}`.`audit_logs` TO '{$opsUser}'@'localhost'");
$audit = cli('preflight.php', [], '.env.opse');
check('preflight: akun aplikasi yang boleh UPDATE audit_logs = GAGAL (audit harus append-only)', $audit['code'] === 1 && str_contains($audit['out'], '[ GAGAL    ] Hak akun aplikasi') && str_contains($audit['out'], 'append-only'));
$srv->exec("REVOKE UPDATE ON `{$testDb}`.`audit_logs` FROM '{$opsUser}'@'localhost'");
check('preflight: setelah hak dicabut kembali bersih', cli('preflight.php', [], '.env.opse')['code'] === 0);
$srv->exec("GRANT DROP, ALTER ON `{$testDb}`.`transactions` TO '{$opsUser}'@'localhost'");
check('preflight: hak DROP/ALTER pada akun aplikasi = GAGAL', cli('preflight.php', [], '.env.opse')['code'] === 1);
$srv->exec("REVOKE DROP, ALTER ON `{$testDb}`.`transactions` FROM '{$opsUser}'@'localhost'");
$srv->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$testDb}`.* TO '{$opsUser}'@'localhost'");
check('preflight: hak tulis pada SELURUH database (bukan per tabel) = GAGAL', cli('preflight.php', [], '.env.opse')['code'] === 1);
$srv->exec("REVOKE SELECT, INSERT, UPDATE, DELETE ON `{$testDb}`.* FROM '{$opsUser}'@'localhost'");

$json = json_decode(cli('preflight.php', ['--json'], '.env.opse')['out'], true);
check('preflight: --json berformat mesin berisi ok dan daftar pemeriksaan bertingkat', is_array($json) && isset($json['ok'], $json['checks']) && is_array($json['checks']) && isset($json['checks'][0]['level'], $json['checks'][0]['name']));

$srv->exec("DROP USER IF EXISTS '{$opsUser}'@'localhost'");
check('preflight: akun uji dibersihkan', (int) $srv->query("SELECT COUNT(*) FROM mysql.user WHERE User = '{$opsUser}'")->fetchColumn() === 0);

// ======================= HTTPS dan proxy lewat server sungguhan =======================
$https = array_merge($base, ['APP_ENV' => 'production', 'APP_URL' => 'https://simpin.example.id', 'APP_FORCE_HTTPS' => 'true']);
writeEnv('.env.opsa', $https);
writeEnv('.env.opsb', array_merge($https, ['TRUSTED_PROXIES' => '127.0.0.1']));
writeEnv('.env.opsf', array_merge($base, ['APP_ENV' => 'production', 'DB_USER' => 'tidak_ada_akun', 'DB_PASS' => 'salah']));
$servers = [];
foreach ([['.env.opsa', 8097], ['.env.opsb', 8096], ['.env.opsf', 8095]] as [$envFile, $port]) {
    $servers[$port] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/tests/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/adem-ops.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/adem-ops.log', 'a']],
        $pipes, dirname(__DIR__), array_merge(getenv(), ['APP_ENV_FILE' => $envFile]));
}
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
        }
    }
    @unlink(sys_get_temp_dir() . '/adem-ops.log');
});
foreach ([8097, 8096, 8095] as $port) {
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($c) {
            fclose($c);
            break;
        }
        usleep(100000);
    }
}
$call = static function (int $port, string $method, string $path, array $headers = [], string $body = ''): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 10,
        'header' => implode("\r\n", array_merge($headers, $method === 'POST' ? ['Content-Type: application/x-www-form-urlencoded'] : [])), 'content' => $body]]);
    $text = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $h = $http_response_header ?? [];
    $status = isset($h[0]) && preg_match('#\s(\d{3})\s#', $h[0], $m) ? (int) $m[1] : 0;
    $map = [];
    foreach (array_slice($h, 1) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $map[strtolower(trim($k))][] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $map, 'body' => $text];
};
$a = $call(8097, 'GET', '/login');
check('server https: GET http dialihkan 308 ke APP_URL, tanpa isi aplikasi', $a['status'] === 308 && ($a['headers']['location'][0] ?? '') === 'https://simpin.example.id/login' && !str_contains($a['body'], 'Masuk'));
check('server https: pengalihan membawa header keamanan, tanpa cookie sesi', isset($a['headers']['content-security-policy']) && !isset($a['headers']['set-cookie']));
check('server https: X-Forwarded-Proto palsu dari klien biasa TIDAK menipu (tanpa proxy tepercaya)', $call(8097, 'GET', '/login', ['X-Forwarded-Proto: https'])['status'] === 308);
$post = $call(8097, 'POST', '/login', [], 'username=a&password=b');
check('server https: POST login lewat http ditolak 403 (kata sandi tidak diproses)', $post['status'] === 403 && !str_contains($post['body'], 'Masuk'));
check('server https: Host palsu tidak mengubah tujuan pengalihan', ($call(8097, 'GET', '/laporan/x?q=1', ['Host: evil.example'])['headers']['location'][0] ?? '') === 'https://simpin.example.id/laporan/x?q=1');
$b = $call(8096, 'GET', '/login', ['X-Forwarded-Proto: https']);
check('server proxy: dari proxy tepercaya (127.0.0.1) dengan X-Forwarded-Proto https halaman dilayani 200', $b['status'] === 200 && str_contains($b['body'], 'Masuk'));
check('server proxy: respons HTTPS membawa HSTS', isset($b['headers']['strict-transport-security']));
$cookies = implode(' | ', $b['headers']['set-cookie'] ?? []);
check('server proxy: cookie sesi bertanda Secure dan HttpOnly di balik proxy HTTPS', str_contains(strtolower($cookies), 'secure') && str_contains(strtolower($cookies), 'httponly') && str_contains(strtolower($cookies), 'samesite=lax'));
check('server proxy: tanpa X-Forwarded-Proto dari proxy tetap dialihkan', $call(8096, 'GET', '/login')['status'] === 308);
check('server proxy: /health dari proxy HTTPS menjawab ringkas {"status":"ok"} (produksi, tanpa rincian) dan memeriksa database', (function () use ($call): bool {
    $h = $call(8096, 'GET', '/health', ['X-Forwarded-Proto: https']);
    return $h['status'] === 200 && trim($h['body']) === '{"status":"ok"}';
})());
$down = $call(8095, 'GET', '/health');
check('server health: database tak terjangkau = 503 {"status":"error"} (pemantau tahu), tanpa membocorkan galat', $down['status'] === 503 && trim($down['body']) === '{"status":"error"}');

// ======================= kebersihan =======================
check('kebersihan: tidak ada database sementara tersisa', Backup::admin('')->query("SHOW DATABASES LIKE '{$testDb}\\_%'")->fetchAll() === [] || (function () use ($testDb): bool {
    $left = Backup::admin('')->query("SHOW DATABASES LIKE '{$testDb}\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
    return array_diff($left, [$testDb . '_ops']) === [];
})());
Backup::admin('')->exec("DROP DATABASE IF EXISTS `{$restoreDb}`");
$rm = static function (string $d) use (&$rm): void {
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir($d . DIRECTORY_SEPARATOR . $e) ? $rm($d . DIRECTORY_SEPARATOR . $e) : @unlink($d . DIRECTORY_SEPARATOR . $e);
    }
    @rmdir($d);
};
$rm($dir);
check('kebersihan: tidak ada berkas kredensial sementara tersisa di folder sementara', tmpFiles() <= $tmpBefore);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
