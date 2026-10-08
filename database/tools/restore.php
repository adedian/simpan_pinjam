<?php
declare(strict_types=1);

/**
 * Pulihkan cadangan (Phase 16). Bawaan AMAN: memulihkan ke database BARU, tidak menyentuh database produksi.
 *
 *   php database/tools/restore.php --file=BERKAS.sql.gz                 pulihkan ke <nama_db>_restore (dibuat baru)
 *   php database/tools/restore.php --file=... --db=NAMA_TUJUAN           ke database lain (harus belum ada)
 *   php database/tools/restore.php --file=... --db=NAMA --replace        timpa database tujuan yang sudah ada
 *
 * Menimpa database PRODUKSI (nama sama dengan DB_NAME) hanya bila --db=NAMA_PRODUKSI --replace --confirm=NAMA_PRODUKSI,
 * dan SEBELUMNYA otomatis dibuat cadangan "pra-pemulihan" kondisi saat ini. Hentikan akses pengguna dulu (mode pemeliharaan).
 * Setelah pulih, hak akun aplikasi di database baru harus dibuat ulang: php database/tools/create_app_user.php --apply
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Logger;
use App\Services\Backup;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

try {
    $file = (string) ($options['file'] ?? '');
    if ($file === '' || !is_file($file)) {
        throw new RuntimeException('Pakai --file=BERKAS.sql.gz (berkas harus ada).');
    }
    $production = (string) Config::get('database.name');
    $target     = (string) ($options['db'] ?? $production . '_restore');
    Backup::assertName($target);
    $replace = isset($options['replace']);

    if ($target === $production) {
        if (!$replace || ($options['confirm'] ?? '') !== $production) {
            throw new RuntimeException("Menimpa database produksi butuh --replace --confirm={$production}. Tidak ada yang diubah.");
        }
    }

    $check = Backup::check($file);
    if (!$check['ok']) {
        throw new RuntimeException("Cadangan tidak layak dipulihkan:\n  - " . implode("\n  - ", $check['problems']));
    }
    echo "Berkas diperiksa: sehat.\n";

    if ($target === $production) {
        $pre = Backup::create($production, dirname($file), 'pra-pemulihan');
        echo 'Cadangan pra-pemulihan (kondisi saat ini): ' . $pre['file'] . "\n";
    }

    Backup::freshDatabase($target, $replace);
    Backup::restore($file, $target);
    $fp = Backup::fingerprint($target);
    printf("Dipulihkan ke %s: %d transaksi (%d disetujui), %d audit, saldo tabungan %s, kas %s, piutang %s, selisih %d, masalah integritas %d.\n",
        $target, $fp['transactions'], $fp['approved'], $fp['audit'], number_format($fp['saldo'], 0, ',', '.'), number_format($fp['kas'], 0, ',', '.'),
        number_format($fp['piutang'], 0, ',', '.'), $fp['selisih'], $fp['issues']);
    if ($fp['selisih'] !== 0 || $fp['issues'] > 0) {
        throw new RuntimeException('Data hasil pemulihan tidak lolos pemeriksaan integritas: jangan dipakai sebelum diperiksa.');
    }
    if ($target !== $production) {
        echo "Periksa isinya, lalu bila benar arahkan DB_NAME di .env ke {$target} atau pulihkan ulang dengan --db={$production} --replace --confirm={$production}.\n";
    } else {
        echo "Jalankan: php database/tools/create_app_user.php --apply (hak akun aplikasi) lalu php database/tools/preflight.php.\n";
    }
} catch (Throwable $e) {
    Logger::error('Pemulihan gagal', $e);
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
