<?php
declare(strict_types=1);

/**
 * Membuat semua ikon aplikasi dari satu gambar sumber (database/tools/logo-source.png 1200x720: papan merek
 * Dana Gemilang, ubin biru "DG" + uang + panah simpan-pinjam di sisi kiri) dengan GD. Jalankan sekali bila logo berubah:
 *   C:\xampp\php\php.exe database\tools\make-pwa-icons.php
 *
 *   logo.png (256) / logo-64.png / favicon-32.png   tanda merek (sidebar, tab peramban): logo utuh, sudut membulat
 *   apple-touch-icon.png (180)                      layar utama iPhone: logo utuh, sudut membulat
 *   pwa-192.png / pwa-512.png                       tujuan "any": logo utuh, sudut membulat, latar luar transparan
 *   pwa-maskable-512.png                            tujuan "maskable": penuh sampai tepi, isi di zona aman (OS yang memotongnya)
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "Ekstensi GD tidak aktif.\n");
    exit(1);
}

const TILE_X = 102;     // ubin biru pada gambar sumber (x 96..515, y 150..569); garis tepi terang 3 px dibuang
const TILE_Y = 156;
const TILE_W = 408;
const BG     = [9, 38, 57];   // warna latar biru laut tua gambar sumber (untuk ikon maskable)

$dir = dirname(__DIR__, 2) . '/public/assets/img/';
$src = imagecreatefrompng(__DIR__ . '/logo-source.png');
if ($src === false) {
    fwrite(STDERR, "logo-source.png tidak terbaca.\n");
    exit(1);
}

/** Ambil bujur sangkar [x, y, w] dari sumber, kecilkan ke $size, potong sudut membulat (radius = $round x sisi). */
function crop(\GdImage $src, int $x, int $y, int $w, int $size, float $round): \GdImage
{
    $ss  = 4;                                   // supersampling agar tepi sudut halus
    $big = $size * $ss;
    $tmp = imagecreatetruecolor($big, $big);
    imagecopyresampled($tmp, $src, 0, 0, $x, $y, $big, $big, $w, $w);

    $mask = imagecreatetruecolor($big, $big);
    imagealphablending($mask, false);
    imagesavealpha($mask, true);
    imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127));
    $r = (int) round($big * $round);
    for ($py = 0; $py < $big; $py++) {
        for ($px = 0; $px < $big; $px++) {
            if ($round > 0) {
                $cx = $px < $r ? $r : ($px >= $big - $r ? $big - $r - 1 : $px);
                $cy = $py < $r ? $r : ($py >= $big - $r ? $big - $r - 1 : $py);
                if (($px - $cx) ** 2 + ($py - $cy) ** 2 > $r ** 2) {
                    continue;
                }
            }
            $c = imagecolorat($tmp, $px, $py);
            imagesetpixel($mask, $px, $py, imagecolorallocate($mask, ($c >> 16) & 255, ($c >> 8) & 255, $c & 255));
        }
    }
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $mask, 0, 0, 0, 0, $size, $size, $big, $big);
    return $out;
}

function save(\GdImage $im, string $path): void
{
    imagepng($im, $path, 9);
    echo $path, ' (', filesize($path), " B)\n";
}

$round = 0.225;

// Ubin utuh pada semua ukuran.
foreach ([["logo.png", 256], ["logo-64.png", 64], ["favicon-32.png", 32], ["apple-touch-icon.png", 180], ["pwa-192.png", 192], ["pwa-512.png", 512]] as [$name, $size]) {
    save(crop($src, TILE_X, TILE_Y, TILE_W, $size, $round), $dir . $name);
}

// Ikon maskable: ubin membulat dikecilkan ke 76% di atas latar biru laut tua (zona aman lingkaran ±40% dari tengah).
$size  = 512;
$im    = imagecreatetruecolor($size, $size);
imagefill($im, 0, 0, imagecolorallocate($im, BG[0], BG[1], BG[2]));
$inner = (int) round($size * 0.76);
$off   = (int) round(($size - $inner) / 2);
imagealphablending($im, true);
imagecopy($im, crop($src, TILE_X, TILE_Y, TILE_W, $inner, $round), $off, $off, 0, 0, $inner, $inner);
save($im, $dir . 'pwa-maskable-512.png');
