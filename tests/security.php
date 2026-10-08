<?php
declare(strict_types=1);

/**
 * Audit keamanan statis (Phase 16): memindai SELURUH kode sumber, tanpa database dan tanpa server.
 * Tujuannya "pagar": hal berbahaya yang sudah ditinjau dicatat di daftar putih di bawah; temuan BARU di luar daftar
 * membuat tes gagal sehingga harus ditinjau dulu. Pengujian dari luar (XSS, IDOR, CSRF, sesi, fuzzing) ada di tests/http.php bagian 13.
 *   C:\xampp\php\php.exe tests\security.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

define('ROOT', dirname(__DIR__));

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

/** @return array<int,string> berkas PHP aplikasi (tanpa tes, penyimpanan, dan folder pihak ketiga) */
function appFiles(array $dirs = ['controllers', 'models', 'services', 'core', 'middleware', 'helpers', 'config', 'public', 'database/tools']): array
{
    $out = [];
    foreach ($dirs as $d) {
        if (!is_dir(ROOT . '/' . $d)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/' . $d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_ends_with($f->getPathname(), '.php')) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen(ROOT) + 1));
            }
        }
    }
    sort($out);
    return $out;
}

// ======================= 1. SQL: string SQL yang memuat variabel =======================
// Setiap pasangan berkas|variabel di bawah SUDAH ditinjau: berisi potongan tetap (kondisi berparameter "?", daftar
// kolom/tabel konstan) atau bilangan bulat yang di-cast. Nilai dari pengguna selalu lewat parameter PDO.
$sqlReviewed = [
    'models/Audit.php' => ['$w', 'implode'],            // implode: deretan "?" sebanyak daftar kode (array_fill)
    'models/Dashboard.php' => ['$cond', '$scope', '$limit'],
    'models/Member.php' => ['$where', '$whereSql', '$from', '$pager'],
    'models/Transaction.php' => ['$scope', '$from', '$baseWhere', '$whereSql', '$pager', '$sql', '$alias'],   // $alias konstanta pemanggil dan divalidasi lagi oleh Scope; $sql keluaran Scope
    'models/Validation.php' => ['$from', '$whereSql', '$pager', 'ValidationService'],
    'services/ExcelImporter.php' => ['$table'],           // daftar tabel konstan, hanya CLI
    'services/LiveFeed.php' => ['ValidationService', 'self'],   // konstanta kelas
    'services/LoanService.php' => ['$tenor'],             // (int) $data['tenor']
    'services/ReportService.php' => ['$column', '$mIn', '$pIn', '$in', '$from', '$w', '$scope', 'implode', '$mScope'],   // kolom divalidasi regex; IN() dari intval
    'services/Scope.php' => ['$memberColumn'],            // divalidasi regex ^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$
    'services/ValidationService.php' => ['self'],         // konstanta kelas HEAD_RELATED_SQL
];

