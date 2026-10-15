/* Service worker Adem Ayem (PWA).
 *
 * Prinsip: ini aplikasi keuangan, jadi HALAMAN TIDAK PERNAH DISIMPAN. Yang disimpan hanya berkas statis
 * (CSS, JS, gambar di /assets/) dan satu halaman /offline tanpa data. Semua halaman lain, termasuk login,
 * formulir, laporan, dan denyut pembaruan langsung, langsung ke jaringan; bila jaringan putus pada saat membuka
 * halaman, yang tampil adalah halaman offline, bukan saldo lama.
 *
 * Path dasar diambil dari scope pendaftaran, sehingga jalan di /simpan_pinjam/ maupun di akar domain.
 * Naikkan VERSION bila logika berkas ini berubah. Aset diberi ?v=waktu-ubah oleh server, jadi cache-first aman.
 */
'use strict';

var VERSION = 'adem-ayem-v2';
var BASE = new URL(self.registration.scope).pathname;     // mis. "/simpan_pinjam/"
var OFFLINE = BASE + 'offline';
var ASSETS = BASE + 'assets/';

/** Kumpulkan alamat aset dari HTML halaman offline (href, src, srcset) agar cache-nya lengkap. */
function assetUrls(html) {
    var found = {};
    var attr = /(?:href|src)="([^"]+)"/g;
    var set = /srcset="([^"]+)"/g;
    var m;
    while ((m = attr.exec(html)) !== null) { found[m[1]] = true; }
    while ((m = set.exec(html)) !== null) {
        m[1].split(',').forEach(function (part) { var u = part.trim().split(/\s+/)[0]; if (u) { found[u] = true; } });
    }
    return Object.keys(found).map(function (u) { return u.replace(/&amp;/g, '&'); }).filter(function (u) { return u.indexOf(ASSETS) === 0; });
}

/** Aset sah = bukan HTML. Sebagian hosting gratis membalas permintaan tanpa cookie dengan halaman cek-bot berstatus 200. */
function isAsset(res) {
    return res.ok && res.type === 'basic' && (res.headers.get('content-type') || '').indexOf('text/html') === -1;
}

/**
 * Simpan halaman offline beserta asetnya. Hanya halaman yang benar-benar halaman offline kita (berpenanda
 * data-reload) yang disimpan; halaman cek-bot hosting atau galat server ditolak, bukan dijadikan "offline".
 */
async function cacheOffline(cache) {
    var res = await fetch(OFFLINE, { cache: 'reload' });
    var html = await res.clone().text();
    if (!res.ok || html.indexOf('data-reload') === -1) { return false; }
    await cache.put(OFFLINE, res);
    await Promise.all(assetUrls(html).map(async function (u) {
        try {
            var r = await fetch(u);
            if (isAsset(r)) { await cache.put(u, r); }
        } catch (e) { /* aset gagal diambil: disimpan saat dipakai */ }
    }));
    return true;
}

/** Pastikan halaman offline ada di cache (dicoba ulang setelah navigasi berhasil bila instalasi tidak sempat). */
function ensureOffline() {
    return caches.open(VERSION).then(function (cache) {
        return cache.match(OFFLINE).then(function (hit) { return hit ? true : cacheOffline(cache); });
    }).catch(function () { return false; });
}

/** Cadangan terakhir bila halaman offline belum tersimpan: teks polos, tanpa data. */
function fallbackPage() {
    return new Response(
        '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tidak ada koneksi</title>'
        + '<body style="font-family:sans-serif;text-align:center;padding:48px 20px"><h1>Tidak ada koneksi</h1><p>Periksa jaringan Anda, lalu coba lagi.</p></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('install', function (event) {
    event.waitUntil((async function () {
        try { await cacheOffline(await caches.open(VERSION)); } catch (e) { /* dicoba lagi lewat ensureOffline() */ }
        await self.skipWaiting();
    })());
});
self.addEventListener('activate', function (event) {
    event.waitUntil((async function () {
        var names = await caches.keys();
        await Promise.all(names.filter(function (n) { return n.indexOf('adem-ayem-') === 0 && n !== VERSION; })
            .map(function (n) { return caches.delete(n); }));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') { return; }
    var url = new URL(req.url);
    if (url.origin !== self.location.origin || url.pathname.indexOf(BASE) !== 0) { return; }

    // Membuka halaman: selalu jaringan. Putus koneksi -> halaman offline (bukan salinan lama).
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).then(function (res) {
            event.waitUntil(ensureOffline());
            return res;
        }, function () {
            return caches.match(OFFLINE).then(function (r) { return r || fallbackPage(); });
        }));
        return;
    }

    // Aset statis berversi: cache dulu, jaringan bila belum ada (lalu disimpan).
    if (url.pathname.indexOf(ASSETS) === 0) {
        event.respondWith(caches.match(req).then(function (hit) {
            if (hit) { return hit; }
            return fetch(req).then(function (res) {
                if (isAsset(res)) {
                    var copy = res.clone();
                    caches.open(VERSION).then(function (c) { c.put(req, copy); });
                }
                return res;
            });
        }));
    }
    // Selain itu (mis. denyut pembaruan langsung, unduhan) tidak disentuh sama sekali.
});
