<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Penerap migrasi SQL berurutan. File: database/migrations/NNN_nama.sql, pernyataan dipisah
 * baris "-- @@". Checksum disimpan; migrasi yang sudah diterapkan lalu diubah akan ditolak
 * (perubahan skema baru = file migrasi baru, tidak pernah mengedit yang lama).
 * Catatan: DDL MariaDB tidak bisa di-rollback; bila satu migrasi gagal, perbaiki lalu jalankan ulang
 * (hanya setelah database dikosongkan dengan --fresh pada lingkungan lokal).
 */
final class Migrator
{
    public function __construct(private \PDO $pdo, private string $directory)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name       VARCHAR(100) NOT NULL PRIMARY KEY,
                checksum   CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string,string> nama => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT name, checksum FROM schema_migrations ORDER BY name')->fetchAll();
        return array_column($rows, 'checksum', 'name');
    }

    /** @return array<string,string> nama => path, terurut */
    public function files(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $out[basename($file, '.sql')] = $file;
        }
        return $out;
    }

    /**
     * @return array<int,string> nama migrasi yang baru diterapkan
     * @throws \RuntimeException bila migrasi lama berubah atau pernyataan gagal
     */
    public function migrate(): array
    {
        $applied = $this->applied();
        $done    = [];

        foreach ($this->files() as $name => $file) {
            $sql      = (string) file_get_contents($file);
            $checksum = hash('sha256', $sql);

            if (isset($applied[$name])) {
                if ($applied[$name] !== $checksum) {
                    throw new \RuntimeException("Migrasi {$name} sudah diterapkan tetapi isinya berubah. Buat migrasi baru, jangan mengedit yang lama.");
                }
                continue;
            }

            foreach (self::statements($sql) as $i => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(sprintf('Migrasi %s gagal pada pernyataan #%d: %s', $name, $i + 1, $e->getMessage()), 0, $e);
                }
            }

            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (name, checksum) VALUES (?, ?)');
            $stmt->execute([$name, $checksum]);
            $done[] = $name;
        }
        return $done;
    }

    /** @return array<int,string> */
    public static function statements(string $sql): array
    {
        $out = [];
        foreach (preg_split('/^\s*--\s*@@\s*$/m', $sql) ?: [] as $chunk) {
            $withoutComments = trim(preg_replace('/^\s*--.*$/m', '', $chunk) ?? '');
            if ($withoutComments !== '') {
                $out[] = trim($chunk);
            }
        }
        return $out;
    }

    /** Hapus SEMUA tabel dan view. Hanya untuk pengembangan lokal. */
    public function dropEverything(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            $this->pdo->exec('DROP VIEW IF EXISTS `' . $row[0] . '`');
        }
        foreach ($this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $row[0] . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
