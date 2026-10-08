<?php /** @var array<int,array<string,mixed>> $nav */ /** @var array<string,int> $badges */ ?>
<aside class="sidebar" id="sidebar" aria-label="Menu utama">
    <div class="sidebar__head">
        <a href="<?= e(url('/')) ?>" class="sidebar__brand" aria-label="Beranda"><?= \App\Core\View::include('partials/brand', []) ?></a>
        <button type="button" class="icon-btn sidebar__close" data-drawer-close aria-label="Tutup menu"><?= icon('x') ?></button>
    </div>

    <nav class="nav">
        <?php foreach ($nav as $group): ?>
            <div class="nav__group">
                <p class="nav__label"><?= e($group['label']) ?></p>
                <?php foreach ($group['items'] as $item): ?>
                    <?php if ($item['available']): ?>
                        <a class="nav__item<?= $item['active'] ? ' is-active' : '' ?>" href="<?= e(url($item['path'])) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                            <?= icon($item['icon'], 18) ?><span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['badge'])): $count = (int) ($badges[$item['badge']] ?? 0); ?>
                                <b class="nav__badge" data-badge="<?= e($item['badge']) ?>" data-badge-new="Ada transaksi baru menunggu validasi."<?= $count > 0 ? '' : ' hidden' ?>><bdi data-badge-n><?= $count ?></bdi><bdi class="sr-only"> menunggu tindakan Anda</bdi></b>
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <span class="nav__item is-disabled" aria-disabled="true" title="Aktif pada Phase <?= (int) $item['phase'] ?>">
                            <?= icon($item['icon'], 18) ?><span><?= e($item['label']) ?></span><em>P<?= (int) $item['phase'] ?></em>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
