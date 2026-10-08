<?php
declare(strict_types=1);

namespace App\Core;

final class Database
{
    private static ?\PDO $pdo = null;
    /** @var array<string,mixed> */
    private static array $override = [];

    /**
     * Ganti konfigurasi koneksi (dipakai skrip CLI dan tes, mis. akun admin atau database uji).
     * @param array<string,mixed> $override
     */
    public static function configure(array $override): void
    {
        self::$override = $override;
        self::$pdo = null;
    }

    /** Buat koneksi PDO baru. Dipisah dari pdo() agar skrip bisa memegang koneksi sendiri. */
    public static function connect(?string $user = null, ?string $pass = null, ?string $dbName = null): \PDO
    {
        $c   = array_merge((array) Config::get('database'), self::$override);
        $db  = $dbName ?? $c['name'];
        $dsn = sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4', $c['host'], $c['port'], $db === '' ? '' : 'dbname=' . $db . ';');
        $pdo = new \PDO($dsn, $user ?? (string) $c['user'], $pass ?? (string) $c['pass'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Collation koneksi harus sama dengan tabel, bila tidak literal string di view/query bisa bentrok.
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'");
        return $pdo;
    }

    /** Koneksi bersama untuk request web. */
    public static function pdo(): \PDO
    {
        return self::$pdo ??= self::connect();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Jalankan blok kerja dalam satu transaksi. Semua perubahan uang HARUS lewat sini
     * agar saldo tidak pernah berada dalam keadaan setengah jadi.
     *
     * @param callable(\PDO):mixed $work
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
