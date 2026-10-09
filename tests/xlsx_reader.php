<?php
declare(strict_types=1);

/**
 * Pembaca .xlsx sederhana untuk tes: membongkar zip dan membaca XML lembar kerja apa adanya (bukan lewat pustaka
 * penulis), sehingga yang diperiksa adalah isi berkas sungguhan.
 *
 * xlsx_read($bytes) => [
 *   'ok' => bool, 'error' => ?string, 'parts' => [nama berkas], 'styles' => jumlah <xf>,
 *   'sheets' => [nama => ['cells' => ['A1' => ['v' => nilai, 'type' => 'str'|'num'|'empty', 'formula' => bool, 's' => id gaya]],
 *                         'merges' => ['A1:G1'], 'breaks' => [baris], 'orientation' => string, 'freeze' => int, 'cols' => [nomor => lebar]]]
 * ]
 */
function xlsx_read(string $bytes): array
{
    $out = ['ok' => false, 'error' => null, 'parts' => [], 'styles' => 0, 'sheets' => []];
    $tmp = (string) tempnam(sys_get_temp_dir(), 'xr');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        unlink($tmp);
        $out['error'] = 'bukan zip';
        return $out;
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out['parts'][] = (string) $zip->getNameIndex($i);
    }
    $read = static fn (string $name): ?string => ($c = $zip->getFromName($name)) === false ? null : $c;
    libxml_use_internal_errors(true);
    // semua bagian XML harus well-formed
    foreach ($out['parts'] as $p) {
        $xml = $read($p);
        if ($xml !== null && simplexml_load_string($xml) === false) {
            $out['error'] = 'XML rusak: ' . $p;
            $zip->close();
            unlink($tmp);
            return $out;
        }
    }
    $wb = simplexml_load_string((string) $read('xl/workbook.xml'));
    $rels = simplexml_load_string((string) $read('xl/_rels/workbook.xml.rels'));
    $target = [];
    foreach ($rels->Relationship as $r) {
        $target[(string) $r['Id']] = (string) $r['Target'];
    }
    $st = simplexml_load_string((string) $read('xl/styles.xml'));
    $out['styles'] = count($st->cellXfs->xf);
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string) $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $ws = simplexml_load_string((string) $read('xl/' . $target[$rid]));
        $cells = [];
        foreach ($ws->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $t = (string) $c['t'];
                if ($t === 'inlineStr') {
                    $cells[$ref] = ['v' => (string) $c->is->t, 'type' => 'str', 'formula' => isset($c->f), 's' => (int) $c['s']];
                } elseif (isset($c->v)) {
                    $cells[$ref] = ['v' => (float) $c->v, 'type' => 'num', 'formula' => isset($c->f), 's' => (int) $c['s']];
                } else {
                    $cells[$ref] = ['v' => null, 'type' => 'empty', 'formula' => isset($c->f), 's' => (int) $c['s']];
                }
            }
        }
        $merges = [];
        foreach ($ws->mergeCells->mergeCell ?? [] as $m) {
            $merges[] = (string) $m['ref'];
        }
        $breaks = [];
        foreach ($ws->rowBreaks->brk ?? [] as $b) {
            $breaks[] = (int) $b['id'];
        }
        $cols = [];
        foreach ($ws->cols->col ?? [] as $col) {
            $cols[(int) $col['min']] = (float) $col['width'];
        }
        $out['sheets'][(string) $sh['name']] = [
            'cells' => $cells, 'merges' => $merges, 'breaks' => $breaks, 'cols' => $cols,
            'orientation' => (string) $ws->pageSetup['orientation'], 'freeze' => (int) ($ws->sheetViews->sheetView->pane['ySplit'] ?? 0),
        ];
    }
    $zip->close();
    unlink($tmp);
    $out['ok'] = true;
    return $out;
}

/** Nilai sel (null bila tidak ada). @param array<string,mixed> $sheet */
function xlsx_v(array $sheet, string $ref): mixed
{
    return $sheet['cells'][$ref]['v'] ?? null;
}

/** Semua baris sebagai larik nilai (kolom A..), untuk perbandingan isi. @param array<string,mixed> $sheet @return array<int,array<int,mixed>> */
function xlsx_rows(array $sheet): array
{
    $rows = [];
    foreach ($sheet['cells'] as $ref => $c) {
        if (preg_match('/^([A-Z]+)(\d+)$/', (string) $ref, $m) !== 1) {
            continue;
        }
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        $rows[(int) $m[2]][$col] = $c['v'];
    }
    ksort($rows);
    foreach ($rows as &$r) {
        ksort($r);
    }
    return $rows;
}
