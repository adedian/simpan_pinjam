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
?>
<div class="table-wrap table-wrap--report">
    <table class="table table--report">
        <thead>
            <tr><?php foreach ($report['columns'] as $c): ?><th<?= $numeric($c) ? ' class="num"' : '' ?>><?= e($c['label']) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php if ($report['rows'] === []): ?>
            <tr><td colspan="<?= count($report['columns']) ?>" class="empty">Tidak ada data untuk saringan ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($report['rows'] as $row): ?>
            <tr>
                <?php foreach ($report['columns'] as $c): $v = $row[$c['key']] ?? null; $href = $row['_links'][$c['key']] ?? null; ?>
                    <td<?= $numeric($c) ? ' class="num"' : '' ?>><?= $href !== null ? '<a href="' . e(url($href)) . '">' . $cell($c, $v) . '</a>' : $cell($c, $v) ?></td>
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
