<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

/**
 * Pengelolaan akun pengguna. Pelanggaran aturan dilempar sebagai InvalidArgumentException
 * (pesan siap tampil) dari DALAM transaksi, sehingga perubahan dan audit dibatalkan bersama.
 */
final class UserService
{
    private const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,29}$/';

    /**
     * Buat pengguna. Kata sandi sementara dibuat acak bila tidak diberikan dan pengguna WAJIB
     * menggantinya saat masuk pertama.
     *
     * @param array<int,string> $roleCodes
     * @param array{id:?int,username:string,roles:array<int,string>}|null $actor
     * @return array{id:int,password:string} password = kata sandi sementara (tampilkan sekali saja)
     * @throws \InvalidArgumentException bila data tidak valid
     */
    public static function create(
        string $username,
        string $name,
        array $roleCodes,
        ?string $memberNo = null,
        ?string $password = null,
        ?Request $request = null,
        ?array $actor = null,
    ): array {
        $username = strtolower(trim($username));
        $name     = clean_text($name);
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new \InvalidArgumentException('Nama pengguna 3-30 karakter: huruf kecil, angka, titik, garis bawah atau strip.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Nama wajib diisi (maksimal 100 karakter).');
        }

        $password ??= PasswordPolicy::generate();
        $problems = PasswordPolicy::check($password, $username);
        if ($problems !== []) {
            throw new \InvalidArgumentException('Kata sandi ditolak: ' . implode(' ', $problems));
        }

        return Database::transaction(static function (\PDO $pdo) use ($username, $name, $roleCodes, $memberNo, $password, $request, $actor): array {
            $roleIds  = self::resolveRoles($pdo, $roleCodes);
            $memberId = self::resolveMember($pdo, $memberNo);
            self::assertAccessRules($pdo, $roleCodes, $memberId);

            try {
                $pdo->prepare('INSERT INTO users (username, name, password_hash, member_id, must_change_password) VALUES (?,?,?,?,1)')
                    ->execute([$username, $name, PasswordPolicy::hash($password), $memberId]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new \InvalidArgumentException('Nama pengguna sudah dipakai, atau anggota ini sudah punya akun.');
                }
                throw $e;
            }
            $userId = (int) $pdo->lastInsertId();
            foreach ($roleIds as $rid) {
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$userId, $rid]);
            }

            // Kata sandi TIDAK ikut dicatat.
            AuditLog::record($request, $actor, 'USER_CREATED', 'user', $userId, $username, null,
                ['username' => $username, 'name' => $name, 'roles' => array_values($roleCodes), 'member_no' => $memberNo], $pdo);
            return ['id' => $userId, 'password' => $password];
        });
    }

    /**
     * Ubah nama, peran, dan tautan anggota. Nama pengguna tidak bisa diubah.
     * @param array<int,string> $roleCodes
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function update(Request $request, array $actor, int $id, string $name, array $roleCodes, ?string $memberNo, string $version): void
    {
        $name = clean_text($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Nama wajib diisi (maksimal 100 karakter).');
        }

        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $name, $roleCodes, $memberNo, $version): void {
            $row = self::lock($pdo, $id, $version);
            $oldRoles = self::rolesOf($pdo, $id);
            $newRoles = array_values(array_unique($roleCodes));
            $roleIds  = self::resolveRoles($pdo, $newRoles);
            $memberId = self::resolveMember($pdo, $memberNo);

            $rolesChanged = array_diff($oldRoles, $newRoles) !== [] || array_diff($newRoles, $oldRoles) !== [];
            if ($id === $actor['id'] && $rolesChanged) {
                throw new \InvalidArgumentException('Anda tidak bisa mengubah peran akun Anda sendiri.');
            }
            self::assertAccessRules($pdo, $newRoles, $memberId);
            if (in_array('HEAD', $oldRoles, true) && !in_array('HEAD', $newRoles, true)) {
                self::assertAnotherHead($pdo, $id);
            }

            try {
                $pdo->prepare('UPDATE users SET name = ?, member_id = ? WHERE id = ?')->execute([$name, $memberId, $id]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new \InvalidArgumentException('Anggota ini sudah punya akun lain.');
                }
                throw $e;
            }
            if ($rolesChanged) {
                $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
                foreach ($roleIds as $rid) {
                    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$id, $rid]);
                }
            }

            $oldMemberNo = self::memberNoOf($pdo, $row['member_id'] === null ? null : (int) $row['member_id']);
            $before = ['name' => $row['name'], 'roles' => $oldRoles, 'member_no' => $oldMemberNo];
            $after  = ['name' => $name, 'roles' => $newRoles, 'member_no' => $memberNo];
            if ($before !== $after) {
                AuditLog::record($request, $actor, 'USER_UPDATED', 'user', $id, (string) $row['username'], $before, $after, $pdo);
            }
        });
    }

    /** @param array{id:int,username:string,roles:array<int,string>} $actor */
    public static function setActive(Request $request, array $actor, int $id, bool $active, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $active, $version): void {
            $row = self::lock($pdo, $id, $version);
            if (!$active) {
                if ($id === $actor['id']) {
                    throw new \InvalidArgumentException('Anda tidak bisa menonaktifkan akun Anda sendiri.');
                }
                if (in_array('HEAD', self::rolesOf($pdo, $id), true)) {
                    self::assertAnotherHead($pdo, $id);
                }
            }
            if ((int) $row['is_active'] === (int) $active) {
                return;
            }
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([(int) $active, $id]);
            AuditLog::record($request, $actor, $active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED', 'user', $id, (string) $row['username'], null, null, $pdo);
        });
    }

    /**
     * Setel ulang kata sandi: kata sandi sementara baru, wajib diganti, dan SEMUA sesi akun itu mati.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @return string kata sandi sementara (tampilkan sekali saja)
     */
    public static function resetPassword(Request $request, array $actor, int $id, string $version): string
    {
        if ($id === $actor['id']) {
            throw new \InvalidArgumentException('Untuk akun Anda sendiri, gunakan Ganti Kata Sandi di Profil.');
        }
        $password = PasswordPolicy::generate();
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version, $password): void {
            $row = self::lock($pdo, $id, $version);
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, credentials_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ?')
                ->execute([PasswordPolicy::hash($password), $id]);
            AuditLog::record($request, $actor, 'USER_PASSWORD_RESET', 'user', $id, (string) $row['username'], null, null, $pdo);
        });
        return $password;
    }

    /** @param array{id:int,username:string,roles:array<int,string>} $actor */
    public static function unlock(Request $request, array $actor, int $id): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id): void {
            $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$id]);
            $username = $stmt->fetchColumn();
            if ($username === false) {
                throw new \InvalidArgumentException('Pengguna tidak ditemukan.');
            }
            $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
            AuditLog::record($request, $actor, 'USER_UNLOCKED', 'user', $id, (string) $username, null, null, $pdo);
        });
    }

    // ------------------------------------------------------------------ aturan

    /**
     * Aturan hubungan peran-anggota:
     *  - HEAD dan PEMERIKSA tidak boleh dipegang orang yang sama (pemisahan tugas validasi, Q3)
     *  - ANGGOTA wajib tertaut ke anggota
     *  - KETUA_REGU wajib tertaut ke anggota yang memimpin regu aktif (kalau tidak, ia tidak melihat data apa pun)
     * @param array<int,string> $roleCodes
     */
    public static function assertAccessRules(\PDO $pdo, array $roleCodes, ?int $memberId): void
    {
        if ($roleCodes === []) {
            throw new \InvalidArgumentException('Pilih minimal satu peran.');
        }
        if (in_array('HEAD', $roleCodes, true) && in_array('PEMERIKSA', $roleCodes, true)) {
            throw new \InvalidArgumentException('Peran Head dan Pemeriksa tidak boleh dipegang orang yang sama: Pemeriksa adalah validator kedua.');
        }
        if (in_array('ANGGOTA', $roleCodes, true) && $memberId === null) {
            throw new \InvalidArgumentException('Peran Anggota harus ditautkan ke data anggota.');
        }
        if (in_array('KETUA_REGU', $roleCodes, true)) {
            if ($memberId === null) {
                throw new \InvalidArgumentException('Peran Ketua Regu harus ditautkan ke anggota yang memimpin regu.');
            }
            $stmt = $pdo->prepare('SELECT 1 FROM team_leaders WHERE leader_member_id = ? AND is_active = 1');
            $stmt->execute([$memberId]);
            if ($stmt->fetchColumn() === false) {
                throw new \InvalidArgumentException('Anggota yang ditautkan belum menjadi ketua regu aktif. Atur dulu di Data Ketua Regu.');
            }
        }
    }

    private static function assertAnotherHead(\PDO $pdo, int $exceptUserId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'HEAD' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.id <> ?"
        );
        $stmt->execute([$exceptUserId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('Harus tersisa minimal satu Head aktif. Angkat Head lain dulu.');
        }
    }

    // ------------------------------------------------------------------ util

    /** @return array<string,mixed> baris users (terkunci) */
    private static function lock(\PDO $pdo, int $id, string $version): array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \InvalidArgumentException('Pengguna tidak ditemukan.');
        }
        if ((string) $row['updated_at'] !== $version) {
            throw new \InvalidArgumentException('Data ini baru saja diubah oleh pengguna lain. Muat ulang halaman lalu ulangi perubahan Anda.');
        }
        return $row;
    }

    /** @return array<int,string> */
    private static function rolesOf(\PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @param array<int,string> $roleCodes
     * @return array<int,int> id peran
     */
    private static function resolveRoles(\PDO $pdo, array $roleCodes): array
    {
        $ids = [];
        foreach (array_unique($roleCodes) as $code) {
            $stmt = $pdo->prepare('SELECT id FROM roles WHERE code = ?');
            $stmt->execute([$code]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                throw new \InvalidArgumentException("Peran tidak dikenal: {$code}");
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    private static function resolveMember(\PDO $pdo, ?string $memberNo): ?int
    {
        if ($memberNo === null || $memberNo === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM members WHERE member_no = ? AND deleted_at IS NULL');
        $stmt->execute([$memberNo]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Anggota {$memberNo} tidak ditemukan.");
        }
        return (int) $id;
    }

    private static function memberNoOf(\PDO $pdo, ?int $memberId): ?string
    {
        if ($memberId === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT member_no FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
