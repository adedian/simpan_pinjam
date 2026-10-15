<?php
declare(strict_types=1);

/**
 * Pencocokan bagi hasil aplikasi dengan Excel koperasi, per anggota. HANYA MEMBACA (database dan berkas Excel).
 *
 *   C:\xampp\php\php.exe database\tools\verify_profit_excel.php "C:\...\07. Laporan Simpin bulan September  2026.xlsx"
 *
 * Membaca sheet "3-REKAP TABUNGAN+bunga" (kolom BO bruto, BP cadangan, BQ bagi hasil penabung setelah cadangan) dan
 * "7-BAGI HASIL PINJAMAN" (DO bruto, DP cadangan, DQ bagi hasil peminjam setelah cadangan), lalu membandingkannya dengan
 * ProfitShare::compute() (mode Excel) menurut NAMA anggota. Angka aplikasi dibulatkan per baris; Excel tidak, jadi selisih
 * yang diperbolehkan hanya pembulatan (< 1 rupiah per baris pada angka bersih).
 * Kode keluar 0 bila semua anggota cocok.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Database;
use App\Services\ProfitShare;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Pakai: php database/tools/verify_profit_excel.php <berkas.xlsx>\n");
    exit(2);
}

$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    fwrite(STDERR, "Bukan berkas xlsx yang valid.\n");
    exit(2);
}
$ss = [];
if (($x = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    foreach (simplexml_load_string($x)->si as $si) {
        $t = '';
        if (isset($si->t)) {
            $t = (string) $si->t;
        } else {
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
        }
        $ss[] = $t;
    }
}
$wb   = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
$rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
$map  = [];
foreach ($rels->Relationship as $r) {
    $map[(string) $r['Id']] = (string) $r['Target'];
}
$sheets = [];
foreach ($wb->sheets->sheet as $s) {
    $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $sheets[trim((string) $s['name'])] = 'xl/' . ltrim($map[$rid], '/');
}
$cells = static function (string $path) use ($zip, $ss): array {
    $out = [];
    foreach (simplexml_load_string((string) $zip->getFromName($path))->sheetData->row as $row) {
        foreach ($row->c as $c) {
            $v = isset($c->v) ? (string) $c->v : '';
            if ((string) $c['t'] === 's') {
                $v = $ss[(int) $v] ?? '';
            }
            $out[(string) $c['r']] = $v;
        }
    }
    return $out;
};
$c3 = $cells($sheets['3-REKAP TABUNGAN+bunga'] ?? '');
$c7 = $cells($sheets['7-BAGI HASIL PINJAMAN'] ?? '');
if ($c3 === [] || $c7 === []) {
    fwrite(STDERR, "Sheet 3-REKAP TABUNGAN+bunga atau 7-BAGI HASIL PINJAMAN tidak ditemukan.\n");
    exit(2);
}

/** @return array<string,array{gross:float,reserve:float,net:float}> menurut nama (spasi dirapikan) */
$read = static function (array $c, string $nameCol, string $gross, string $reserve, string $net): array {
    $out = [];
    for ($r = 7; $r <= 66; $r++) {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($c[$nameCol . $r] ?? '')) ?? '');
        if ($name === '' || !isset($c[$net . $r])) {
            continue;
        }
        $out[$name] = ['gross' => (float) ($c[$gross . $r] ?? 0), 'reserve' => (float) ($c[$reserve . $r] ?? 0), 'net' => (float) $c[$net . $r]];
    }
    return $out;
};
$saver    = $read($c3, 'B', 'BO', 'BP', 'BQ');
$borrower = $read($c7, 'C', 'DO', 'DP', 'DQ');

$period = (int) Database::select('SELECT id FROM periods ORDER BY id LIMIT 1')[0]['id'];
$calc   = ProfitShare::compute($period, true);
$names  = [];
foreach (Database::select('SELECT id, name FROM members WHERE deleted_at IS NULL') as $m) {
    $names[trim((string) preg_replace('/\s+/', ' ', (string) $m['name']))] = (int) $m['id'];
}

$bad = 0;
$checked = 0;
$sumApp = ['saver' => 0, 'borrower' => 0];
$sumXl  = ['saver' => 0.0, 'borrower' => 0.0];
printf("%-22s %12s %12s %12s %12s  %s\n", 'Anggota', 'Pen. Excel', 'Pen. App', 'Pem. Excel', 'Pem. App', 'Hasil');
foreach ($names as $name => $id) {
    $s = $saver[$name] ?? null;
    $b = $borrower[$name] ?? null;
    if ($s === null && $b === null) {
        continue;   // anggota yang tidak ada di Excel (mis. bukan peserta)
    }
    $m = $calc['members'][$id] ?? ['saver' => 0, 'borrower' => 0, 'saver_gross' => 0.0, 'borrower_gross' => 0.0];
    $okS = abs(round((float) ($s['net'] ?? 0.0)) - $m['saver']) < 1 && abs(($s['gross'] ?? 0.0) - $m['saver_gross']) < 0.01;
    $okB = abs(round((float) ($b['net'] ?? 0.0)) - $m['borrower']) < 1 && abs(($b['gross'] ?? 0.0) - $m['borrower_gross']) < 0.01;
    $checked++;
    $sumApp['saver'] += $m['saver'];
    $sumApp['borrower'] += $m['borrower'];
    $sumXl['saver'] += (float) ($s['net'] ?? 0.0);
    $sumXl['borrower'] += (float) ($b['net'] ?? 0.0);
    if (!$okS || !$okB) {
        $bad++;
    }
    printf("%-22s %12s %12s %12s %12s  %s\n", mb_substr($name, 0, 22), number_format((float) ($s['net'] ?? 0), 2, ',', '.'), number_format($m['saver'], 0, ',', '.'),
        number_format((float) ($b['net'] ?? 0), 2, ',', '.'), number_format($m['borrower'], 0, ',', '.'), $okS && $okB ? 'cocok' : 'BEDA');
}
$missing = array_diff(array_keys($saver + $borrower), array_keys($names));
echo "\nAnggota dibandingkan: {$checked}; berbeda: {$bad}; ada di Excel tetapi tidak di aplikasi: " . ($missing === [] ? '-' : implode(', ', $missing)) . "\n";
printf("Jumlah penabung : Excel %s | aplikasi %s (selisih pembulatan baris %s)\n", number_format($sumXl['saver'], 2, ',', '.'), number_format($sumApp['saver'], 0, ',', '.'), number_format($sumXl['saver'] - $sumApp['saver'], 2, ',', '.'));
printf("Jumlah peminjam : Excel %s | aplikasi %s (selisih pembulatan baris %s)\n", number_format($sumXl['borrower'], 2, ',', '.'), number_format($sumApp['borrower'], 0, ',', '.'), number_format($sumXl['borrower'] - $sumApp['borrower'], 2, ',', '.'));
printf("Pool 40%%+40%%     : aplikasi %s, tidak terbagi %s\n", number_format($calc['interest_pool'], 0, ',', '.'), number_format($calc['undistributed'], 0, ',', '.'));
exit($bad === 0 && $checked > 0 ? 0 : 1);
