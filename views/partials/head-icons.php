<?php /** Ikon tab peramban dan layar utama ponsel (logo yang sama dengan tanda merek), plus penanda PWA (manifest, warna bilah, service worker). */ ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <meta name="theme-color" content="#4b2e9a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= e(config('app.short')) ?>">
    <script src="<?= e(asset('js/pwa.js')) ?>" data-sw="<?= e(url('/sw.js')) ?>" defer></script>
