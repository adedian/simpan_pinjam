<?php /** Tanda merek: logo tiga gelombang tenang di atas bidang ungu ("adem ayem"). Gambar di public/assets/img. */ ?>
<span class="brand">
    <img class="brand__mark" src="<?= e(asset('img/logo-64.png')) ?>" srcset="<?= e(asset('img/logo-64.png')) ?> 1x, <?= e(asset('img/logo.png')) ?> 3x" width="36" height="36" alt="" decoding="async">
    <span class="brand__text">
        <strong><?= e(config('app.short')) ?></strong>
        <small><?= e(config('app.tagline')) ?></small>
    </span>
</span>
