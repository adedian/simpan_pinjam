<?php
declare(strict_types=1);

/**
 * Membuat ikon PWA (tiga gelombang putih di atas bidang ungu, sama dengan logo) dengan GD.
 * Dijalankan sekali bila logo berubah:  C:\xampp\php\php.exe database\tools\make-pwa-icons.php
 *
 *   pwa-192.png / pwa-512.png        tujuan "any": sudut membulat, latar luar transparan
 *   pwa-maskable-512.png             tujuan "maskable": penuh sampai tepi, gelombang di zona aman 80% (OS yang memotongnya)
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "Ekstensi GD tidak aktif.\n");
    exit(1);
}

const SS = 4; // supersampling agar tepi halus

/** @return array{0:int,1:int,2:int} */
function mix(array $a, array $b, float $t): array
{
    return [(int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t)];
}

function make(int $size, bool $maskable, string $path): void
{
    $s  = $size * SS;
    $im = imagecreatetruecolor($s, $s);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));

    // Gradien diagonal sama dengan --grad-side di CSS: #7d4ff2 -> #4b2e9a -> #2a1b5e.
    $c0 = [125, 79, 242];
    $c1 = [75, 46, 154];
    $c2 = [42, 27, 94];
    $radius = $maskable ? 0 : (int) round($s * 0.225);
    for ($y = 0; $y < $s; $y++) {
        for ($x = 0; $x < $s; $x++) {
            if ($radius > 0) {
                $cx = $x < $radius ? $radius : ($x >= $s - $radius ? $s - $radius - 1 : $x);
                $cy = $y < $radius ? $radius : ($y >= $s - $radius ? $s - $radius - 1 : $y);
                if (($x - $cx) ** 2 + ($y - $cy) ** 2 > $radius ** 2) {
                    continue; // di luar sudut membulat: tetap transparan
                }
            }
            $t = ($x + $y) / (2 * ($s - 1));
            [$r, $g, $b] = $t < 0.52 ? mix($c0, $c1, $t / 0.52) : mix($c1, $c2, ($t - 0.52) / 0.48);
            imagesetpixel($im, $x, $y, imagecolorallocate($im, $r, $g, $b));
        }
    }

    // Tiga gelombang. Ikon maskable mengecilkan gambar ke zona aman (OS memotong tepi sampai ±20%).
    imagealphablending($im, true);
    $white = imagecolorallocate($im, 255, 255, 255);
    $scale = $maskable ? 0.60 : 0.74;
    $w     = $s * $scale * 0.80;          // lebar gelombang
    $x0    = ($s - $w) / 2;
    $amp   = $s * $scale * 0.065;
    $pitch = $s * $scale * 0.215;         // jarak antar gelombang
    $thick = $s * $scale * 0.075;         // tebal garis
    for ($row = -1; $row <= 1; $row++) {
        $cy = $s / 2 + $row * $pitch;
        for ($i = 0; $i <= 900; $i++) {
            $t = $i / 900;
            $x = $x0 + $w * $t;
            $y = $cy + $amp * sin($t * 2 * M_PI * 1.5 + M_PI); // 1,5 periode: turun lalu naik, seperti logo
            imagefilledellipse($im, (int) round($x), (int) round($y), (int) round($thick), (int) round($thick), $white);
        }
    }

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $s, $s);
    imagepng($out, $path, 9);
    echo $path, ' (', filesize($path), " B)\n";
}

$dir = dirname(__DIR__, 2) . '/public/assets/img/';
make(192, false, $dir . 'pwa-192.png');
make(512, false, $dir . 'pwa-512.png');
make(512, true, $dir . 'pwa-maskable-512.png');
