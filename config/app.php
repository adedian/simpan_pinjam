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
    // Alamat publik situs (mis. https://simpin.example.id). Dipakai untuk memaksa HTTPS; kosong = tidak memaksa.
    'url'         => rtrim((string) Env::get('APP_URL', ''), '/'),
    'force_https' => (bool) Env::get('APP_FORCE_HTTPS', false),
    // Daftar IP proxy/penyeimbang beban tepercaya (dipisah koma). Hanya dari alamat ini header
    // X-Forwarded-For / X-Forwarded-Proto dipercaya. Kosong = langsung ke Apache (bawaan).
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    // Lama (detik) hasil pemeriksaan integritas di dashboard dipakai ulang; 0 = selalu hitung. Lihat Dashboard::integrityIssues.
    'integrity_cache_ttl' => (int) Env::get('INTEGRITY_CACHE_TTL', 60),
    'session'   => [
        'name'         => 'adem_ayem_sid',
        'idle_timeout' => (int) Env::get('SESSION_IDLE_TIMEOUT', 1800),
        // Umur maksimum sebuah sesi sejak masuk, walau terus dipakai (12 jam): sesi yang dicuri tidak hidup selamanya.
        'absolute_timeout' => (int) Env::get('SESSION_ABSOLUTE_TIMEOUT', 43200),
    ],
];
