<?php
declare(strict_types=1);

/**
 * Buat akun database KHUSUS aplikasi dengan hak seminimal mungkin, supaya aplikasi web tidak
 * berjalan sebagai root. Konsekuensi yang disengaja:
 *   - audit_logs, transaction_validations : hanya SELECT + INSERT (tidak bisa UPDATE/DELETE)
 *   - view                                : hanya SELECT
 *   - tidak punya DROP / ALTER / TRIGGER / CREATE, jadi tidak bisa membuang trigger pengaman
 *
 *   php database/tools/create_app_user.php            tampilkan rencana (tidak mengubah apa pun)
 *   php database/tools/create_app_user.php --apply    buat akun + simpan DB_USER/DB_PASS ke .env
 *   php database/tools/create_app_user.php --db=NAMA  database lain
 * Skema HARUS sudah dimigrasi dulu (hak diberikan per tabel).
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

const APP_USER = 'adem_ayem_app';

$apply  = in_array('--apply', $argv, true);
$dbName = (string) Config::get('database.name');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--db=([A-Za-z0-9_]+)$/', $arg, $m)) {
        $dbName = $m[1];
    }
}

try {
    $pdo = Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), $dbName);

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $views  = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
    if ($tables === []) {
        throw new RuntimeException("Database {$dbName} belum dimigrasi. Jalankan migrate.php dulu.");
    }

    $insertOnly = ['audit_logs', 'transaction_validations'];
    $readOnly   = ['schema_migrations', 'roles', 'loan_plan_adjustments'];   // penyesuaian rencana hanya ditulis lewat plan_adjust.php
    $grants     = [];
    foreach ($tables as $t) {
        $grants[$t] = in_array($t, $insertOnly, true) ? 'SELECT, INSERT'
            : (in_array($t, $readOnly, true) ? 'SELECT' : 'SELECT, INSERT, UPDATE, DELETE');
    }
    foreach ($views as $v) {
        $grants[$v] = 'SELECT';
    }

    echo "Akun: " . APP_USER . " pada database {$dbName}\n";
    foreach ($grants as $obj => $priv) {
        printf("  %-26s %s\n", $obj, $priv);
    }
    if (!$apply) {
        echo "\n(Rencana saja. Tambahkan --apply untuk membuat akun dan memperbarui .env.)\n";
        exit(0);
    }

    $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    foreach (['localhost', '127.0.0.1'] as $host) {
        $user = "'" . APP_USER . "'@'{$host}'";
        $pdo->exec("CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY " . $pdo->quote($password));
        $pdo->exec("ALTER USER {$user} IDENTIFIED BY " . $pdo->quote($password));
        $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$user}");
        foreach ($grants as $obj => $priv) {
            $pdo->exec("GRANT {$priv} ON `{$dbName}`.`{$obj}` TO {$user}");
        }
    }
    $pdo->exec('FLUSH PRIVILEGES');

    $envFile = BASE_PATH . '/.env';
    $env = (string) file_get_contents($envFile);
    foreach (['DB_USER' => APP_USER, 'DB_PASS' => $password] as $key => $value) {
        $line = "{$key}={$value}";
        $env = preg_match("/^{$key}=.*$/m", $env) ? (string) preg_replace("/^{$key}=.*$/m", $line, $env) : rtrim($env) . "\n{$line}\n";
    }
    file_put_contents($envFile, $env);
    echo "\nAkun dibuat. DB_USER dan DB_PASS di .env diperbarui (kata sandi acak, tidak ditampilkan).\n";
    echo "Skrip CLI (migrasi/impor) tetap memakai DB_ADMIN_USER/DB_ADMIN_PASS.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
