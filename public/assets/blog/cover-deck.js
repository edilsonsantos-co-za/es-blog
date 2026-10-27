/* Atlas home hero: rotates the cover deck and paints generative cover art for posts without an image. */
(function () {
    var hero = document.querySelector('[data-deck]');
    if (!hero) return;
    var covers = Array.prototype.slice.call(hero.querySelectorAll('.cover'));
    var nav = hero.querySelector('.deck-nav');
    var buttons = nav ? Array.prototype.slice.call(nav.querySelectorAll('button')) : [];
    var label = hero.querySelector('[data-deck-label]');
    var title = hero.querySelector('[data-deck-title]');
    var excerpt = hero.querySelector('[data-deck-excerpt]');
    var cta = hero.querySelector('[data-deck-cta]');
    var labelBase = label ? label.textContent.split(' · ').slice(0, 2).join(' · ') : '';
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var interval = 6000;
    var current = 0;

    // FNV-1a, so the same title always draws the same cover.
    function hash(s) {
        var h = 2166136261;
        for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
        return h >>> 0;
    }
    function random(seed) {
        return function () { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return seed / 4294967296; };
    }

    function paint(canvas, text, palette) {
        var box = canvas.parentNode.getBoundingClientRect();
        var w = Math.max(box.width, 1), h = Math.max(box.height, 1), d = window.devicePixelRatio || 1;
        canvas.width = w * d; canvas.height = h * d;
        var g = canvas.getContext('2d');
        g.setTransform(d, 0, 0, d, 0, 0);
        var seed = hash(text), r = random(seed);
        // Category (or topic) picks the palette, title picks the pattern.
        var hue = hash(palette) % 360, a = 'hsl(' + hue + ' 85% 62%)', b = 'hsl(' + ((hue + 50) % 360) + ' 80% 55%)';
        g.fillStyle = 'hsl(' + hue + ' 45% 8%)';
        g.fillRect(0, 0, w, h);
        var grad = g.createLinearGradient(0, 0, w, h);
        grad.addColorStop(0, a); grad.addColorStop(1, b);
        var style = seed % 3, i, k, x, y;
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
        } else {
            var s = 22;
            for (y = 0; y < h; y += s) for (x = 0; x < w; x += s) {
                var v = r();
                if (v > .55) { g.globalAlpha = v - .35; g.fillStyle = v > .85 ? b : a; g.fillRect(x + 2, y + 2, s - 4, s - 4); }
            }
        }
        g.globalAlpha = 1;
        g.font = '500 11px "JetBrains Mono", monospace';
        g.fillStyle = 'rgba(255,255,255,.5)';
        g.fillText('#' + seed.toString(16).slice(0, 6), w - 70, h - 12);
    }

    function paintAll() {
        covers.forEach(function (cover) {
            var canvas = cover.querySelector('canvas');
            if (canvas) paint(canvas, cover.dataset.title, cover.dataset.palette);
        });
    }

    function layout() {
        var n = covers.length;
        covers.forEach(function (cover, i) {
            var k = (i - current + n) % n;
            cover.style.zIndex = 10 - k;
            cover.style.transform = k === 0
                ? 'translate3d(0,0,0) rotateY(-8deg)'
                : 'translate3d(' + k * 34 + 'px,' + -k * 18 + 'px,' + -k * 90 + 'px) rotateY(-14deg) rotateZ(' + k * 2.5 + 'deg)';
            cover.style.opacity = k > 2 ? 0 : 1 - k * .18;
            cover.style.filter = k ? 'saturate(' + (1 - k * .3) + ')' : '';
            cover.tabIndex = k === 0 ? 0 : -1;
            cover.setAttribute('aria-hidden', k === 0 ? 'false' : 'true');
        });
        buttons.forEach(function (b, i) {
            if (i === current) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
        });
        if (autoplay) { nav.classList.remove('playing'); void nav.offsetWidth; nav.classList.add('playing'); }
    }

    function show(i) {
        current = (i + covers.length) % covers.length;
        var cover = covers[current];
        hero.classList.add('swapping');
        window.setTimeout(function () {
            if (label) label.textContent = labelBase + ' · ' + cover.dataset.kicker;
            title.textContent = cover.dataset.title;
            excerpt.textContent = cover.dataset.excerpt;
            if (cta) { cta.href = cover.href; cta.textContent = cover.dataset.cta; }
            hero.classList.remove('swapping');
        }, reduce ? 0 : 250);
        layout();
    }

    // The progress bar's CSS animation is the timer: it pauses on hover or focus, and its end advances the deck.
    var autoplay = !reduce && covers.length > 1 && nav;
    if (autoplay) {
        hero.style.setProperty('--deck-interval', interval / 1000 + 's');
        nav.addEventListener('animationend', function () { show(current + 1); });
    }

    buttons.forEach(function (b, i) { b.addEventListener('click', function () { show(i); }); });
    hero.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        if (!e.target.closest('.deck, .deck-nav')) return;
        show(current + (e.key === 'ArrowRight' ? 1 : -1));
        var focus = e.target.closest('.deck-nav') ? buttons[current] : covers[current];
        if (focus) focus.focus();
    });

    var resizeTimer;
    window.addEventListener('resize', function () { window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(paintAll, 150); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(paintAll); else paintAll();
    layout();
})();
