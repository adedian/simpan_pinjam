<?php
/**
 * Kartu grafik. $c = spesifikasi dari HomeController::chart().
 * Grafik digambar skrip charts.js dari atribut data-chart; angka yang sama selalu tersedia sebagai tabel ("Lihat angka"),
 * jadi tidak ada informasi yang hanya terbaca lewat warna atau tooltip.
 */
$spec = ['type' => $c['type'], 'labels' => $c['labels'], 'series' => $c['series'], 'horizontal' => $c['horizontal']];
$isDoughnut = $c['type'] === 'doughnut';
$count = count($c['labels']);
$size = $count <= 4 ? 's' : ($count <= 8 ? 'm' : 'l');
$descr = $c['title'] . ': ' . implode(', ', array_map(static fn (string $l, int $i): string => $l . ' ' . money((int) $c['series'][0]['data'][$i]), array_slice($c['labels'], 0, 4), array_keys(array_slice($c['labels'], 0, 4))));
?>
<figure class="chart chart--<?= e($c['type']) ?>" id="chart-<?= e($c['id']) ?>">
    <figcaption class="chart__head">
        <h3 class="chart__title"><?= e($c['title']) ?></h3>
        <?php if ($c['note'] !== ''): ?><span class="chart__note"><?= e($c['note']) ?></span><?php endif; ?>
    </figcaption>
    <?php if ($isDoughnut || count($c['series']) > 1): ?>
        <ul class="legend">
            <?php if ($isDoughnut): foreach ($c['labels'] as $i => $label): ?>
                <li><span class="swatch swatch--<?= (int) $i + 1 ?>" aria-hidden="true"></span><?= e($label) ?></li>
            <?php endforeach; else: foreach ($c['series'] as $s): ?>
                <li><span class="swatch swatch--<?= (int) $s['slot'] ?>" aria-hidden="true"></span><?= e($s['label']) ?></li>
            <?php endforeach; endif; ?>
        </ul>
    <?php endif; ?>
    <div class="chart__plot chart__plot--<?= e($size) ?>">
        <canvas data-chart="<?= e((string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" role="img" aria-label="<?= e($descr) ?>">Grafik tidak tampil di peramban ini. Angkanya ada pada tabel di bawah.</canvas>
    </div>
    <details class="chart__table">
        <summary>Lihat angka</summary>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th><?= $isDoughnut ? 'Bagian' : 'Kategori' ?></th><?php if ($isDoughnut): ?><th class="num">Jumlah</th><?php else: foreach ($c['series'] as $s): ?><th class="num"><?= e($s['label']) ?></th><?php endforeach; endif; ?></tr></thead>
                <tbody>
                <?php foreach ($c['labels'] as $i => $label): ?>
                    <tr>
                        <td><?= e($label) ?></td>
                        <?php foreach ($c['series'] as $s): ?><td class="num"><?= e(money((int) $s['data'][$i])) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</figure>
