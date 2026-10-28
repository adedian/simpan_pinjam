<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;

/**
 * Pencarian anggota untuk kotak pilih anggota (searchable dropdown) di seluruh aplikasi.
 *
 * Setiap "konteks" punya aturan hak akses dan himpunan anggota sendiri, ditegakkan di SQL (bukan di tampilan):
 *
 *   catat  : anggota AKTIF di regu pencatat. Hanya pemilik izin transaction.create yang punya regu (Ketua Regu).
 *   lihat  : anggota dalam cakupan data pengguna (Superadmin = semua, Ketua Regu = regunya, Anggota = dirinya). Untuk saringan.
 *   ketua  : calon ketua regu (Superadmin, izin team_leader.manage).
 *   akun   : anggota aktif yang boleh ditautkan ke akun pengguna (Superadmin, izin user.manage).
 *
 * Nilai yang tersimpan di database tetap id anggota (kecuali konteks "akun" yang memakai nomor anggota, sesuai
 * UserService); kecocokan akhir diperiksa ulang oleh service penyimpan masing-masing.
 */
final class MemberLookup
{
    public const CONTEXTS = ['catat', 'lihat', 'ketua', 'akun'];
    public const MAX_LIMIT = 30;

    /**
     * @param array<string,mixed>|null $user
     * @param array{team?:int,include?:int} $opts team: id regu (konteks ketua, ubah regu); include: id anggota yang sudah tertaut (konteks akun)
     */
    public static function allowed(?array $user, string $context): bool
    {
        if ($user === null) {
            return false;
        }
        return match ($context) {
            'catat' => Gate::allows($user, 'transaction.create') && ($user['team_id'] ?? null) !== null,
            // Anggota biasa (cakupan diri sendiri) tidak punya apa-apa untuk dicari: kotak pilih tidak ditampilkan dan API menolak.
            'lihat' => in_array(Scope::level($user, 'member'), [Scope::ALL, Scope::TEAM], true),
            'ketua' => Gate::allows($user, 'team_leader.manage'),
            'akun'  => Gate::allows($user, 'user.manage'),
            default => false,
        };
    }

    /**
     * Cari anggota. Hasil terurut: nomor persis, awalan nomor, awalan nama, lalu sisanya menurut nama.
     *
     * @param array<string,mixed>|null $user
     * @param array{team?:int,include?:int} $opts
     * @return array{items:array<int,array<string,mixed>>,more:bool}
     */
    public static function search(?array $user, string $context, string $q = '', array $opts = [], int $offset = 0, int $limit = 20): array
    {
        if (!self::allowed($user, $context)) {
            return ['items' => [], 'more' => false];
        }
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, min(10000, $offset));
        $q      = mb_substr(clean_text($q), 0, 60);

        [$where, $params] = self::restriction($user, $context, $opts);

        $order       = 'm.name, m.member_no';
        $orderParams = [];
        if ($q !== '') {
            foreach (array_slice(preg_split('/\s+/u', $q) ?: [], 0, 5) as $token) {
                $like     = '%' . Pagination::likeEscape($token) . '%';
                $where[]  = "(m.name LIKE ? ESCAPE '\\\\' OR m.member_no LIKE ? ESCAPE '\\\\')";
                array_push($params, $like, $like);
            }
            $prefix      = Pagination::likeEscape($q) . '%';
            $order       = "(m.member_no = ?) DESC, (m.member_no LIKE ? ESCAPE '\\\\') DESC, (m.name LIKE ? ESCAPE '\\\\') DESC, m.name, m.member_no";
            $orderParams = [$q, $prefix, $prefix];
        }

