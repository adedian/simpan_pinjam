<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Berkas Excel berisi formulir "Rekap Pinjaman" dan "Tabungan Hari Raya" (tata letak, warna, dan garis mengikuti
 * formulir Excel koperasi; lihat views/partials/sheet-*.php untuk padanan layarnya). Satu lembar per jenis formulir;
 * bila anggotanya banyak, formulir disusun ke bawah dengan pemisah halaman tiap dua formulir.
 */
final class SheetWorkbook
{
    private const BLUE = 'C9E2F6';
    private const PEACH = 'FBE3D6';
    private const PER_PAGE = 2;

    /**
     * @param array<int,array<string,mixed>> $sheets hasil MemberSheet
     */
    public static function build(array $sheets): string
    {
        $x = new Xlsx();
        $st = [
            'title'  => $x->style(['b' => true, 'sz' => 14, 'h' => 'center', 'border' => 'ltr']),
            'titleU' => $x->style(['b' => true, 'u' => true, 'sz' => 12, 'h' => 'center', 'border' => 'ltr']),
            'lblB'   => $x->style(['b' => true, 'sz' => 12, 'border' => 'l']),
            'colon'  => $x->style(['b' => true, 'sz' => 12]),
            'valB'   => $x->style(['sz' => 12, 'h' => 'left', 'border' => 'r']),
            'lbl'    => $x->style(['border' => 'l']),
            'val'    => $x->style(['h' => 'left', 'border' => 'r']),
            'empty'  => $x->style([]),
            'activeL' => $x->style(['i' => true, 'h' => 'right']),
            'activeR' => $x->style(['i' => true, 'u' => true, 'h' => 'right', 'border' => 'r']),
            'hBlue'  => $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'center', 'wrap' => true]),
            'hPeach' => $x->style(['b' => true, 'fill' => self::PEACH, 'border' => 'thin', 'h' => 'center', 'wrap' => true]),
            'no'     => $x->style(['border' => 'thin', 'h' => 'center']),
            'date'   => $x->style(['border' => 'thin', 'h' => 'center', 'fmt' => 'date']),
            'num'    => $x->style(['border' => 'thin', 'h' => 'right', 'fmt' => 'int']),
            'text'   => $x->style(['border' => 'thin', 'h' => 'left']),
            'sumBlue' => $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'center']),
            'sumBlueN' => $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'right', 'fmt' => 'int']),
            'sum'    => $x->style(['b' => true, 'border' => 'thin', 'h' => 'center']),
            'sumN'   => $x->style(['b' => true, 'border' => 'thin', 'h' => 'right', 'fmt' => 'int']),
            'sub'    => $x->style(['i' => true, 'border' => 'thin', 'h' => 'center']),
            'subN'   => $x->style(['i' => true, 'border' => 'thin', 'h' => 'right', 'fmt' => 'int']),
            'note'   => $x->style(['i' => true, 'sz' => 8, 'color' => '444444', 'wrap' => true, 'v' => 'top', 'border' => 'lrb']),
        ];

        $loan = $x->sheet('Rekap Pinjaman');
        foreach ([1 => 5, 2 => 11, 3 => 16, 4 => 13, 5 => 22, 6 => 16, 7 => 24] as $c => $w) {
            $loan->width($c, $w);
        }
        $save = $x->sheet('Tabungan Hari Raya');
        foreach ([1 => 5, 2 => 11, 3 => 14, 4 => 14, 5 => 16] as $c => $w) {
            $save->width($c, $w);
        }

        $r1 = 1;
        $r2 = 1;
        foreach ($sheets as $i => $sheet) {
            $r1 = self::loanForm($loan, $r1, $sheet, $st);
            $r2 = self::savingForm($save, $r2, $sheet, $st);
            if (($i + 1) % self::PER_PAGE === 0 && $i + 1 < count($sheets)) {
                $loan->pageBreakAfter($r1 - 1);
                $save->pageBreakAfter($r2 - 1);
            }
            $r1++;   // satu baris kosong antar formulir
            $r2++;
        }
        return $x->render();
    }

    /** @param array<string,mixed> $sh @param array<string,int> $st @return int baris terakhir formulir + 1 */
    private static function loanForm(XlsxSheet $s, int $r, array $sh, array $st): int
    {
        $m = $sh['member'];
        $s->set($r, 1, 'REKAP PINJAMAN "' . mb_strtoupper((string) config('app.short')) . '"', $st['title'])->merge($r, 1, $r, 7, $st['title'])->height($r, 24);
        $r++;
        foreach ([['NO. URUT', (int) $m['no']], ['NAMA', (string) $m['name']], ['ALAMAT', (string) $m['address']]] as [$label, $value]) {
            $s->set($r, 1, $label, $st['lblB'])->merge($r, 1, $r, 2, $st['lblB']);
            $s->set($r, 3, ':', $st['colon']);
            $s->set($r, 4, $value, $st['valB'])->merge($r, 4, $r, 7, $st['valB']);
            $r++;
        }
        foreach (['NO', 'TGL', "PINJAMAN\nPOKOK", 'BUNGA', "BAYAR ANGSURAN\n+ BUNGA", "SISA\nPINJAMAN", 'Keterangan'] as $c => $h) {
            $s->set($r, $c + 1, $h, $st['hBlue']);
        }
        $s->height($r, 32);
        $r++;
        foreach ($sh['loan']['rows'] as $row) {
            $s->set($r, 1, (int) $row['no'], $st['no'])->set($r, 2, Xlsx::date((string) $row['date']), $st['date']);
            $s->set($r, 3, (int) $row['pokok'], $st['num'])->set($r, 4, (int) $row['bunga'], $st['num'])->set($r, 5, (int) $row['bayar'], $st['num'])->set($r, 6, (int) $row['sisa'], $st['num']);
            $s->set($r, 7, (string) $row['note'], $st['text']);
            $r++;
        }
        $s->set($r, 1, 'SISA PINJAMAN', $st['sumBlue'])->merge($r, 1, $r, 5, $st['sumBlue']);
        $s->set($r, 6, (int) $sh['loan']['remaining'], $st['sumBlueN'])->set($r, 7, null, $st['sumBlue']);
        return $r + 1;
    }

    /** @param array<string,mixed> $sh @param array<string,int> $st @return int baris terakhir formulir + 1 */
    private static function savingForm(XlsxSheet $s, int $r, array $sh, array $st): int
    {
        $m = $sh['member'];
        $v = $sh['saving'];
        $s->set($r, 1, 'Tabungan Hari Raya  "' . config('app.short') . '"', $st['titleU'])->merge($r, 1, $r, 5, $st['titleU'])->height($r, 20);
        $r++;
        foreach ([['NO. URUT', (int) $m['no']], ['NAMA', (string) $m['name']], ['DAWIS / BLOK', (string) $m['address']]] as [$label, $value]) {
            $s->set($r, 1, $label, $st['lbl'])->merge($r, 1, $r, 2, $st['lbl']);
            $s->set($r, 3, $value, $st['val'])->merge($r, 3, $r, 5, $st['val']);
            $r++;
        }
        $s->set($r, 1, null, $st['lbl']);
        $s->set($r, 4, 'Aktif Per', $st['activeL'])->set($r, 5, month_label((string) $m['active_from']), $st['activeR']);
        $r++;
        foreach (['NO', 'TGL', 'MASUK', 'KELUAR', "TOTAL\nTABUNGAN"] as $c => $h) {
            $s->set($r, $c + 1, $h, $st['hPeach']);
        }
        $s->height($r, 32);
        $r++;
        foreach ($v['rows'] as $row) {
            $s->set($r, 1, (int) $row['no'], $st['no'])->set($r, 2, Xlsx::date((string) $row['date']), $st['date']);
            $s->set($r, 3, (int) $row['in'], $st['num'])->set($r, 4, (int) $row['out'] === 0 ? null : (int) $row['out'], $st['num'])->set($r, 5, (int) $row['total'], $st['num']);
            $r++;
        }
        $s->set($r, 1, 'Total Tabungan', $st['sum'])->merge($r, 1, $r, 4, $st['sum'])->set($r, 5, (int) $v['total'], $st['sumN']);
        $r++;
        $s->set($r, 1, 'Bagi Hasil Tabungan', $st['sub'])->merge($r, 1, $r, 4, $st['sub'])->set($r, 5, (int) $v['saver_share'], $st['subN']);
        $r++;
        $s->set($r, 1, 'Total Pinjaman', $st['sub'])->merge($r, 1, $r, 3, $st['sub'])->set($r, 4, (int) $v['loan_total'], $st['subN'])->set($r, 5, null, $st['subN']);
        $r++;
        $s->set($r, 1, 'Bagi Hasil Pinjaman', $st['sub'])->merge($r, 1, $r, 4, $st['sub'])->set($r, 5, (int) $v['borrower_share'], $st['subN']);
        $r++;
        $s->set($r, 1, 'Total di Terima', $st['sum'])->merge($r, 1, $r, 4, $st['sum'])->set($r, 5, (int) $v['received'], $st['sumN']);
        $r++;
        $note = 'Bagi hasil adalah perkiraan' . ($sh['as_of'] !== null ? ' per data ' . month_label((string) $sh['as_of']) : '')
            . '; cicilan yang belum dibayar dihitung sesuai jadwal. Belum dikurangi sisa pinjaman.';
        $s->set($r, 1, $note, $st['note'])->merge($r, 1, $r, 5, $st['note'])->height($r, 26);
        return $r + 1;
    }
}
