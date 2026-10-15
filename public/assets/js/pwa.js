/* PWA: pendaftaran service worker, tombol "Pasang aplikasi", dan tombol "Coba lagi" halaman offline.
 * Tanpa dependensi, tanpa inline script (CSP). Alamat service worker datang dari data-sw pada tag <script>. */
(function () {
    'use strict';

    var script = document.currentScript;
    var swUrl = script ? script.getAttribute('data-sw') : null;

    // ---- Service worker (hanya HTTPS atau localhost; di luar itu browser menolak sendiri) ----
    if ('serviceWorker' in navigator && swUrl) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(swUrl).catch(function () { /* aplikasi tetap jalan tanpa PWA */ });
        });
    }

    // ---- Tombol "Coba lagi" di halaman offline ----
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target instanceof Element && target.closest('[data-reload]')) { window.location.reload(); }
    });

    // ---- Pasang aplikasi ----
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    var boxes = document.querySelectorAll('[data-pwa-box]');
    var deferred = null;

    function show(selector) {
        boxes.forEach(function (box) {
            box.hidden = false;
            box.querySelectorAll('[data-pwa-install], [data-pwa-ios]').forEach(function (el) { el.hidden = !el.matches(selector); });
        });
    }
    function hideAll() { boxes.forEach(function (box) { box.hidden = true; }); }

    if (standalone || !boxes.length) { return; }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferred = event;
        show('[data-pwa-install]');
    });
    window.addEventListener('appinstalled', function () { deferred = null; hideAll(); });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element) || !target.closest('[data-pwa-install]') || !deferred) { return; }
        var prompt = deferred;
        deferred = null;
        prompt.prompt();
        prompt.userChoice.then(hideAll, hideAll);
    });

    // iPhone/iPad (Safari) tidak punya beforeinstallprompt: tampilkan petunjuk manual.
    var ua = window.navigator.userAgent;
    var ios = /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && window.navigator.maxTouchPoints > 1);
    if (ios) { show('[data-pwa-ios]'); }
})();
