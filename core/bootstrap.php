<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require __DIR__ . '/Autoloader.php';
App\Core\Autoloader::register([
    'App\\Core\\'        => BASE_PATH . '/core/',
    'App\\Controllers\\' => BASE_PATH . '/controllers/',
    'App\\Middleware\\'  => BASE_PATH . '/middleware/',
    'App\\Models\\'      => BASE_PATH . '/models/',
    'App\\Services\\'    => BASE_PATH . '/services/',
    'App\\Helpers\\'     => BASE_PATH . '/helpers/',
]);

require BASE_PATH . '/helpers/functions.php';

// APP_ENV_FILE (variabel proses, hanya bisa diatur oleh yang menjalankan server) memilih berkas env,
// mis. .env.testing untuk tes HTTP. Dibatasi ke pola .env.* di akar proyek.
$envFile = getenv('APP_ENV_FILE');
$envFile = is_string($envFile) && preg_match('/^\.env\.[a-z]+$/', $envFile) === 1 ? $envFile : '.env';
App\Core\Env::load(BASE_PATH . '/' . $envFile);
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'Asia/Jakarta'));

// Galat PHP (peringatan, notice) tidak boleh tampil ke pengguna kecuali APP_DEBUG menyala, apa pun isi php.ini:
// pesan seperti "Array to string conversion in C:\...\x.php on line 12" membocorkan jalur dan struktur kode.
// Semuanya tetap dicatat ke storage/logs/php-TANGGAL.log agar bisa diperbaiki. CLI (alat, tes) tetap menampilkan.
if (PHP_SAPI !== 'cli') {
    error_reporting(E_ALL);
    ini_set('display_errors', (bool) App\Core\Config::get('app.debug', false) ? '1' : '0');
    ini_set('display_startup_errors', '0');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');
    if (is_dir(BASE_PATH . '/storage/logs') && is_writable(BASE_PATH . '/storage/logs')) {
        ini_set('error_log', BASE_PATH . '/storage/logs/php-' . date('Y-m-d') . '.log');
    }
}
mb_internal_encoding('UTF-8');
