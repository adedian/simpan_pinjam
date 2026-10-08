<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;

/**
 * Penulis jejak audit. Tabel audit_logs append-only (trigger + hak akses akun aplikasi).
 * Kegagalan menulis audit TIDAK boleh menyembunyikan kejadian: dicatat ke log berkas
 * dan tidak membatalkan permintaan pengguna (khusus aksi non-keuangan); untuk transaksi
 * keuangan, penulisan audit dilakukan DI DALAM transaksi database yang sama sehingga gagal bersama.
 */
final class AuditLog
{
    /**
     * @param array{id?:int,username?:string,roles?:array<int,string>}|null $actor
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function record(
        ?Request $request,
        ?array $actor,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $referenceNo = null,
        ?array $before = null,
        ?array $after = null,
        ?\PDO $pdo = null,
    ): void {
        try {
            $stmt = ($pdo ?? Database::pdo())->prepare(
                'INSERT INTO audit_logs (user_id, username, roles, action, entity_type, entity_id, reference_no, before_data, after_data, ip_address, user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $actor['id'] ?? null,
                isset($actor['username']) ? mb_substr((string) $actor['username'], 0, 50) : null,
                isset($actor['roles']) ? mb_substr(implode(',', $actor['roles']), 0, 100) : null,
                $action,
                $entityType,
                $entityId,
                $referenceNo,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $request?->ip(),
                $request === null ? null : mb_substr((string) $request->header('User-Agent'), 0, 255),
            ]);
        } catch (\Throwable $e) {
            if ($pdo !== null) {
                throw $e; // di dalam transaksi pemanggil: gagal bersama
            }
            Logger::error("Gagal menulis audit log ({$action})", $e);
        }
    }
}
