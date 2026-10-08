<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Kebijakan kata sandi. Panjang diutamakan daripada aturan simbol yang rumit.
 * Batas 72 BYTE adalah batas nyata bcrypt: lebih dari itu akan terpotong diam-diam.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_BYTES  = 72;

    private const COMMON = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwertyui', 'qwerty123',
        'admin123', 'admin1234', 'abc12345', 'iloveyou1', 'welcome1', 'ademayem', 'ademayem1', 'ademayem123',
        'simpanpinjam', 'simpanpinjam1', 'koperasi1', 'koperasi123', 'purwati123', 'rahasia123',
    ];

    /** @return array<int,string> daftar masalah; kosong bila kata sandi diterima */
    public static function check(string $password, string $username = ''): array
    {
        $problems = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $problems[] = 'Minimal ' . self::MIN_LENGTH . ' karakter.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            $problems[] = 'Terlalu panjang (maksimal ' . self::MAX_BYTES . ' byte).';
        }
        if (preg_match('/\pL/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $problems[] = 'Harus mengandung huruf dan angka.';
        }
        $lower = mb_strtolower($password);
        if ($username !== '' && str_contains($lower, mb_strtolower($username))) {
            $problems[] = 'Tidak boleh mengandung nama pengguna.';
        }
        if (in_array($lower, self::COMMON, true)) {
            $problems[] = 'Terlalu umum, pilih yang lain.';
        }
        if (preg_match('/^(.)\1+$/u', $password) === 1) {
            $problems[] = 'Tidak boleh berupa satu karakter yang diulang.';
        }
        return $problems;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Kata sandi acak yang mudah dibaca (tanpa 0/O/1/l/I), pasti memuat huruf dan angka. */
    public static function generate(int $length = 14): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $all     = $letters . $digits;
        do {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $all[random_int(0, strlen($all) - 1)];
            }
        } while (preg_match('/\d/', $out) !== 1 || preg_match('/[a-zA-Z]/', $out) !== 1);
        return $out;
    }
}
