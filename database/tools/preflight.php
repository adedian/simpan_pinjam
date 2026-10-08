<?php
declare(strict_types=1);

/**
 * Pemeriksaan kesiapan produksi (Phase 16). Hanya membaca. Jalankan sebelum membuka untuk pengguna
 * dan setiap selesai mengubah konfigurasi/menaikkan versi.
 *
 *   php database/tools/preflight.php               periksa semuanya
 *   php database/tools/preflight.php --allow-http  jaringan lokal tanpa TLS: HTTP menjadi peringatan, bukan kegagalan
 *   php database/tools/preflight.php --json        keluaran mesin (untuk pemantauan)
 *
 * Kode keluar 0 = tidak ada GAGAL (peringatan boleh ada), 1 = ada GAGAL.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Services\Preflight;

$results = (new Preflight(['allow_http' => in_array('--allow-http', $argv, true)]))->run();
$failed  = Preflight::hasFailure($results);

if (in_array('--json', $argv, true)) {
    echo json_encode(['ok' => !$failed, 'checks' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit($failed ? 1 : 0);
}

$label = [Preflight::OK => '[ OK       ]', Preflight::WARN => '[ PERINGATAN ]', Preflight::FAIL => '[ GAGAL    ]'];
$count = [Preflight::OK => 0, Preflight::WARN => 0, Preflight::FAIL => 0];
foreach ($results as $r) {
    $count[$r['level']]++;
    echo $label[$r['level']], ' ', $r['name'], ($r['detail'] !== '' ? ' — ' . $r['detail'] : ''), "\n";
}
printf("\n%d OK, %d peringatan, %d gagal. %s\n", $count[Preflight::OK], $count[Preflight::WARN], $count[Preflight::FAIL],
    $failed ? 'JANGAN dibuka untuk pengguna sebelum yang GAGAL diperbaiki.' : 'Tidak ada yang menghalangi.');
exit($failed ? 1 : 0);
