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
                <a class="btn btn--primary" href="<?= e(url('/laporan/' . $report['key'] . '/unduh' . ($exportQuery !== '' ? '?' . $exportQuery : ''))) ?>">Unduh CSV</a>
            <?php endif; ?>
        </div>
    </header>

    <form class="toolbar no-print" method="get" action="<?= e(url($basePath)) ?>">
        <?php foreach ($fields as $fd): $id = 'r_' . $fd['name']; ?>
            <div>
                <label class="sr-only" for="<?= e($id) ?>"><?= e($fd['label']) ?></label>
                <?php if ($fd['type'] === 'select'): ?>
                    <select id="<?= e($id) ?>" class="input" name="<?= e($fd['name']) ?>" title="<?= e($fd['label']) ?>">
                        <?php foreach ($fd['options'] as $ov => $ol): ?>
                            <option value="<?= e($ov) ?>"<?= (string) $ov === (string) $fd['value'] ? ' selected' : '' ?>><?= e($ol) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($fd['type'] === 'date'): ?>
                    <input id="<?= e($id) ?>" class="input" type="date" name="<?= e($fd['name']) ?>" value="<?= e($fd['value']) ?>" title="<?= e($fd['label']) ?>">
                <?php else: ?>
                    <input id="<?= e($id) ?>" class="input" type="search" name="<?= e($fd['name']) ?>" value="<?= e($fd['value']) ?>" placeholder="<?= e($fd['placeholder'] ?? $fd['label']) ?>" maxlength="60">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn--secondary">Tampilkan</button>
        <a class="btn btn--link" href="<?= e(url($basePath)) ?>">Atur ulang</a>
    </form>

    <?php if ($level === 'none'): ?>
        <div class="alert alert--warning" role="note">Akun Anda belum tertaut ke data anggota atau regu, jadi belum ada laporan yang bisa ditampilkan.</div>
    <?php endif; ?>

    <?= View::include('partials/report-table', ['report' => $report]) ?>
    <?php if ($report['note'] !== ''): ?><p class="muted report__note"><?= e($report['note']) ?></p><?php endif; ?>
    <?php if (!empty($report['pager'])): ?>
        <div class="no-print"><?= View::include('partials/pagination', ['pager' => $report['pager'], 'query' => $query, 'basePath' => $basePath]) ?></div>
    <?php endif; ?>
</section>
