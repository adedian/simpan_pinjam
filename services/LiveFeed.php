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
     * - 'validasi': jumlah transaksi MENUNGGU_VALIDASI, hanya untuk pengguna yang boleh memvalidasi (Superadmin),
     *   supaya angkanya berarti "menunggu tindakan Anda". Pengguna tanpa izin validasi tidak mendapat kunci ini
     *   dan tidak memicu query apa pun.
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
            $out['validasi'] = ValidationService::assess($user)['eligible']
                ? (int) Database::select("SELECT COUNT(*) AS n FROM transactions WHERE status = 'MENUNGGU_VALIDASI' AND deleted_at IS NULL")[0]['n']
                : 0;
        } catch (\Throwable $e) {
            // penanda menu tidak boleh merusak halaman; angka tidak tampil
        }
        return $out;
    }
}
