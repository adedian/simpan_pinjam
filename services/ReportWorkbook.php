<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Berkas Excel dari struktur tabel laporan (ReportService::build) dan audit log. Gaya mengikuti formulir cetak koperasi:
 * judul tebal di tengah, kepala tabel biru bertebal, garis tipis, angka rata kanan dengan "-" untuk nol, baris jumlah biru.
 * Isi sama persis dengan tabel di layar (satu struktur untuk HTML dan Excel).
 *
 * Struktur masukan: ['title','subtitle'?,'columns'=>[['key','label','type'=>text|money|int|pct]],'rows'=>[[kolom=>nilai]],'totals'=>?baris,'note'?]
 * `pct` disimpan dalam seperseratus persen (123 = 1,23%) dan ditulis sebagai persen Excel sungguhan.
 */
final class ReportWorkbook
{
    private const BLUE = 'C9E2F6';

    /** @param array<string,mixed> $report */
    public static function build(array $report): string
    {
        $x = new Xlsx();
        $title = $x->style(['b' => true, 'sz' => 14, 'h' => 'center']);
        $sub   = $x->style(['i' => true, 'h' => 'center']);
        $head  = $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'center', 'wrap' => true]);
        $text  = $x->style(['border' => 'thin', 'h' => 'left']);
        $num   = $x->style(['border' => 'thin', 'h' => 'right', 'fmt' => 'int']);
        $pct   = $x->style(['border' => 'thin', 'h' => 'right', 'fmt' => 'pct']);
        $tText = $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'left']);
        $tNum  = $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'right', 'fmt' => 'int']);
        $tPct  = $x->style(['b' => true, 'fill' => self::BLUE, 'border' => 'thin', 'h' => 'right', 'fmt' => 'pct']);
        $note  = $x->style(['i' => true, 'sz' => 9, 'color' => '444444', 'wrap' => true, 'v' => 'top']);

        $columns = $report['columns'];
        $last = max(1, count($columns));
        $s = $x->sheet((string) ($report['title'] ?? 'Laporan'));

        $r = 1;
        $s->set($r, 1, mb_strtoupper((string) ($report['title'] ?? 'Laporan')) . ' "' . mb_strtoupper((string) config('app.short')) . '"', $title)->merge($r, 1, $r, $last, $title)->height($r, 24);
        $r++;
        if (!empty($report['subtitle'])) {
            $s->set($r, 1, (string) $report['subtitle'], $sub)->merge($r, 1, $r, $last, $sub);
            $r++;
        }
        $r++;   // satu baris kosong sebelum tabel

        $headRow = $r;
        $widths = [];
        foreach ($columns as $i => $c) {
            $s->set($r, $i + 1, (string) $c['label'], $head);
            $widths[$i] = mb_strlen((string) $c['label']) + 3;
        }
        $s->height($r, 32);
        $r++;

        $write = static function (int $row, array $line, bool $isTotal) use ($s, $columns, $text, $num, $pct, $tText, $tNum, $tPct, &$widths): void {
            foreach ($columns as $i => $c) {
                $v = $line[$c['key']] ?? null;
                if ($v === null || $v === '') {
                    $s->set($row, $i + 1, null, $isTotal ? $tText : $text);
                    continue;
                }
                switch ($c['type']) {
                    case 'money':
                    case 'int':
                        $s->set($row, $i + 1, (int) $v, $isTotal ? $tNum : $num);
                        $widths[$i] = max($widths[$i], strlen(number_format(abs((int) $v), 0, ',', '.')) + 3);
                        break;
                    case 'pct':
                        $s->set($row, $i + 1, (int) $v / 10000, $isTotal ? $tPct : $pct);
                        $widths[$i] = max($widths[$i], 9);
                        break;
                    default:
                        $s->set($row, $i + 1, (string) $v, $isTotal ? $tText : $text);
                        $widths[$i] = max($widths[$i], min(48, mb_strlen((string) $v) + 2));
                }
            }
        };
        foreach ($report['rows'] as $row) {
            $write($r++, $row, false);
        }
        if (!empty($report['totals'])) {
            $write($r++, $report['totals'], true);
        }
        if (!empty($report['note'])) {
            $r++;
            $s->set($r, 1, (string) $report['note'], $note)->merge($r, 1, $r, $last, $note)->height($r, 30);
        }

        foreach ($widths as $i => $w) {
            $s->width($i + 1, (float) max(7, min(48, $w)));
        }
        $s->freezeRows($headRow)->landscape(count($columns) > 6);
        return $x->render();
    }
}
