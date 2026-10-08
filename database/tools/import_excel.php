<?php
declare(strict_types=1);

/**
 * Impor data historis Excel.
 *
 *   php database/tools/import_excel.php                     DRY-RUN: tidak menyentuh database
 *   php database/tools/import_excel.php --commit            tulis ke database (atomik; batal bila ada selisih)
 *   php database/tools/import_excel.php --db=NAMA           database lain (default: DB_NAME)
 *   php database/tools/import_excel.php --file=PATH.json    file ekstrak lain
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Money;
use App\Services\ExcelImporter;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}
$file   = (string) ($options['file'] ?? dirname(__DIR__) . '/import/excel-extract.json');
$dbName = (string) ($options['db'] ?? Config::get('database.name'));
$rp     = static fn (int|string $n): string => Money::format((int) $n);

try {
    $importer = ExcelImporter::load($file);
    $plan     = $importer->plan();
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL menyusun rencana: ' . $e->getMessage() . "\n");
    exit(1);
}

$byType = [];
foreach ($plan['trxs'] as $t) {
    $byType[$t['type']] = ($byType[$t['type']] ?? 0) + 1;
}

echo "== RENCANA IMPOR (belum menulis apa pun) ==\n";
printf("Transaksi: %d  (%s)\n", count($plan['trxs']), implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($byType), $byType)));
printf("Pinjaman: %d, cicilan terjadwal: %d, data terakhir: %s\n", count($plan['loans']),
    array_sum(array_map(fn ($l) => count($l['installments']), $plan['loans'])), $plan['last_active']);

echo "\n== VERIFIKASI TERHADAP ANGKA KONTROL EXCEL ==\n";
$bad = 0;
foreach ($plan['checks'] as $c) {
    $isMoney = is_int($c['expected']) && abs($c['expected']) >= 1000;
    printf("  [%s] %-62s %s%s\n", $c['ok'] ? 'OK ' : 'XX ', $c['name'],
        $isMoney ? $rp($c['actual']) : $c['actual'], $c['ok'] ? '' : '   (Excel: ' . ($isMoney ? $rp($c['expected']) : $c['expected']) . ')');
    $bad += $c['ok'] ? 0 : 1;
}
printf("\n%d pemeriksaan, %d gagal.\n", count($plan['checks']), $bad);

if ($plan['warnings'] !== []) {
    echo "\nPeringatan:\n  - " . implode("\n  - ", $plan['warnings']) . "\n";
}

$r = $plan['report'];
echo "\n== UNTUK DITINJAU MANUSIA (bukan kesalahan impor) ==\n";
printf("Cicilan lewat jatuh tempo per %s: %d anggota, total %s\n", $plan['last_active'], count($r['overdue']),
    $rp(array_sum(array_column($r['overdue'], 'amount'))));
foreach (array_slice($r['overdue'], 0, 12) as $o) {
    printf("  %-14s %2d cicilan  %s\n", $o['name'], $o['count'], $rp($o['amount']));
}
if (count($r['overdue']) > 12) {
    printf("  ... dan %d anggota lain\n", count($r['overdue']) - 12);
}
echo 'Belum pernah membayar sama sekali: ' . ($r['never_paid'] ? implode(', ', $r['never_paid']) : '-') . "\n";
echo 'Dikecualikan cadangan 5% (hardcode di Excel): ' . implode(', ', $r['exempt']) . "\n";
echo 'Anggota tanpa transaksi apa pun: ' . ($r['idle_members'] ? implode(', ', $r['idle_members']) : '-') . "\n";

if (!isset($options['commit'])) {
    echo "\nDRY-RUN selesai. Database tidak diubah. Jalankan dengan --commit untuk menulis ke '{$dbName}'.\n";
    exit($bad === 0 ? 0 : 2);
}

if (!$importer->isSafeToCommit($plan)) {
    fwrite(STDERR, "\nCOMMIT DITOLAK: ada pemeriksaan yang gagal (lihat di atas).\n");
    exit(2);
}

try {
    $pdo    = Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), $dbName);
    $counts = $importer->commit($pdo, $plan);
    echo "\nCOMMIT BERHASIL ke '{$dbName}':\n";
    foreach ($counts as $k => $v) {
        printf("  %-10s %d\n", $k, $v);
    }
    echo "Verifikasi ulang di database (view saldo) lolos. Jalankan check_integrity.php untuk memastikan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nCOMMIT DIBATALKAN (database tidak berubah): " . $e->getMessage() . "\n");
    exit(1);
}
