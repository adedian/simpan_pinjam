<?php
declare(strict_types=1);

/**
 * Tes layar Audit Log (Phase 13): saringan, pencarian aman, urutan, paginasi, ringkasan, penyaringan nilai rahasia,
 * dan ketidakbisaan-diubah catatan. MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\audit.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Audit;
use App\Services\Migrator;

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
function put(string $action, string $entity, ?int $entityId, ?string $user, ?string $ref, string $when, ?string $ip = '10.0.0.1', ?array $before = null, ?array $after = null): int
{
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO audit_logs (user_id, username, roles, action, entity_type, entity_id, reference_no, before_data, after_data, ip_address, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([null, $user, $user === null ? null : 'HEAD', $action, $entity, $entityId, $ref, $before === null ? null : json_encode($before), $after === null ? null : json_encode($after), $ip, 'tes', $when]);
    return (int) $pdo->lastInsertId();
}
function ids(array $r): array
{
    return array_map(static fn ($x) => (int) $x['id'], $r['rows']);
}

$now  = date('Y-m-d H:i:s');
$d1   = date('Y-m-d H:i:s', strtotime('-3 hour'));
$d3   = date('Y-m-d H:i:s', strtotime('-3 day'));
$d10  = date('Y-m-d H:i:s', strtotime('-10 day'));
$a1 = put('LOGIN_SUCCESS', 'user', 1, 'kepala', null, $d10);
$a2 = put('SAVING_CREATED', 'transaction', 10, 'ketua.alfa', 'SMP-2026-000001', $d10, '10.0.0.2', null, ['amount' => 5000]);
$a3 = put('LOGIN_FAILED', 'user', null, null, null, $d3, '10.0.0.9', null, ['username' => 'budi']);
$a4 = put('ACCESS_DENIED_SCOPE', 'member', 7, 'ketua.beta', null, $d1, '10.0.0.3');
$a5 = put('TRX_APPROVED', 'transaction', 10, 'kepala', 'SMP-2026-000001', $d1, '10.0.0.1', ['status' => 'MENUNGGU_VALIDASI'], ['status' => 'DISETUJUI', 'note' => 'ok']);
$a6 = put('REPORT_EXPORTED', 'report', null, 'periksa', 'simpanan', $now);
$a7 = put('USER_CREATED', 'user', 5, 'kepala', 'budi', $now, '10.0.0.1', null, ['username' => 'budi', 'password_hash' => '$2y$secret', 'roles' => ['ANGGOTA'], 'nested' => ['token' => 'abc', 'ok' => 1]]);
$a8 = put('ENTAH_APA', 'route', null, 'kepala', null, $now);   // kode tak dikenal

// ======================= saringan =======================
$f = Audit::filters(['aksi' => 'login_failed', 'kelompok' => 'ngawur', 'entitas' => 'x', 'dari' => '2026-02-31', 'sampai' => '2026-03-05', 'ip' => 'bukan ip!', 'pengguna' => "  <b>kepala</b>\n"]);
check('saringan: kode aksi dinormalkan huruf besar; kelompok/objek/tanggal/IP tak sah dibuang; pengguna dirapikan', $f['aksi'] === 'LOGIN_FAILED' && $f['kelompok'] === '' && $f['entitas'] === '' && $f['dari'] === '' && $f['sampai'] === '2026-03-05' && $f['ip'] === '' && $f['pengguna'] === '<b>kepala</b>');
check('saringan: kode aksi dengan simbol SQL dibuang', Audit::filters(['aksi' => "X' OR 1=1 --"])['aksi'] === '' && Audit::filters(['aksi' => ''])['aksi'] === '');

// ======================= daftar =======================
$all = Audit::search(Audit::filters([]), 1);
check('daftar: terbaru dulu, semua catatan', $all['pager']['total'] === 8 && ids($all) === [$a8, $a7, $a6, $a5, $a4, $a3, $a2, $a1]);
check('daftar: rentang tanggal inklusif sampai akhir hari (3 hari lalu s.d. kemarin)', ids(Audit::search(Audit::filters(['dari' => date('Y-m-d', strtotime('-3 day')), 'sampai' => date('Y-m-d', strtotime('-3 day'))]), 1)) === [$a3]
    && ids(Audit::search(Audit::filters(['sampai' => date('Y-m-d', strtotime('-9 day'))]), 1)) === [$a2, $a1]);
check('daftar: saringan hari ini memuat yang dibuat sekarang dan 3 jam lalu', array_values(array_intersect(ids(Audit::search(Audit::filters(['dari' => date('Y-m-d')]), 1)), [$a8, $a7, $a6])) === [$a8, $a7, $a6]);
check('daftar: pengguna (sebagian nama) dan pencarian referensi', ids(Audit::search(Audit::filters(['pengguna' => 'ketua']), 1)) === [$a4, $a2] && ids(Audit::search(Audit::filters(['q' => 'SMP-2026']), 1)) === [$a5, $a2] && ids(Audit::search(Audit::filters(['q' => 'periksa']), 1)) === [$a6]);
check('daftar: aksi tepat, objek, dan IP', ids(Audit::search(Audit::filters(['aksi' => 'TRX_APPROVED']), 1)) === [$a5] && ids(Audit::search(Audit::filters(['entitas' => 'transaction']), 1)) === [$a5, $a2] && ids(Audit::search(Audit::filters(['ip' => '10.0.0.9']), 1)) === [$a3]);
check('daftar: kelompok Keamanan memuat gagal masuk dan akses ditolak, tidak simpanan/masuk berhasil', ids(Audit::search(Audit::filters(['kelompok' => 'keamanan']), 1)) === [$a4, $a3]);
check('daftar: kelompok Transaksi, Validasi, Laporan, Masuk', ids(Audit::search(Audit::filters(['kelompok' => 'transaksi']), 1)) === [$a2] && ids(Audit::search(Audit::filters(['kelompok' => 'validasi']), 1)) === [$a5]
    && ids(Audit::search(Audit::filters(['kelompok' => 'laporan']), 1)) === [$a6] && ids(Audit::search(Audit::filters(['kelompok' => 'masuk']), 1)) === [$a3, $a1]);
check('daftar: pencarian aman (wildcard harfiah, injeksi SQL tidak mengembalikan semua)', Audit::search(Audit::filters(['pengguna' => '%']), 1)['rows'] === [] && Audit::search(Audit::filters(['q' => '%']), 1)['rows'] === []
    && Audit::search(Audit::filters(['pengguna' => "' OR '1'='1"]), 1)['rows'] === [] && Audit::search(Audit::filters(['q' => '_']), 1)['rows'] === []);
check('daftar: kombinasi saringan (pengguna kepala + aksi USER_CREATED + referensi budi)', ids(Audit::search(Audit::filters(['pengguna' => 'kepala', 'aksi' => 'USER_CREATED', 'q' => 'budi']), 1)) === [$a7]);

// paginasi dan ekspor
for ($i = 0; $i < 60; $i++) {
    put('LOGIN_SUCCESS', 'user', 1, 'massal', null, $now);
}
$p1 = Audit::search(Audit::filters([]), 1);
$p2 = Audit::search(Audit::filters([]), 2);
$full = Audit::search(Audit::filters([]), 1, true);
check('paginasi: 50 baris per halaman, halaman 2 sisanya, ekspor memuat semua', count($p1['rows']) === 50 && count($p2['rows']) === 18 && count($full['rows']) === 68 && $p1['pager']['pages'] === 2);
check('paginasi: halaman di luar batas dijepit', count(Audit::search(Audit::filters([]), 99)['rows']) === 18 && count(Audit::search(Audit::filters([]), -5)['rows']) === 50);

// ======================= detail, ringkasan, daftar aksi =======================
check('detail: ditemukan; tidak ada = null', Audit::find($a5)['action'] === 'TRX_APPROVED' && Audit::find(999999) === null);
// peristiwa lama (3 hari lalu) tidak boleh masuk hitungan 24 jam
put('ACCESS_DENIED', 'route', null, 'lama', null, $d3);
put('LOGIN_FAILED', 'user', null, null, null, $d3);
put('REPORT_EXPORTED', 'report', null, 'lama', 'x', $d10);
$s = Audit::summary();
check('ringkasan: total, 24 jam (tanpa yang lebih lama), gagal masuk 0 dan akses ditolak 1 dalam 24 jam, unduhan 7 hari 1 (yang 10 hari lalu tidak)', $s['total'] === 71 && $s['day'] === 3 + 60 + 2 && $s['failed'] === 0 && $s['denied'] === 1 && $s['exports'] === 1);
$pa = Audit::presentActions();
check('daftar aksi: hanya yang ada, berlabel Indonesia; kode tak dikenal tampil apa adanya', $pa['TRX_APPROVED'] === 'Transaksi disetujui' && $pa['ENTAH_APA'] === 'ENTAH_APA' && !isset($pa['SAVING_SUBMITTED']) && Audit::label('LOGIN_FAILED') === 'Gagal masuk');
check('label: semua kode aksi yang ditulis aplikasi punya label (tidak ada yang tampil sebagai kode mentah)', (function (): bool {
    $root = dirname(__DIR__);
    $codes = [];
    foreach (['services', 'controllers', 'middleware', 'models'] as $dir) {
        foreach (glob("{$root}/{$dir}/*.php") as $file) {
            if (basename($file) === 'Audit.php') {
                continue;
            }
            if (preg_match_all("/'([A-Z]{3,}_[A-Z_]+)'/", (string) file_get_contents($file), $m)) {
                $codes = array_merge($codes, $m[1]);
            }
        }
    }
    $known = array_keys(Audit::ACTIONS);
    $missing = [];
    foreach (array_unique($codes) as $c) {
        if (!in_array($c, $known, true) && preg_match('/^(LOGIN|LOGOUT|PASSWORD|ACCESS|SAVING|LOAN|PAYMENT|REVERSAL|TRX|MEMBER|TEAM|USER|SETTING|REPORT|AUDIT)_/', $c) === 1
            && !in_array($c, ['SAVING_TRANSACTION', 'TRX_NO'], true) && !in_array($c, ['MENUNGGU_VALIDASI', 'DISETUJUI', 'DITOLAK', 'DIBATALKAN', 'PENCAIRAN_PINJAMAN', 'KETUA_REGU'], true)) {
            $missing[] = $c;
        }
    }
    if ($missing !== []) {
        echo '  (tanpa label: ' . implode(', ', $missing) . ")\n";
    }
    return $missing === [];
})());

check('label: aksi impor Excel (ditulis skrip impor, bukan layanan web) dikenal, berikut objeknya', Audit::label('IMPOR_EXCEL') === 'Impor data dari Excel' && Audit::ENTITIES['import'] === 'Impor');

// ======================= rahasia disaring =======================
$flat = Audit::flatten((string) Audit::find($a7)['after_data']);
$map = array_column($flat, 1, 0);
check('rahasia: kunci password/hash/token disembunyikan, termasuk di dalam objek bersarang; data lain utuh', $map['password_hash'] === '[disembunyikan]' && $map['username'] === 'budi' && str_contains($map['nested'], '[disembunyikan]') && !str_contains(json_encode($flat), 'secret') && !str_contains(json_encode($flat), 'abc') && str_contains($map['nested'], '"ok":1'));
check('rahasia: array nilai, boolean, null, JSON rusak, dan kosong ditangani', Audit::flatten(null) === [] && Audit::flatten('') === [] && Audit::flatten('{"a":true,"b":null,"c":[1,2]}') === [['a', 'ya'], ['b', 'kosong'], ['c', '[1,2]']] && Audit::flatten('bukan json')[0][0] === '(isi)');
check('rahasia: ringkasan untuk CSV juga bersih', !str_contains(Audit::compactJson((string) Audit::find($a7)['after_data']), '$2y$') && str_contains(Audit::compactJson((string) Audit::find($a7)['after_data']), 'username=budi'));
check('rahasia: deteksi kunci', Audit::isSecretKey('password') && Audit::isSecretKey('new_password_hash') && Audit::isSecretKey('API_TOKEN') && !Audit::isSecretKey('username') && !Audit::isSecretKey('status'));

// ======================= tidak bisa diubah =======================
$blocked = static function (string $sql): bool {
    try {
        Database::pdo()->exec($sql);
        return false;
    } catch (PDOException $e) {
        return str_contains($e->getMessage(), 'append-only');
    }
};
check('tidak bisa diubah: UPDATE dan DELETE catatan audit ditolak database (bahkan oleh akun admin)', $blocked("UPDATE audit_logs SET username = 'x' WHERE id = {$a1}") && $blocked("DELETE FROM audit_logs WHERE id = {$a1}") && (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 71);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