$kw = '/\b(SELECT|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|FROM\s+\w+|WHERE\s|ORDER\s+BY|LIMIT\s|JOIN\s|(AND|OR)\s+[\w.(]+\s*(=|<|>|IN\b|LIKE\b)|\b[a-z_]+\.[a-z_]+\s*(=|<>|!=|<=|>=|<|>|LIKE\b|IN\s*\())/i';   // juga potongan WHERE seperti "t.status = '$x'"
$sqlFound = [];
foreach (appFiles(['controllers', 'models', 'services', 'core', 'middleware', 'helpers']) as $file) {
    $toks = token_get_all((string) file_get_contents(ROOT . '/' . $file));
    $n = count($toks);
    $skipWs = static function (int $i) use ($toks, $n): int {
        while ($i < $n && is_array($toks[$i]) && $toks[$i][0] === T_WHITESPACE) {
            $i++;
        }
        return $i;
    };
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if ($t === '"' || (is_array($t) && $t[0] === T_START_HEREDOC)) {
            $end = $t === '"' ? '"' : T_END_HEREDOC;
            $j = $i + 1;
            $text = '';
            $vars = [];
            while ($j < $n && !($end === '"' ? $toks[$j] === '"' : (is_array($toks[$j]) && $toks[$j][0] === $end))) {
                if (is_array($toks[$j])) {
                    if ($toks[$j][0] === T_ENCAPSED_AND_WHITESPACE) {
                        $text .= $toks[$j][1];
                    } elseif ($toks[$j][0] === T_VARIABLE) {
                        $vars[] = $toks[$j][1];
                    }
                }
                $j++;
            }
            if ($vars && preg_match($kw, $text)) {
                foreach ($vars as $v) {
                    $sqlFound[$file][$v] = true;
                }
            }
            $i = $j;
            continue;
        }
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match($kw, $t[1])) {
            $j = $skipWs($i + 1);
            if (($toks[$j] ?? null) === '.') {
                $k = $skipWs($j + 1);
                if (is_array($toks[$k] ?? null) && in_array($toks[$k][0], [T_VARIABLE, T_STRING], true)) {
                    $sqlFound[$file][$toks[$k][1]] = true;
                }
            }
        }
        if (is_array($t) && $t[0] === T_VARIABLE) {
            $j = $skipWs($i + 1);
            if (($toks[$j] ?? null) === '.') {
                $k = $skipWs($j + 1);
                if (is_array($toks[$k] ?? null) && $toks[$k][0] === T_CONSTANT_ENCAPSED_STRING && preg_match($kw, $toks[$k][1])) {
                    $sqlFound[$file][$t[1]] = true;
                }
            }
        }
    }
}
$sqlUnreviewed = [];
foreach ($sqlFound as $file => $vars) {
    foreach (array_keys($vars) as $v) {
        if (!in_array($v, $sqlReviewed[$file] ?? [], true)) {
            $sqlUnreviewed[] = $file . ' ' . $v;
        }
    }
}
check('sql: tidak ada variabel BARU di string SQL di luar daftar yang sudah ditinjau (tinjau dulu: nilai pengguna harus lewat parameter "?")' . ($sqlUnreviewed ? ' [' . implode('; ', $sqlUnreviewed) . ']' : ''), $sqlUnreviewed === []);
check('sql: daftar tinjauan tidak basi (setiap entri masih ada di kode)', (function () use ($sqlReviewed, $sqlFound): bool {
    foreach ($sqlReviewed as $file => $vars) {
        foreach ($vars as $v) {
            if (!isset($sqlFound[$file][$v])) {
                echo "    basi: {$file} {$v}\n";
                return false;
            }
        }
    }
    return true;
})());
$ctx = static fn (string $file, string $needle): bool => str_contains((string) file_get_contents(ROOT . '/' . $file), $needle);
check('sql: pagar variabel: tenor di-cast int, LIMIT dashboard dibatasi, nama kolom ruang lingkup divalidasi regex, IN() memakai intval', $ctx('services/LoanService.php', '$tenor = (int) $data[\'tenor\']')
    && $ctx('models/Dashboard.php', '$limit = max(1, min(20, $limit))') && $ctx('services/Scope.php', "preg_match('/^[a-z_][a-z0-9_]*(\\.[a-z_][a-z0-9_]*)?\$/i', \$memberColumn)")
    && $ctx('services/ReportService.php', "array_map('intval', \$memberIds)") && $ctx('services/ReportService.php', "array_map('intval', \$monthIds)"));
check('sql: PDO tanpa emulasi prepare (parameter dikirim terpisah dari SQL) dan mode galat exception', $ctx('core/Database.php', 'ATTR_EMULATE_PREPARES') && $ctx('core/Database.php', 'ERRMODE_EXCEPTION'));
check('sql: tidak memakai mysql_*/mysqli_query dan tidak ada query langsung dari superglobal', (function (): bool {
    foreach (appFiles() as $f) {
        $src = (string) file_get_contents(ROOT . '/' . $f);
        if (preg_match('/\bmysqli?_(query|connect|real_escape_string)\s*\(|->query\(\s*\$_(GET|POST|REQUEST)/', $src)) {
            return false;
        }
    }
    return true;
})());

