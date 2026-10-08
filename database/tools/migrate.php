<?php
declare(strict_types=1);

/**
 * Terapkan migrasi database.
 *
 *   php database/tools/migrate.php                 terapkan migrasi yang belum
 *   php database/tools/migrate.php --status        daftar migrasi
 *   php database/tools/migrate.php --fresh         KOSONGKAN database lalu terapkan ulang (hanya APP_ENV=local)
 *   php database/tools/migrate.php --db=NAMA       database lain (mis. database uji)
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Migrator;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

$dbName = (string) ($options['db'] ?? Config::get('database.name'));
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "Nama database tidak valid.\n");
    exit(1);
}

try {
    $server = Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), '');
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), $dbName);

    $migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations');

    if (isset($options['fresh'])) {
        if (Config::get('app.env') !== 'local') {
            throw new RuntimeException('--fresh hanya diizinkan saat APP_ENV=local.');
        }
        $migrator->dropEverything();
        echo "Database {$dbName} dikosongkan.\n";
    }

    if (isset($options['status'])) {
        $applied = $migrator->applied();
        foreach ($migrator->files() as $name => $file) {
            printf("  [%s] %s\n", isset($applied[$name]) ? 'sudah' : 'belum', $name);
        }
        exit(0);
    }

    $done = $migrator->migrate();
    echo $done === [] ? "Tidak ada migrasi baru. Database {$dbName} sudah terkini.\n" : "Diterapkan ke {$dbName}:\n  " . implode("\n  ", $done) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