        // Ambil satu baris lebih banyak untuk mengetahui apakah masih ada halaman berikutnya.
        // LIMIT/OFFSET adalah bilangan bulat hasil pembatasan di atas (bukan input mentah).
        $rows = Database::select(
            self::select() . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT " . ($limit + 1) . " OFFSET {$offset}",
            array_merge($params, $orderParams)
        );
        $more = count($rows) > $limit;
        return ['items' => array_map([self::class, 'shape'], array_slice($rows, 0, $limit)), 'more' => $more];
    }

    /**
     * Satu anggota menurut id (atau nomor anggota), HANYA bila termasuk himpunan konteks ini. Dipakai untuk
     * menampilkan anggota yang sudah terpilih (ubah draft, saringan di URL); id di luar cakupan menghasilkan null.
     *
     * @param array<string,mixed>|null $user
     * @param array{team?:int,include?:int} $opts
     * @return array<string,mixed>|null
     */
    public static function find(?array $user, string $context, int|string $idOrNo, array $opts = []): ?array
    {
        if (!self::allowed($user, $context)) {
            return null;
        }
        // Konteks "akun" menyimpan nomor anggota; konteks lain menyimpan id.
        $byNo = $context === 'akun';
        $key  = $byNo ? mb_substr(trim((string) $idOrNo), 0, 30) : (int) $idOrNo;
        if ($key === '' || (is_int($key) && $key <= 0)) {
            return null;
        }
        [$where, $params] = self::restriction($user, $context, $opts);
        $where[]  = $byNo ? 'm.member_no = ?' : 'm.id = ?';
        $params[] = $key;
        $rows = Database::select(self::select() . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1', $params);
        return $rows === [] ? null : self::shape($rows[0]);
    }

    /** Teks yang tampil di kolom setelah anggota dipilih. */
    public static function label(array $member): string
    {
        return $member['name'] . ' (' . $member['member_no'] . ')';
    }

    private static function select(): string
    {
        return 'SELECT m.id, m.member_no, m.name, m.status, m.address_block, a.team_id, tl.name AS team_name
                FROM members m
                LEFT JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL
                LEFT JOIN team_leaders tl ON tl.id = a.team_id';
    }

    /**
     * Batas himpunan anggota per konteks. Dipanggil hanya setelah allowed() lolos.
     * @param array<string,mixed> $user
     * @param array{team?:int,include?:int} $opts
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private static function restriction(array $user, string $context, array $opts): array
    {
        $where = ['m.deleted_at IS NULL'];
        $params = [];
        switch ($context) {
            case 'catat':
                $where[]  = "m.status = 'AKTIF'";
                $where[]  = 'a.team_id = ?';
                $params[] = (int) $user['team_id'];
                break;
            case 'lihat':
                [$scope, $scopeParams] = Scope::memberCondition($user, 'm.id');
                $where[] = "({$scope})";
                $params  = array_merge($params, $scopeParams);
                break;
            case 'ketua':
                $team     = (int) ($opts['team'] ?? 0);
                $where[]  = "m.status = 'AKTIF'";
                if ($team > 0) {
                    $where[]  = 'a.team_id = ?';
                    $params[] = $team;
                }
                $where[]  = 'NOT EXISTS (SELECT 1 FROM team_leaders t WHERE t.leader_member_id = m.id AND t.id <> ?)';
                $params[] = $team;
                break;
            case 'akun':
                $where[]  = "m.status = 'AKTIF'";
                $where[]  = '(NOT EXISTS (SELECT 1 FROM users u WHERE u.member_id = m.id) OR m.id = ?)';
                $params[] = (int) ($opts['include'] ?? 0);
                break;
        }
        return [$where, $params];
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int,member_no:string,name:string,status:string,team:string,block:string,label:string}
     */
    private static function shape(array $r): array
    {
        $m = [
            'id'        => (int) $r['id'],
            'member_no' => (string) $r['member_no'],
            'name'      => (string) $r['name'],
            'status'    => (string) $r['status'],
            'team'      => (string) ($r['team_name'] ?? ''),
            'block'     => (string) ($r['address_block'] ?? ''),
        ];
        $m['label'] = self::label($m);
        return $m;
    }
}
