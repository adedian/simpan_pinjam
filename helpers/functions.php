<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Url;
use App\Helpers\Icons;
use App\Helpers\Money;

/** Escape keluaran HTML. Pakai untuk SEMUA data dinamis di view. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return Url::to($path);
}

function asset(string $path): string
{
    return Url::asset($path);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function money(int $amount): string
{
    return Money::format($amount);
}

/** SVG ikon bawaan (konten tepercaya, bukan input pengguna). */
function icon(string $name, int $size = 20): string
{
    return Icons::svg($name, $size);
}

/** Lencana status transaksi. Label dan kelas CSS dipusatkan di sini agar konsisten di semua halaman. */
function status_badge(string $status): string
{
    $labels = [
        'DRAFT'             => 'Draft',
        'MENUNGGU_VALIDASI' => 'Menunggu Validasi',
        'DISETUJUI'         => 'Disetujui',
        'DITOLAK'           => 'Ditolak',
        'DIBATALKAN'        => 'Dibatalkan',
    ];
    $label = $labels[$status] ?? $status;
    $class = 'badge badge--' . strtolower(str_replace('_', '-', $status));
    return '<span class="' . e($class) . '">' . e($label) . '</span>';
}

/** "2026-03-01" -> "Maret 2026". */
function month_label(string $date): string
{
    static $names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($date);
    return $ts === false ? $date : $names[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** "2026-03-07" -> "07-03-2026". */
function date_id(?string $date): string
{
    $ts = $date === null ? false : strtotime($date);
    return $ts === false ? '-' : date('d-m-Y', $ts);
}

/** Bersihkan teks isian: buang karakter kontrol, pangkas, rapatkan spasi ganda. */
function clean_text(mixed $value): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}
