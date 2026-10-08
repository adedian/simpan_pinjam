<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Pemeriksaan kesiapan produksi (Phase 16). Hanya MEMBACA; tidak mengubah apa pun.
 * Tiap pemeriksaan menghasilkan ['level' => OK|WARN|FAIL, 'name' => ..., 'detail' => ...].
 * FAIL = jangan buka untuk pengguna sebelum diperbaiki. WARN = sebaiknya diperbaiki / disadari.
 */
final class Preflight
{
    public const OK = 'OK';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    /** @var array<int,array{level:string,name:string,detail:string}> */
    private array $results = [];

    /** @param array{allow_http?:bool,backup_dir?:string,max_backup_age_hours?:int} $options */
    public function __construct(private array $options = [])
    {
    }

    /** @return array<int,array{level:string,name:string,detail:string}> */
    public function run(): array
    {
        $this->results = [];
        $this->runtime();
        $this->application();
        $this->filesystem();
        $this->database();
        $this->backups();
        return $this->results;
    }

    /** @param array<int,array{level:string,name:string,detail:string}> $results */
    public static function hasFailure(array $results): bool
    {
        foreach ($results as $r) {
            if ($r['level'] === self::FAIL) {
                return true;
            }
        }
        return false;
    }

    private function add(string $level, string $name, string $detail = ''): void
    {
        $this->results[] = ['level' => $level, 'name' => $name, 'detail' => $detail];
    }

    private function expect(bool $good, string $name, string $failDetail, string $okDetail = '', string $badLevel = self::FAIL): void
    {
        $good ? $this->add(self::OK, $name, $okDetail) : $this->add($badLevel, $name, $failDetail);
    }

    // ------------------------------------------------------------------ PHP

    private function runtime(): void
    {
        $this->expect(version_compare(PHP_VERSION, '8.0.0', '>='), 'Versi PHP', 'Butuh PHP 8.0 atau lebih baru, ditemukan ' . PHP_VERSION . '.', PHP_VERSION);
        foreach (['pdo_mysql', 'mbstring'] as $ext) {
            $this->expect(extension_loaded($ext), "Ekstensi PHP {$ext}", "Ekstensi {$ext} belum aktif di php.ini.");
        }
        $display = strtolower((string) ini_get('display_errors'));
        $this->expect(in_array($display, ['', '0', 'off', 'false', 'stderr'], true), 'php.ini display_errors', 'display_errors menyala: galat PHP bisa tampil ke pengguna. Matikan di php.ini (periksa juga php.ini Apache).', 'mati', self::WARN);
        $this->expect(!(bool) ini_get('expose_php'), 'php.ini expose_php', 'expose_php menyala: versi PHP diumumkan di header. Set expose_php=Off.', 'mati', self::WARN);
    }

    // ------------------------------------------------------------------ aplikasi

    private function application(): void
    {
        $env = (string) Config::get('app.env');
        $this->expect($env === 'production', 'APP_ENV', "APP_ENV={$env}. Produksi harus APP_ENV=production (halaman acuan gaya dan rincian /health hanya hidup di local).", 'production');
        $this->expect(!(bool) Config::get('app.debug'), 'APP_DEBUG', 'APP_DEBUG menyala: jejak tumpukan bisa tampil ke pengguna. Set APP_DEBUG=false.', 'mati');

        $url    = (string) Config::get('app.url', '');
        $https  = str_starts_with($url, 'https://');
        $allow  = (bool) ($this->options['allow_http'] ?? false);
        if ($https) {
            $this->add(self::OK, 'APP_URL', $url);
        } else {
            $this->add($allow ? self::WARN : self::FAIL, 'APP_URL / HTTPS', $url === ''
                ? 'APP_URL kosong. Isi alamat publik berskema https:// (kata sandi dan data keuangan tidak boleh lewat jaringan tanpa enkripsi). Jaringan lokal tanpa TLS: jalankan dengan --allow-http dan sadari risikonya.'
                : "APP_URL ({$url}) tidak memakai https://.");
        }
        $this->expect((bool) Config::get('app.force_https', false) || !$https, 'APP_FORCE_HTTPS', 'HTTPS tersedia tetapi belum dipaksa. Set APP_FORCE_HTTPS=true agar permintaan http dialihkan.', 'aktif atau tidak diperlukan', self::WARN);

        $idle = (int) Config::get('app.session.idle_timeout', 1800);
        $this->expect($idle > 0 && $idle <= 3600, 'SESSION_IDLE_TIMEOUT', "Sesi menganggur {$idle} detik. Untuk aplikasi keuangan disarankan 30 menit (1800) atau kurang.", $idle . ' detik', self::WARN);
        $proxies = (array) Config::get('app.trusted_proxies', []);
        $this->add(self::OK, 'TRUSTED_PROXIES', $proxies === [] ? 'kosong (langsung ke Apache; X-Forwarded-* diabaikan)' : implode(', ', $proxies));
    }

