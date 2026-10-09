<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Pagination;

/**
 * Pembacaan jejak audit (Phase 13). HANYA membaca: tabel audit_logs append-only (trigger + hak akses akun aplikasi).
 * Hanya untuk pemilik izin audit.view (Head, Pemeriksa); izin dijaga di route. Isi sebelum/sesudah disaring dari
 * nilai rahasia sebelum ditampilkan atau diunduh (pertahanan berlapis; penulis audit memang tidak mencatat rahasia).
 */
final class Audit
{
    /**
     * Kode aksi => [label, kelompok...]. Kode yang tidak dikenal tetap tampil apa adanya.
     * @var array<string,array<int,string>>
     */
    public const ACTIONS = [
        'LOGIN_SUCCESS'       => ['Masuk berhasil', 'masuk'],
        'LOGIN_FAILED'        => ['Gagal masuk', 'masuk', 'keamanan'],
        'LOGIN_LOCKED'        => ['Akun terkunci karena gagal berulang', 'masuk', 'keamanan'],
        'LOGIN_BLOCKED_LOCKED' => ['Masuk ditolak: akun terkunci', 'masuk', 'keamanan'],
        'LOGIN_THROTTLED'     => ['Masuk dibatasi: terlalu sering mencoba', 'masuk', 'keamanan'],
        'LOGOUT'              => ['Keluar', 'masuk'],
        'PASSWORD_CHANGED'    => ['Kata sandi diganti', 'masuk'],
        'PASSWORD_CHANGE_FAILED' => ['Ganti kata sandi gagal', 'masuk', 'keamanan'],
        'ACCESS_DENIED'       => ['Akses ditolak (tanpa izin)', 'keamanan'],
        'ACCESS_DENIED_SCOPE' => ['Akses data di luar cakupan ditolak', 'keamanan'],
        'SAVING_CREATED'      => ['Simpanan dicatat', 'transaksi'],
        'SAVING_UPDATED'      => ['Simpanan diubah', 'transaksi'],
        'SAVING_SUBMITTED'    => ['Simpanan diajukan', 'transaksi'],
        'SAVING_CANCELLED'    => ['Simpanan dibatalkan', 'transaksi'],
        'LOAN_CREATED'        => ['Pinjaman dicatat', 'transaksi'],
        'LOAN_UPDATED'        => ['Pinjaman diubah', 'transaksi'],
        'LOAN_SUBMITTED'      => ['Pinjaman diajukan', 'transaksi'],
        'LOAN_CANCELLED'      => ['Pinjaman dibatalkan', 'transaksi'],
        'PAYMENT_CREATED'     => ['Angsuran dicatat', 'transaksi'],
        'PAYMENT_UPDATED'     => ['Angsuran diubah', 'transaksi'],
        'PAYMENT_REALLOCATED' => ['Pembagian angsuran dihitung ulang', 'transaksi'],
        'PAYMENT_SUBMITTED'   => ['Angsuran diajukan', 'transaksi'],
        'PAYMENT_CANCELLED'   => ['Angsuran dibatalkan', 'transaksi'],
        'REVERSAL_CREATED'    => ['Pembalik dibuat', 'transaksi', 'koreksi'],
        'REVERSAL_REQUESTED'  => ['Koreksi diajukan', 'transaksi', 'koreksi'],
        'TRX_APPROVED'        => ['Transaksi disetujui', 'validasi'],
        'TRX_REJECTED'        => ['Transaksi ditolak', 'validasi'],
        'MEMBER_CREATED'      => ['Anggota ditambah', 'master'],
        'MEMBER_UPDATED'      => ['Data anggota diubah', 'master'],
        'MEMBER_TEAM_CHANGED' => ['Anggota pindah regu', 'master'],
        'TEAM_CREATED'        => ['Regu ditambah', 'master'],
        'TEAM_UPDATED'        => ['Regu diubah', 'master'],
        'USER_CREATED'        => ['Pengguna dibuat', 'master'],
        'USER_UPDATED'        => ['Pengguna diubah', 'master'],
        'USER_ACTIVATED'      => ['Pengguna diaktifkan', 'master'],
        'USER_DEACTIVATED'    => ['Pengguna dinonaktifkan', 'master'],
        'USER_PASSWORD_RESET' => ['Kata sandi pengguna direset', 'master', 'keamanan'],
        'USER_UNLOCKED'       => ['Kunci akun dibuka', 'master', 'keamanan'],
        'SETTING_UPDATED'     => ['Pengaturan diubah', 'pengaturan'],
        'REPORT_EXPORTED'     => ['Laporan diunduh', 'laporan'],
        'AUDIT_EXPORTED'      => ['Audit log diunduh', 'laporan'],
        'IMPOR_EXCEL'         => ['Impor data dari Excel', 'master'],
        'PLAN_ADJUSTED'       => ['Penyesuaian rencana pembayaran (bagi hasil)', 'master'],
    ];

