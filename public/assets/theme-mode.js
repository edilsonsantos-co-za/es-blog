/*
 * Light/dark choice shared by every Atlas surface (portfolio, blog, store and their admins).
 * Subdomains don't share localStorage, so the choice lives in an `es-theme` cookie on the
 * parent domain (e.g. .edilsonsantos.co.za), with localStorage as a fallback for hosts where
 * no parent-domain cookie can be set. Load it synchronously in <head> so the saved theme is
 * applied before first paint.
 */
(function () {
    var NAME = 'es-theme';
    var LEGACY_KEYS = ['atlas-theme', 'edfolio-public-theme-mode', 'es-store-theme'];
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var listeners = [];

    function valid(mode) {
        return mode === 'dark' || mode === 'light' ? mode : null;
    }

    function readCookie() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=(dark|light)(?:;|$)'));
        return match ? match[1] : null;
    }

    function readStorage() {
        try {
            var keys = [NAME].concat(LEGACY_KEYS);
            for (var i = 0; i < keys.length; i++) {
                var mode = valid(localStorage.getItem(keys[i]));
                if (mode) { return mode; }
            }
        } catch (e) {}
        return null;
    }

    // Set the cookie on the widest domain the browser accepts: it refuses public suffixes
    // such as co.za, so the first one that sticks is the site's own domain.
    function writeCookie(mode) {
        var base = NAME + '=' + mode + '; path=/; max-age=31536000; SameSite=Lax';
        var labels = location.hostname.split('.');
        for (var i = labels.length - 2; i >= 0; i--) {
            document.cookie = base + '; domain=' + labels.slice(i).join('.');
            if (readCookie() === mode) { return; }
        }
        document.cookie = base;
    }

    function get() {
        var forced = valid(root.getAttribute('data-theme'));
        if (forced) { return forced; }
        return media && media.matches ? 'dark' : 'light';
    }

    function set(mode) {
        if (!valid(mode)) { return; }
        root.setAttribute('data-theme', mode);
        writeCookie(mode);
        try { localStorage.setItem(NAME, mode); } catch (e) {}
        listeners.forEach(function (listener) { listener(mode); });
    }

    var saved = readCookie();
    if (!saved) {
        // Carry a choice saved before the shared cookie existed over to the other sites.
        saved = readStorage();
        if (saved) { writeCookie(saved); }
    }
    if (saved) { root.setAttribute('data-theme', saved); }

    window.esTheme = {
        get: get,
        set: set,
        toggle: function () { set(get() === 'dark' ? 'light' : 'dark'); },
        subscribe: function (listener) {
            listeners.push(listener);
            if (media) { media.addEventListener('change', listener); }
            return function () {
                listeners = listeners.filter(function (l) { return l !== listener; });
                if (media) { media.removeEventListener('change', listener); }
            };
        },
    };
})();
