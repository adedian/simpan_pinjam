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
mb_internal_encoding('UTF-8');