    /** @var array<string,string> */
    public const GROUPS = [
        'masuk' => 'Masuk dan kata sandi', 'keamanan' => 'Keamanan', 'transaksi' => 'Transaksi', 'koreksi' => 'Koreksi (pembalik)',
        'validasi' => 'Validasi', 'master' => 'Master data', 'pengaturan' => 'Pengaturan', 'laporan' => 'Laporan dan unduhan',
    ];

    /** @var array<string,string> */
    public const ENTITIES = [
        'transaction' => 'Transaksi', 'member' => 'Anggota', 'user' => 'Pengguna', 'team' => 'Regu', 'setting' => 'Pengaturan', 'report' => 'Laporan', 'route' => 'Halaman', 'import' => 'Impor',
    ];

    public const PER_PAGE = 50;
    public const EXPORT_MAX_ROWS = 50000;

    public static function label(string $action): string
    {
        return self::ACTIONS[$action][0] ?? $action;
    }

    /**
     * Saringan dari query string, dibersihkan.
     * @param array<string,mixed> $q
     * @return array{dari:string,sampai:string,pengguna:string,aksi:string,kelompok:string,entitas:string,q:string,ip:string}
     */
    public static function filters(array $q): array
    {
        $date = static function (mixed $v): string {
            $v = trim((string) $v);
            $d = \DateTime::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
        };
        $action = strtoupper(trim((string) ($q['aksi'] ?? '')));
        return [
            'dari'     => $date($q['dari'] ?? ''),
            'sampai'   => $date($q['sampai'] ?? ''),
            'pengguna' => mb_substr(clean_text($q['pengguna'] ?? ''), 0, 50),
            'aksi'     => preg_match('/^[A-Z_]{1,50}$/', $action) === 1 ? $action : '',
            'kelompok' => isset(self::GROUPS[(string) ($q['kelompok'] ?? '')]) ? (string) $q['kelompok'] : '',
            'entitas'  => isset(self::ENTITIES[(string) ($q['entitas'] ?? '')]) ? (string) $q['entitas'] : '',
            'q'        => mb_substr(clean_text($q['q'] ?? ''), 0, 60),
            'ip'       => preg_match('/^[0-9a-fA-F:.]{1,45}$/', trim((string) ($q['ip'] ?? ''))) === 1 ? trim((string) $q['ip']) : '',
        ];
    }