    // ------------------------------------------------------------------ berkas

    private function filesystem(): void
    {
        $root = dirname(__DIR__);
        foreach (['storage/logs', 'storage/sessions'] as $dir) {
            $this->expect(is_dir("{$root}/{$dir}") && is_writable("{$root}/{$dir}"), "Folder {$dir} bisa ditulis", "{$dir} tidak ada atau tidak bisa ditulis oleh akun yang menjalankan PHP.");
        }
        $missing = [];
        foreach (['.htaccess', 'storage/.htaccess', 'config/.htaccess', 'database/.htaccess', 'core/.htaccess', 'services/.htaccess', 'views/.htaccess', 'tests/.htaccess', 'docs/.htaccess', 'public/.htaccess'] as $f) {
            if (!is_file("{$root}/{$f}")) {
                $missing[] = $f;
            }
        }
        $this->expect($missing === [], 'Berkas .htaccess pelindung', 'Hilang: ' . implode(', ', $missing) . '. Tanpa ini folder non-publik bisa terbaca bila document root salah. Idealnya document root = folder public/.');
        $creds = glob("{$root}/storage/credentials-*.txt") ?: [];
        $this->expect($creds === [], 'Berkas kata sandi sementara', 'Ada ' . count($creds) . ' berkas storage/credentials-*.txt berisi kata sandi sementara. Salurkan ke pemiliknya lalu HAPUS.', 'tidak ada', self::WARN);
    }

    // ------------------------------------------------------------------ database

