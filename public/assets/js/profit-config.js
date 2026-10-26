/* Komponen pembagian bunga: tambah/hapus/urut baris, total persentase otomatis, dan pratinjau nominal.
   Server memeriksa ulang semuanya; skrip ini hanya memudahkan. Tanpa JS, baris dana kosong tetap bisa diisi. */
(function () {
    'use strict';
    var form = document.querySelector('[data-comp-form]');
    if (!form) { return; }

    var rowsBox = form.querySelector('[data-comp-rows]');
    var tpl = form.querySelector('[data-comp-template]');
    var addBtn = form.querySelector('[data-add]');
    var addHint = form.querySelector('[data-add-hint]');
    var allocBox = form.querySelector('[data-alloc]');
    var fill = form.querySelector('[data-alloc-fill]');
    var textEl = form.querySelector('[data-alloc-text]');
    var baseInput = form.querySelector('[data-base]');
    var eff = form.querySelector('[data-eff]');
    var past = form.querySelector('[data-past]');
    var saveBtn = form.querySelector('[data-save]');

    function fmtRp(n) { return 'Rp ' + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function fmtPct(h) { return (h / 100).toString().replace('.', ',') + '%'; }
    function parsePct(v) {
        v = String(v).replace(/[\s%]/g, '').replace(',', '.');
        if (!/^\d{1,3}(\.\d{1,2})?$/.test(v)) { return null; }
        return Math.round(parseFloat(v) * 100);
    }
    function rows() { return Array.prototype.slice.call(rowsBox.querySelectorAll('[data-row]')); }

    /* Teks status total, disusun dengan node DOM (tanpa innerHTML): bagian bercetak tebal diberi sebagai {b: '...'}. */
    function say(parts) {
        while (textEl.firstChild) { textEl.removeChild(textEl.firstChild); }
        parts.forEach(function (p) {
            if (typeof p === 'string') { textEl.appendChild(document.createTextNode(p)); return; }
            var s = document.createElement('strong');
            s.textContent = p.b;
            textEl.appendChild(s);
        });
    }

    function reindex() {
        rows().forEach(function (row, i) {
            row.querySelectorAll('[name^="items["]').forEach(function (el) {
                el.name = el.name.replace(/^items\[[^\]]*\]/, 'items[' + i + ']');
            });
        });
    }

    function recalc() {
        var total = 0, invalid = false, base = parseInt(String(baseInput.value).replace(/\D/g, ''), 10) || 0;
        var used = 0, shuRow = null;
        rows().forEach(function (row) {
            var input = row.querySelector('[data-pct]');
            var h = parsePct(input.value);
            var name = row.querySelector('input[type=text]').value.trim();
            var blankDana = row.getAttribute('data-kind') === 'DANA' && name === '' && input.value.trim() === '';
            var out = row.querySelector('[data-preview]');
            if (blankDana) { out.textContent = '-'; return; }
            if (h === null) { invalid = true; out.textContent = '-'; return; }
            total += h;
            if (row.getAttribute('data-kind') === 'SHU') { shuRow = row; return; }
            var amount = Math.floor((2 * base * h + 10000) / 20000);
            used += amount;
            out.textContent = fmtRp(amount);
        });
        if (shuRow) { shuRow.querySelector('[data-preview]').textContent = fmtRp(base - used); }

        fill.style.width = Math.min(100, total / 100) + '%';
        allocBox.classList.remove('alloc--ok', 'alloc--warn', 'alloc--bad');
        var ok = !invalid && total === 10000;
        if (invalid) {
            say(['Ada persentase yang belum valid. Isi angka, maksimal 2 desimal.']);
            allocBox.classList.add('alloc--bad');
        } else if (total === 10000) {
            say(['Total ', { b: '100%' }, ': alokasi lengkap.']);
            allocBox.classList.add('alloc--ok');
        } else if (total < 10000) {
            say(['Total ', { b: fmtPct(total) }, ': masih tersisa ', { b: fmtPct(10000 - total) }, '.']);
            allocBox.classList.add('alloc--warn');
        } else {
            say(['Total ', { b: fmtPct(total) }, ': melebihi batas ', { b: fmtPct(total - 10000) }, '.']);
            allocBox.classList.add('alloc--bad');
        }
        saveBtn.disabled = !ok;
        saveBtn.title = ok ? '' : 'Total persentase harus tepat 100%';
    }

    function checkPast() {
        if (!eff || !past) { return; }
        past.hidden = !(eff.value && eff.value < eff.getAttribute('data-current-month'));
    }

    function addRow() {
        rowsBox.appendChild(tpl.content.cloneNode(true));
        reindex();
        recalc();
        var added = rows().pop();
        if (added) { added.querySelector('input[type=text]').focus(); }
    }

    // Dengan JS aktif, baris dana kosong cadangan dibuang dari tampilan dan diganti tombol "Tambah".
    rows().forEach(function (row) {
        var name = row.querySelector('input[type=text]').value.trim();
        if (row.getAttribute('data-kind') === 'DANA' && name === '' && row.querySelector('[data-pct]').value.trim() === '' && !row.querySelector('.field__error')) {
            row.parentNode.removeChild(row);
        }
    });
    if (addBtn) { addBtn.hidden = false; }
    if (addHint) { addHint.hidden = true; }
    reindex();

    form.addEventListener('click', function (event) {
        var t = event.target;
        if (!(t instanceof Element)) { return; }
        var row = t.closest('[data-row]');
        if (t.closest('[data-add]')) { event.preventDefault(); addRow(); return; }
        if (!row) { return; }
        if (t.closest('[data-remove]')) {
            event.preventDefault();
            row.parentNode.removeChild(row);
            reindex(); recalc();
        } else if (t.closest('[data-up]')) {
            event.preventDefault();
            var prev = row.previousElementSibling;
            if (prev && prev.getAttribute('data-kind') === 'DANA') { rowsBox.insertBefore(row, prev); reindex(); }
        } else if (t.closest('[data-down]')) {
            event.preventDefault();
            var next = row.nextElementSibling;
            if (next) { rowsBox.insertBefore(next, row); reindex(); }
        }
    });
    form.addEventListener('input', function () { recalc(); checkPast(); });
    if (eff) { eff.addEventListener('change', checkPast); }

    recalc();
    checkPast();
})();
