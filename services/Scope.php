<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Gate;

/**
 * Cakupan DATA (milik siapa) per pengguna. Dipakai oleh SEMUA query yang mengembalikan data anggota
 * atau transaksi, supaya pembatasan tidak bergantung pada tampilan:
 *
 *   all  : seluruh koperasi          (izin *.view.all  : Head, Pemeriksa)
 *   team : anggota regu yang dipimpin (izin *.view.team : Ketua Regu)
 *   self : hanya diri sendiri         (izin *.view.self : Anggota)
 *   none : tidak ada
 *
 * Hasil berupa potongan SQL berparameter, jadi tidak ada nilai dari pengguna yang masuk ke string SQL.
 */
final class Scope
{
    public const ALL = 'all';
    public const TEAM = 'team';
    public const SELF = 'self';
    public const NONE = 'none';

    /**
     * @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @param string $resource 'member' atau 'transaction'
     */
    public static function level(?array $user, string $resource = 'member'): string
    {
        if ($user === null) {
            return self::NONE;
        }
        if (Gate::allows($user, "{$resource}.view.all")) {
            return self::ALL;
        }
        if (Gate::allows($user, "{$resource}.view.team") && ($user['team_id'] ?? null) !== null) {
            return self::TEAM;
        }
        if (($user['member_id'] ?? null) !== null && Gate::allows($user, "{$resource}.view.self")) {
            return self::SELF;
        }
        return self::NONE;
    }

    /**
     * Kondisi SQL pembatas anggota.
     * @param array{roles?:array<int,string>,member_id?:?int,team_id?:?int}|null $user
     * @param string $memberColumn kolom id anggota di query pemanggil, mis. "m.id" atau "t.member_id"
     * @return array{0:string,1:array<int,int>} [potongan SQL, parameter]
     */
    public static function memberCondition(?array $user, string $memberColumn = 'm.id', string $resource = 'member'): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $memberColumn)) {
            throw new \InvalidArgumentException('Nama kolom tidak valid.');
        }
        return match (self::level($user, $resource)) {
            self::ALL  => ['1 = 1', []],
            self::TEAM => ["{$memberColumn} IN (SELECT a.member_id FROM member_team_assignments a WHERE a.team_id = ? AND a.valid_to IS NULL)", [(int) $user['team_id']]],
            self::SELF => ["{$memberColumn} = ?", [(int) $user['member_id']]],
            default    => ['1 = 0', []],
        };
    }
}
