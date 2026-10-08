<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Konvensi uang: SELALU bilangan bulat rupiah (BIGINT di database), tidak pernah float.
 * Format tampilan Indonesia: titik sebagai pemisah ribuan, tanpa desimal.
 */
final class Money
{
    public static function format(int $amount, bool $withSymbol = true): string
    {
        $text = number_format(abs($amount), 0, ',', '.');
        $sign = $amount < 0 ? '-' : '';
        return $withSymbol ? $sign . 'Rp ' . $text : $sign . $text;
    }

    /**
     * Mengurai input pengguna ("1.000.000", "Rp 1.000.000", "1000000") menjadi rupiah bulat.
     * Mengembalikan null untuk input ambigu (desimal, huruf, pemisah salah) agar tidak ada tebakan.
     */
    public static function parse(string $input): ?int
    {
        $clean = trim(preg_replace('/^\s*Rp\.?\s*/i', '', $input) ?? '');
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $clean) === 1) {
            $clean = str_replace('.', '', $clean);
        }
        if (preg_match('/^\d{1,15}$/', $clean) !== 1) {
            return null;
        }
        return (int) $clean;
    }
}
