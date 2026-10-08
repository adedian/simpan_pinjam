<?php
declare(strict_types=1);

/**
 * Cadangan database (Phase 16). Aman dijalankan kapan saja, termasuk saat aplikasi dipakai (satu snapshot konsisten).
 *
 *   php database/tools/backup.php                   buat cadangan, periksa berkasnya, simpan 30 terbaru
 *   php database/tools/backup.php --prove           + BUKTIKAN bisa dipulihkan (pulihkan ke database sementara, bandingkan, hapus)
 *   php database/tools/backup.php --keep=60         simpan 60 terbaru
 *   php database/tools/backup.php --dir=D:\Cadangan folder lain (default storage/backups)
 *   php database/tools/backup.php --db=NAMA         database lain
 *   php database/tools/backup.php --check=BERKAS    hanya periksa satu berkas cadangan
 *
 * Kode keluar 0 = berhasil dan terbukti sehat, 1 = gagal. Jadwalkan harian (lihat docs/16-deploy-phase16.md).
 * Cadangan berisi SELURUH data keuangan dan anggota: simpan salinan di luar komputer ini dan jaga aksesnya.
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
    if (isset($options['check']) && is_string($options['check'])) {
        $r = Backup::check($options['check']);
        echo $r['ok'] ? "Berkas sehat.\n" : "BERKAS BERMASALAH:\n  - " . implode("\n  - ", $r['problems']) . "\n";
        exit($r['ok'] ? 0 : 1);
    }

    $dbName = (string) ($options['db'] ?? Config::get('database.name'));
    $dir    = (string) ($options['dir'] ?? dirname(__DIR__, 2) . '/storage/backups');
    $keep   = max(1, (int) ($options['keep'] ?? 30));

    $made = Backup::create($dbName, $dir);
    printf("Cadangan dibuat: %s (%s KB)\n", $made['file'], number_format($made['bytes'] / 1024, 1, ',', '.'));

    $check = Backup::check($made['file']);
    if (!$check['ok']) {
        throw new RuntimeException("Cadangan TIDAK sehat:\n  - " . implode("\n  - ", $check['problems']));
    }
    echo "Berkas diperiksa: lengkap, utuh (SHA-256), memuat tabel inti dan trigger.\n";

    if (isset($options['prove'])) {
        $proof = Backup::verify($made['file'], $dbName);
        if (!$proof['ok']) {
            throw new RuntimeException("Pemulihan percobaan GAGAL:\n  - " . implode("\n  - ", $proof['problems']));
        }
        $r = $proof['restored'];
        printf("Terbukti bisa dipulihkan: %d transaksi (%d disetujui), saldo tabungan %s, selisih %d, masalah integritas %d.\n",
            $r['transactions'], $r['approved'], number_format($r['saldo'], 0, ',', '.'), $r['selisih'], $r['issues']);
    }

    $gone = Backup::rotate($dir, $keep);
    if ($gone !== []) {
        echo 'Cadangan lama dihapus (simpan ' . $keep . " terbaru): " . implode(', ', $gone) . "\n";
    }
    echo "Selesai.\n";
} catch (Throwable $e) {
    Logger::error('Cadangan gagal', $e);
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
