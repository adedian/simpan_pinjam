/* Grafik dashboard (Chart.js dari public/assets/vendor, tanpa CDN).
   Server menaruh spesifikasi di canvas[data-chart]; warna dibaca dari token CSS (--series-N) supaya satu sumber.
   Tidak ada skrip atau gaya inline (CSP). Nama kategori diperlakukan sebagai teks, bukan HTML. */
(function () {
    'use strict';
    if (!window.Chart) { return; }

    var css = window.getComputedStyle(document.documentElement);
    var token = function (name) { return css.getPropertyValue(name).trim(); };
    var nf = new Intl.NumberFormat('id-ID');
    var rupiah = function (v) { return (v < 0 ? '-' : '') + 'Rp ' + nf.format(Math.abs(Math.round(v))); };
    var compact = function (v) {
        var a = Math.abs(v), sign = v < 0 ? '-' : '';
        if (a >= 1e9) { return sign + (a / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M'; }
        if (a >= 1e6) { return sign + (a / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'; }
        if (a >= 1e3) { return sign + (a / 1e3).toLocaleString('id-ID', { maximumFractionDigits: 0 }) + ' rb'; }
        return sign + String(a);
    };

    var instances = [];

    var build = function (canvas) {
        var spec;
        try { spec = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
        var ink = token('--ink-2'), grid = token('--line'), surface = token('--surface');
        var color = function (slot) { return token('--series-' + slot); };
        var doughnut = spec.type === 'doughnut';
        var line = spec.type === 'line';

        var datasets = spec.series.map(function (s) {
            var base = { label: s.label, data: s.data };
            if (doughnut) {
                base.backgroundColor = spec.labels.map(function (_, i) { return color(i + 1); });
                base.borderColor = surface;
                base.borderWidth = 2;
            } else if (line) {
                base.borderColor = color(s.slot);
                base.backgroundColor = color(s.slot);
                base.borderWidth = 2;
                base.pointRadius = 3;
                base.pointHoverRadius = 5;
                base.pointBackgroundColor = surface;
                base.pointBorderColor = color(s.slot);
                base.pointBorderWidth = 2;
                base.tension = 0;
            } else {
                base.backgroundColor = color(s.slot);
                base.borderRadius = 4;
                base.borderSkipped = spec.horizontal ? 'start' : 'bottom';
                base.maxBarThickness = 28;
            }
            return base;
        });

        var tooltip = {
            backgroundColor: token('--ink'),
            titleColor: '#fff',
            bodyColor: '#fff',
            padding: 10,
            cornerRadius: 8,
            displayColors: !doughnut,
            callbacks: {
                label: function (ctx) {
                    var v = doughnut ? ctx.raw : (spec.horizontal ? ctx.parsed.x : ctx.parsed.y);
                    return (doughnut ? ctx.label : ctx.dataset.label) + ': ' + rupiah(v);
                }
            }
        };

        var options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { display: false }, tooltip: tooltip },
            font: { family: token('--font') }
        };
        if (doughnut) {
            options.cutout = '62%';
        } else {
            var valueAxis = { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: ink, callback: function (v) { return compact(v); } } };
            var catAxis = { grid: { display: false }, border: { color: grid }, ticks: { color: ink, autoSkip: false, maxRotation: 0 } };
            options.interaction = { mode: 'index', intersect: false, axis: spec.horizontal ? 'y' : 'x' };
            if (spec.horizontal) {
                options.indexAxis = 'y';
                options.scales = { x: valueAxis, y: catAxis };
            } else {
                options.scales = { x: catAxis, y: valueAxis };
            }
        }
        instances.push(new window.Chart(canvas, { type: spec.type, data: { labels: spec.labels, datasets: datasets }, options: options }));
    };

    var init = function () {
        // Grafik yang kanvasnya sudah diganti (pembaruan langsung) dilepas dulu.
        instances = instances.filter(function (c) {
            if (c.canvas && c.canvas.isConnected) { return true; }
            c.destroy();
            return false;
        });
        document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
            if (!window.Chart.getChart(canvas)) { build(canvas); }
        });
    };

    init();
    document.addEventListener('adem:live-updated', init);
})();