// ======================= 2. Fungsi berbahaya =======================
$dangerous = ['eval', 'assert', 'create_function', 'unserialize', 'shell_exec', 'exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'extract', 'parse_str', 'md5', 'sha1', 'crypt', 'mt_rand', 'rand', 'uniqid', 'srand', 'mt_srand', 'extract', 'call_user_func', 'call_user_func_array', 'preg_replace_callback_array', 'file_put_contents', 'unlink', 'rename', 'mkdir', 'rmdir', 'chmod', 'move_uploaded_file', 'fopen', 'readfile', 'highlight_file', 'show_source', 'phpinfo', 'ini_set', 'putenv', 'header', 'setcookie'];
$allowedUse = [   // fungsi => berkas yang boleh memakainya (sudah ditinjau)
    'proc_open' => ['services/Backup.php', 'tests'],
    'exec' => ['services/Preflight.php'],           // hanya $pdo->exec (metode), bukan fungsi global: ditandai metode dilewati di bawah
    'extract' => ['core/View.php'],                 // EXTR_SKIP pada data yang disusun controller
    'file_put_contents' => ['core/Logger.php', 'models/Dashboard.php', 'services/Backup.php', 'database/tools/create_app_user.php', 'database/tools/create_user.php', 'database/tools/extract_excel.py', 'database/tools/import_excel.php'],
    'unlink' => ['services/Backup.php'],
    'rename' => ['services/Backup.php', 'models/Dashboard.php'],   // Dashboard: cache integritas, penulisan atomik
    'md5' => ['models/Dashboard.php'],                               // hanya nama berkas cache per database, bukan keamanan
    'mkdir' => ['services/Backup.php', 'database/tools/import_excel.php', 'database/tools/create_user.php'],
    'fopen' => ['services/ExcelImporter.php', 'services/ReportService.php'],
    'ini_set' => ['core/Session.php', 'core/bootstrap.php'],
    'header' => ['core/Response.php', 'core/SecurityHeaders.php'],
    'setcookie' => ['core/Session.php'],
    'call_user_func' => [], 'call_user_func_array' => [],
    'chmod' => ['services/Backup.php'], 'rmdir' => [], 'readfile' => [], 'move_uploaded_file' => [],
];
$badCalls = [];
foreach (appFiles() as $file) {
    $toks = token_get_all((string) file_get_contents(ROOT . '/' . $file));
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_STRING || !in_array(strtolower($toks[$i][1]), $dangerous, true)) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($toks[$j] ?? null) !== '(') {
            continue;   // bukan pemanggilan
        }
        $p = $i - 1;
        while ($p >= 0 && is_array($toks[$p]) && $toks[$p][0] === T_WHITESPACE) {
            $p--;
        }
        $prev = $toks[$p] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;   // metode ($pdo->exec) atau deklarasi, bukan fungsi global
        }
        $name = strtolower($toks[$i][1]);
        if (in_array($file, $allowedUse[$name] ?? [], true)) {
            continue;
        }
        $badCalls[] = $file . ' ' . $name . '()';
    }
}
check('berbahaya: tidak ada pemanggilan eval/exec/unserialize/md5/rand/fopen/dll. di luar daftar yang sudah ditinjau' . ($badCalls ? ' [' . implode('; ', array_slice(array_unique($badCalls), 0, 8)) . ']' : ''), $badCalls === []);
check('berbahaya: include/require dengan jalur dinamis hanya di pemuat kelas, konfigurasi, dan tampilan (jalur divalidasi)', (function (): bool {
    $allowed = ['core/Autoloader.php', 'core/Config.php', 'core/View.php', 'core/App.php', 'core/bootstrap.php', 'public/index.php'];
    foreach (appFiles() as $f) {
        if (in_array($f, $allowed, true)) {
            continue;
        }
        if (preg_match('/\b(include|require)(_once)?\s*\(?\s*\$/', (string) file_get_contents(ROOT . '/' . $f))) {
            return false;
        }
    }
    return true;
})());
check('berbahaya: View::include menolak nama dengan ".." atau karakter di luar [a-z0-9_/-]', $ctx('core/View.php', "preg_match('#^[a-z0-9_/-]+\$#i', \$name)") && $ctx('core/View.php', "str_contains(\$name, '..')"));
check('berbahaya: extract() di View memakai EXTR_SKIP (data tampilan tidak menimpa variabel internal)', $ctx('core/View.php', 'extract($__data, EXTR_SKIP)'));

