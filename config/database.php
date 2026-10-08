<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'name' => (string) Env::get('DB_NAME', 'simpan_pinjam_adem_ayem'),
    // Akun aplikasi: hak terbatas (lihat database/tools/create_app_user.php).
    'user' => (string) Env::get('DB_USER', 'root'),
    'pass' => (string) Env::get('DB_PASS', ''),
    // Akun admin: HANYA untuk skrip migrasi/impor di CLI, tidak dipakai saat melayani web.
    'admin_user' => (string) Env::get('DB_ADMIN_USER', 'root'),
    'admin_pass' => (string) Env::get('DB_ADMIN_PASS', ''),
];
