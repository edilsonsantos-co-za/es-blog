/* Atlas generative covers: posts without an image get cover art drawn from their title, in shades of one blue, nudged by their category. */
(function () {
    if (window.atlasCoverArt) return;
    window.atlasCoverArt = true;

    // FNV-1a, so the same title always draws the same cover.
    function hash(s) {
        var h = 2166136261;
        for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
        return h >>> 0;
    }
    function random(seed) {
        return function () { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return seed / 4294967296; };
    }

    var STYLES = 6, HUES = 8;

    // Pattern and colour both come from the title; the category shifts the colour wheel.
    // On one page, patterns are not reused until all have been shown, and the same goes for colours.
    // Steps through the options by `stride` (coprime with `count`), so neighbours land far apart.
    function free(start, count, taken, stride) {
        for (var n = 0; n < count; n++) {
            if (!taken[(start + n * stride) % count]) return (start + n * stride) % count;
        }
        return start;
    }
    function pick(text, palette, used) {
        var seed = hash(text);
        var style = free((seed >>> 8) % STYLES, STYLES, used.styles, 1);
        var step = free(used.last === undefined ? (seed >>> 16) % HUES : (used.last + 3) % HUES, HUES, used.hues, 3);
        if (Object.keys(used.styles).length >= STYLES) { used.styles = {}; }
        if (Object.keys(used.hues).length >= HUES) { used.hues = {}; }
        used.styles[style] = used.hues[step] = true;
        used.last = step;
        // Shades of one blue only (the ES rule): eight steps across 200–224°, nudged by the category
        return { seed: seed, style: style, hue: 200 + step * 3 + hash(palette) % 4 };
    }

    function paint(canvas, look) {
        var box = canvas.parentNode.getBoundingClientRect();
        var w = Math.max(box.width, 1), h = Math.max(box.height, 1), d = window.devicePixelRatio || 1;
        canvas.width = w * d; canvas.height = h * d;
        var g = canvas.getContext('2d');
        g.setTransform(d, 0, 0, d, 0, 0);
        var seed = look.seed, r = random(seed), hue = look.hue, a = 'hsl(' + hue + ' 85% 62%)', b = 'hsl(' + (hue + 10) + ' 90% 78%)';
        g.fillStyle = 'hsl(' + hue + ' 45% 8%)';
        g.fillRect(0, 0, w, h);
        var grad = g.createLinearGradient(0, 0, w, h);
        grad.addColorStop(0, a); grad.addColorStop(1, b);
        var style = look.style, i, k, x, y;
        if (style === 0) {
            g.strokeStyle = grad; g.lineWidth = 1.2;
            for (i = 0; i < 48; i++) {
                g.globalAlpha = .25 + r() * .6; g.beginPath();
                x = r() * w; y = r() * h; g.moveTo(x, y);
                for (k = 0; k < 60; k++) {
                    var angle = Math.sin(x * .012 + hue) * 2 + Math.cos(y * .015) * 1.5;
                    x += Math.cos(angle) * 6; y += Math.sin(angle) * 6; g.lineTo(x, y);
                }
                g.stroke();
            }
        } else if (style === 1) {
            var cx = w * (.3 + r() * .4), cy = h * (.3 + r() * .4);
            for (i = 1; i < 28; i++) {
                g.globalAlpha = 1 - i / 30; g.strokeStyle = i % 3 ? a : b; g.lineWidth = i % 4 === 0 ? 3 : 1;
                g.beginPath(); g.arc(cx, cy, i * 11, r() * 6, r() * 6 + 4); g.stroke();
            }
        } else if (style === 2) {
            var s = 22;
            for (y = 0; y < h; y += s) for (x = 0; x < w; x += s) {
                var v = r();
                if (v > .55) { g.globalAlpha = v - .35; g.fillStyle = v > .85 ? b : a; g.fillRect(x + 2, y + 2, s - 4, s - 4); }
            }
        } else if (style === 3) {
            // Layered ridges, like a mountain range.
            for (i = 0; i < 6; i++) {
                g.globalAlpha = .25 + i * .12; g.fillStyle = i % 2 ? a : b;
                var base = h * (.35 + i * .11), amp = 18 + r() * 30, f = .004 + r() * .01, ph = r() * 6;
                g.beginPath(); g.moveTo(0, h);
                for (x = 0; x <= w; x += 8) g.lineTo(x, base + Math.sin(x * f + ph) * amp + Math.sin(x * f * 3.1) * amp * .3);
                g.lineTo(w, h); g.closePath(); g.fill();
            }
        } else if (style === 4) {
            // Soft orbs.
            for (i = 0; i < 9; i++) {
                x = r() * w; y = r() * h; var rad = 30 + r() * Math.max(w, h) * .35;
                var orb = g.createRadialGradient(x, y, 0, x, y, rad);
                orb.addColorStop(0, i % 2 ? a : b); orb.addColorStop(1, 'transparent');
                g.globalAlpha = .35 + r() * .4; g.fillStyle = orb;
                g.beginPath(); g.arc(x, y, rad, 0, Math.PI * 2); g.fill();
            }
        } else {
            // Diagonal bars.
            var gap = 14 + r() * 18, tilt = r() > .5 ? 1 : -1;
            g.lineWidth = 2 + r() * 6;
            for (i = -h; i < w + h; i += gap) {
                g.globalAlpha = .2 + r() * .6; g.strokeStyle = r() > .5 ? a : b;
                g.beginPath(); g.moveTo(i, tilt > 0 ? 0 : h); g.lineTo(i + h, tilt > 0 ? h : 0); g.stroke();
            }
        }
        g.globalAlpha = 1;
        g.font = '500 11px "JetBrains Mono", monospace';
        g.fillStyle = 'rgba(255,255,255,.5)';
        g.fillText('#' + seed.toString(16).slice(0, 6), w - 70, h - 12);
    }

    function paintAll() {
        // The hero and the post list are separate sets; the same post keeps its cover in both.
        var used = { styles: {}, hues: {} }, looks = {};
        document.querySelectorAll('canvas[data-cover]').forEach(function (canvas) {
            var text = canvas.dataset.cover, palette = canvas.dataset.palette || text;
            var look = looks[text + '|' + palette] || (looks[text + '|' + palette] = pick(text, palette, used));
            paint(canvas, look);
        });
    }

    var resizeTimer;
    window.addEventListener('resize', function () { window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(paintAll, 150); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(paintAll); else paintAll();
})();
