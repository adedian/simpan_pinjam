<?php
use App\Core\View;

/** @var array<string,mixed> $report */
$exportQuery = http_build_query($query);
?>
<section class="card report">
    <header class="report__head">
        <div>
            <h2 class="card__title"><?= e($report['title']) ?></h2>
            <p class="muted"><?= e($report['subtitle']) ?></p>
            <p class="muted report__stamp">Dicetak <?= e($printedAt) ?> oleh <?= e($printedBy) ?> · <?= e(config('app.name')) ?></p>
        </div>
        <div class="actions no-print">
            <button type="button" class="btn btn--secondary" data-print>Cetak</button>
            <?php if ($canExport): ?>
                <a class="btn btn--primary" href="<?= e(url('/laporan/' . $report['key'] . '/unduh' . ($exportQuery !== '' ? '?' . $exportQuery : ''))) ?>">Unduh Excel</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($filters['range_error'])): ?><div class="alert alert--warning no-print" role="alert"><?= e($filters['range_error']) ?></div><?php endif; ?>
    <p class="report__period no-print"><span class="badge badge--disetujui"><?= e(\App\Services\ReportService::periodLabel($filters)) ?></span></p>

    <form class="toolbar no-print" method="get" action="<?= e(url($basePath)) ?>" data-range-form>
        <?php foreach ($fields as $fd): $id = 'r_' . $fd['name']; ?>
            <div class="toolbar__field toolbar__field--<?= e($fd['type']) ?>">
                <label class="toolbar__label" for="<?= e($id) ?>"><?= e($fd['label']) ?></label>
                <?php if ($fd['type'] === 'select'): ?>
                    <select id="<?= e($id) ?>" class="input" name="<?= e($fd['name']) ?>" title="<?= e($fd['label']) ?>"<?= !empty($fd['preset']) ? ' data-range-preset' : '' ?>>
                        <?php foreach ($fd['options'] as $ov => $ol): ?>
                            <option value="<?= e($ov) ?>"<?= (string) $ov === (string) $fd['value'] ? ' selected' : '' ?>><?= e($ol) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($fd['type'] === 'date'): ?>
                    <input id="<?= e($id) ?>" class="input" type="date" name="<?= e($fd['name']) ?>" value="<?= e($fd['value']) ?>" title="<?= e($fd['label']) ?>"<?= $fd['name'] === 'dari' ? ' data-range-from' : ' data-range-to' ?>>
                <?php else: ?>
                    <input id="<?= e($id) ?>" class="input" type="search" name="<?= e($fd['name']) ?>" value="<?= e($fd['value']) ?>" placeholder="<?= e($fd['placeholder'] ?? $fd['label']) ?>" maxlength="60">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn--primary">Terapkan Filter</button>
        <a class="btn btn--link" href="<?= e(url($basePath)) ?>">Reset</a>
    </form>

    <?php if ($report['key'] === 'bagihasil'): ?>
        <?= View::include('partials/profit-extra', ['report' => $report, 'snapshotForm' => $snapshotForm ?? null, 'voidForm' => $voidForm ?? null, 'filters' => $filters]) ?>
    <?php endif; ?>

    <?php if ($report['key'] === 'shu' && !empty($report['summary'])): ?>
        <div class="stats stats--report">
            <?php foreach ($report['summary'] as $s): ?>
                <div class="stat"><span class="stat__label"><?= e($s['label']) ?></span><strong class="stat__value"><?= e(money((int) $s['value'])) ?></strong><span class="stat__hint"><?= e($s['hint']) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php if (isset($report['control']) && !$report['control']['ok']): ?><div class="alert alert--danger" role="alert">Kontrol gagal: penabung + peminjam + Kas SHU tidak sama dengan bunga dibukukan. Periksa data.</div><?php endif; ?>
    <?php endif; ?>

    <?php if ($level === 'none'): ?>
        <div class="alert alert--warning" role="note">Akun Anda belum tertaut ke data anggota atau regu, jadi belum ada laporan yang bisa ditampilkan.</div>
    <?php endif; ?>

    <?= View::include('partials/report-table', ['report' => $report]) ?>
    <?php if ($report['note'] !== ''): ?><p class="muted report__note"><?= e($report['note']) ?></p><?php endif; ?>
    <?php if (!empty($report['pager'])): ?>
        <div class="no-print"><?= View::include('partials/pagination', ['pager' => $report['pager'], 'query' => $query, 'basePath' => $basePath]) ?></div>
    <?php endif; ?>
</section>