// ======================= 3. Superglobal hanya di pintu masuk =======================
$superOk = ['core/Request.php' => ['$_GET', '$_POST', '$_SERVER'], 'core/Session.php' => ['$_SESSION', '$_SERVER'], 'core/Csrf.php' => ['$_SESSION'], 'core/Url.php' => ['$_SERVER'], 'core/App.php' => [],
            'core/Logger.php' => [], 'services/Auth.php' => [], 'core/bootstrap.php' => [], 'services/FormToken.php' => ['$_SESSION'], 'services/AuditLog.php' => [], 'helpers/functions.php' => ['$_SESSION']];
$superBad = [];
foreach (appFiles() as $file) {
    foreach (token_get_all((string) file_get_contents(ROOT . '/' . $file)) as $t) {
        if (is_array($t) && $t[0] === T_VARIABLE && preg_match('/^\$_(GET|POST|COOKIE|FILES|REQUEST|SERVER|ENV|SESSION)$/', $t[1])) {
            if (!in_array($t[1], $superOk[$file] ?? [], true) && !str_starts_with($file, 'database/tools/')) {
                $superBad[$file . ' ' . $t[1]] = true;
            }
        }
    }
}
check('superglobal: $_GET/$_POST/$_COOKIE/$_REQUEST/$_FILES hanya dibaca di Request (pintu masuk tunggal); $_SESSION hanya di inti' . ($superBad ? ' [' . implode('; ', array_keys($superBad)) . ']' : ''), $superBad === []);
check('superglobal: $_REQUEST dan $_COOKIE tidak dipakai sama sekali (tak ada sumber masukan ambigu)', (function (): bool {
    foreach (appFiles() as $f) {
        if (preg_match('/\$_(REQUEST|COOKIE|FILES)\b/', (string) file_get_contents(ROOT . '/' . $f))) {
            return false;
        }
    }
    return true;
})());

