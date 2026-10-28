/* Sistem Simpan Pinjam Adem Ayem — kotak pilih anggota (dropdown yang bisa dicari). Tanpa dependensi, tanpa inline script (CSP).
 *
 * Markup dari views/partials/member-picker.php:
 *   <div class="mp" data-member-picker data-endpoint data-context data-team data-include>
 *     <input type="hidden" data-mp-value>   <- satu-satunya nilai yang dikirim formulir; terisi HANYA lewat memilih dari daftar
 *     <input class="mp__input" role="combobox">   <- teks pencarian / nama terpilih
 *   Hasil dimuat dari /api/anggota (hak akses ditegakkan server). Daftar dipasang di <body> dengan posisi tetap, sehingga
 *   tidak terpotong oleh modal, tabel, atau wadah ber-overflow.
 *
 * Aturan: mengubah teks setelah memilih langsung mengosongkan nilai (teks dan nilai tidak pernah berbeda); teks yang
 * tidak dipilih dari daftar dibuang saat kotak ditutup. */
(function () {
    'use strict';

    var PAGE_SIZE = 20;
    var DEBOUNCE_MS = 220;
    var uid = 0;
    var current = null;   // kotak yang sedang terbuka (hanya satu)

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        return node;
    }

    // Isi node dengan teks, bagian yang cocok dengan kata kunci ditebalkan (tanpa innerHTML).
    function marked(node, text, tokens) {
        node.textContent = '';
        var lower = text.toLowerCase();
        var ranges = [];
        if (tokens.length && lower.length === text.length) {
            tokens.forEach(function (t) {
                var i = lower.indexOf(t);
                while (i !== -1) { ranges.push([i, i + t.length]); i = lower.indexOf(t, i + t.length); }
            });
        }
        if (!ranges.length) { node.textContent = text; return; }
        ranges.sort(function (a, b) { return a[0] - b[0]; });
        var merged = [ranges[0]];
        ranges.slice(1).forEach(function (r) {
            var last = merged[merged.length - 1];
            if (r[0] <= last[1]) { last[1] = Math.max(last[1], r[1]); } else { merged.push(r); }
        });
        var pos = 0;
        merged.forEach(function (r) {
            if (r[0] > pos) { node.appendChild(document.createTextNode(text.slice(pos, r[0]))); }
            var m = el('mark', 'mp__hit');
            m.textContent = text.slice(r[0], r[1]);
            node.appendChild(m);
            pos = r[1];
        });
        if (pos < text.length) { node.appendChild(document.createTextNode(text.slice(pos))); }
    }

    function init(wrap) {
        if (wrap.hasAttribute('data-mp-ready')) { return; }
        var hidden = wrap.querySelector('[data-mp-value]');
        var input = wrap.querySelector('.mp__input');
        if (!hidden || !input) { return; }
        wrap.setAttribute('data-mp-ready', '');

        var clearBtn = wrap.querySelector('.mp__clear');
        var endpoint = wrap.getAttribute('data-endpoint');
        var context = wrap.getAttribute('data-context');
        var team = wrap.getAttribute('data-team');
        var include = wrap.getAttribute('data-include');
        var byNo = context === 'akun';   // konteks akun menyimpan nomor anggota, lainnya id
        var id = 'mp' + (++uid);

        // Anggota terpilih terakhir (null = belum ada). Dibaca dari HTML server saat halaman dimuat.
        var committed = hidden.value !== '' ? { value: hidden.value, label: input.value } : null;
        var dirty = false;   // teks sedang diubah dan belum dipilih dari daftar

        var panel = null, list = null, foot = null, status = null;
        var items = [], active = -1, query = '', offset = 0, more = false;
        var loading = false, searching = false, failed = false, loaded = false, seq = 0, aborter = null, timer = null, blurTimer = null;

        var api = { wrap: wrap, close: close, settle: settle, position: position };
        wrap._mp = api;

        function sync() {
            wrap.classList.toggle('has-value', hidden.value !== '');
            if (clearBtn) { clearBtn.hidden = input.value === '' || input.disabled; }
        }

        function fire() {
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            wrap.dispatchEvent(new CustomEvent('mp:change', { bubbles: true, detail: { value: hidden.value, label: committed ? committed.label : '' } }));
        }

        // ---- Posisi: tetap (fixed) di bawah kolom; naik ke atas bila bawah sempit; selalu di dalam layar ----
        function position() {
            if (!panel) { return; }
            var vv = window.visualViewport;
            var vw = vv ? vv.width : window.innerWidth;
            var top0 = vv ? vv.offsetTop : 0;
            var left0 = vv ? vv.offsetLeft : 0;
            var vh = vv ? vv.height : window.innerHeight;
            var rect = input.getBoundingClientRect();
            var width = Math.min(Math.max(rect.width, 280), 440, vw - 16);
            var left = Math.min(Math.max(left0 + 8, rect.left), left0 + vw - width - 8);
            var below = top0 + vh - rect.bottom - 10;
            var above = rect.top - top0 - 10;
            var up = below < 230 && above > below;
            // tinggi daftar = ruang yang tersedia dikurangi bingkai dan baris keterangan (diukur, karena bisa dua baris)
            var chrome = 22 + (foot.hidden ? 0 : foot.offsetHeight + 4);
            var room = Math.max(96, (up ? above : below) - chrome);
            list.style.maxHeight = Math.min(300, room) + 'px';
            panel.style.width = width + 'px';
            panel.style.left = left + 'px';
            if (up) {
                panel.style.top = 'auto';
                panel.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
            } else {
                panel.style.bottom = 'auto';
                panel.style.top = (rect.bottom + 6) + 'px';
            }
        }

        // ---- Muat hasil dari server ----
        function url(q, from) {
            var p = new URLSearchParams({ konteks: context, q: q, offset: String(from), limit: String(PAGE_SIZE) });
            if (team && team !== '0') { p.set('regu', team); }
            if (include && include !== '0') { p.set('termasuk', include); }
            return endpoint + '?' + p.toString();
        }

        function abort() {
            seq++;
            if (aborter) { aborter.abort(); aborter = null; }
            loading = false;
        }

        function load(reset) {
            if (!panel) { return; }
            if (reset) {
                abort();
                items = []; offset = 0; more = false; active = -1; loaded = false; failed = false;
                list.textContent = '';
            } else if (loading || !more) {
                return;
            }
            var mine = ++seq;
            loading = true;
            searching = false;
            failed = false;
            renderFoot();
            position();
            var ctrl = window.AbortController ? new AbortController() : null;
            aborter = ctrl;
            window.AdemAyem.request(url(query, offset), ctrl ? { signal: ctrl.signal } : {})
                .then(function (data) {
                    if (mine !== seq || !panel) { return; }
                    loading = false; loaded = true;
                    var start = items.length;
                    items = items.concat(data.items || []);
                    offset = items.length;
                    more = !!data.more;
                    renderItems(start);
                    if (reset) { highlightFirst(); }
                    renderFoot();
                    position();
                })
                .catch(function (err) {
                    if (mine !== seq || (err && err.name === 'AbortError')) { return; }
                    loading = false; failed = true;
                    renderFoot();
                    position();
                });
        }

        function tokens() {
            return query.toLowerCase().split(/\s+/).filter(Boolean);
        }

        function renderItems(start) {
            var toks = tokens();
            for (var i = start; i < items.length; i++) {
                var m = items[i];
                var li = el('li', 'mp__opt', { role: 'option', id: id + '-o' + i, 'data-index': String(i) });
                var name = el('span', 'mp__name');
                marked(name, m.name, toks);
                var meta = el('span', 'mp__meta');
                var no = el('span', 'mp__no');
                marked(no, m.member_no, toks);
                meta.appendChild(no);
                var rest = [m.team, m.block].filter(Boolean).join(' · ');
                if (rest) { meta.appendChild(document.createTextNode(' · ' + rest)); }
                li.appendChild(name);
                if (m.status !== 'AKTIF') { var tag = el('span', 'mp__tag'); tag.textContent = 'Nonaktif'; li.appendChild(tag); }
                li.appendChild(meta);
                var isSel = committed !== null && committed.value === (byNo ? m.member_no : String(m.id));
                li.setAttribute('aria-selected', isSel ? 'true' : 'false');
                if (isSel) { li.classList.add('is-selected'); }
                list.appendChild(li);
            }
        }

        function renderFoot() {
            if (!foot) { return; }
            foot.textContent = '';
            foot.classList.remove('is-error');
            var text = '';
            if (failed) {
                foot.classList.add('is-error');
                var span = el('span');
                span.textContent = 'Gagal memuat daftar anggota. ';
                var retry = el('button', 'mp__retry', { type: 'button' });
                retry.textContent = 'Coba lagi';
                foot.appendChild(span);
                foot.appendChild(retry);
                text = 'Gagal memuat daftar anggota.';
            } else if (loading || searching) {
                text = items.length ? 'Memuat lagi…' : 'Memuat anggota…';
                foot.textContent = text;
                foot.classList.add('is-loading');
            } else if (loaded && items.length === 0) {
                text = query ? 'Tidak ada anggota yang cocok dengan “' + query + '”.' : 'Belum ada anggota yang bisa dipilih.';
                foot.textContent = text;
            } else if (more) {
                text = 'Gulir ke bawah untuk memuat lebih banyak.';
                foot.textContent = text;
            } else if (items.length) {
                text = query ? items.length + ' anggota ditemukan.' : 'Ketik nama atau nomor untuk mencari.';
                foot.textContent = text;
            }
            if (!loading && !searching) { foot.classList.remove('is-loading'); }
            foot.hidden = text === '';
            if (status) { status.textContent = loading || searching ? '' : text; }
        }

        function setActive(i, scroll) {
            var prev = list ? list.querySelector('.is-active') : null;
            if (prev) { prev.classList.remove('is-active'); }
            active = i;
            var li = i >= 0 && list ? list.children[i] : null;
            if (li) {
                li.classList.add('is-active');
                input.setAttribute('aria-activedescendant', li.id);
                if (scroll !== false) { li.scrollIntoView({ block: 'nearest' }); }
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function highlightFirst() {
            // Mengetik: sorot hasil pertama (Enter langsung memilih). Tanpa kata kunci: sorot pilihan saat ini bila terlihat.
            if (query !== '') { setActive(items.length ? 0 : -1, false); return; }
            var idx = -1;
            if (committed) {
                items.forEach(function (m, i) { if (idx === -1 && committed.value === (byNo ? m.member_no : String(m.id))) { idx = i; } });
            }
            setActive(idx, true);
        }

        function move(step) {
            if (!items.length) { return; }
            var next = active === -1 ? (step > 0 ? 0 : items.length - 1) : (active + step + items.length) % items.length;
            setActive(next);
            if (more && next >= items.length - 3) { load(false); }
        }

        // ---- Buka / tutup ----
        function open() {
            if (panel || input.disabled || input.readOnly) { return; }
            if (current && current !== api) { current.close(true); }
            current = api;
            panel = el('div', 'mp__panel');
            list = el('ul', 'mp__list', { id: id + '-list', role: 'listbox', 'aria-label': 'Daftar anggota' });
            foot = el('div', 'mp__foot');
            status = el('div', 'sr-only', { role: 'status', 'aria-live': 'polite' });
            panel.appendChild(list);
            panel.appendChild(foot);
            panel.appendChild(status);
            document.body.appendChild(panel);
            input.setAttribute('aria-controls', id + '-list');
            input.setAttribute('aria-expanded', 'true');
            wrap.classList.add('is-open');
            query = dirty ? input.value.trim() : '';
            position();
            load(true);
            if (!dirty && input.value !== '') { input.select(); }

            // Peristiwa daftar
            panel.addEventListener('mousedown', function (event) { event.preventDefault(); });   // fokus tetap di kolom
            list.addEventListener('click', function (event) {
                var li = event.target instanceof Element ? event.target.closest('.mp__opt') : null;
                if (li) { choose(items[Number(li.getAttribute('data-index'))]); }
            });
            list.addEventListener('mousemove', function (event) {
                var li = event.target instanceof Element ? event.target.closest('.mp__opt') : null;
                if (li) { var i = Number(li.getAttribute('data-index')); if (i !== active) { setActive(i, false); } }
            });
            list.addEventListener('scroll', function () {
                if (more && list.scrollTop + list.clientHeight >= list.scrollHeight - 48) { load(false); }
            });
            foot.addEventListener('click', function (event) {
                if (event.target instanceof Element && event.target.closest('.mp__retry')) { load(items.length === 0); }
            });
        }

        function close(revert) {
            window.clearTimeout(timer);
            searching = false;
            if (panel) {
                abort();
                panel.remove();
                panel = list = foot = status = null;
                items = []; active = -1;
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-controls');
                input.removeAttribute('aria-activedescendant');
                wrap.classList.remove('is-open');
                if (current === api) { current = null; }
            }
            if (revert && dirty) { restore(); }
        }

        // Batalkan perubahan teks yang belum dipilih: kembali ke anggota terpilih sebelumnya (atau kosong).
        function restore() {
            dirty = false;
            var had = hidden.value;
            hidden.value = committed ? committed.value : '';
            input.value = committed ? committed.label : '';
            sync();
            if (had !== hidden.value) { fire(); }
        }

        // Sebelum formulir dikirim: teks yang diketik tapi tidak dipilih tidak boleh ikut.
        function settle() {
            window.clearTimeout(blurTimer);
            close(true);
            if (dirty) { restore(); }
        }

        function choose(m) {
            if (!m) { return; }
            var value = byNo ? m.member_no : String(m.id);
            var changed = hidden.value !== value;
            committed = { value: value, label: m.label };
            hidden.value = value;
            input.value = m.label;
            dirty = false;
            input.removeAttribute('aria-invalid');
            sync();
            close(false);
            if (changed) { fire(); }
        }

        function clear() {
            var had = hidden.value;
            committed = null;
            dirty = false;
            hidden.value = '';
            input.value = '';
            sync();
            if (had !== '') { fire(); }
        }

        // ---- Peristiwa kolom ----
        input.addEventListener('focus', function () { window.clearTimeout(blurTimer); open(); });
        input.addEventListener('click', function () { open(); });

        input.addEventListener('input', function () {
            // Teks diubah: nilai lama tidak berlaku lagi, supaya yang tampil dan yang dikirim tidak pernah berbeda.
            dirty = true;
            if (hidden.value !== '') { hidden.value = ''; fire(); }
            sync();
            if (!panel) { open(); }
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                if (!panel) { return; }
                query = input.value.trim();
                load(true);
            }, DEBOUNCE_MS);
            searching = true;   // tanda "memuat" langsung terlihat selama menunggu jeda ketikan
            renderFoot();
        });

        input.addEventListener('keydown', function (event) {
            if (event.isComposing) { return; }
            var key = event.key;
            if (key === 'ArrowDown') { event.preventDefault(); if (!panel) { open(); } else { move(1); } }
            else if (key === 'ArrowUp') { event.preventDefault(); if (!panel) { open(); } else { move(-1); } }
            else if (key === 'PageDown' && panel) { event.preventDefault(); move(5); }
            else if (key === 'PageUp' && panel) { event.preventDefault(); move(-5); }
            else if (key === 'Enter' && panel) {
                event.preventDefault();   // Enter saat daftar terbuka memilih, bukan mengirim formulir
                if (active >= 0 && items[active]) { choose(items[active]); }
            }
            else if (key === 'Escape' && panel) { event.preventDefault(); event.stopPropagation(); close(true); }
            else if (key === 'Tab') { close(true); }
        });

        input.addEventListener('blur', function () {
            window.clearTimeout(blurTimer);
            blurTimer = window.setTimeout(function () {
                if (document.activeElement !== input) { close(true); }
            }, 120);
        });

        input.addEventListener('invalid', function () { input.setAttribute('aria-invalid', 'true'); });

        if (clearBtn) {
            clearBtn.addEventListener('mousedown', function (event) { event.preventDefault(); });
            clearBtn.addEventListener('click', function () {
                close(false);
                clear();
                input.focus();
                open();
            });
        }

        // Pengaman: apa pun yang membuat formulir terkirim, teks yang belum dipilih dibuang dulu.
        var form = input.form;
        if (form && !form.hasAttribute('data-mp-guard')) {
            form.setAttribute('data-mp-guard', '');
            form.addEventListener('submit', function () {
                form.querySelectorAll('[data-member-picker]').forEach(function (w) { if (w._mp) { w._mp.settle(); } });
            }, true);
        }

        sync();
    }

    function initAll(root) {
        (root || document).querySelectorAll('[data-member-picker]').forEach(init);
    }

    // Klik/ketuk di luar menutup daftar.
    function outside(event) {
        if (!current || !(event.target instanceof Element)) { return; }
        var panel = document.querySelector('.mp__panel');
        if (current.wrap.contains(event.target) || (panel && panel.contains(event.target))) { return; }
        current.close(true);
    }
    document.addEventListener('mousedown', outside, true);
    document.addEventListener('touchstart', outside, { capture: true, passive: true });

    // Daftar mengikuti kolom saat halaman digulir, layar diputar, atau papan ketik muncul.
    function reposition() { if (current) { current.position(); } }
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', reposition);
        window.visualViewport.addEventListener('scroll', reposition);
    }

    // Isi halaman diganti oleh pembaruan langsung: buang daftar yatim, pasang ulang kotak yang baru.
    document.addEventListener('adem:live-updated', function () {
        document.querySelectorAll('.mp__panel').forEach(function (p) { p.remove(); });
        current = null;
        initAll(document);
    });

    initAll(document);
})();
