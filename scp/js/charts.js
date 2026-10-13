/*
 * OstCharts — small dependency-free SVG charts for the dashboard and
 * reports. Neutral palette built from the brand accent plus greys.
 *
 *   OstCharts.render(el, { type: 'line'|'area'|'bar'|'stacked'|'donut',
 *                          labels: [...], series: [{name, values}] })
 */
(function (window, document) {
    'use strict';

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name)
            || getComputedStyle(document.body).getPropertyValue(name);
        v = (v || '').trim();
        return v || fallback;
    }

    function palette(n) {
        var accent = cssVar('--ost-accent', '#1f4e79');
        var base = [accent, '#64748b', '#94a3b8', '#1f2937', '#cbd5e1', '#475569', '#9ca3af', '#0f172a'];
        var out = [];
        for (var i = 0; i < n; i++) out.push(base[i % base.length]);
        return out;
    }

    function dash(i) {
        return i >= 4 ? (i % 2 ? '6 4' : '2 3') : '';
    }

    function el(tag, attrs, parent) {
        var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (var k in attrs) if (attrs.hasOwnProperty(k)) e.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(e);
        return e;
    }

    function fmt(v, unit) {
        if (v === null || v === undefined) return '–';
        if (unit === 'percent') return v + '%';
        if (unit === 'hours') return v + 'h';
        return String(v);
    }

    function niceMax(max) {
        if (max <= 0) return 1;
        var p = Math.pow(10, Math.floor(Math.log10(max)));
        var m = max / p;
        var n = m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10;
        return n * p;
    }

    function shortLabel(label) {
        if (/^\d{4}-\d{2}-\d{2}$/.test(label)) return label.slice(5);
        if (/^\d{4}-\d{2}$/.test(label)) return label;
        return label.length > 12 ? label.slice(0, 11) + '…' : label;
    }

    function tooltip(container) {
        var t = container.querySelector('.chart-tooltip');
        if (!t) {
            t = document.createElement('div');
            t.className = 'chart-tooltip';
            container.appendChild(t);
        }
        return t;
    }

    function legend(container, series, colors, onToggle) {
        var old = container.querySelector('.chart-legend');
        if (old) old.remove();
        if (series.length < 2) return;
        var lg = document.createElement('div');
        lg.className = 'chart-legend';
        series.forEach(function (s, i) {
            var item = document.createElement('span');
            item.className = 'legend-item';
            item.innerHTML = '<span class="swatch" style="background:' + colors[i] + '"></span>' + s.name;
            item.addEventListener('click', function () {
                item.classList.toggle('off');
                onToggle(i, item.classList.contains('off'));
            });
            lg.appendChild(item);
        });
        container.appendChild(lg);
    }

    function cartesian(container, opts) {
        var labels = opts.labels || [], series = (opts.series || []).filter(function (s) { return s && s.values; });
        var hidden = {};
        var width = Math.max(320, container.clientWidth || 600), height = opts.height || 260;
        var pad = { l: 44, r: 12, t: 12, b: 34 };
        var colors = palette(series.length);
        var unit = opts.unit || 'count';
        var type = opts.type || 'line';

        function draw() {
            var old = container.querySelector('svg');
            if (old) old.remove();
            var svg = el('svg', { viewBox: '0 0 ' + width + ' ' + height, preserveAspectRatio: 'none' });
            svg.style.height = height + 'px';
            container.insertBefore(svg, container.firstChild);

            var visible = series.filter(function (s, i) { return !hidden[i]; });
            var n = labels.length, max = 0;
            if (type === 'stacked') {
                for (var k = 0; k < n; k++) {
                    var sum = 0;
                    visible.forEach(function (s) { sum += (s.values[k] || 0); });
                    max = Math.max(max, sum);
                }
            } else {
                visible.forEach(function (s) { s.values.forEach(function (v) { if (v !== null && v > max) max = v; }); });
            }
            max = niceMax(max);
            if (unit === 'percent') max = 100;
            var innerW = width - pad.l - pad.r, innerH = height - pad.t - pad.b;
            var x = function (i) { return n <= 1 ? pad.l + innerW / 2 : pad.l + (i / (n - 1)) * innerW; };
            var xb = function (i) { return pad.l + (i / Math.max(1, n)) * innerW; };
            var y = function (v) { return pad.t + innerH - (v / max) * innerH; };

            // Grid and axes
            var grid = el('g', { 'class': 'grid' }, svg);
            var ticks = 4;
            for (var t = 0; t <= ticks; t++) {
                var v = (max / ticks) * t;
                el('line', { x1: pad.l, x2: width - pad.r, y1: y(v), y2: y(v) }, grid);
                var tx = el('text', { x: pad.l - 6, y: y(v) + 4, 'text-anchor': 'end' }, svg);
                tx.textContent = fmt(Math.round(v * 10) / 10, unit);
            }
            var step = Math.max(1, Math.ceil(n / Math.floor(innerW / 64)));
            for (var i = 0; i < n; i += step) {
                var lx = (type === 'bar' || type === 'stacked') ? xb(i) + innerW / Math.max(1, n) / 2 : x(i);
                var lt = el('text', { x: lx, y: height - 10, 'text-anchor': 'middle' }, svg);
                lt.textContent = shortLabel(String(labels[i]));
            }

            var tip = tooltip(container);
            function showTip(evt, text) {
                tip.textContent = text;
                tip.style.display = 'block';
                var r = container.getBoundingClientRect();
                tip.style.left = (evt.clientX - r.left) + 'px';
                tip.style.top = (evt.clientY - r.top) + 'px';
            }
            function hideTip() { tip.style.display = 'none'; }

            if (type === 'bar' || type === 'stacked') {
                var slot = innerW / Math.max(1, n);
                var gap = Math.min(10, slot * 0.2);
                var bw = type === 'stacked' ? slot - gap : (slot - gap) / Math.max(1, visible.length);
                var stacks = [];
                for (var c = 0; c < n; c++) stacks[c] = 0;
                visible.forEach(function (s, si) {
                    var color = colors[series.indexOf(s)];
                    s.values.forEach(function (v, i) {
                        if (v === null) return;
                        var h = (v / max) * innerH;
                        var bx = type === 'stacked' ? xb(i) + gap / 2 : xb(i) + gap / 2 + si * bw;
                        var by = type === 'stacked' ? y(stacks[i] + v) : y(v);
                        if (type === 'stacked') stacks[i] += v;
                        var rect = el('rect', { x: bx, y: by, width: Math.max(1, bw - 1), height: Math.max(0, h), fill: color, rx: 2, 'class': 'series-bar' }, svg);
                        rect.addEventListener('mousemove', function (evt) { showTip(evt, labels[i] + ' · ' + s.name + ': ' + fmt(v, unit)); });
                        rect.addEventListener('mouseleave', hideTip);
                    });
                });
            } else {
                visible.forEach(function (s) {
                    var color = colors[series.indexOf(s)];
                    var d = '', started = false;
                    s.values.forEach(function (v, i) {
                        if (v === null) { started = false; return; }
                        d += (started ? ' L' : ' M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1);
                        started = true;
                    });
                    if (type === 'area' && d) {
                        var first = s.values.findIndex(function (v) { return v !== null; });
                        var last = s.values.length - 1;
                        while (last >= 0 && s.values[last] === null) last--;
                        if (first >= 0 && last >= first) {
                            var ad = d + ' L' + x(last).toFixed(1) + ' ' + y(0).toFixed(1) + ' L' + x(first).toFixed(1) + ' ' + y(0).toFixed(1) + ' Z';
                            el('path', { d: ad, fill: color, 'class': 'series-area' }, svg);
                        }
                    }
                    el('path', { d: d, stroke: color, 'class': 'series-line', 'stroke-dasharray': dash(series.indexOf(s)) }, svg);
                    s.values.forEach(function (v, i) {
                        if (v === null) return;
                        var dot = el('circle', { cx: x(i), cy: y(v), r: n > 60 ? 2 : 3.5, fill: color, 'class': 'dot' }, svg);
                        dot.addEventListener('mousemove', function (evt) { showTip(evt, labels[i] + ' · ' + s.name + ': ' + fmt(v, unit)); });
                        dot.addEventListener('mouseleave', hideTip);
                    });
                });
            }
            if (!visible.length || !n) {
                var empty = el('text', { x: width / 2, y: height / 2, 'text-anchor': 'middle' }, svg);
                empty.textContent = opts.emptyLabel || 'No data for this range';
            }
        }

        draw();
        legend(container, series, colors, function (i, off) { hidden[i] = off; draw(); });
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { width = Math.max(320, container.clientWidth || 600); draw(); }, 150);
        });
    }

    function donut(container, opts) {
        var items = (opts.items || opts.series || []).map(function (it) {
            return { name: it.name || it.label, value: it.values ? it.values.reduce(function (a, b) { return a + (b || 0); }, 0) : (it.value || 0) };
        }).filter(function (it) { return it.value > 0; });
        var old = container.querySelector('svg');
        if (old) old.remove();
        var size = opts.size || 200, r = size / 2, ir = r * 0.62, total = items.reduce(function (a, b) { return a + b.value; }, 0);
        var svg = el('svg', { viewBox: '0 0 ' + size + ' ' + size, width: size, height: size });
        svg.style.width = size + 'px'; svg.style.margin = '0 auto';
        container.insertBefore(svg, container.firstChild);
        var colors = palette(items.length), angle = -Math.PI / 2, tip = tooltip(container);
        if (!total) {
            var e = el('text', { x: r, y: r, 'text-anchor': 'middle' }, svg);
            e.textContent = opts.emptyLabel || 'No data';
            return;
        }
        items.forEach(function (it, i) {
            var a = (it.value / total) * Math.PI * 2, a2 = angle + a;
            var large = a > Math.PI ? 1 : 0;
            var p1 = [r + r * Math.cos(angle), r + r * Math.sin(angle)], p2 = [r + r * Math.cos(a2), r + r * Math.sin(a2)];
            var q1 = [r + ir * Math.cos(a2), r + ir * Math.sin(a2)], q2 = [r + ir * Math.cos(angle), r + ir * Math.sin(angle)];
            var d = 'M' + p1.join(' ') + ' A' + r + ' ' + r + ' 0 ' + large + ' 1 ' + p2.join(' ') + ' L' + q1.join(' ') + ' A' + ir + ' ' + ir + ' 0 ' + large + ' 0 ' + q2.join(' ') + ' Z';
            var path = el('path', { d: d, fill: colors[i], 'class': 'series-bar' }, svg);
            path.addEventListener('mousemove', function (evt) {
                tip.textContent = it.name + ': ' + it.value + ' (' + Math.round(it.value / total * 100) + '%)';
                tip.style.display = 'block';
                var rc = container.getBoundingClientRect();
                tip.style.left = (evt.clientX - rc.left) + 'px'; tip.style.top = (evt.clientY - rc.top) + 'px';
            });
            path.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
            angle = a2;
        });
        var c = el('text', { x: r, y: r + 6, 'text-anchor': 'middle', 'font-size': '20', 'font-weight': '700' }, svg);
        c.textContent = total;
        c.style.fill = cssVar('--ost-text', '#111');
        legend(container, items, colors, function () {});
    }

    var OstCharts = {
        render: function (target, opts) {
            var container = typeof target === 'string' ? document.querySelector(target) : target;
            if (!container) return;
            container.classList.add('chart');
            if ((opts.type || 'line') === 'donut') donut(container, opts);
            else cartesian(container, opts);
        },
        palette: palette
    };
    window.OstCharts = OstCharts;
})(window, document);
