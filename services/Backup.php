<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Cadangan dan pemulihan database (Phase 16). Dipakai oleh skrip CLI database/tools/{backup,restore}.php.
 *
 * - Cadangan = mysqldump (konsisten: satu snapshot transaksi, termasuk trigger/view/rutin) dialirkan ke .sql.gz,
 *   ditulis ke berkas sementara lalu diganti nama setelah lengkap, disertai berkas .sha256.
 * - Cadangan baru bernilai bila TERBUKTI bisa dipulihkan: verify() memulihkan ke database sementara dan
 *   memeriksa saldo, invarian, dan integritas.
 * - Kata sandi database tidak pernah ada di baris perintah atau lingkungan proses: dipakai berkas opsi sementara
 *   yang dihapus segera sesudahnya.
 */
final class Backup
{
    public const PREFIX = 'adem-ayem-';

    /** Tabel inti yang wajib ada di cadangan yang sehat. */
    private const REQUIRED_TABLES = ['transactions', 'members', 'audit_logs', 'users', 'settings', 'schema_migrations'];

    // ------------------------------------------------------------------ biner MySQL

    /** Lokasi mysqldump/mysql: MYSQL_BIN_DIR, folder xampp di sebelah PHP, lalu PATH. */
    public static function binary(string $name): string
    {
        $exe   = $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $dirs  = [];
        $env   = getenv('MYSQL_BIN_DIR');
        if (is_string($env) && $env !== '') {
            $dirs[] = $env;
        }
        $dirs[] = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin';
        foreach ($dirs as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (is_file($path)) {
                return $path;
            }
        }
        return $exe;   // biarkan sistem mencari di PATH
    }

    /** Berkas opsi klien sementara berisi kredensial admin. Panggil unlink() sesudah dipakai. */
    private static function credentialsFile(): string
    {
        $esc  = static fn (string $v): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
        $file = (string) tempnam(sys_get_temp_dir(), 'adem');
        file_put_contents($file, "[client]\n"
            . 'user="' . $esc((string) Config::get('database.admin_user')) . "\"\n"
            . 'password="' . $esc((string) Config::get('database.admin_pass')) . "\"\n"
            . 'host="' . $esc((string) Config::get('database.host')) . "\"\n"
            . 'port=' . (int) Config::get('database.port') . "\n");
        @chmod($file, 0600);
        return $file;
    }

    // ------------------------------------------------------------------ cadangan