    /**
     * @param array<string,string> $f hasil filters()
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function where(array $f): array
    {
        $where = ['1 = 1'];
        $p     = [];
        if ($f['dari'] !== '') {
            $where[] = 'a.created_at >= ?';
            $p[]     = $f['dari'] . ' 00:00:00';
        }
        if ($f['sampai'] !== '') {
            $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $p[]     = $f['sampai'];
        }
        if ($f['pengguna'] !== '') {
            $where[] = "a.username LIKE ? ESCAPE '\\\\'";
            $p[]     = '%' . Pagination::likeEscape($f['pengguna']) . '%';
        }
        if ($f['aksi'] !== '') {
            $where[] = 'a.action = ?';
            $p[]     = $f['aksi'];
        }
        if ($f['kelompok'] !== '') {
            $codes = array_keys(array_filter(self::ACTIONS, static fn (array $a): bool => in_array($f['kelompok'], array_slice($a, 1), true)));
            $where[] = 'a.action IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($p, ...$codes);
        }
        if ($f['entitas'] !== '') {
            $where[] = 'a.entity_type = ?';
            $p[]     = $f['entitas'];
        }
        if ($f['ip'] !== '') {
            $where[] = 'a.ip_address = ?';
            $p[]     = $f['ip'];
        }
        if ($f['q'] !== '') {
            $like    = '%' . Pagination::likeEscape($f['q']) . '%';
            $where[] = "(a.reference_no LIKE ? ESCAPE '\\\\' OR a.username LIKE ? ESCAPE '\\\\')";
            array_push($p, $like, $like);
        }
        return [implode(' AND ', $where), $p];
    }

    /**
     * Daftar entri audit terbaru dulu.
     * @param array<string,string> $f
     * @return array{rows:array<int,array<string,mixed>>,pager:array<string,int>}
     */
    public static function search(array $f, int $page, bool $all = false): array
    {
        [$w, $p] = self::where($f);
        $total   = (int) Database::select("SELECT COUNT(*) AS n FROM audit_logs a WHERE {$w}", $p)[0]['n'];
        $pager   = $all ? Pagination::make($total, 1, 200) : Pagination::make($total, $page, self::PER_PAGE);
        $limit   = $all ? self::EXPORT_MAX_ROWS : $pager['per_page'];
        $offset  = $all ? 0 : $pager['offset'];
        $rows    = Database::select(
            "SELECT a.id, a.created_at, a.user_id, a.username, a.roles, a.action, a.entity_type, a.entity_id, a.reference_no, a.ip_address, a.before_data, a.after_data
             FROM audit_logs a WHERE {$w} ORDER BY a.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            $p
        );
        return ['rows' => $rows, 'pager' => $pager];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::select('SELECT * FROM audit_logs WHERE id = ?', [$id])[0] ?? null;
    }

    /**
     * Angka ringkas 24 jam terakhir untuk kartu di atas daftar.
     * @return array{total:int,day:int,failed:int,denied:int,exports:int}
     */
    public static function summary(): array
    {
        $r = Database::select(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)), 0) AS day,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND action IN ('LOGIN_FAILED','LOGIN_LOCKED','LOGIN_BLOCKED_LOCKED','LOGIN_THROTTLED','PASSWORD_CHANGE_FAILED')), 0) AS failed,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND action IN ('ACCESS_DENIED','ACCESS_DENIED_SCOPE')), 0) AS denied,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND action IN ('REPORT_EXPORTED','AUDIT_EXPORTED')), 0) AS exports
             FROM audit_logs"
        )[0];
        return array_map('intval', $r);
    }

    /**
     * Kode aksi yang benar-benar ada di tabel (untuk pilihan saringan), berlabel.
     * @return array<string,string>
     */
    public static function presentActions(): array
    {
        $out = [];
        foreach (Database::select('SELECT DISTINCT action FROM audit_logs ORDER BY action') as $r) {
            $out[(string) $r['action']] = self::label((string) $r['action']);
        }
        asort($out);
        return $out;
    }

    /**
     * JSON audit menjadi daftar [kunci, nilai teks] dengan nilai rahasia disembunyikan.
     * @return array<int,array{0:string,1:string}>
     */
    public static function flatten(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [['(isi)', self::redactText($json)]];
        }
        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            $out[] = [$key, self::isSecretKey($key) ? '[disembunyikan]' : (is_scalar($v) || $v === null ? self::scalar($v) : self::redactJson($v))];
        }
        return $out;
    }

    /** JSON ringkas (untuk CSV), rahasia disembunyikan. */
    public static function compactJson(?string $json): string
    {
        return implode('; ', array_map(static fn (array $kv): string => $kv[0] . '=' . $kv[1], self::flatten($json)));
    }

    public static function isSecretKey(string $key): bool
    {
        return preg_match('/pass|hash|token|secret|credential|otp/i', $key) === 1;
    }

    private static function scalar(mixed $v): string
    {
        return $v === null ? 'kosong' : (is_bool($v) ? ($v ? 'ya' : 'tidak') : (string) $v);
    }

    private static function redactJson(mixed $v): string
    {
        $clean = static function (mixed $x) use (&$clean) {
            if (!is_array($x)) {
                return $x;
            }
            $o = [];
            foreach ($x as $k => $val) {
                $o[$k] = is_string($k) && self::isSecretKey($k) ? '[disembunyikan]' : $clean($val);
            }
            return $o;
        };
        return (string) json_encode($clean($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function redactText(string $text): string
    {
        return mb_substr($text, 0, 500);
    }
}
