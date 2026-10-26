<?php
/**
 * Tabel laporan dari struktur ReportService (sama persis dengan yang diunduh sebagai Excel).
 * Parameter: $report. Angka uang tanpa "Rp" per sel (judul menyebut rupiah); nol ditulis "-".
 */
$cell = static function (array $col, mixed $v): string {
    if ($v === null) {
        return '';
    }
    return match ($col['type']) {
        'money' => (int) $v === 0 ? '<span class="muted">-</span>' : e(\App\Helpers\Money::format((int) $v, false)),
        'int'   => e(number_format((int) $v, 0, ',', '.')),
        'pct'   => e(number_format(((int) $v) / 100, 2, ',', '.')) . '%',
        default => e((string) $v),
    };
};
$numeric = static fn (array $c): bool => in_array($c['type'], ['money', 'int', 'pct'], true);
// Lebar kolom mengikuti isi: kolom teks yang isinya pendek (regu, status, bulan, jenis) menyusut sebesar isinya dan tidak patah;
// kolom teks panjang (nama, anggota) mengambil sisa lebar. Ambang berdasarkan isi sebenarnya, bukan angka tetap per laporan.
$fit = [];
foreach ($report['columns'] as $c) {
    if ($numeric($c) || $c['type'] !== 'text') {
        continue;
    }
    $max = 0;
    foreach ($report['rows'] as $row) {
        $max = max($max, mb_strlen((string) ($row[$c['key']] ?? '')));
    }
    $fit[$c['key']] = $max <= 18;
}
$cls = static fn (array $c, bool $isNum, array $fit): string => $isNum ? ' class="num"' : (!empty($fit[$c['key']]) ? ' class="fit"' : '');
?>
<div class="table-wrap table-wrap--report">
    <table class="table table--report">
        <thead>
            <tr><?php foreach ($report['columns'] as $c): ?><th<?= $cls($c, $numeric($c), $fit) ?>><?= e($c['label']) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php if ($report['rows'] === []): ?>
            <tr><td colspan="<?= count($report['columns']) ?>" class="empty">Tidak ada data untuk saringan ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($report['rows'] as $row): ?>
            <tr>
                <?php foreach ($report['columns'] as $c): $v = $row[$c['key']] ?? null; $href = $row['_links'][$c['key']] ?? null; ?>
                    <td<?= $cls($c, $numeric($c), $fit) ?>><?= $href !== null ? '<a href="' . e(url($href)) . '">' . $cell($c, $v) . '</a>' : $cell($c, $v) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (!empty($report['totals']) && $report['rows'] !== []): ?>
        <tfoot>
            <tr><?php foreach ($report['columns'] as $c): ?><th<?= $numeric($c) ? ' class="num"' : '' ?>><?= $cell($c, $report['totals'][$c['key']] ?? null) ?></th><?php endforeach; ?></tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>
