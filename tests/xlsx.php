<?php
declare(strict_types=1);

/**
 * Tes penulis Excel (.xlsx) buatan sendiri: keabsahan berkas, keamanan isi sel, gaya, tata letak cetak, dan kinerja.
 * Tanpa database. Berkas dibaca lewat pembaca tes yang terpisah dari penulisnya (tests/xlsx_reader.php).
 *   C:\xampp\php\php.exe tests\xlsx.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';
require __DIR__ . '/xlsx_reader.php';

use App\Services\Xlsx;
use App\Services\XlsxSheet;

$passed = 0;
$failed = [];
function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $name;
    echo "  GAGAL  {$name}\n";
}

$x = new Xlsx();
$bold = $x->style(['b' => true, 'sz' => 14, 'h' => 'center']);
$head = $x->style(['b' => true, 'fill' => 'C9E2F6', 'border' => 'thin', 'h' => 'center', 'wrap' => true]);
$num  = $x->style(['border' => 'thin', 'h' => 'right', 'fmt' => 'int']);
$date = $x->style(['border' => 'thin', 'fmt' => 'date']);
$s = $x->sheet('Uji / Coba: [1]?');
$s->set(1, 1, 'Judul "Adem Ayem" & <b>', $bold)->merge(1, 1, 1, 4, $bold);
$s->set(2, 1, '=1+1', $head)->set(2, 2, '@SUM(A1)', $head)->set(2, 3, "+cmd|' /C calc'!A0", $head)->set(2, 4, "kontrol\x00\x01\x1F-ok\tTab", $head);
$s->set(3, 1, 1234567, $num)->set(3, 2, -5, $num)->set(3, 3, 0, $num)->set(3, 4, 0.5, $num);
$s->set(4, 1, Xlsx::date('2026-03-05'), $date)->set(4, 27, 'kolom AA', $head)->set(4, 52, 'kolom AZ', $head);
$s->width(1, 6)->width(2, 12.5)->height(2, 32)->freezeRows(2)->pageBreakAfter(3)->pageBreakAfter(3)->landscape();
$s->set(5, 1, '', $num)->set(5, 2, null, $num);
$s2 = $x->sheet('Uji / Coba: [1]?');   // nama ganda setelah dibersihkan
$s2->set(1, 1, 'dua');
$bytes = $x->render();
$r = xlsx_read($bytes);

check('xlsx: berkas zip dengan bagian wajib OOXML dan semua XML well-formed', $r['ok'] && $r['error'] === null
    && count(array_diff(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'], $r['parts'])) === 0);
check('xlsx: berkas diawali tanda tangan zip "PK" (bukan teks CSV)', str_starts_with($bytes, 'PK'));
check('xlsx: nama lembar dibersihkan dari karakter terlarang ([]:*?/\\) dan dibuat unik', array_keys($r['sheets']) === ['Uji Coba 1', 'Uji Coba 1 2'] || (count($r['sheets']) === 2 && !preg_match('/[\[\]:*?\/\\\\]/', implode('', array_keys($r['sheets'])))));
$a = reset($r['sheets']);
check('xlsx: teks berawalan = + @ - tetap TEKS (inlineStr), tidak ada elemen rumus <f> di berkas', $a['cells']['A2']['type'] === 'str' && $a['cells']['A2']['formula'] === false && $a['cells']['A2']['v'] === '=1+1' && $a['cells']['B2']['v'] === '@SUM(A1)' && $a['cells']['C2']['v'] === "+cmd|' /C calc'!A0"
    && !str_contains((string) (new ZipArchiveReader())->all($bytes), '<f>'));
check('xlsx: tanda khusus XML (& < > ") diloloskan dan kembali utuh saat dibaca', $a['cells']['A1']['v'] === 'Judul "Adem Ayem" & <b>');
check('xlsx: karakter kontrol terlarang dibuang agar XML sah; tab dipertahankan', $a['cells']['D2']['v'] === "kontrol-ok\tTab");
check('xlsx: angka tersimpan sebagai angka (bulat, negatif, nol, pecahan) bukan teks', $a['cells']['A3']['type'] === 'num' && $a['cells']['A3']['v'] === 1234567.0 && $a['cells']['B3']['v'] === -5.0 && $a['cells']['C3']['v'] === 0.0 && $a['cells']['D3']['v'] === 0.5);
check('xlsx: tanggal = nomor seri Excel (2026-03-05 = 46086), 1900-based', Xlsx::date('2026-03-05') === 46086.0 && $a['cells']['A4']['v'] === 46086.0);
check('xlsx: kolom di atas Z (27 = AA, 52 = AZ) dan sel kosong bergaya tetap ada', isset($a['cells']['AA4'], $a['cells']['AZ4']) && $a['cells']['AA4']['v'] === 'kolom AA' && $a['cells']['A5']['type'] === 'empty' && $a['cells']['B5']['type'] === 'empty');
check('xlsx: sel gabungan, lebar kolom, pembekuan baris, orientasi, dan pemisah halaman (tanpa duplikat)', $a['merges'] === ['A1:D1'] && $a['cols'][1] === 6.0 && $a['cols'][2] === 12.5 && $a['freeze'] === 2 && $a['orientation'] === 'landscape' && $a['breaks'] === [3]);
check('xlsx: seluruh sel gabungan memakai gaya yang sama (garis tepi utuh)', $a['cells']['A1']['s'] === $a['cells']['D1']['s'] && $a['cells']['B1']['v'] === null);
check('xlsx: gaya yang sama didaftarkan sekali (id sama) dan jumlah gaya terkendali', $x->style(['b' => true, 'sz' => 14, 'h' => 'center']) === $bold && $x->style(['h' => 'center', 'sz' => 14, 'b' => true]) === $bold && $r['styles'] === 5);
check('xlsx: lembar kedua terpisah dan portrait secara bawaan', next($r['sheets'])['orientation'] === 'portrait');

// Nilai gaya liar tidak merusak XML (warna/penjajaran dari luar daftar putih jatuh ke bawaan)
$y = new Xlsx();
$ys = $y->sheet('Liar');
$ys->set(1, 1, 'x', $y->style(['fill' => 'zzzzzz"/><x', 'color' => '12', 'h' => 'justify"><evil/>', 'v' => 'zzz', 'fmt' => 'tidak-ada', 'border' => 'xx"']));
$ry = xlsx_read($y->render());
check('xlsx: gaya dengan nilai liar (warna, penjajaran, format) tidak merusak XML', $ry['ok'] && $ry['sheets']['Liar']['cells']['A1']['v'] === 'x');

// Berkas kosong tetap sah
$e = xlsx_read((new Xlsx())->render());
check('xlsx: tanpa lembar dibuat satu lembar kosong yang sah', $e['ok'] && count($e['sheets']) === 1);

// Kinerja dan memori: 30.000 baris x 9 kolom (setara laporan transaksi besar)
$big = new Xlsx();
$bs = $big->sheet('Besar');
$t = $big->style(['border' => 'thin']);
$n = $big->style(['border' => 'thin', 'fmt' => 'int']);
$t0 = microtime(true);
for ($i = 1; $i <= 30000; $i++) {
    for ($c = 1; $c <= 9; $c++) {
        $bs->set($i, $c, $c % 3 === 0 ? $i * $c : "teks {$i}-{$c}", $c % 3 === 0 ? $n : $t);
    }
}
$out = $big->render();
$sec = microtime(true) - $t0;
check(sprintf('xlsx: 30.000 baris x 9 kolom dibuat dalam %.1f dtk dan %.0f MB puncak (batas 12 dtk, 200 MB)', $sec, memory_get_peak_usage(true) / 1048576), $sec < 12 && memory_get_peak_usage(true) < 200 * 1048576 && strlen($out) > 100000);
$rb = xlsx_read($out);
check('xlsx: berkas besar tetap terbaca utuh (baris terakhir dan nilai sel)', $rb['ok'] && $rb['sheets']['Besar']['cells']['I30000']['v'] === 270000.0 && $rb['sheets']['Besar']['cells']['A30000']['v'] === 'teks 30000-1');

/** Bantu: isi semua bagian zip sebagai satu string (untuk mencari elemen terlarang). */
final class ZipArchiveReader
{
    public function all(string $bytes): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'xz');
        file_put_contents($tmp, $bytes);
        $z = new ZipArchive();
        $z->open($tmp);
        $all = '';
        for ($i = 0; $i < $z->numFiles; $i++) {
            $all .= (string) $z->getFromIndex($i);
        }
        $z->close();
        unlink($tmp);
        return $all;
    }
}

echo "\n";
if ($failed === []) {
    echo "Penulis Excel: {$passed} lulus, 0 gagal.\n";
    exit(0);
}
echo "Penulis Excel: {$passed} lulus, " . count($failed) . " GAGAL.\n";
exit(1);
