<?php
declare(strict_types=1);

/**
 * Menjalankan SELURUH pengujian berurutan dan melaporkan satu tabel ringkas (Phase 17).
 *
 *   C:\xampp\php\php.exe tests\all.php                 semua suite + lint PHP dan JS (±5 menit)
 *   C:\xampp\php\php.exe tests\all.php --fast          tanpa simulasi acak, konkurensi, operasional (±3,5 menit)
 *   C:\xampp\php\php.exe tests\all.php --only=auth,http
 *   C:\xampp\php\php.exe tests\all.php --skip=simulation,perf
 *   C:\xampp\php\php.exe tests\all.php --perf          sertakan uji kinerja skala besar (+±2 menit)
 *   C:\xampp\php\php.exe tests\all.php --no-retry      jangan ulangi suite yang gagal
 *
 * Suite yang gagal diulang SEKALI. Bila lulus pada ulangan, ia dilaporkan sebagai "ACAK" (flaky) lengkap dengan nama
 * pemeriksaan yang gagal pada percobaan pertama, tidak disembunyikan. Kode keluar 0 hanya bila semuanya lulus akhirnya.
 * Suite saling berbagi database uji dan HARUS berjalan berurutan (jangan dijalankan paralel).
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$root = dirname(__DIR__);
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$all = ['run', 'db', 'import', 'auth', 'master', 'saving', 'loan', 'payment', 'validation', 'reversal', 'dashboard', 'report', 'audit', 'live', 'ops', 'security', 'concurrency', 'simulation', 'http', 'perf'];
$heavy = ['simulation', 'concurrency', 'ops'];
$suites = array_values(array_filter($all, static fn (string $s): bool => $s !== 'perf' || isset($opts['perf'])));
if (isset($opts['fast'])) {
    $suites = array_values(array_diff($suites, $heavy));
}
if (isset($opts['only']) && is_string($opts['only'])) {
    $suites = array_values(array_intersect($all, explode(',', $opts['only'])));
}
if (isset($opts['skip']) && is_string($opts['skip'])) {
    $suites = array_values(array_diff($suites, explode(',', $opts['skip'])));
}
$retry = !isset($opts['no-retry']);

/** @return array{code:int,out:string,secs:float} */
function runPhp(string $script, string $root, array $args = []): array
{
    $t = microtime(true);
    $proc = proc_open(array_merge([PHP_BINARY, $script], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => $out, 'secs' => microtime(true) - $t];
}
function counts(string $out): array
{
    if (preg_match('/(\d+) lulus, (\d+) gagal/', $out, $m)) {
        return [(int) $m[1], (int) $m[2]];
    }
    if (preg_match('/Semua (\d+) pemeriksaan lulus/', $out, $m)) {
        return [(int) $m[1], 0];
    }
    return [0, -1];
}
/** @return array<int,string> */
function failures(string $out): array
{
    preg_match_all('/^  GAGAL  (.+)$/m', $out, $m);
    return $m[1];
}

$rows = [];
$totalPass = 0;
$overall = true;
$t0 = microtime(true);

// ---- lint PHP dan JS
$lintT = microtime(true);
$bad = [];
$n = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!str_ends_with($p, '.php') || preg_match('#/(storage|vendor|\.git|\.claude)/#', $p)) {
        continue;
    }
    $n++;
    $r = runPhp('-l', $root, [$f->getPathname()]);
    if ($r['code'] !== 0) {
        $bad[] = substr($p, strlen($root) + 1);
    }
}
$jsBad = [];
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'which node 2>/dev/null'));
foreach (glob($root . '/public/assets/js/*.js') ?: [] as $js) {
    if ($node !== '') {
        $line = strtok($node, "\r\n");
        exec('"' . $line . '" --check ' . escapeshellarg($js) . ' 2>&1', $o, $c);
        if ($c !== 0) {
            $jsBad[] = basename($js);
        }
    }
}
$lintOk = $bad === [] && $jsBad === [];
$overall = $overall && $lintOk;
$rows[] = ['lint (' . $n . ' berkas PHP' . ($node !== '' ? ' + JS' : '') . ')', $lintOk ? 'LULUS' : 'GAGAL', $n, 0, microtime(true) - $lintT, $lintOk ? '' : implode(', ', array_merge($bad, $jsBad))];

foreach ($suites as $suite) {
    $script = $root . '/tests/' . $suite . '.php';
    $r = runPhp($script, $root);
    [$pass, $fail] = counts($r['out']);
    $status = $r['code'] === 0 && $fail === 0 ? 'LULUS' : 'GAGAL';
    $note = '';
    if ($status === 'GAGAL' && $retry) {
        $firstFail = failures($r['out']);
        $r2 = runPhp($script, $root);
        [$pass2, $fail2] = counts($r2['out']);
        if ($r2['code'] === 0 && $fail2 === 0) {
            $status = 'ACAK';
            $note = 'gagal pada percobaan pertama: ' . implode(' | ', array_map(static fn (string $s): string => substr($s, 0, 90), $firstFail ?: ['(tanpa nama; kode ' . $r['code'] . ')']));
            $pass = $pass2;
            $fail = 0;
            $r['secs'] += $r2['secs'];
        } else {
            $note = implode(' | ', array_map(static fn (string $s): string => substr($s, 0, 110), failures($r2['out']) ?: ['keluaran: ' . substr(trim($r2['out']), -160)]));
            $pass = $pass2;
            $fail = $fail2;
            $r['secs'] += $r2['secs'];
        }
    } elseif ($status === 'GAGAL') {
        $note = implode(' | ', array_map(static fn (string $s): string => substr($s, 0, 110), failures($r['out']) ?: ['keluaran: ' . substr(trim($r['out']), -160)]));
    }
    $overall = $overall && $status !== 'GAGAL';
    $totalPass += $pass;
    $rows[] = [$suite, $status, $pass, max(0, $fail), $r['secs'], $note];
    printf("  %-12s %-6s %5d lulus %3d gagal  %6.1f dtk\n", $suite, $status, $pass, max(0, $fail), $r['secs']);
    if ($note !== '') {
        echo "      -> {$note}\n";
    }
}

echo "\n", str_repeat('=', 64), "\n";
printf("%-14s %-6s %7s %7s %9s\n", 'Suite', 'Hasil', 'Lulus', 'Gagal', 'Waktu');
foreach ($rows as [$name, $status, $pass, $fail, $secs]) {
    printf("%-14s %-6s %7d %7d %7.1f s\n", $name, $status, $pass, $fail, $secs);
}
printf("%s\nTOTAL %d pemeriksaan lulus dalam %.0f detik. %s\n", str_repeat('-', 64), $totalPass, microtime(true) - $t0,
    $overall ? (in_array('ACAK', array_column($rows, 1), true) ? 'LULUS, tetapi ada suite ACAK (flaky): periksa catatan di atas.' : 'SEMUA LULUS.') : 'ADA YANG GAGAL.');
exit($overall ? 0 : 1);
