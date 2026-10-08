<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name'      => 'Sistem Simpan Pinjam Adem Ayem',
    'short'     => 'Adem Ayem',
    'tagline'   => 'yo nabung, yo ngutang',
    'env'       => (string) Env::get('APP_ENV', 'production'),
    'debug'     => (bool) Env::get('APP_DEBUG', false),
    'timezone'  => 'Asia/Jakarta',
    'base_path' => (string) Env::get('APP_BASE_PATH', 'auto'),
    'session'   => [
        'name'         => 'adem_ayem_sid',
        'idle_timeout' => (int) Env::get('SESSION_IDLE_TIMEOUT', 1800),
    ],
];
