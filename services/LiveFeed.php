<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;

/**
 * Penanda perubahan data untuk pembaruan langsung antar-pengguna.
 *
 * Setiap perubahan data (simpanan, pinjaman, angsuran, validasi, master data, pengaturan) menulis
 * jejak audit DI DALAM transaksi database yang sama, jadi id jejak audit terbesar naik tepat saat
 * perubahan itu tersimpan (dan tidak naik bila dibatalkan). Penanda ini hanya satu angka: tidak
 * membawa data apa pun, sehingga aman dibaca semua pengguna login. Isi halaman tetap diambil lewat
 * route biasa yang menegakkan izin dan cakupan data.
 */
final class LiveFeed
{
    /** Batas baris yang dipindai untuk penanda menu; antrean sungguhan hanya puluhan, ini pagar bila membengkak. */
    private const BADGE_SCAN_LIMIT = 2000;

    public static function version(): int
    {
        try {
            return (int) Database::pdo()->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;   // gagal baca penanda tidak boleh merusak halaman; klien hanya tidak mendapat pembaruan langsung
        }
    }

    /**
     * Penanda jumlah di menu, khusus pengguna ini. Kuncinya sama dengan 'badge' di config/menu.php.
     *
     * - 'validasi': transaksi MENUNGGU_VALIDASI yang BOLEH divalidasi pengguna ini (aturan yang sama dengan
     *   kolom "Bisa divalidasi" di antrean: bukan pembuat, bukan atas nama sendiri, dst.). Yang tidak boleh
     *   dia validasi tidak dihitung, supaya angkanya berarti "menunggu tindakan Anda". Pengguna tanpa izin
     *   validasi tidak mendapat kunci ini dan tidak memicu query apa pun.
     *
     * Hanya angka; tidak membawa nomor dokumen, nama, atau nominal.
     *
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int}|null $user
     * @return array<string,int>
     */
    public static function badges(?array $user): array
    {
        $out = [];
        if ($user === null || !Gate::allows($user, 'transaction.validate')) {
            return $out;
        }
        try {
            $rows = Database::select(
                "SELECT t.created_by, t.member_id, " . ValidationService::HEAD_RELATED_SQL . " AS head_related,
                        (SELECT tl.leader_member_id FROM team_leaders tl WHERE tl.id = t.team_id) AS leader_member_id
                 FROM transactions t
                 WHERE t.status = 'MENUNGGU_VALIDASI' AND t.deleted_at IS NULL LIMIT " . self::BADGE_SCAN_LIMIT
            );
            $n = 0;
            foreach ($rows as $r) {
                $verdict = ValidationService::assess($user, $r, (bool) $r['head_related'], $r['leader_member_id'] === null ? null : (int) $r['leader_member_id']);
                if ($verdict['eligible']) {
                    $n++;
                }
            }
            $out['validasi'] = $n;
        } catch (\Throwable $e) {
            // penanda menu tidak boleh merusak halaman; angka tidak tampil
        }
        return $out;
    }
}