    /**
     * Buat cadangan database $dbName ke $dir. Mengembalikan jalur berkas .sql.gz (lengkap atau pengecualian).
     *
     * @return array{file:string,bytes:int,sha256:string}
     */
    public static function create(string $dbName, string $dir, ?string $tag = null): array
    {
        self::assertName($dbName);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Folder cadangan tidak bisa dibuat: {$dir}");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Folder cadangan tidak bisa ditulis: {$dir}");
        }
        $name  = self::PREFIX . ($tag !== null ? preg_replace('/[^a-z0-9-]/i', '', $tag) . '-' : '') . date('Ymd-His') . '.sql.gz';
        $final = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        $part  = $final . '.part';
        $err   = (string) tempnam(sys_get_temp_dir(), 'adem');
        $cred  = self::credentialsFile();

        try {
            $cmd  = [self::binary('mysqldump'), '--defaults-extra-file=' . $cred, '--single-transaction', '--routines', '--triggers', '--events',
                     '--hex-blob', '--default-character-set=utf8mb4', '--add-drop-table', $dbName];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('mysqldump tidak bisa dijalankan. Set MYSQL_BIN_DIR bila lokasinya tidak standar.');
            }
            $gz = gzopen($part, 'wb6');
            if ($gz === false) {
                proc_terminate($proc);
                throw new \RuntimeException("Tidak bisa menulis {$part}");
            }
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 20);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    gzwrite($gz, $chunk);
                }
            }
            fclose($pipes[1]);
            gzclose($gz);
            $code = proc_close($proc);
            if ($code !== 0) {
                throw new \RuntimeException('mysqldump gagal (kode ' . $code . '): ' . trim((string) file_get_contents($err)));
            }
            if (!@rename($part, $final)) {
                throw new \RuntimeException('Tidak bisa menyelesaikan berkas cadangan.');
            }
        } catch (\Throwable $e) {
            @unlink($part);
            throw $e;
        } finally {
            @unlink($cred);
            @unlink($err);
        }

        $sha = hash_file('sha256', $final) ?: '';
        file_put_contents($final . '.sha256', $sha . '  ' . basename($final) . "\n");
        return ['file' => $final, 'bytes' => (int) filesize($final), 'sha256' => $sha];
    }

    /**
     * Periksa berkas cadangan tanpa memulihkannya: sidik jari cocok, bisa dibaca, selesai ("Dump completed"),
     * memuat tabel inti dan trigger.
     *
     * @return array{ok:bool,problems:array<int,string>}
     */
    public static function check(string $file): array
    {
        $problems = [];
        if (!is_file($file) || (int) filesize($file) === 0) {
            return ['ok' => false, 'problems' => ['Berkas tidak ada atau kosong.']];
        }
        $shaFile = $file . '.sha256';
        if (is_file($shaFile)) {
            $expected = strtolower(substr(trim((string) file_get_contents($shaFile)), 0, 64));
            if ($expected !== hash_file('sha256', $file)) {
                $problems[] = 'Sidik jari SHA-256 tidak cocok: berkas berubah atau rusak.';
            }
        } else {
            $problems[] = 'Berkas .sha256 tidak ada (keutuhan tidak bisa dibuktikan).';
        }
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            return ['ok' => false, 'problems' => array_merge($problems, ['Bukan berkas gzip yang valid.'])];
        }
        $tables = [];
        $last   = '';
        $hasTrigger = false;
        while (($line = gzgets($gz)) !== false) {
            $t = rtrim($line);
            if ($t === '') {
                continue;
            }
            $last = $t;
            if (str_starts_with($t, 'CREATE TABLE')) {
                if (preg_match('/^CREATE TABLE `([^`]+)`/', $t, $m) === 1) {
                    $tables[$m[1]] = true;
                }
            } elseif (!$hasTrigger && str_contains($t, 'TRIGGER') && str_contains($t, 'CREATE')) {
                $hasTrigger = true;
            }
        }
        $truncated = !gzeof($gz);
        gzclose($gz);
        if ($truncated) {
            $problems[] = 'Berkas gzip terpotong atau rusak.';
        }
        if (!str_starts_with($last, '-- Dump completed')) {
            $problems[] = 'Cadangan tidak selesai (baris penutup "Dump completed" tidak ada).';
        }
        $missing = array_diff(self::REQUIRED_TABLES, array_keys($tables));
        if ($missing !== []) {
            $problems[] = 'Tabel inti tidak ada di cadangan: ' . implode(', ', $missing) . '.';
        }
        if (!$hasTrigger) {
            $problems[] = 'Trigger pengaman tidak ada di cadangan.';
        }
        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /** Hapus cadangan tertua; sisakan $keep terbaru. Hanya berkas berpola nama kita yang disentuh. @return array<int,string> */
    public static function rotate(string $dir, int $keep): array
    {
        $files = self::listBackups($dir);
        $gone  = [];
        foreach (array_slice($files, max(1, $keep)) as $file) {   // minimal 1 terbaru selalu aman
            @unlink($file);
            @unlink($file . '.sha256');
            $gone[] = basename($file);
        }
        return $gone;
    }

    /** Cadangan di folder, terbaru dulu. @return array<int,string> */
    public static function listBackups(string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::PREFIX . '*.sql.gz') ?: [];
        usort($files, static fn (string $a, string $b): int => [filemtime($b), $b] <=> [filemtime($a), $a]);
        return $files;
    }

    // ------------------------------------------------------------------ pemulihan

    /** Pulihkan $file ke database $dbName. Database harus sudah ada dan KOSONG (pemanggil yang menyiapkan). */
    public static function restore(string $file, string $dbName): void
    {
        self::assertName($dbName);
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Berkas cadangan tidak bisa dibuka.');
        }
        $err  = (string) tempnam(sys_get_temp_dir(), 'adem');
        $cred = self::credentialsFile();
        try {
            $cmd  = [self::binary('mysql'), '--defaults-extra-file=' . $cred, '--default-character-set=utf8mb4', $dbName];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $err, 'a'], 2 => ['file', $err, 'a']], $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('mysql tidak bisa dijalankan. Set MYSQL_BIN_DIR bila lokasinya tidak standar.');
            }
            while (($line = gzgets($gz)) !== false) {
                // DEFINER akun asal mungkin tidak ada di server tujuan; pemulihan memakai akun admin yang sedang berjalan.
                if (str_contains($line, 'DEFINER=')) {
                    $line = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`/', '', $line);
                }
                if (@fwrite($pipes[0], $line) === false) {
                    break;   // mysql berhenti (galat); kode keluar dilaporkan di bawah
                }
            }
            @fclose($pipes[0]);
            $code = proc_close($proc);
            if ($code !== 0) {
                throw new \RuntimeException('Pemulihan gagal (kode ' . $code . '): ' . trim((string) file_get_contents($err)));
            }
        } finally {
            gzclose($gz);
            @unlink($cred);
            @unlink($err);
        }
    }

    /** Buat database kosong (hapus dulu bila $replace). */
    public static function freshDatabase(string $dbName, bool $replace): void
    {
        self::assertName($dbName);
        $server = self::admin('');
        $exists = (bool) $server->query("SHOW DATABASES LIKE '" . $dbName . "'")->fetchColumn();
        if ($exists && !$replace) {
            throw new \RuntimeException("Database {$dbName} sudah ada. Pakai --replace untuk menimpanya.");
        }
        if ($exists) {
            $server->exec("DROP DATABASE `{$dbName}`");
        }
        $server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    // ------------------------------------------------------------------ pembuktian

    /**
     * Sidik jari keuangan sebuah database: dipakai membuktikan hasil pemulihan utuh.
     *
     * @return array{tables:array<int,string>,migrations:int,transactions:int,approved:int,audit:int,saldo:int,kas:int,piutang:int,selisih:int,issues:int}
     */
    public static function fingerprint(string $dbName): array
    {
        $pdo    = self::admin($dbName);
        $one    = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables);
        $g = $pdo->query('SELECT saldo_tabungan, kas_tersedia, piutang_beredar, selisih FROM v_global_summary')->fetch();
        return [
            'tables'       => $tables,
            'migrations'   => $one('SELECT COUNT(*) FROM schema_migrations'),
            'transactions' => $one('SELECT COUNT(*) FROM transactions'),
            'approved'     => $one("SELECT COUNT(*) FROM transactions WHERE status = 'DISETUJUI'"),
            'audit'        => $one('SELECT COUNT(*) FROM audit_logs'),
            'saldo'        => (int) $g['saldo_tabungan'],
            'kas'          => (int) $g['kas_tersedia'],
            'piutang'      => (int) $g['piutang_beredar'],
            'selisih'      => (int) $g['selisih'],
            'issues'       => $one('SELECT COUNT(*) FROM v_integrity_issues'),
        ];
    }

    /**
     * Buktikan cadangan dengan memulihkannya ke database sementara dan membandingkan dengan sumber.
     * Sumber boleh sudah berubah sejak cadangan dibuat (data hanya bertambah), jadi angka sumber boleh >= hasil
     * pemulihan untuk transaksi/audit; sisanya (selisih, integritas, struktur) harus sehat.
     *
     * @return array{ok:bool,problems:array<int,string>,restored:array<string,mixed>}
     */
    public static function verify(string $file, string $sourceDb): array
    {
        $scratch  = substr($sourceDb, 0, 40) . '_verify_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $problems = [];
        $restored = [];
        try {
            self::freshDatabase($scratch, false);
            self::restore($file, $scratch);
            $restored = self::fingerprint($scratch);
            $source   = self::fingerprint($sourceDb);

            $problems = self::compare($restored, $source);
        } catch (\Throwable $e) {
            $problems[] = $e->getMessage();
        } finally {
            try {
                self::admin('')->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            } catch (\Throwable $e) {
                $problems[] = "Database sementara {$scratch} gagal dihapus: hapus manual.";
            }
        }
        return ['ok' => $problems === [], 'problems' => $problems, 'restored' => $restored];
    }

    /**
     * Bandingkan hasil pemulihan dengan sumber. Sumber boleh sudah berubah sejak cadangan dibuat (data hanya
     * bertambah), jadi transaksi/audit sumber boleh >= hasil pemulihan; sisanya (selisih, integritas, struktur) harus sehat.
     *
     * @param array<string,mixed> $restored
     * @param array<string,mixed> $source
     * @return array<int,string>
     */
    public static function compare(array $restored, array $source): array
    {
        $problems = [];
        if ($restored['tables'] !== $source['tables']) {
            $problems[] = 'Daftar tabel hasil pemulihan berbeda dari sumber.';
        }
        if ($restored['selisih'] !== 0) {
            $problems[] = 'Invarian keuangan hasil pemulihan tidak nol (selisih ' . $restored['selisih'] . ').';
        }
        if ($restored['issues'] > $source['issues']) {
            $problems[] = 'Hasil pemulihan memiliki masalah integritas yang tidak ada di sumber.';
        }
        if ($restored['transactions'] > $source['transactions'] || $restored['audit'] > $source['audit']) {
            $problems[] = 'Hasil pemulihan memuat LEBIH banyak data daripada sumber: cadangan bukan dari database ini?';
        }
        if ($restored['transactions'] === $source['transactions'] && $restored['approved'] === $source['approved']
            && ($restored['saldo'] !== $source['saldo'] || $restored['kas'] !== $source['kas'] || $restored['piutang'] !== $source['piutang'])) {
            $problems[] = 'Data sama banyak tetapi saldo/kas/piutang hasil pemulihan berbeda dari sumber.';
        }
        if ($restored['migrations'] > $source['migrations']) {
            $problems[] = 'Cadangan lebih baru dari skema database sumber.';
        }
        return $problems;
    }

    // ------------------------------------------------------------------ pembantu

    public static function admin(string $dbName): \PDO
    {
        return Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), $dbName);
    }

    public static function assertName(string $dbName): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName) !== 1) {
            throw new \InvalidArgumentException('Nama database tidak valid.');
        }
    }
}