    private function database(): void
    {
        $name  = (string) Config::get('database.name');
        $user  = (string) Config::get('database.user');
        $admin = (string) Config::get('database.admin_user');
        $this->expect($user !== 'root' && $user !== $admin, 'Akun database aplikasi', "Aplikasi web berjalan sebagai '{$user}' (root/akun admin). Buat akun terbatas: php database/tools/create_app_user.php --apply.", $user);
        $this->expect((string) Config::get('database.pass') !== '', 'Kata sandi akun aplikasi', 'DB_PASS kosong.');
        $this->expect((string) Config::get('database.admin_pass') !== '', 'Kata sandi akun admin database', "DB_ADMIN_PASS kosong. Pastikan MySQL hanya menerima sambungan lokal (bind-address=127.0.0.1) dan beri akun admin kata sandi.", 'terisi', self::WARN);

        try {
            $app = Database::connect($user, (string) Config::get('database.pass'), $name);
            $this->add(self::OK, 'Sambungan akun aplikasi', "database {$name}");
        } catch (\Throwable $e) {
            $this->add(self::FAIL, 'Sambungan akun aplikasi', 'Gagal tersambung: ' . $e->getMessage());
            return;
        }

        // Hak akun aplikasi: tanpa hak merusak (DROP/ALTER/TRIGGER/...), audit hanya SELECT+INSERT.
        $grants = array_map('strval', $app->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN));
        $bad = [];
        foreach ($grants as $g) {
            if (preg_match('/^GRANT (.+?) ON (\S+) TO/i', $g, $m) !== 1) {
                continue;
            }
            $privs = strtoupper($m[1]);
            $scope = $m[2];
            if (str_contains($privs, 'ALL PRIVILEGES') || preg_match('/\b(DROP|ALTER|TRIGGER|CREATE|GRANT OPTION|SUPER|FILE)\b/', $privs) === 1) {
                $bad[] = $privs . ' pada ' . $scope;
            }
            if (preg_match('/`(audit_logs|transaction_validations)`$/', $scope) === 1 && preg_match('/\b(UPDATE|DELETE)\b/', $privs) === 1) {
                $bad[] = $privs . ' pada ' . $scope . ' (harus append-only)';
            }
            if ($scope === '*.*' && $privs !== 'USAGE') {
                $bad[] = $privs . ' pada seluruh server';
            }
            if (preg_match('/^`[^`]+`\.\*$/', $scope) === 1 && preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $privs) === 1) {
                $bad[] = $privs . ' pada ' . $scope . ' (seluruh database, bukan per tabel)';
            }
        }
        $this->expect($bad === [], 'Hak akun aplikasi', 'Terlalu luas: ' . implode('; ', array_unique($bad)) . '. Jalankan create_app_user.php --apply.', 'minimal (audit append-only, tanpa DROP/ALTER/TRIGGER)');

        try {
            $admPdo = Backup::admin($name);
            $migrator = new Migrator($admPdo, dirname(__DIR__) . '/database/migrations');
            $applied  = $migrator->applied();
            $pending  = array_values(array_diff(array_keys($migrator->files()), array_keys($applied)));
            $this->expect($pending === [], 'Migrasi database', 'Belum diterapkan: ' . implode(', ', $pending) . '. Jalankan php database/tools/migrate.php.', count($applied) . ' diterapkan');

            $g = $admPdo->query('SELECT selisih FROM v_global_summary')->fetch();
            $issues = (int) $admPdo->query('SELECT COUNT(*) FROM v_integrity_issues')->fetchColumn();
            $this->expect((int) $g['selisih'] === 0 && $issues === 0, 'Integritas keuangan', 'Selisih invarian ' . (int) $g['selisih'] . ', masalah integritas ' . $issues . '. Jalankan php database/tools/check_integrity.php.', 'invarian nol, tanpa masalah');

            $head = (int) $admPdo->query("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'HEAD' AND u.is_active = 1 AND u.deleted_at IS NULL")->fetchColumn();
            $this->expect($head > 0, 'Akun Head aktif', 'Tidak ada akun Head aktif.');
            $chk = (int) $admPdo->query("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'PEMERIKSA' AND u.is_active = 1 AND u.deleted_at IS NULL")->fetchColumn();
            $this->expect($chk > 0, 'Akun Pemeriksa aktif', 'Belum ada akun Pemeriksa: transaksi milik/terkait Head tidak bisa divalidasi siapa pun. Buat di Data Pengguna.', (string) $chk, self::WARN);
            $stale = (int) $admPdo->query('SELECT COUNT(*) FROM users WHERE must_change_password = 1 AND is_active = 1 AND deleted_at IS NULL AND created_at < NOW() - INTERVAL 14 DAY')->fetchColumn();
            $this->expect($stale === 0, 'Kata sandi sementara basi', "{$stale} akun masih memakai kata sandi sementara lebih dari 14 hari. Reset atau nonaktifkan.", 'tidak ada', self::WARN);
        } catch (\Throwable $e) {
            $this->add(self::FAIL, 'Pemeriksaan database (akun admin)', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ cadangan

    private function backups(): void
    {
        $dir   = (string) ($this->options['backup_dir'] ?? dirname(__DIR__) . '/storage/backups');
        $max   = (int) ($this->options['max_backup_age_hours'] ?? 48);
        $files = is_dir($dir) ? Backup::listBackups($dir) : [];
        if ($files === []) {
            $this->add(self::WARN, 'Cadangan database', "Belum ada cadangan di {$dir}. Jalankan php database/tools/backup.php --prove dan jadwalkan harian.");
            return;
        }
        $age = (int) floor((time() - (int) filemtime($files[0])) / 3600);
        $check = Backup::check($files[0]);
        $this->expect($age <= $max, 'Cadangan terbaru', "Cadangan terbaru berumur {$age} jam (batas {$max}). Pastikan jadwal harian berjalan.", basename($files[0]) . " ({$age} jam lalu)", self::WARN);
        $this->expect($check['ok'], 'Keutuhan cadangan terbaru', implode(' ', $check['problems']), 'sehat (SHA-256, lengkap, tabel inti dan trigger ada)');
    }
}