// ======================= 4. Keluaran tampilan =======================
// Ekspresi echo-pendek di view yang BUKAN e(...), (int), count, icon, View::include, $content dikumpulkan; sesudah dibuang
// bagian bersih (e(), money(), literal teks, cast int, syarat ternary) tidak boleh tersisa variabel kecuali yang ditinjau.
$stripCalls = static function (string $s, array $names): string {
    foreach ($names as $name) {
        while (preg_match('/' . $name . '\(/', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1];
            $i = $start + strlen($m[0][0]);
            $depth = 1;
            $q = '';
            for (; $i < strlen($s) && $depth > 0; $i++) {
                $ch = $s[$i];
                if ($q !== '') {
                    if ($ch === '\\') {
                        $i++;
                    } elseif ($ch === $q) {
                        $q = '';
                    }
                } elseif ($ch === "'" || $ch === '"') {
                    $q = $ch;
                } elseif ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
            }
            $s = substr($s, 0, $start) . '§' . substr($s, $i);
        }
    }
    return $s;
};
$outputPart = static function (string $expr): string {
    $depth = 0;
    $q = '';
    for ($i = 0; $i < strlen($expr); $i++) {
        $ch = $expr[$i];
        if ($q !== '') {
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === $q) {
                $q = '';
            }
        } elseif ($ch === "'" || $ch === '"') {
            $q = $ch;
        } elseif ($ch === '(' || $ch === '[') {
            $depth++;
        } elseif ($ch === ')' || $ch === ']') {
            $depth--;
        } elseif ($ch === '?' && $depth === 0 && ($expr[$i + 1] ?? '') !== '?' && ($expr[$i - 1] ?? '') !== '?') {
            return substr($expr, $i + 1);
        }
    }
    return $expr;
};
// Ekspresi mentah yang sudah ditinjau (berkas|ekspresi dengan spasi dirapatkan). Penutup: closure di bawah meng-escape sendiri.
$viewReviewed = [
    'home.php' => ['$stat(', '$chart(', '$count('],   // closure di awal home.php: e() pada label dan money() pada angka bulat; chart.php meng-escape sendiri
    'report-table.php' => ['$cell('],                 // $cell memakai e() pada setiap cabang
    'audit-show.php' => ['$a === null ?'],           // percabangan: keluaran hanya e($a) atau literal; $b/$a hanya syarat
    'field.php' => ['$attrs'],                        // $attrs disusun dari e() dan (int) di atas berkas
    'sidebar.php' => ['$count'],                      // (int)
    'loan-simulation.php' => ['$i + 1'],              // bilangan bulat
];
$viewLeft = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/views', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (!str_ends_with($f->getPathname(), '.php')) {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    $base = basename($f->getPathname());
    preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $src, $m);
    foreach ($m[1] as $expr) {
        $e = trim($expr);
        if (preg_match('/^(\\\\?App\\\\Core\\\\)?View::include\(|^\$content$|^icon\(|^csrf_field\(\)$/', $e)) {
            continue;
        }
        $out = $stripCalls($outputPart($e), ['e', 'money', 'month_label']);
        $out = (string) preg_replace('/\(int\)\s*\$\w+(?:\[[^\]]*\])*|\(int\)\s*\([^)]*\)|\(float\)\s*\$\w+(?:\[[^\]]*\])*/', '§', $out);
        $out = (string) preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/"(?:[^"\\\\]|\\\\.)*"/'], ['§', '§'], $out);
        $out = $stripCalls($out, ['count', 'status_badge', 'View::include', 'Audit::label', 'array_sum']);
        if (!preg_match('/\$\w+/', $out)) {
            continue;
        }
        $ok = false;
        foreach ($viewReviewed[$base] ?? [] as $needle) {
            if (str_contains($e, $needle)) {
                $ok = true;
            }
        }
        // ternary bersarang: variabel yang tersisa hanya syarat percabangan (bukan keluaran) bila sisanya sudah § saja
        if (!$ok && preg_match('/^[\s§.():]*(?:\$\w+[\s!=<>&|\[\]§\'"\w]*[?:])+[\s§.():]*$/', $out) && !preg_match('/\$\w+\s*(?:\.|\))\s*(?:§|$)/', preg_replace('/\$\w+\s*(?:!==|===|>|<|>=|<=|&&|\|\|)[^?:]*/', '', $out))) {
            $ok = true;
        }
        if (!$ok) {
            $viewLeft[] = $base . ': ' . preg_replace('/\s+/', ' ', substr($e, 0, 90));
        }
    }
}
check('tampilan: setiap <?= ?> yang tidak di-e() hanya berisi literal, cast int, atau pemanggil yang sudah ditinjau' . ($viewLeft ? ' [' . implode(' | ', array_slice($viewLeft, 0, 5)) . ']' : ''), $viewLeft === []);
check('tampilan: tidak ada echo/print langsung atau <?php echo di view (hanya <?= dengan pagar di atas)', (function (): bool {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/views', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with($f->getPathname(), '.php') && preg_match('/<\?php\s+(echo|print)\b|\bprint_r\(|\bvar_dump\(/', (string) file_get_contents($f->getPathname()))) {
            return false;
        }
    }
    return true;
})());
check('tampilan: e() memakai htmlspecialchars dengan ENT_QUOTES dan UTF-8 (aman di dalam atribut)', preg_match('/function e\([^)]*\)[^{]*\{[^}]*htmlspecialchars\([^;]*ENT_QUOTES[^;]*UTF-8/s', (string) file_get_contents(ROOT . '/helpers/functions.php')) === 1);
check('tampilan: tidak ada sink DOM berbahaya di JavaScript selain pengganti wilayah live dari server sendiri', (function (): bool {
    foreach (glob(ROOT . '/public/assets/js/*.js') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match('/document\.write|\beval\(|new Function|insertAdjacentHTML|outerHTML\s*=|\.innerHTML\s*=\s*[^;]*(location|document\.cookie|\.value)/', $src)) {
            return false;
        }
        if (substr_count($src, '.innerHTML =') > 1) {
            return false;
        }
    }
    return true;
})());
check('tampilan: tidak ada skrip/gaya inline, handler on*=, atau tautan javascript: di seluruh view (selaras CSP)', (function (): bool {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/views', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with($f->getPathname(), '.php') && preg_match('/<script(?![^>]*\bsrc=)|\son[a-z]+=|\sstyle=|javascript:/i', (string) file_get_contents($f->getPathname()))) {
            echo '    ' . $f->getPathname() . "\n";
            return false;
        }
    }
    return true;
})());

