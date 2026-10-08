<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . config('app.short') : config('app.name')) ?></title>
    <?= \App\Core\View::include('partials/head-icons', []) ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest">
<main class="guest__wrap">
    <div class="guest__card">
        <?= \App\Core\View::include('partials/brand', []) ?>
        <?= \App\Core\View::include('partials/flash', ['flash' => $flash ?? []]) ?>
        <?= $content ?>
    </div>
    <p class="guest__foot"><?= e(config('app.name')) ?></p>
</main>
</body>
</html>
