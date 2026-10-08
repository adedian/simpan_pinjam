<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Akses data pengguna. Semua query memakai prepared statement. */
final class User
{
    /**
     * Baris lengkap TERMASUK password_hash. Hanya untuk layanan autentikasi; jangan kirim ke view.
     * @return array<string,mixed>|null
     */
    public static function findForLogin(string $username): ?array
    {
        $rows = Database::select(
            'SELECT id, username, name, password_hash, is_active, deleted_at, failed_logins,
                    (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
             FROM users WHERE username = ? LIMIT 1',
            [$username]
        );
        return $rows[0] ?? null;
    }

    /**
     * Profil sesi: pengguna aktif beserta peran dan regu. Tanpa password_hash.
     * @return array{id:int,username:string,name:string,roles:array<int,string>,member_id:?int,team_id:?int,must_change_password:bool,credentials_ts:int}|null
     */
    public static function findActive(int $id): ?array
    {
        $rows = Database::select(
            'SELECT id, username, name, member_id, must_change_password,
                    COALESCE(UNIX_TIMESTAMP(credentials_changed_at), 0) AS credentials_ts
             FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$id]
        );
        if ($rows === []) {
            return null;
        }
        $u     = $rows[0];
        $roles = Database::select(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id',
            [$id]
        );
        return [
            'id'                   => (int) $u['id'],
            'username'             => (string) $u['username'],
            'name'                 => (string) $u['name'],
            'roles'                => array_column($roles, 'code'),
            'member_id'            => $u['member_id'] === null ? null : (int) $u['member_id'],
            'team_id'              => self::teamIdLedBy($u['member_id'] === null ? null : (int) $u['member_id']),
            'must_change_password' => (bool) $u['must_change_password'],
            'credentials_ts'       => (int) $u['credentials_ts'],
        ];
    }

    /** Regu yang dipimpin anggota ini (null bila bukan ketua regu aktif). */
    public static function teamIdLedBy(?int $memberId): ?int
    {
        if ($memberId === null) {
            return null;
        }
        $rows = Database::select('SELECT id FROM team_leaders WHERE leader_member_id = ? AND is_active = 1', [$memberId]);
        return $rows === [] ? null : (int) $rows[0]['id'];
    }

    /** @return array<string,mixed>|null */
    public static function passwordHashOf(int $id): ?string
    {
        $rows = Database::select('SELECT password_hash FROM users WHERE id = ?', [$id]);
        return $rows === [] ? null : (string) $rows[0]['password_hash'];
    }

    public static function recordFailure(int $id, int $maxFailures, int $lockMinutes): bool
    {
        Database::execute('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$id]);
        $locked = Database::execute(
            'UPDATE users SET locked_until = NOW() + INTERVAL ? MINUTE, failed_logins = 0 WHERE id = ? AND failed_logins >= ?',
            [$lockMinutes, $id, $maxFailures]
        );
        return $locked > 0;
    }

    public static function recordSuccess(int $id, ?string $newHash = null): void
    {
        Database::execute(
            'UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW()' . ($newHash !== null ? ', password_hash = ?' : '') . ' WHERE id = ?',
            $newHash !== null ? [$newHash, $id] : [$id]
        );
    }

    public static function updatePassword(int $id, string $hash): void
    {
        Database::execute(
            'UPDATE users SET password_hash = ?, must_change_password = 0, credentials_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ?',
            [$hash, $id]
        );
    }

    /**
     * Daftar akun untuk halaman admin (tanpa password_hash).
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Database::select(
            "SELECT u.id, u.username, u.name, u.is_active, u.must_change_password, u.last_login_at, u.updated_at,
                    (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked,
                    m.member_no, m.name AS member_name,
                    GROUP_CONCAT(r.code ORDER BY r.id SEPARATOR ',') AS role_codes
             FROM users u
             LEFT JOIN members m ON m.id = u.member_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.username, u.name, u.is_active, u.must_change_password, u.last_login_at, u.updated_at, u.locked_until, m.member_no, m.name
             ORDER BY u.is_active DESC, u.username"
        );
    }

    /** @return array<string,mixed>|null akun untuk formulir admin (tanpa password_hash) */
    public static function findForAdmin(int $id): ?array
    {
        $rows = Database::select(
            "SELECT u.id, u.username, u.name, u.is_active, u.member_id, u.updated_at, m.member_no
             FROM users u LEFT JOIN members m ON m.id = u.member_id
             WHERE u.id = ? AND u.deleted_at IS NULL",
            [$id]
        );
        if ($rows === []) {
            return null;
        }
        $rows[0]['roles'] = array_column(Database::select(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id',
            [$id]
        ), 'code');
        return $rows[0];
    }

    /**
     * Anggota yang boleh ditautkan: yang belum punya akun, ditambah anggota yang sudah tertaut ke akun ini.
     * @return array<int,array{member_no:string,name:string,leads_team:int}>
     */
    public static function memberOptions(?int $includeMemberId): array
    {
        return Database::select(
            "SELECT m.member_no, m.name,
                    EXISTS (SELECT 1 FROM team_leaders t WHERE t.leader_member_id = m.id AND t.is_active = 1) AS leads_team
             FROM members m
             WHERE m.deleted_at IS NULL AND m.status = 'AKTIF'
               AND (NOT EXISTS (SELECT 1 FROM users u WHERE u.member_id = m.id) OR m.id = ?)
             ORDER BY m.name",
            [$includeMemberId ?? 0]
        );
    }
}