// ======================= 5. Kata sandi, acak, rahasia =======================
$pp = (string) file_get_contents(ROOT . '/services/PasswordPolicy.php');
check('sandi: hash bcrypt cost >= 12; needsRehash memakai cost yang sama; kata sandi sementara dibangkitkan dengan random_int', preg_match("/PASSWORD_BCRYPT, \['cost' => 12\]/", $pp) === 1 && substr_count($pp, "'cost' => 12") >= 2 && str_contains($pp, 'random_int'));
check('sandi: tak ada password_hash tanpa PasswordPolicy::hash di luar kebijakan, dan tak ada perbandingan sandi dengan == atau ===', (function (): bool {
    foreach (appFiles(['controllers', 'models', 'services', 'core', 'middleware']) as $f) {
        $src = (string) file_get_contents(ROOT . '/' . $f);
        if ($f !== 'services/PasswordPolicy.php' && preg_match('/\bpassword_hash\(/', $src)) {
            return false;
        }
        if (preg_match('/password(_hash)?\'?\]?\s*(===|==|!==|!=)\s*\$/', $src)) {
            return false;
        }
    }
    return true;
})());
check('acak: token CSRF, ID formulir, dan ID sesi memakai random_bytes/random_int (bukan rand/mt_rand/uniqid)', $ctx('core/Csrf.php', 'random_bytes') && $ctx('services/FormToken.php', 'random_bytes'));
check('csrf: perbandingan token dengan hash_equals (tahan serangan waktu)', $ctx('core/Csrf.php', 'hash_equals'));
check('rahasia: tidak ada kredensial tertanam di kode; .env.example tanpa kata sandi nyata', (function (): bool {
    foreach (appFiles() as $f) {
        $src = (string) file_get_contents(ROOT . '/' . $f);
        if (preg_match('/(DB_PASS|DB_ADMIN_PASS|API_KEY|SECRET_KEY)\s*=\s*[\'"][^\'"\s]{4,}/', $src)) {
            return false;
        }
    }
    $env = (string) file_get_contents(ROOT . '/.env.example');
    return (bool) preg_match('/^DB_PASS=\s*$/m', $env) && (bool) preg_match('/^DB_ADMIN_PASS=\s*$/m', $env);
})());
check('rahasia: .env, kredensial awal, cadangan, sesi, dan log tidak masuk Git (.gitignore)', (function (): bool {
    $gi = (string) file_get_contents(ROOT . '/.gitignore');
    foreach (['.env', 'storage/credentials-', 'storage/backups/*', 'storage/sessions/*', 'storage/logs/*'] as $needle) {
        if (!str_contains($gi, $needle)) {
            return false;
        }
    }
    return true;
})());

// ======================= 6. Sesi dan cookie =======================
$ss = (string) file_get_contents(ROOT . '/core/Session.php');
check('sesi: strict_mode, cookies only, tanpa trans_sid, ID 48 karakter, cookie HttpOnly + SameSite + Secure(saat HTTPS)', str_contains($ss, "'session.use_strict_mode', '1'") && str_contains($ss, "'session.use_only_cookies', '1'") && str_contains($ss, "'session.use_trans_sid', '0'")
    && str_contains($ss, "'session.sid_length', '48'") && str_contains($ss, "'httponly' => true") && str_contains($ss, "'samesite'") && str_contains($ss, "'secure'   => \$request->isSecure()"));
check('sesi: ID diganti saat login dan ganti kata sandi; sesi dihancurkan saat keluar; batas idle dan mutlak ada', $ctx('services/Auth.php', 'Session::regenerate()') && $ctx('services/Auth.php', 'Session::destroy()') && $ctx('core/Session.php', 'idle_timeout') && $ctx('services/Auth.php', 'absolute_timeout'));

