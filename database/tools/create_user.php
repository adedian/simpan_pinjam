<?php
declare(strict_types=1);

/**
 * Buat pengguna dari CLI (sebelum halaman Data Pengguna dibangun di Phase 5).
 *
 *   php database/tools/create_user.php --username=purwati --name="Purwati" --roles=HEAD,KETUA_REGU --member=AGT-034 --out=storage/credentials.txt
 *
 *   --roles=   daftar peran dipisah koma: HEAD, PEMERIKSA, KETUA_REGU, ANGGOTA
 *   --member=  nomor anggota yang ditautkan (opsional, mis. AGT-034)
 *   --out=     tulis kata sandi sementara ke berkas ini (JANGAN tampilkan di layar). Tanpa --out, dicetak.
 *   --db=      database lain
 * Kata sandi sementara dibuat acak, dan pengguna WAJIB menggantinya saat masuk pertama.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\UserService;

$o = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) {
        $o[$m[1]] = $m[2];
    }
}
foreach (['username', 'name', 'roles'] as $required) {
    if (empty($o[$required])) {
        fwrite(STDERR, "Opsi --{$required} wajib.\n" . "Contoh: --username=purwati --name=\"Purwati\" --roles=HEAD,KETUA_REGU --member=AGT-034\n");
        exit(1);
    }
}

// Akun admin dipakai agar pembuatan pengguna tidak bergantung pada hak akun aplikasi.
Database::configure([
    'user' => Config::get('database.admin_user'),
    'pass' => Config::get('database.admin_pass'),
    'name' => $o['db'] ?? Config::get('database.name'),
]);

try {
    $result = UserService::create(
        $o['username'], $o['name'], array_map('trim', explode(',', $o['roles'])),
        $o['member'] ?? null, null, null, ['id' => null, 'username' => 'cli', 'roles' => ['CLI']],
    );
    if (!empty($o['out'])) {
        $file = str_starts_with($o['out'], '/') || preg_match('/^[A-Za-z]:/', $o['out']) ? $o['out'] : BASE_PATH . '/' . $o['out'];
        file_put_contents($file, sprintf("%s\t%s\n", strtolower($o['username']), $result['password']), FILE_APPEND | LOCK_EX);
        echo "Pengguna '{$o['username']}' dibuat (id {$result['id']}). Kata sandi sementara ditulis ke {$o['out']}.\n";
    } else {
        echo "Pengguna '{$o['username']}' dibuat (id {$result['id']}).\nKata sandi sementara: {$result['password']}\n";
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'DITOLAK: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
