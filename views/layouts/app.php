<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . config('app.short') : config('app.name')) ?></title>
    <?= \App\Core\View::include('partials/head-icons', []) ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php /* app.js lebih dulu: ia mencatat HTML asli wilayah live sebelum grafik menggambar ke canvas */ ?>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php if (!empty($charts)): ?>
        <script src="<?= e(asset('vendor/chart.umd.min.js')) ?>" defer></script>
        <script src="<?= e(asset('js/charts.js')) ?>" defer></script>
    <?php endif; ?>
</head>
<body<?= !empty($user) ? ' data-live-tick="' . e(url('/live/tick')) . '"' : '' ?>>
<div class="app">
    <?= \App\Core\View::include('partials/sidebar', ['nav' => $nav, 'badges' => $badges ?? []]) ?>
    <div class="scrim" data-drawer-close></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn topbar__menu" data-drawer-open aria-controls="sidebar" aria-label="Buka menu"><?= icon('menu') ?></button>
            <h1 class="topbar__title"><?= e($title ?? '') ?></h1>
            <?php if (!empty($user)): ?>
                <div class="userchip">
                    <span class="userchip__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
                    <span class="userchip__text">
                        <strong><?= e($user['name']) ?></strong>
                        <small><?= e($roleLabel) ?></small>
                    </span>
                </div>
                <form class="topbar__logout" method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="icon-btn" aria-label="Keluar" title="Keluar"><?= icon('logout') ?></button>
                </form>
            <?php endif; ?>
        </header>

        <main class="content">
            <?= \App\Core\View::include('partials/flash', ['flash' => $flash ?? []]) ?>
            <?php if (($liveVersion ?? null) !== null): ?>
                <div data-live-region data-live-version="<?= (int) $liveVersion ?>" data-live-tick="<?= e(url('/live/tick')) ?>"><?= $content ?></div>
            <?php else: ?>
                <?= $content ?>
            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>
