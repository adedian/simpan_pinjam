<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Satu lembar kerja untuk penulis Xlsx. Sel disimpan per baris/kolom (mulai 1); gaya berupa id dari Xlsx::style().
 * Teks selalu ditulis sebagai string (inlineStr), jadi isi seperti "=1+1" tidak pernah menjadi rumus.
 */
final class XlsxSheet
{
    /** @var array<int,array<int,array{0:mixed,1:int}>> [baris][kolom] => [nilai, gaya] (larik ringkas: laporan besar punya ratusan ribu sel) */
    private array $cells = [];
    /** @var array<int,array{0:int,1:int,2:int,3:int}> */
    private array $merges = [];
    /** @var array<int,float> */
    private array $widths = [];
    /** @var array<int,float> */
    private array $heights = [];
    /** @var array<int,int> */
    private array $breaks = [];
    private bool $landscape = false;
    private int $freezeRow = 0;
    private bool $gridlines = false;

    public function __construct(private string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Isi satu sel. @param int|float|string|null $value */
    public function set(int $row, int $col, mixed $value, int $style = 0): self
    {
        $this->cells[$row][$col] = [$value, $style];
        return $this;
    }

    /** Gabungkan sel; seluruh sel dalam rentang diberi gaya yang sama (agar garis tepi utuh). Isi hanya di sel kiri atas. */
    public function merge(int $r1, int $c1, int $r2, int $c2, int $style = 0): self
    {
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) {
                $this->cells[$r][$c] ??= [null, $style];
                $this->cells[$r][$c][1] = $style;
            }
        }
        $this->merges[] = [$r1, $c1, $r2, $c2];
        return $this;
    }

    public function width(int $col, float $chars): self
    {
        $this->widths[$col] = $chars;
        return $this;
    }

    public function height(int $row, float $points): self
    {
        $this->heights[$row] = $points;
        return $this;
    }

    /** Pindah halaman SETELAH baris ini saat dicetak. */
    public function pageBreakAfter(int $row): self
    {
        $this->breaks[] = $row;
        return $this;
    }

    public function landscape(bool $on = true): self
    {
        $this->landscape = $on;
        return $this;
    }

    /** Bekukan baris 1..$row (judul kolom tetap terlihat saat menggulir). */
    public function freezeRows(int $row): self
    {
        $this->freezeRow = $row;
        return $this;
    }

    public function gridlines(bool $on): self
    {
        $this->gridlines = $on;
        return $this;
    }

    public function maxRow(): int
    {
        return $this->cells === [] ? 1 : max(array_keys($this->cells));
    }

    public function xml(): string
    {
        $rows = $this->cells;
        ksort($rows);
        $maxCol = 1;
        foreach ($rows as $r) {
            $maxCol = max($maxCol, max(array_keys($r)));
        }
        $maxRow = $this->maxRow();

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . self::ref($maxRow, $maxCol) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"' . ($this->gridlines ? '' : ' showGridLines="0"') . '>';
        if ($this->freezeRow > 0) {
            $x .= '<pane ySplit="' . $this->freezeRow . '" topLeftCell="A' . ($this->freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A' . ($this->freezeRow + 1) . '" sqref="A' . ($this->freezeRow + 1) . '"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';

        if ($this->widths !== []) {
            ksort($this->widths);
            $x .= '<cols>';
            foreach ($this->widths as $c => $w) {
                $x .= '<col min="' . $c . '" max="' . $c . '" width="' . self::num($w) . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }

        $x .= '<sheetData>';
        foreach ($rows as $r => $cols) {
            ksort($cols);
            $ht = isset($this->heights[$r]) ? ' ht="' . self::num($this->heights[$r]) . '" customHeight="1"' : '';
            $x .= '<row r="' . $r . '"' . $ht . '>';
            foreach ($cols as $c => $cell) {
                $x .= self::cell($r, $c, $cell[0], $cell[1]);
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';

        if ($this->merges !== []) {
            $x .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as [$r1, $c1, $r2, $c2]) {
                $x .= '<mergeCell ref="' . self::ref($r1, $c1) . ':' . self::ref($r2, $c2) . '"/>';
            }
            $x .= '</mergeCells>';
        }

        $x .= '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="' . ($this->landscape ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>';

        if ($this->breaks !== []) {
            $b = array_values(array_unique($this->breaks));
            sort($b);
            $x .= '<rowBreaks count="' . count($b) . '" manualBreakCount="' . count($b) . '">';
            foreach ($b as $row) {
                $x .= '<brk id="' . $row . '" max="16383" man="1"/>';
            }
            $x .= '</rowBreaks>';
        }
        return $x . '</worksheet>';
    }

    private static function cell(int $row, int $col, mixed $v, int $style): string
    {
        $ref = self::ref($row, $col);
        $s   = $style > 0 ? ' s="' . $style . '"' : '';
        if ($v === null || $v === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }
        if (is_int($v) || is_float($v)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . (is_float($v) ? self::num($v) : (string) $v) . '</v></c>';
        }
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
    }

    /** Teks aman untuk XML 1.0: buang karakter kontrol yang dilarang, escape tanda khusus. */
    public static function esc(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function ref(int $row, int $col): string
    {
        $letters = '';
        for ($c = $col; $c > 0; $c = intdiv($c - 1, 26)) {
            $letters = chr(65 + ($c - 1) % 26) . $letters;
        }
        return $letters . $row;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }
}
