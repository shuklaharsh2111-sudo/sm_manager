(function () {
    var dataEl = document.getElementById('anData');
    if (!dataEl) return;
    var D;
    try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var NS = 'http://www.w3.org/2000/svg';
    var COLORS = {};
    D.statuses.forEach(function (s) { COLORS[s.key] = s.color; });
    var ORDER = ['approved', 'pending', 'revision', 'rejected'];

    function $(id) { return document.getElementById(id); }
    function svg(tag, attrs, parent) {
        var n = document.createElementNS(NS, tag);
        for (var k in attrs) n.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(n);
        return n;
    }
    function tip(node, text) {
        var t = document.createElementNS(NS, 'title');
        t.textContent = text;
        node.appendChild(t);
    }
    function html(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function legend(ul, items) {
        if (!ul) return;
        items.forEach(function (i) {
            var li = html('li');
            var sw = html('span', 'an-sw');
            sw.style.background = i.color;
            var name = html('span', 'an-lg-name', i.label);
            li.append(sw, name);
            if (i.value != null) li.appendChild(html('strong', null, i.value));
            ul.appendChild(li);
        });
    }
    function empty(container, text) {
        container.innerHTML = '';
        container.appendChild(html('p', 'an-empty', text));
    }

    /* ---------- Donut ---------- */
    function donut(container, segs, centerValue, centerLabel) {
        if (!container) return;
        var total = segs.reduce(function (a, s) { return a + s.value; }, 0);
        var s = svg('svg', { viewBox: '0 0 100 100', role: 'img' }, container);
        var R = 38, C = 2 * Math.PI * R;
        svg('circle', { cx: 50, cy: 50, r: R, fill: 'none', stroke: 'rgba(148,163,255,.12)', 'stroke-width': 12 }, s);
        var offset = 0;
        if (total > 0) {
            segs.forEach(function (g) {
                if (g.value <= 0) return;
                var len = g.value / total * C;
                var c = svg('circle', {
                    cx: 50, cy: 50, r: R, fill: 'none', stroke: g.color, 'stroke-width': 12,
                    'stroke-dasharray': len + ' ' + (C - len),
                    'stroke-dashoffset': -offset,
                    transform: 'rotate(-90 50 50)'
                }, s);
                tip(c, g.label + ': ' + g.value + ' (' + Math.round(g.value / total * 100) + '%)');
                offset += len;
            });
        }
        var v = svg('text', { x: 50, y: 50, 'text-anchor': 'middle', 'dominant-baseline': 'central', class: 'an-donut-num' }, s);
        v.textContent = centerValue;
        var l = svg('text', { x: 50, y: 63, 'text-anchor': 'middle', class: 'an-donut-sub' }, s);
        l.textContent = centerLabel;
    }

    var statusSegs = D.statuses.map(function (s) { return { label: s.label, color: s.color, value: s.count }; });
    donut($('anStatusDonut'), statusSegs, D.total, 'creatives');
    legend($('anStatusLegend'), D.statuses.map(function (s) {
        var pct = D.total > 0 ? Math.round(s.count / D.total * 100) : 0;
        return { label: s.label, color: s.color, value: s.count + ' · ' + pct + '%' };
    }));

    var m = D.meetings;
    donut($('anMeetDonut'), [
        { label: 'Done', color: '#3dd693', value: m.done },
        { label: 'Not done', color: '#ffc052', value: m.pending }
    ], m.done + m.pending, 'meetings');

    /* ---------- Monthly stacked columns ---------- */
    (function () {
        var box = $('anMonthlyChart');
        if (!box) return;
        var months = D.monthly;
        var max = Math.max.apply(null, months.map(function (x) { return x.total; }).concat([0]));
        if (max === 0) return empty(box, 'No creatives in this period.');
        var step = max <= 5 ? 1 : max <= 10 ? 2 : Math.ceil(max / 5);
        var top = Math.ceil(max / step) * step;
        var W = 720, H = 280, L = 36, R = 10, T = 12, B = 30;
        var iw = W - L - R, ih = H - T - B;
        var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Creatives per month' }, box);
        for (var v = 0; v <= top; v += step) {
            var y = T + ih - v / top * ih;
            svg('line', { x1: L, x2: W - R, y1: y, y2: y, class: 'an-grid-line' }, s);
            var t = svg('text', { x: L - 6, y: y + 3, 'text-anchor': 'end', class: 'an-axis' }, s);
            t.textContent = v;
        }
        var slot = iw / months.length, bw = Math.min(44, slot * 0.6);
        months.forEach(function (mo, i) {
            var x = L + slot * i + (slot - bw) / 2, yBase = T + ih;
            ORDER.forEach(function (k) {
                var c = mo.counts[k];
                if (!c) return;
                var h = c / top * ih;
                yBase -= h;
                var r = svg('rect', { x: x, y: yBase, width: bw, height: h, fill: COLORS[k], rx: 2 }, s);
                tip(r, mo.label + ' · ' + k.charAt(0).toUpperCase() + k.slice(1) + ': ' + c);
            });
            var lb = svg('text', { x: x + bw / 2, y: H - 10, 'text-anchor': 'middle', class: 'an-axis' }, s);
            lb.textContent = mo.label;
            if (mo.total) {
                var tt = svg('text', { x: x + bw / 2, y: T + ih - mo.total / top * ih - 4, 'text-anchor': 'middle', class: 'an-axis an-axis-strong' }, s);
                tt.textContent = mo.total;
            }
        });
        legend($('anMonthlyLegend'), D.statuses.map(function (s) { return { label: s.label, color: s.color }; }));
    })();

    /* ---------- Trend lines (% per month) ---------- */
    (function () {
        var box = $('anTrendChart');
        if (!box) return;
        var months = D.monthly;
        var withData = months.filter(function (x) { return x.total > 0; }).length;
        if (!withData) return empty(box, 'No data for a trend yet.');
        var W = 720, H = 260, L = 40, R = 14, T = 12, B = 30;
        var iw = W - L - R, ih = H - T - B;
        var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Approval, redo and rejection trend' }, box);
        [0, 25, 50, 75, 100].forEach(function (v) {
            var y = T + ih - v / 100 * ih;
            svg('line', { x1: L, x2: W - R, y1: y, y2: y, class: 'an-grid-line' }, s);
            var t = svg('text', { x: L - 6, y: y + 3, 'text-anchor': 'end', class: 'an-axis' }, s);
            t.textContent = v + '%';
        });
        var n = months.length;
        function xAt(i) { return n === 1 ? L + iw / 2 : L + iw * i / (n - 1); }
        months.forEach(function (mo, i) {
            var lb = svg('text', { x: xAt(i), y: H - 10, 'text-anchor': 'middle', class: 'an-axis' }, s);
            lb.textContent = mo.label;
        });
        [['approved', 'Approval'], ['revision', 'Redo'], ['rejected', 'Rejection']].forEach(function (ln) {
            var pts = [];
            months.forEach(function (mo, i) {
                if (!mo.total) return;
                pts.push({ x: xAt(i), y: T + ih - mo.counts[ln[0]] / mo.total * 100 / 100 * ih,
                           pct: Math.round(mo.counts[ln[0]] / mo.total * 100), label: mo.label });
            });
            if (pts.length > 1) {
                svg('polyline', {
                    points: pts.map(function (p) { return p.x + ',' + p.y; }).join(' '),
                    fill: 'none', stroke: COLORS[ln[0]], 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'
                }, s);
            }
            pts.forEach(function (p) {
                var c = svg('circle', { cx: p.x, cy: p.y, r: 4, fill: COLORS[ln[0]], stroke: '#11182c', 'stroke-width': 1.5 }, s);
                tip(c, p.label + ' · ' + ln[1] + ': ' + p.pct + '%');
            });
        });
    })();

    /* ---------- Single toggle: monthly bars <-> trend lines ---------- */
    (function () {
        var btn = $('anToggle'), title = $('anComboTitle');
        var monthly = $('anMonthlyView'), trend = $('anTrendView');
        if (!btn || !title || !monthly || !trend) return;
        var showTrend = false;
        btn.addEventListener('click', function () {
            showTrend = !showTrend;
            monthly.hidden = showTrend;
            trend.hidden = !showTrend;
            btn.setAttribute('aria-pressed', showTrend ? 'true' : 'false');
            btn.textContent = showTrend ? 'Show monthly counts' : 'Show trend %';
            title.innerHTML = showTrend
                ? 'Approval vs Redo Trend <small>% of each month\'s creatives</small>'
                : 'Creatives per Month <small>by status</small>';
        });
    })();

    /* ---------- Horizontal bars ---------- */
    function barRow(container, label, segments, valueText) {
        var row = html('div', 'an-bar-row');
        row.appendChild(html('span', 'an-bar-label', label));
        var track = html('div', 'an-bar-track');
        segments.forEach(function (sg) {
            if (sg.pct <= 0) return;
            var f = html('span', 'an-bar-fill');
            f.style.width = sg.pct + '%';
            f.style.background = sg.color;
            if (sg.tip) f.title = sg.tip;
            track.appendChild(f);
        });
        row.appendChild(track);
        row.appendChild(html('span', 'an-bar-value', valueText));
        container.appendChild(row);
    }

    (function () {
        var box = $('anPlatforms');
        if (!box) return;
        if (!D.platforms.length) return empty(box, 'No platform data.');
        var max = Math.max.apply(null, D.platforms.map(function (p) { return p.total; }));
        D.platforms.forEach(function (p) {
            var approvedPct = p.approved / max * 100, restPct = (p.total - p.approved) / max * 100;
            barRow(box, p.label, [
                { pct: approvedPct, color: COLORS.approved, tip: 'Approved: ' + p.approved },
                { pct: restPct, color: '#8b5cf6', tip: 'Other: ' + (p.total - p.approved) }
            ], p.total);
        });
        var lg = html('ul', 'an-legend an-legend-inline');
        legend(lg, [{ label: 'Approved', color: COLORS.approved }, { label: 'Other statuses', color: '#8b5cf6' }]);
        box.appendChild(lg);
    })();

    (function () {
        var box = $('anWeeks');
        if (!box) return;
        var max = Math.max.apply(null, D.weeks.concat([1]));
        ['This week', 'Next week', 'In 2 weeks', 'In 3 weeks'].forEach(function (l, i) {
            barRow(box, l, [{ pct: D.weeks[i] / max * 100, color: '#60a5fa' }], D.weeks[i]);
        });
    })();
})();
