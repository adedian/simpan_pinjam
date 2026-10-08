<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;

/**
 * Token sekali pakai untuk formulir pembuat transaksi. Mencegah transaksi ganda akibat klik dua kali,
 * tombol "kirim ulang" browser, atau tab yang dikirim dua kali: token hanya bisa dipakai SATU kali,
 * kiriman kedua dengan token yang sama ditolak tanpa membuat apa pun.
 * Sesi PHP mengunci berkas per request, jadi dua kiriman serentak diproses berurutan.
 */
final class FormToken
{
    private const KEY = '_form_tokens';
    private const KEEP = 30;

    public static function issue(): string
    {
        $token  = bin2hex(random_bytes(16));
        $tokens = (array) Session::get(self::KEY, []);
        $tokens[$token] = time();
        if (count($tokens) > self::KEEP) {
            asort($tokens);
            $tokens = array_slice($tokens, -self::KEEP, null, true);
        }
        Session::set(self::KEY, $tokens);
        return $token;
    }

    /** true bila token sah dan baru saja dipakai (token langsung hangus). */
    public static function consume(string $token): bool
    {
        $tokens = (array) Session::get(self::KEY, []);
        if ($token === '' || !isset($tokens[$token])) {
            return false;
        }
        unset($tokens[$token]);
        Session::set(self::KEY, $tokens);
        return true;
    }
}
