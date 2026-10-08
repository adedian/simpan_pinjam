<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;

/**
 * Halaman galat untuk pengguna yang sudah masuk (Phase 15): tetap di dalam kerangka aplikasi (menu dan
 * bilah atas) dengan petunjuk dan jalan kembali, bukan kartu tamu yang memutus konteks. Tidak dirutekan;
 * dipanggil dari App::errorResponse.
 */
final class ErrorController extends BaseController
{
    /** Petunjuk tindakan per status; hanya teks umum (tidak membocorkan ada/tidaknya data). */
    private const HINTS = [
        403 => 'Peran Anda tidak punya izin untuk halaman atau tindakan ini. Bila seharusnya bisa, hubungi Head koperasi.',
        404 => 'Alamatnya mungkin salah, datanya sudah tidak ada, atau berada di luar cakupan data Anda.',
        405 => 'Tindakan ini tidak tersedia lewat cara yang dipakai. Kembali, lalu gunakan tombol di halamannya.',
        419 => 'Formulir sudah terlalu lama terbuka atau sesi berganti. Kembali, muat ulang halamannya, lalu isi lagi.',
    ];

    /**
     * Pengguna login yang boleh melihat kerangka aplikasi, atau null bila halaman harus tampil sebagai tamu
     * (belum masuk, wajib ganti kata sandi, atau status 500 yang bisa saja disebabkan database).
     *
     * @return array<string,mixed>|null
     */
    public static function framedUser(int $status): ?array
    {
        if (!isset(self::HINTS[$status])) {
            return null;
        }
        try {
            $user = Auth::user();
        } catch (\Throwable $e) {
            return null;
        }
        return $user !== null && !$user['must_change_password'] ? $user : null;
    }

    public function show(Request $request, int $status, string $message): Response
    {
        return $this->view($request, 'error', [
            'title'   => 'Error ' . $status,
            'status'  => $status,
            'message' => $message,
            'hint'    => self::HINTS[$status] ?? '',
            'debug'   => null,
            'framed'  => true,
        ], 'app', $status);
    }
}
