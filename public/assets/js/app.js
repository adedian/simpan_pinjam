/* Sistem Simpan Pinjam Adem Ayem — skrip dasar. Tanpa dependensi, tanpa inline script (CSP). */
(function () {
    'use strict';

    // ---- Drawer menu (layar kecil) ----
    var body = document.body;
    var openBtn = document.querySelector('[data-drawer-open]');

    function setDrawer(open) {
        body.classList.toggle('drawer-open', open);
        if (openBtn) { openBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) { return; }
        if (target.closest('[data-drawer-open]')) { setDrawer(true); }
        else if (target.closest('[data-drawer-close]')) { setDrawer(false); }
        else if (target.closest('.sidebar a.nav__item')) { setDrawer(false); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { setDrawer(false); }
    });

    window.matchMedia('(min-width: 961px)').addEventListener('change', function (mq) {
        if (mq.matches) { setDrawer(false); }
    });

    // ---- Konfirmasi untuk aksi berisiko: <button data-confirm="pesan"> ----
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) { return; }
        var btn = target.closest('[data-confirm]');
        if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    // ---- Tombol "Kembali" di halaman galat: <a data-back href="/"> ----
    // Kembali ke halaman sebelumnya bila berasal dari aplikasi ini; selain itu tautan biasa ke beranda.
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element) || !target.closest('[data-back]')) { return; }
        var from = document.referrer;
        if (window.history.length > 1 && from && from.indexOf(window.location.origin) === 0) {
            event.preventDefault();
            window.history.back();
        }
    });

    // ---- Cetak laporan: <button data-print> ----
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target instanceof Element && target.closest('[data-print]')) { window.print(); }
    });

    // ---- Tombol tampilkan/sembunyikan kata sandi (hanya muncul bila JS aktif) ----
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        var input = document.getElementById(btn.getAttribute('data-password-toggle'));
        if (!input) { return; }
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            btn.querySelector('[data-icon-show]').hidden = show;
            btn.querySelector('[data-icon-hide]').hidden = !show;
            input.focus();
        });
    });

    // ---- Helper fetch: otomatis menyertakan token CSRF dan menangani sesi berakhir ----
    // Dipakai oleh fitur realtime/AJAX (Phase 14) dan aksi validasi (Phase 9).
    var meta = document.querySelector('meta[name="csrf-token"]');

    window.AdemAyem = {
        csrfToken: function () { return meta ? meta.getAttribute('content') : ''; },

        request: function (url, options) {
            options = options || {};
            var headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.csrfToken()
            }, options.headers || {});

            if (options.json !== undefined) {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(options.json);
            }

            return fetch(url, Object.assign({}, options, { headers: headers, credentials: 'same-origin' }))
                .then(function (response) {
                    if (response.status === 401) {
                        window.location.reload(); // sesi berakhir: biarkan server mengarahkan ke halaman masuk
                        throw new Error('Sesi berakhir');
                    }
                    return response.json().then(function (data) {
                        if (!response.ok) { throw Object.assign(new Error(data.error || 'Permintaan gagal'), { status: response.status, data: data }); }
                        return data;
                    });
                });
        }
    };

    // ---- Dropdown dengan pencarian: <select class="input"> otomatis diganti kotak pilih yang bisa dicari ----
    // <select> asli tetap ada (tersembunyi) sehingga pengiriman formulir, validasi, dan label tidak berubah.
    // Tambahkan data-native pada <select> yang sengaja ingin dibiarkan bawaan browser.
    var ssCount = 0;

    function fold(text) {
        return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        return node;
    }

    function enhanceSelect(select) {
        if (select.multiple || select.hasAttribute('data-native') || select.hasAttribute('data-ss-ready')) { return; }
        select.setAttribute('data-ss-ready', '');
        var uid = 'ss' + (++ssCount);

        var wrap = el('div', 'ss');
        var button = el('button', 'input ss__btn', { type: 'button', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', 'aria-controls': uid + '-list' });
        var label = el('span', 'ss__label');
        var chevron = el('span', 'ss__chevron', { 'aria-hidden': 'true' });
        button.appendChild(label);
        button.appendChild(chevron);

        var panel = el('div', 'ss__panel');
        panel.hidden = true;
        var search = el('input', 'input ss__search', { type: 'search', placeholder: 'Cari…', autocomplete: 'off', 'aria-label': 'Cari pilihan', 'aria-controls': uid + '-list' });
        var list = el('ul', 'ss__list', { id: uid + '-list', role: 'listbox' });
        var empty = el('p', 'ss__empty');
        empty.textContent = 'Tidak ada hasil.';
        empty.hidden = true;
        panel.appendChild(search);
        panel.appendChild(list);
        panel.appendChild(empty);

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(button);
        wrap.appendChild(panel);
        select.classList.add('ss__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        if (select.disabled) { wrap.classList.add('is-disabled'); button.disabled = true; }
        if (select.id) {
            // label[for] yang menunjuk <select> kini membuka kotak pilih
            document.querySelectorAll('label[for="' + select.id + '"]').forEach(function (lb) {
                lb.addEventListener('click', function (event) { event.preventDefault(); button.focus(); });
            });
        }
        if (select.getAttribute('aria-invalid') === 'true') { button.setAttribute('aria-invalid', 'true'); }

        var items = [];
        var active = -1;

        function build() {
            list.textContent = '';
            items = [];
            Array.prototype.forEach.call(select.options, function (opt, i) {
                var li = el('li', 'ss__opt', { role: 'option', id: uid + '-o' + i });
                li.textContent = opt.text;
                li.setAttribute('data-index', String(i));
                if (opt.disabled) { li.setAttribute('aria-disabled', 'true'); li.classList.add('is-disabled'); }
                if (opt.value === '') { li.classList.add('is-empty'); }
                list.appendChild(li);
                items.push({ li: li, text: fold(opt.text), opt: opt });
            });
        }

        function syncLabel() {
            var opt = select.options[select.selectedIndex];
            label.textContent = opt ? opt.text : '';
            label.classList.toggle('is-placeholder', !opt || opt.value === '');
            items.forEach(function (it) {
                var on = it.opt === opt;
                it.li.setAttribute('aria-selected', on ? 'true' : 'false');
                it.li.classList.toggle('is-selected', on);
            });
        }

        function visible() {
            return items.filter(function (it) { return !it.li.hidden && !it.opt.disabled; });
        }

        function setActive(item, scroll) {
            items.forEach(function (it) { it.li.classList.remove('is-active'); });
            active = item ? items.indexOf(item) : -1;
            if (item) {
                item.li.classList.add('is-active');
                search.setAttribute('aria-activedescendant', item.li.id);
                if (scroll !== false) { item.li.scrollIntoView({ block: 'nearest' }); }
            } else {
                search.removeAttribute('aria-activedescendant');
            }
        }

        function filter() {
            var tokens = fold(search.value).split(/\s+/).filter(Boolean);
            var shown = 0;
            items.forEach(function (it) {
                var ok = tokens.every(function (t) { return it.text.indexOf(t) !== -1; });
                it.li.hidden = !ok;
                if (ok) { shown++; }
            });
            empty.hidden = shown !== 0;
            var first = visible()[0] || null;
            // Saat mengetik, sorotan ke hasil pertama; tanpa kata kunci, ke pilihan saat ini.
            var selected = select.options[select.selectedIndex];
            var current = items.filter(function (it) { return it.opt === selected && !it.li.hidden; })[0];
            setActive(tokens.length ? first : (current || first));
        }

        function isOpen() { return !panel.hidden; }

        function open(seed) {
            if (select.disabled || isOpen()) { return; }
            document.querySelectorAll('.ss.is-open').forEach(function (other) {
                if (other !== wrap) { other.dispatchEvent(new CustomEvent('ss:close')); }
            });
            panel.hidden = false;
            wrap.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            var rect = wrap.getBoundingClientRect();
            var below = window.innerHeight - rect.bottom;
            wrap.classList.toggle('ss--up', below < 300 && rect.top > below);
            wrap.classList.remove('ss--right');
            if (panel.getBoundingClientRect().right > window.innerWidth - 8) { wrap.classList.add('ss--right'); }   // jangan keluar layar
            search.value = seed || '';
            filter();
            search.focus();
        }

        function close(refocus) {
            if (!isOpen()) { return; }
            panel.hidden = true;
            wrap.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');
            if (refocus) { button.focus(); }
        }

        function choose(item) {
            if (!item || item.opt.disabled) { return; }
            var index = items.indexOf(item);
            var changed = select.selectedIndex !== index;
            select.selectedIndex = index;
            syncLabel();
            close(true);
            if (changed) {
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function move(step) {
            var vis = visible();
            if (!vis.length) { return; }
            var cur = vis.indexOf(items[active]);
            var next = cur === -1 ? (step > 0 ? 0 : vis.length - 1) : (cur + step + vis.length) % vis.length;
            setActive(vis[next]);
        }

        button.addEventListener('click', function () { if (isOpen()) { close(false); } else { open(''); } });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open('');
            } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                open(event.key);   // langsung mengetik = langsung mencari
            }
        });

        search.addEventListener('input', filter);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); move(1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); move(-1); }
            else if (event.key === 'Enter') { event.preventDefault(); choose(items[active]); }
            else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
            else if (event.key === 'Tab') { close(false); }
        });

        list.addEventListener('mousemove', function (event) {
            var li = event.target instanceof Element ? event.target.closest('.ss__opt') : null;
            if (li && !li.hidden && !li.classList.contains('is-disabled')) { setActive(items[Number(li.getAttribute('data-index'))], false); }
        });
        list.addEventListener('click', function (event) {
            var li = event.target instanceof Element ? event.target.closest('.ss__opt') : null;
            if (li) { choose(items[Number(li.getAttribute('data-index'))]); }
        });

        wrap.addEventListener('ss:close', function () { close(false); });
        // Validasi bawaan browser (required) menunjuk <select> tersembunyi: arahkan fokus ke tombolnya.
        select.addEventListener('invalid', function () { button.setAttribute('aria-invalid', 'true'); button.focus(); });
        select.addEventListener('change', syncLabel);

        build();
        syncLabel();
    }

    function enhanceSelects(root) {
        (root || document).querySelectorAll('select.input').forEach(enhanceSelect);
    }

    document.addEventListener('mousedown', function (event) {
        if (!(event.target instanceof Element)) { return; }
        document.querySelectorAll('.ss.is-open').forEach(function (wrap) {
            if (!wrap.contains(event.target)) { wrap.dispatchEvent(new CustomEvent('ss:close')); }
        });
    });

    // ---- Pembaruan langsung: data yang diinput pengguna lain muncul tanpa refresh ----
    // Server menandai halaman baca-saja dengan [data-live-region]. Tiap beberapa detik halaman menanyakan
    // penanda perubahan (angka kecil); bila berubah, isi halaman diambil ulang lewat route biasa (izin dan
    // cakupan data tetap ditegakkan server) dan hanya bagian isinya yang diganti. Isian yang sedang diketik
    // tidak pernah ditimpa: pembaruan ditunda sampai pengguna selesai.
    var region = document.querySelector('[data-live-region]');
    var shownHtml = region ? region.innerHTML : '';   // HTML mentah dari server, sebelum diperkaya skrip: pembanding perubahan

    enhanceSelects(document);

    // Denyut berjalan di SETIAP halaman aplikasi (juga formulir): ia membawa penanda jumlah di menu. Pengambilan
    // ulang isi hanya untuk halaman yang punya [data-live-region].
    var tickUrl = body.getAttribute('data-live-tick');
    var canRefresh = !!(region && window.DOMParser);

    if (tickUrl && window.fetch) {
        var POLL_MS = 3000;
        var MAX_BACKOFF_MS = 30000;
        var version = region ? Number(region.getAttribute('data-live-version')) : 0;
        var pending = null;   // pembaruan yang menunggu pengguna selesai mengisi
        var delay = POLL_MS;
        var timer = null;
        var inFlight = false;
        var failures = 0;
        var offline = false;
        var toast = null;
        var toastTimer = null;

        var hideToast = function () {
            window.clearTimeout(toastTimer);
            if (toast) { toast.classList.remove('is-visible'); }
        };

        // ms: lama tampil; 0 = menetap sampai diganti atau ditutup kode.
        var showToast = function (text, action, ms) {
            if (!toast) {
                toast = el('div', 'toast', { role: 'status', 'aria-live': 'polite' });
                document.body.appendChild(toast);
            }
            toast.textContent = '';
            var span = el('span');
            span.textContent = text;
            toast.appendChild(span);
            if (action) {
                var btn = el('button', 'toast__btn', { type: 'button' });
                btn.textContent = action.label;
                btn.addEventListener('click', action.run);
                toast.appendChild(btn);
            }
            toast.classList.add('is-visible');
            window.clearTimeout(toastTimer);
            var life = ms === undefined ? 4000 : ms;
            if (life > 0) { toastTimer = window.setTimeout(hideToast, life); }
        };

        // ---- Penanda jumlah di menu (mis. "Menunggu Validasi") dan judul tab ----
        var badgeNodes = Array.prototype.slice.call(document.querySelectorAll('[data-badge]'));
        var baseTitle = document.title;
        var known = {};
        var total = 0;
        badgeNodes.forEach(function (node) {
            var n = Number(node.querySelector('[data-badge-n]').textContent) || 0;
            known[node.getAttribute('data-badge')] = n;
            total += n;
        });

        var renderTitle = function () { document.title = total > 0 ? '(' + total + ') ' + baseTitle : baseTitle; };
        renderTitle();

        // Mengembalikan teks pemberitahuan bila ada penanda yang naik (dan penandanya bukan halaman yang sedang dibuka).
        var applyBadges = function (counts) {
            var news = null;
            total = 0;
            badgeNodes.forEach(function (node) {
                var key = node.getAttribute('data-badge');
                var n = counts && typeof counts[key] === 'number' ? counts[key] : known[key];   // kunci tak ada: biarkan, jangan dianggap 0
                if (n !== known[key]) {
                    node.querySelector('[data-badge-n]').textContent = String(n);
                    node.hidden = n <= 0;
                    if (n > known[key] && !node.closest('a.is-active')) { news = { text: node.getAttribute('data-badge-new'), href: node.closest('a').href }; }
                    known[key] = n;
                }
                total += n;
            });
            renderTitle();
            return news;
        };

        // Pengguna sedang mengisi? (fokus di kolom isian, kotak pilih terbuka, atau ada isian yang diubah)
        var busy = function () {
            var a = document.activeElement;
            if (a && region.contains(a) && a.matches('input:not([type=button]):not([type=submit]):not([type=checkbox]):not([type=radio]), textarea, select, .ss__btn')) { return true; }
            if (region.querySelector('.ss.is-open')) { return true; }
            var dirty = false;
            region.querySelectorAll('input, textarea').forEach(function (f) {
                if (f.type === 'hidden' || f.type === 'submit' || f.type === 'button') { return; }
                if (f.type === 'checkbox' || f.type === 'radio') { if (f.checked !== f.defaultChecked) { dirty = true; } }
                else if (f.value !== f.defaultValue) { dirty = true; }
            });
            region.querySelectorAll('select').forEach(function (s) {
                // tanpa atribut selected, bawaan browser adalah pilihan pertama
                var initial = 0;
                Array.prototype.some.call(s.options, function (o, i) { if (o.defaultSelected) { initial = i; return true; } return false; });
                if (s.selectedIndex !== initial) { dirty = true; }
            });
            return dirty;
        };

        var apply = function (html) {
            var open = Array.prototype.map.call(region.querySelectorAll('details'), function (d) { return d.open; });
            region.innerHTML = html;
            shownHtml = html;
            pending = null;
            region.querySelectorAll('details').forEach(function (d, i) { if (open[i]) { d.open = true; } });
            enhanceSelects(region);
            showToast('Data diperbarui.');
            document.dispatchEvent(new CustomEvent('adem:live-updated'));
        };

        var tryPending = function () {
            if (pending !== null && !busy()) { apply(pending); }
        };

        var refresh = function () {
            return fetch(window.location.pathname + window.location.search, {
                credentials: 'same-origin',
                headers: { 'Accept': 'text/html', 'X-Live': '1', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (response.redirected || response.status === 401 || response.status === 403 || response.status === 404) {
                    window.location.reload();   // sesi berakhir / akses berubah: biarkan server menampilkan keadaan sebenarnya
                    return null;
                }
                if (!response.ok) { throw new Error('refresh ' + response.status); }
                return response.text();
            }).then(function (text) {
                if (text === null) { return; }
                var fresh = new DOMParser().parseFromString(text, 'text/html').querySelector('[data-live-region]');
                if (!fresh) { window.location.reload(); return; }
                version = Number(fresh.getAttribute('data-live-version'));
                var html = fresh.innerHTML;
                if (html === shownHtml) { pending = null; hideToast(); return; }
                if (busy()) {
                    pending = html;
                    showToast('Ada data baru. Akan dimuat setelah Anda selesai mengisi.', { label: 'Muat sekarang', run: function () { apply(html); } }, 0);
                } else {
                    apply(html);
                }
            });
        };

        var schedule = function (ms) {
            window.clearTimeout(timer);
            if (!document.hidden) { timer = window.setTimeout(tick, ms); }
        };

        var tick = function () {
            if (inFlight) { return; }
            inFlight = true;
            window.AdemAyem.request(tickUrl, { headers: { 'X-Live': '1' } })
                .then(function (data) {
                    delay = POLL_MS;
                    failures = 0;
                    var reconnected = offline;
                    offline = false;
                    var stale = canRefresh && typeof data.v === 'number' && data.v !== version;
                    var news = applyBadges(data.b);
                    if (reconnected && !stale) { showToast('Tersambung kembali.'); }
                    else if (news && !stale) {   // bila isi halaman ikut berubah, toast "Data diperbarui" sudah cukup
                        showToast(news.text, { label: 'Buka', run: function () { window.location.assign(news.href); } }, 10000);
                    }
                    if (stale) { return refresh(); }
                    tryPending();
                })
                .catch(function () {
                    delay = Math.min(delay * 2, MAX_BACKOFF_MS);   // jaringan putus / server sibuk: jarangkan, jangan menyerbu
                    failures++;
                    if (failures === 2 && !offline) {
                        offline = true;
                        showToast('Koneksi terputus. Data mungkin belum terbaru; mencoba lagi…', null, 0);
                    }
                })
                .then(function () { inFlight = false; schedule(delay); });
        };

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { window.clearTimeout(timer); } else { schedule(0); }   // kembali ke tab: cek segera
        });
        if (region) { region.addEventListener('focusout', function () { window.setTimeout(tryPending, 0); }); }

        schedule(POLL_MS);
    }
})();