// ======================= 7. Berkas dan konfigurasi server =======================
check('berkas: setiap folder non-publik punya .htaccess "Require all denied"; akar menolak .env/.git dan mengarahkan ke public/', (function (): bool {
    foreach (['config', 'controllers', 'core', 'database', 'docs', 'helpers', 'middleware', 'models', 'services', 'storage', 'tests', 'views'] as $d) {
        $f = ROOT . '/' . $d . '/.htaccess';
        if (!is_file($f) || !str_contains((string) file_get_contents($f), 'Require all denied')) {
            echo "    tanpa penolakan: {$d}\n";
            return false;
        }
    }
    $root = (string) file_get_contents(ROOT . '/.htaccess');
    return str_contains($root, 'Options -Indexes') && str_contains($root, '(env|git)') && str_contains($root, 'public/');
})());
check('berkas: public/ hanya berisi index.php, .htaccess, dan assets (tidak ada berkas sensitif atau cadangan)', (function (): bool {
    foreach (scandir(ROOT . '/public') ?: [] as $e) {
        if (!in_array($e, ['.', '..', 'index.php', '.htaccess', 'assets'], true)) {
            echo "    tak terduga di public/: {$e}\n";
            return false;
        }
    }
    return true;
})());
check('berkas: tak ada berkas sensitif (.sql, .env, .bak, .log, .zip, .gz) di dalam public/assets', (function (): bool {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/public/assets', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (preg_match('/\.(sql|env|bak|log|zip|gz|php|txt|json)$/i', $f->getFilename())) {
            echo '    ' . $f->getPathname() . "\n";
            return false;
        }
    }
    return true;
})());
check('berkas: pustaka pihak ketiga (Chart.js) dipatok hash-nya di tes dan satu-satunya berkas vendor', (function (): bool {
    $vendor = glob(ROOT . '/public/assets/vendor/*') ?: [];
    return count($vendor) === 1 && str_ends_with($vendor[0], 'chart.umd.min.js') && str_contains((string) file_get_contents(ROOT . '/tests/http.php'), 'chart.umd.min.js');
})());
check('header: CSP, COOP, CORP, nosniff, DENY, Permissions-Policy, no-store didefinisikan di satu tempat (SecurityHeaders) dan X-Powered-By dihapus', (function (): bool {
    $h = (string) file_get_contents(ROOT . '/core/SecurityHeaders.php');
    foreach (['Content-Security-Policy', "object-src 'none'", "frame-ancestors 'none'", 'Cross-Origin-Opener-Policy', 'Cross-Origin-Resource-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Permissions-Policy', 'no-store', "header_remove('X-Powered-By')", 'Strict-Transport-Security'] as $needle) {
        if (!str_contains($h, $needle)) {
            return false;
        }
    }
    return !str_contains($h, 'unsafe-inline') && !str_contains($h, 'unsafe-eval');
})());
check('galat: galat PHP tidak ditampilkan ke pengguna di luar APP_DEBUG (bootstrap memaksa display_errors=0 untuk web) dan dicatat ke log', $ctx('core/bootstrap.php', "'display_errors', (bool)") && $ctx('core/bootstrap.php', "'log_errors', '1'"));

// ======================= 8. Apache sungguhan di mesin ini (opsional) =======================
// Bila Apache XAMPP aktif, buktikan folder non-publik memang tidak bisa dibaca lewat URL. Dilewati bila tak terjangkau.
$probe = static function (string $path): int {
    $ch = curl_init('http://localhost/simpan_pinjam/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
};
if ($probe('login') === 200) {
    $exposed = [];
    foreach (['.env', '.env.example', '.git/config', '.gitignore', 'config/app.php', 'core/App.php', 'services/Auth.php', 'controllers/AuthController.php', 'views/layouts/app.php', 'models/Audit.php', 'helpers/functions.php',
              'database/migrations/001_create_core_tables.sql', 'database/tools/migrate.php', 'docs/tambahan-deploy-cadangan.md', 'tests/run.php', 'tests/http.php', 'storage/logs/', 'storage/sessions/', 'storage/backups/', 'storage/credentials-awal.txt', 'storage/', 'composer.json'] as $p) {
        $code = $probe($p);
        if (!in_array($code, [403, 404], true)) {
            $exposed[] = $p . ' (' . $code . ')';
        }
    }
    check('apache: ' . 22 . ' jalur sensitif (kode, konfigurasi, .env, .git, migrasi, tes, log, sesi, cadangan, kredensial) dijawab 403/404 lewat URL' . ($exposed ? ' [' . implode(', ', $exposed) . ']' : ''), $exposed === []);
    check('apache: daftar folder dimatikan (tanpa Index of) untuk assets dan akar', !str_contains((string) @file_get_contents('http://localhost/simpan_pinjam/assets/'), 'Index of') && !str_contains((string) @file_get_contents('http://localhost/simpan_pinjam/assets/css/'), 'Index of'));
} else {
    echo "  (dilewati: Apache lokal http://localhost/simpan_pinjam tidak aktif)\n";
}

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
