<?php
/** Paginasi. Parameter: $pager (Pagination::make), $query (filter aktif), $basePath */
if ($pager['pages'] <= 1) {
    return;
}
$link = static function (int $page) use ($query, $basePath): string {
    return url($basePath . '?' . http_build_query(array_filter($query + ['page' => $page], static fn ($v) => $v !== '' && $v !== 0 && $v !== null)));
};
?>
<nav class="pager" aria-label="Halaman">
    <span class="pager__info"><?= (int) $pager['from'] ?>–<?= (int) $pager['to'] ?> dari <?= (int) $pager['total'] ?></span>
    <span class="pager__links">
        <?php if ($pager['page'] > 1): ?><a class="btn btn--secondary btn--sm" href="<?= e($link($pager['page'] - 1)) ?>">Sebelumnya</a><?php endif; ?>
        <span class="pager__page">Hal. <?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?></span>
        <?php if ($pager['page'] < $pager['pages']): ?><a class="btn btn--secondary btn--sm" href="<?= e($link($pager['page'] + 1)) ?>">Berikutnya</a><?php endif; ?>
    </span>
</nav>
