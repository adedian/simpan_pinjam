<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Penulis berkas Excel (.xlsx, OOXML) minimal tanpa pustaka luar: hanya ekstensi zip bawaan PHP.
 * Mendukung beberapa lembar, gaya sel (huruf, warna isi, garis, rata, format angka), sel gabungan, lebar kolom,
 * tinggi baris, pembekuan baris, orientasi dan pemisah halaman cetak (A4, muat satu halaman lebar).
 *
 * Teks selalu disimpan sebagai string, bukan rumus: nilai berawalan "=", "+", "-", "@" aman dibuka di Excel.
 *
 * Gaya: ['b','i','u' => bool, 'sz' => ukuran, 'color' => 'RRGGBB', 'fill' => 'RRGGBB', 'border' => 'thin'|'lrtb' (huruf l r t b = sisi),
 *        'h' => left|center|right, 'v' => top|center|bottom, 'wrap' => bool, 'fmt' => int|date|pct]
 */
final class Xlsx
{
    private const FORMATS = [
        'int'  => '#,##0;-#,##0;"-"',   // nol tampil "-" seperti formulir Excel koperasi; pemisah ribuan mengikuti bahasa Excel
        'date' => 'd-mmm-yy',
        'pct'  => '0.00%',
    ];

    /** @var array<int,XlsxSheet> */
    private array $sheets = [];
    /** @var array<string,int> */
    private array $styleIds = [];
    /** @var array<int,array<string,mixed>> */
    private array $styles = [];

    public function __construct()
    {
        $this->style([]);   // gaya 0 = bawaan
    }

    public function sheet(string $name): XlsxSheet
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name));
        $name = mb_substr($name === '' ? 'Lembar' : $name, 0, 31);
        foreach ($this->sheets as $s) {
            if (mb_strtolower($s->name()) === mb_strtolower($name)) {
                $name = mb_substr($name, 0, 28) . ' ' . (count($this->sheets) + 1);
            }
        }
        return $this->sheets[] = new XlsxSheet($name);
    }

    /** Daftarkan gaya; gaya yang sama mengembalikan id yang sama. @param array<string,mixed> $def */
    public function style(array $def): int
    {
        ksort($def);
        $key = json_encode($def);
        if (!isset($this->styleIds[$key])) {
            $this->styleIds[$key] = count($this->styles);
            $this->styles[] = $def;
        }
        return $this->styleIds[$key];
    }

    /** Tanggal "YYYY-MM-DD" -> nomor seri Excel (pakai gaya 'fmt' => 'date'). */
    public static function date(string $ymd): float
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $ymd);
        $epoch = new \DateTime('1899-12-30');
        return $d === false ? 0.0 : (float) $epoch->diff($d)->days * ($d < $epoch ? -1 : 1);
    }

    /** Isi berkas .xlsx sebagai string. */
    public function render(): string
    {
        if ($this->sheets === []) {
            $this->sheet('Lembar');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Ekstensi zip PHP tidak aktif; unduhan Excel tidak tersedia.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Tidak dapat membuat berkas Excel sementara.');
        }
        $n = count($this->sheets);
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $sheetsXml = '';
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetsXml .= '<sheet name="' . XlsxSheet::esc($s->name()) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $s->xml());
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $types . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews><sheets>' . $sheetsXml . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    private function stylesXml(): string
    {
        $fonts = ['<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
        $numFmts = [];
        $xfs = [];
        $idx = static function (array &$list, string $item): int {
            $i = array_search($item, $list, true);
            if ($i === false) {
                $list[] = $item;
                $i = count($list) - 1;
            }
            return (int) $i;
        };

        foreach ($this->styles as $def) {
            $font = '<font>' . (!empty($def['b']) ? '<b/>' : '') . (!empty($def['i']) ? '<i/>' : '') . (!empty($def['u']) ? '<u/>' : '')
                . '<sz val="' . (int) ($def['sz'] ?? 11) . '"/><color rgb="FF' . self::hex((string) ($def['color'] ?? '000000')) . '"/><name val="Calibri"/><family val="2"/></font>';
            $fontId = $idx($fonts, $font);
            $fillId = 0;
            if (!empty($def['fill'])) {
                $fillId = $idx($fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::hex((string) $def['fill']) . '"/><bgColor indexed="64"/></patternFill></fill>');
            }
            $borderId = 0;
            $sides = ($def['border'] ?? '') === 'thin' ? 'lrtb' : (string) ($def['border'] ?? '');   // 'thin' = semua sisi; atau huruf l r t b
            if ($sides !== '') {
                $side = static fn (string $n, string $k) => str_contains($sides, $k) ? '<' . $n . ' style="thin"><color rgb="FF000000"/></' . $n . '>' : '<' . $n . '/>';
                $borderId = $idx($borders, '<border>' . $side('left', 'l') . $side('right', 'r') . $side('top', 't') . $side('bottom', 'b') . '<diagonal/></border>');
            }
            $fmtId = 0;
            if (isset($def['fmt'], self::FORMATS[$def['fmt']])) {
                $code = self::FORMATS[$def['fmt']];
                $fmtId = 164 + $idx($numFmts, $code);
            }
            $align = '';
            if (isset($def['h']) || isset($def['v']) || !empty($def['wrap'])) {
                $align = '<alignment' . (isset($def['h']) ? ' horizontal="' . self::enum((string) $def['h'], ['left', 'center', 'right']) . '"' : '')
                    . ' vertical="' . self::enum((string) ($def['v'] ?? 'center'), ['top', 'center', 'bottom']) . '"'
                    . (!empty($def['wrap']) ? ' wrapText="1"' : '') . '/>';
            }
            $xfs[] = '<xf numFmtId="' . $fmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
                . ' applyNumberFormat="' . ($fmtId > 0 ? 1 : 0) . '" applyFont="1" applyFill="' . ($fillId > 0 ? 1 : 0) . '" applyBorder="' . ($borderId > 0 ? 1 : 0) . '"'
                . ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        }

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($numFmts !== []) {
            $x .= '<numFmts count="' . count($numFmts) . '">';
            foreach ($numFmts as $i => $code) {
                $x .= '<numFmt numFmtId="' . (164 + $i) . '" formatCode="' . XlsxSheet::esc($code) . '"/>';
            }
            $x .= '</numFmts>';
        }
        return $x . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function hex(string $c): string
    {
        return preg_match('/^[0-9A-Fa-f]{6}$/', $c) === 1 ? strtoupper($c) : '000000';
    }

    /** @param array<int,string> $allowed */
    private static function enum(string $v, array $allowed): string
    {
        return in_array($v, $allowed, true) ? $v : $allowed[0];
    }
}
