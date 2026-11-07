/*!
 * <es-apps>: the ES app launcher and account menu, and <es-tabbar>: the phone/tablet bottom tab bar (further down), shared by every ES app (portfolio, blog, journal, store, developers).
 * Source of truth: es-marketplace/store/assets/es-apps.js. Apps ship a copy of this file (their CSP only
 * allows their own scripts); update it here and copy it over.
 *
 * <es-apps current="blog"                          which app this is (portfolio|blog|journal|store|developers)
 *          signed-in                               the SSO user is signed in here
 *          owner                                   that user holds the Logto `es-owner` role (shows the private apps, e.g. Journal).
 *                                                  Never set for store customers. Display only: each private app checks the role itself.
 *          user-name="…" user-email="…"            the account menu (shown whenever signed-in; "Sign in" otherwise)
 *          avatar="https://…"                      profile picture (Gravatar default); initials when missing
 *          sign-in-url="/login"                    where "Sign in" goes
 *          sign-out-url="/logout"                  where "Sign out" goes
 *          sign-out-method="post" csrf-name="_token" csrf-token="…"   for apps that sign out with a POST
 *          manage-url="/admin/profile"             optional "Your profile" link
 *          dashboard-url="/admin">                 optional "Dashboard" link (opens in a new tab)
 *
 * Menu links that open in a new tab carry a small "opens in a new tab" arrow (and say so to screen readers).
 *
 * Signing out here also writes the shared `es-signout` cookie, so the other ES apps sign out too.
 * Signed in here, it publishes who you are (display only) in the shared `es-user` cookie, so apps where
 * you have no session of their own show your account instead of "Sign in".
 * On *.edilsonsantos.local the launcher links the local instances and adds Labs (dev only, and only for the owner).
 * Only apps whose container is up show: each tile loads a small image (`probe`) from its app first, and a stopped
 * container (the proxy answers 502) hides it. Start the container again and it shows on the next page or menu open.
 * </es-apps>
 */
(() => {
    if (customElements.get('es-apps')) {
        return;
    }

    // Local instances (edilsonsantos.local, blog.edilsonsantos.local, …) link to each other, not to production
    const LOCAL = /(^|\.)edilsonsantos\.local$/.test(location.hostname);
    const site = (sub) => LOCAL ? `${location.protocol}//${sub}edilsonsantos.local/` : `https://${sub}edilsonsantos.co.za/`;
    // `probe`: a small public image on that app; it loads only while the app's container is up
    // (Journal: a PNG, because BookStack serves theme SVGs as application/octet-stream + nosniff, which never loads as an image)
    const APPS = [
        { id: 'portfolio', name: 'Portfolio', url: site(''), probe: '/assets/brand/es-mark.svg', icon: 'M4 5h16v11H4z M8 20h8 M12 16v4' },
        { id: 'blog', name: 'Blog', url: site('blog.'), probe: '/assets/brand/es-mark.svg', icon: 'M5 4h10l4 4v12H5z M14 4v5h5 M8 13h8 M8 17h5' },
        { id: 'developers', name: 'Developers', url: site('developers.'), probe: '/img/favicon.svg', icon: 'M8 7l-5 5 5 5 M16 7l5 5-5 5 M14 4l-4 16' },
        { id: 'store', name: 'Store', url: site('store.'), probe: '/assets/es-mark.svg', icon: 'M4 7h16l-1.5 12h-13z M8 7a4 4 0 0 1 8 0' },
        { id: 'journal', name: 'Journal', url: site('journal.'), private: true, probe: '/theme/es-journal/brand/es-icon-32.png', icon: 'M6 3h11a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6z M9 3v18 M12 8h4 M12 12h4' },
        // Dev only and owner only: Labs (experiments) shows on *.edilsonsantos.local to the owner, never in production or to anyone else
        { id: 'labs', name: 'Labs', url: site('labs.'), dev: true, private: true, probe: '/es-mark.svg', icon: 'M9 3h6 M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3 M7.5 15h9' },
    ];

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const initials = (name, email) => (name || email || '?').trim().split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
    const icon = (d) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>`;
    // A menu link that opens in a new tab: label + the "opens in a new tab" arrow
    const newTab = (href, label) => `<a href="${esc(href)}" target="_blank" rel="noopener">${esc(label)}<span class="sr">(opens in a new tab)</span>`
        + `<svg class="ext" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6 M20 4l-9 9 M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a>`;

    const CSS = `
        :host { --bg: #121620; --bg-2: #181d29; --text: #e8edf6; --muted: #8f9ab0; --line: #232a38; --accent: #3b82f6; --shadow: 0 18px 48px rgba(0, 0, 0, .5);
                display: inline-flex; align-items: center; gap: 6px; font: 600 14px/1.4 Manrope, system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--text); position: relative; }
        button { font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
        .trigger { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; color: var(--muted); }
        .trigger:hover, .trigger[aria-expanded="true"] { background: var(--bg-2); color: var(--text); }
        .trigger:focus { outline: none; }
        .panel a:focus, .menu button:focus { outline: none; background: var(--bg-2); }
        .trigger svg { width: 20px; height: 20px; fill: currentColor; }
        .avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; display: grid; place-items: center; background: var(--accent); color: #fff; font-size: 12px; font-weight: 800; overflow: hidden; }
        .avatar.lg { width: 56px; height: 56px; font-size: 18px; }
        .panel { position: absolute; top: calc(100% + 8px); right: 0; z-index: 2147483000; background: var(--bg); border: 1px solid var(--line); border-radius: 20px; box-shadow: var(--shadow); padding: 14px; min-width: 288px; }
        .panel[hidden] { display: none; }
        .kicker { font: 500 11px/1.4 'JetBrains Mono', ui-monospace, monospace; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin: 2px 4px 10px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .app { display: grid; justify-items: center; gap: 6px; padding: 12px 6px; border-radius: 14px; text-decoration: none; color: var(--text); font-size: 13px; }
        .app:hover { background: var(--bg-2); }
        .app[aria-current="page"] { background: var(--bg-2); box-shadow: inset 0 0 0 1px var(--line); }
        /* One ES blue for every app tile (the ES mark's colour); apps differ by icon, not colour */
        .tile { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: #2563eb; }
        .tile svg { width: 22px; height: 22px; fill: none; stroke: #fff; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .who { display: grid; justify-items: center; gap: 4px; padding: 8px 4px 14px; text-align: center; border-bottom: 1px solid var(--line); margin-bottom: 8px; }
        .who strong { font-size: 15px; font-weight: 800; letter-spacing: -.01em; }
        .who span { color: var(--muted); font-weight: 500; word-break: break-all; }
        .who .avatar { color: #fff; font-weight: 800; word-break: normal; }
        .sso { display: inline-flex; gap: 6px; align-items: center; margin-top: 4px; font: 500 11px 'JetBrains Mono', ui-monospace, monospace; color: var(--muted); }
        .sso::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #0ca30c; }
        .menu a, .menu button { display: flex; width: 100%; padding: 10px 12px; border-radius: 12px; text-decoration: none; color: var(--text); text-align: left; box-sizing: border-box; }
        .menu a:hover, .menu button:hover { background: var(--bg-2); }
        .menu a[target="_blank"] { align-items: center; justify-content: space-between; gap: 10px; }
        .ext { width: 14px; height: 14px; flex: none; fill: none; stroke: var(--muted); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .menu a[target="_blank"]:hover .ext, .menu a[target="_blank"]:focus .ext { stroke: var(--text); }
        .signin { padding: 8px 14px; border-radius: 12px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 700; white-space: nowrap; }
        form { margin: 0; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        @media (max-width: 480px) { .panel { position: fixed; top: 64px; left: 12px; right: 12px; min-width: 0; } }
    `;

    // Write a cookie on the parent domain (the widest one the browser accepts),
    // so every ES app sees it. An empty value deletes it (on every level, wherever it was written).
    const setSharedCookie = (name, value, maxAge) => {
        const base = `${name}=${value}; path=/; ${value ? (maxAge ? `max-age=${maxAge}; ` : '') : 'max-age=0; '}SameSite=Lax`;
        const labels = location.hostname.split('.');
        for (let i = labels.length - 2; i >= 0; i--) {
            document.cookie = `${base}; domain=${labels.slice(i).join('.')}`;
            if (value && getCookie(name) === value) return;
        }
        document.cookie = base;
    };
    const getCookie = (name) => (document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`)) || [])[1] || '';

    // Shared signed-in state: an app where you're signed in publishes who you are (display only: name,
    // email, avatar; never a credential) in the `es-user` cookie, so the pages of apps where you have no
    // session of your own (the store, the portfolio and blog pages) show your account instead of "Sign in".
    // It lasts for the browser session and Sign out in any app clears it.
    const USER_COOKIE = 'es-user';
    const encodeUser = (user) => btoa(String.fromCharCode(...new TextEncoder().encode(JSON.stringify(user))))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const sharedUser = () => {
        const raw = getCookie(USER_COOKIE);
        if (!raw) return null;
        try {
            const bin = atob(raw.replace(/-/g, '+').replace(/_/g, '/'));
            const user = JSON.parse(new TextDecoder().decode(Uint8Array.from(bin, (c) => c.charCodeAt(0))));
            return user && (user.n || user.e) ? user : null;
        } catch { return null; }
    };

    // Single sign-out: "Sign out" in any ES app writes a fresh `es-signout` marker on the parent
    // domain. Every app remembers the marker it saw when you signed in there, and ends its own
    // session as soon as the marker changes. The shared signed-in state goes with it.
    const markSignedOut = () => {
        setSharedCookie('es-signout', Math.random().toString(36).slice(2) + Date.now().toString(36), 31536000);
        setSharedCookie(USER_COOKIE, '');
    };

    // App status: is each app's container up? Probed with an <img> (no CORS needed, and every app's CSP
    // allows images from the other ES subdomains): it loads while the app runs; with the container stopped the
    // proxy answers 502 (an HTML page), so it fails. Results last a page view; the last one is kept for the
    // browser session (sessionStorage), so a stopped app stays hidden from the first paint of the next page.
    const STATUS_KEY = 'es-apps-status';
    const PROBE_TIMEOUT = 8000;
    const PROBE_MAX_AGE = 30000;
    const status = (() => { try { return JSON.parse(sessionStorage.getItem(STATUS_KEY)) || {}; } catch { return {}; } })();
    const probing = {};
    const listeners = new Set();
    const saveStatus = () => { try { sessionStorage.setItem(STATUS_KEY, JSON.stringify(status)); } catch { /* private mode */ } };
    const probe = (app) => {
        if (!app.probe || probing[app.id] || (status[app.id] && Date.now() - status[app.id].t < PROBE_MAX_AGE && status[app.id].fresh)) return;
        probing[app.id] = true;
        const img = new Image();
        let settled = false;
        const finish = (up) => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            img.onload = img.onerror = null;
            delete probing[app.id];
            const changed = status[app.id]?.up !== up;
            status[app.id] = { up, t: Date.now(), fresh: true };
            saveStatus();
            if (changed) listeners.forEach((fn) => fn());
        };
        const timer = setTimeout(() => finish(false), PROBE_TIMEOUT);
        img.onload = () => finish(true);
        img.onerror = () => finish(false);
        img.referrerPolicy = 'no-referrer';
        img.src = `${new URL(app.probe, app.url).href}?es-up=${Date.now().toString(36)}`;
    };
    // Kept from an earlier page: use it for the first paint, but probe again
    Object.values(status).forEach((s) => { if (s) s.fresh = false; });
    // Unknown counts as up (shown), so a slow probe never hides a running app
    const isUp = (app) => status[app.id]?.up !== false;

    let SHEET = null;
    try { SHEET = new CSSStyleSheet(); SHEET.replaceSync(CSS); } catch { SHEET = null; }

    class EsApps extends HTMLElement {
        static get observedAttributes() { return ['current', 'signed-in', 'owner', 'user-name', 'user-email', 'avatar', 'dashboard-url']; }

        constructor() {
            super();
            this.attachShadow({ mode: 'open' });
            // A constructed stylesheet works under a strict CSP (no 'unsafe-inline' for styles)
            if (SHEET) this.shadowRoot.adoptedStyleSheets = [SHEET];
            this.onDocClick = (e) => { if (!this.contains(e.target) && !e.composedPath().includes(this)) this.close(); };
            this.onKey = (e) => { if (e.key === 'Escape') this.close(true); };
            // Signed in or out in another tab or app meanwhile: show it when you come back
            this.onVisible = () => {
                if (document.visibilityState !== 'visible') return;
                this.probeApps();
                if (!this.hasAttribute('signed-in')) this.render();
            };
            // An app came up or went down: redraw, keeping an open menu open
            this.onStatus = () => {
                if (!this.shadowRoot.childElementCount) return;
                const open = this.shadowRoot.querySelector('.trigger[aria-expanded="true"]')?.dataset.for;
                this.render();
                if (open) this.toggle(open, false);
            };
        }

        connectedCallback() {
            document.addEventListener('click', this.onDocClick);
            document.addEventListener('keydown', this.onKey);
            document.addEventListener('visibilitychange', this.onVisible);
            listeners.add(this.onStatus);
            this.render();
            this.probeApps();
        }

        // Probe every app this launcher can list (never the app you are on: it is running)
        probeApps() {
            const current = this.getAttribute('current') || '';
            APPS.filter((a) => a.id !== current && (!a.dev || LOCAL)).forEach(probe);
        }

        disconnectedCallback() {
            document.removeEventListener('click', this.onDocClick);
            document.removeEventListener('keydown', this.onKey);
            document.removeEventListener('visibilitychange', this.onVisible);
            listeners.delete(this.onStatus);
        }

        attributeChangedCallback() { if (this.shadowRoot.childElementCount) this.render(); }

        avatarHtml(large) {
            const { name, email, avatar: src } = this.who();
            const cls = large ? 'avatar lg' : 'avatar';
            const fallback = `<span class="${cls}" aria-hidden="true">${esc(initials(name, email))}</span>`;
            return src ? `<img class="${cls}" src="${esc(src)}" alt="" referrerpolicy="no-referrer" data-fallback='${esc(fallback)}'>` : fallback;
        }

        // Signed in here: the attributes. Otherwise whoever is signed in to another ES app (`es-user`).
        who() {
            if (this.hasAttribute('signed-in')) {
                return { name: this.getAttribute('user-name') || '', email: this.getAttribute('user-email') || '', avatar: this.getAttribute('avatar') || '', owner: this.hasAttribute('owner'), local: true };
            }
            const shared = sharedUser();
            return shared ? { name: shared.n || '', email: shared.e || '', avatar: shared.a || '', owner: shared.o === 1, local: false } : null;
        }

        publish(who) {
            if (!who?.local || !(who.name || who.email)) return;
            const value = encodeUser({ n: who.name, e: who.email, a: who.avatar, o: who.owner ? 1 : 0 });
            if (getCookie(USER_COOKIE) !== value) setSharedCookie(USER_COOKIE, value);
        }

        render() {
            const current = this.getAttribute('current') || '';
            const who = this.who();
            this.publish(who);
            const signedIn = !!who;
            const name = who?.name || '';
            const email = who?.email || '';
            // Signed in always shows the account menu (initials until the name/email arrive)
            const hasUser = signedIn;
            const label = name || email || 'Your account';
            // Private apps (Journal, Labs) only for the owner: a store customer is signed in too, but never sees them
            // Stopped apps (container down) are left out; the app you are on always shows
            const apps = APPS.filter((a) => (!a.private || who?.owner) && (!a.dev || LOCAL) && (a.id === current || isUp(a)));

            const appGrid = apps.map((a) => `
                <a class="app" href="${a.url}" ${a.id === current ? 'aria-current="page"' : ''}>
                    <span class="tile">${icon(a.icon)}</span>${esc(a.name)}
                </a>`).join('');

            const signOut = this.getAttribute('sign-out-url');
            const method = (this.getAttribute('sign-out-method') || 'get').toLowerCase();
            // Signed in elsewhere only: Sign out here signs you out of every ES app (the marker)
            const signOutItem = !who?.local ? '<button type="button" data-signout>Sign out</button>' : !signOut ? '' : method === 'post'
                ? `<form method="post" action="${esc(signOut)}"><input type="hidden" name="${esc(this.getAttribute('csrf-name') || '_token')}" value="${esc(this.getAttribute('csrf-token') || '')}"><button type="submit">Sign out</button></form>`
                : `<a href="${esc(signOut)}" data-signout>Sign out</a>`;
            const manage = who?.local ? this.getAttribute('manage-url') : null;
            const dashboard = this.getAttribute('dashboard-url');

            const account = hasUser ? `
                <button class="trigger" part="account" aria-label="Account: ${esc(label)}" aria-haspopup="true" aria-expanded="false" data-for="account">${this.avatarHtml(false)}</button>
                <div class="panel" id="account" role="menu" hidden>
                    <div class="who">${this.avatarHtml(true)}<strong>${esc(label)}</strong>${name && email ? `<span>${esc(email)}</span>` : ''}<span class="sso">Signed in with DataSparkLab SSO</span></div>
                    <div class="menu">${dashboard ? newTab(dashboard, 'Dashboard') : ''}${manage ? `<a href="${esc(manage)}">Your profile</a>` : ''}${signOutItem}</div>
                </div>`
                : (this.getAttribute('sign-in-url') ? `<a class="signin" href="${esc(this.getAttribute('sign-in-url'))}">Sign in</a>` : '');

            this.shadowRoot.innerHTML = `${SHEET ? '' : `<style>${CSS}</style>`}
                <button class="trigger" part="launcher" aria-label="ES apps" aria-haspopup="true" aria-expanded="false" data-for="apps">${icon('M5 5h3v3H5zM10.5 5h3v3h-3zM16 5h3v3h-3zM5 10.5h3v3H5zM10.5 10.5h3v3h-3zM16 10.5h3v3h-3zM5 16h3v3H5zM10.5 16h3v3h-3zM16 16h3v3h-3z')}</button>
                <div class="panel" id="apps" role="menu" hidden><p class="kicker">Edilson Santos apps</p><div class="grid">${appGrid}</div></div>
                ${account}`;

            this.shadowRoot.querySelector('.menu form')?.addEventListener('submit', markSignedOut);
            this.shadowRoot.querySelectorAll('.menu a[data-signout]').forEach((a) => a.addEventListener('click', markSignedOut));
            this.shadowRoot.querySelector('.menu button[data-signout]')?.addEventListener('click', () => { markSignedOut(); this.render(); });
            this.shadowRoot.querySelectorAll('img[data-fallback]').forEach((img) => img.addEventListener('error', () => { img.outerHTML = img.dataset.fallback; }, { once: true }));
            this.shadowRoot.querySelectorAll('.trigger').forEach((t) => t.addEventListener('click', (e) => {
                e.stopPropagation();
                // Opening the apps menu re-checks apps last probed over 30s ago (started or stopped since)
                if (t.dataset.for === 'apps') this.probeApps();
                this.toggle(t.dataset.for, true);
            }));
        }

        toggle(id, focus) {
            const panel = this.shadowRoot.getElementById(id);
            const t = this.shadowRoot.querySelector(`.trigger[data-for="${id}"]`);
            if (!panel || !t) return;
            const open = panel.hidden;
            this.close();
            panel.hidden = !open;
            t.setAttribute('aria-expanded', String(open));
            if (open && focus) panel.querySelector('a, button')?.focus();
        }

        close(refocus) {
            const opener = this.shadowRoot.querySelector('.trigger[aria-expanded="true"]');
            this.shadowRoot.querySelectorAll('.panel').forEach((p) => { p.hidden = true; });
            this.shadowRoot.querySelectorAll('.trigger').forEach((t) => t.setAttribute('aria-expanded', 'false'));
            if (refocus) opener?.focus();
        }
    }

    customElements.define('es-apps', EsApps);

    /*
     * <es-tabbar>: the app-style bottom tab bar every ES app shows on phones and tablets (≤ 1024px), like an
     * installed app. Phones get a docked bar; tablets a floating island. The top bar keeps the brand, Buy me a coffee
     * and <es-apps>; the app's own hamburger menu hides (`hide`).
     *
     * <es-tabbar hide=".menu-btn, #menu"          CSS selectors of the app's own phone/tablet menu, hidden while the bar shows
     *            source="#menu"                   optional: also take the links of this nav (deduplicated); they go under More
     *            more-button=".navbar__toggle">   optional: More opens the app's own menu (Developers' docs sidebar) instead of the sheet
     *     <a href="/" data-icon="home">Home</a>   the tabs, in order; aria-current="page" marks the current one (else the URL decides)
     *     <a href="/apps/x" data-match="/apps/">Apps</a>   data-match: current for every path that starts with it
     * </es-tabbar>
     *
     * Up to five tabs; with more links, the first four plus More (a bottom sheet with the rest).
     * Put the tag anywhere: it moves itself to the end of <body>, where it also reserves the space the bar covers.
     * Icons come from data-icon (see TAB_ICONS), else from the label; the bar hides while the keyboard is up.
     */
    const TAB_ICONS = {
        home: 'M3 11l9-7 9 7 M5 9.5V20h5v-6h4v6h5V9.5',
        experience: 'M3 8h18v12H3z M9 8V5h6v3 M3 13h18',
        education: 'M2 9l10-5 10 5-10 5z M6 11v5c3 2.5 9 2.5 12 0v-5 M22 9v6',
        projects: 'M4 4h7v7H4z M13 4h7v7h-7z M4 13h7v7H4z M13 13h7v7h-7z',
        services: 'M12 3l9 5-9 5-9-5z M3 12.5l9 5 9-5 M3 17l9 5 9-5',
        contact: 'M3 6h18v12H3z M3 7l9 6 9-6',
        posts: 'M5 4h10l4 4v12H5z M14 4v5h5 M8 13h8 M8 17h5',
        search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z M16 16l5 5',
        newsletter: 'M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z M10 21h4',
        rss: 'M5 5a14 14 0 0 1 14 14 M5 11a8 8 0 0 1 8 8 M6 18h.01',
        tag: 'M3 3h8l10 10-8 8L3 11z M7.5 7.5h.01',
        themes: 'M12 3a9 9 0 1 0 0 18c1 0 1.5-.8 1.5-1.5 0-1-1-1.3-1-2.3 0-.8.7-1.2 1.5-1.2H17a4 4 0 0 0 4-4c0-5-4-9-9-9z M7.5 11h.01 M10 7h.01 M15 7.5h.01',
        packs: 'M12 3l8 4.5v9L12 21l-8-4.5v-9z M4 7.5l8 4.5 8-4.5 M12 12v9',
        install: 'M12 4v11 M7 10l5 5 5-5 M5 20h14',
        docs: 'M4 19V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2 M4 19a2 2 0 0 0 2 2h13 M8 7h7',
        apps: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
        guides: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M15.5 8.5l-2 5-5 2 2-5z',
        roadmap: 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z M9 4v14 M15 6v14',
        checkin: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M8 12l3 3 5-6',
        insights: 'M5 20v-9 M12 20V5 M19 20v-6 M3 20h18',
        prayer: 'M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z',
        shelves: 'M4 3v18 M20 3v18 M4 9h16 M4 15h16',
        books: 'M4 4h4v16H4z M10 4h4v16h-4z M15.5 5.2l3.4-.9 3.1 15.5-3.4.9z',
        settings: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z M12 2v3 M12 19v3 M4.9 4.9L7 7 M17 17l2.1 2.1 M2 12h3 M19 12h3 M4.9 19.1L7 17 M17 7l2.1-2.1',
        users: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M2 21a7 7 0 0 1 14 0 M16 3.5a4 4 0 0 1 0 7.5 M22 21a7 7 0 0 0-4-6.3',
        login: 'M14 4h5v16h-5 M10 8l4 4-4 4 M14 12H3',
        cart: 'M3 4h2l2.5 11h11L21 7H6.2 M9 20h.01 M18 20h.01',
        link: 'M9 3L7 21 M17 3l-2 18 M4 8h17 M3 16h17',
        more: 'M5 12h.01 M12 12h.01 M19 12h.01',
    };
    // No data-icon: guess from the label
    const ICON_WORDS = [
        [/^(home|overview|journal|dashboard)$/i, 'home'], [/experience|work|career/i, 'experience'], [/education|study/i, 'education'],
        [/project/i, 'projects'], [/service/i, 'services'], [/contact|hire/i, 'contact'], [/post|writing|article/i, 'posts'],
        [/search/i, 'search'], [/newsletter|subscribe/i, 'newsletter'], [/rss|feed/i, 'rss'], [/pack/i, 'packs'], [/theme/i, 'themes'],
        [/install|download/i, 'install'], [/doc/i, 'docs'], [/apps?$/i, 'apps'], [/guide/i, 'guides'], [/roadmap/i, 'roadmap'],
        [/check.?in/i, 'checkin'], [/insight|stats/i, 'insights'], [/prayer/i, 'prayer'], [/shel/i, 'shelves'], [/book/i, 'books'],
        [/setting/i, 'settings'], [/user/i, 'users'], [/log ?in|sign ?in/i, 'login'], [/cart|basket/i, 'cart'],
    ];
    const tabIcon = (name, label) => {
        const key = TAB_ICONS[name] ? name : (ICON_WORDS.find(([re]) => re.test(label)) || [, 'link'])[1];
        return `<svg viewBox="0 0 24 24" aria-hidden="true"${key === 'more' ? ' class="dots"' : ''}><path d="${TAB_ICONS[key]}"/></svg>`;
    };

    const TAB_CSS = `
        :host { --bg: #121620; --bg-2: #181d29; --text: #e8edf6; --muted: #8f9ab0; --line: #232a38; --accent: #3b82f6; --accent-2: #93c5fd;
                display: none; height: calc(72px + env(safe-area-inset-bottom)); font: 700 11px/1.2 Manrope, system-ui, -apple-system, 'Segoe UI', sans-serif;
                -webkit-tap-highlight-color: transparent; }
        @media (max-width: 1024px) { :host { display: block; } }
        @media (min-width: 640px) and (max-width: 1024px) { :host { height: calc(92px + env(safe-area-inset-bottom)); } }
        * { box-sizing: border-box; }
        .bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147482600; display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr);
               gap: 2px; padding: 6px 6px calc(6px + env(safe-area-inset-bottom)); background: rgba(18, 22, 32, .88); border-top: 1px solid var(--line);
               -webkit-backdrop-filter: blur(18px) saturate(1.4); backdrop-filter: blur(18px) saturate(1.4); transition: transform .25s ease; }
        :host(.kb) .bar { transform: translateY(110%); }
        .tab { display: grid; justify-items: center; align-content: center; gap: 4px; min-height: 54px; padding: 4px 2px; border-radius: 14px; color: var(--muted);
               text-decoration: none; font: inherit; background: none; border: 0; cursor: pointer; touch-action: manipulation; user-select: none; -webkit-user-select: none; }
        .ic { width: 56px; max-width: 100%; height: 30px; border-radius: 999px; display: grid; place-items: center; transition: background .2s, transform .15s; }
        .tab svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .tab svg.dots { stroke-width: 3.2; }
        .lb { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; letter-spacing: .01em; }
        .tab[aria-current], .tab[aria-expanded="true"] { color: var(--text); }
        .tab[aria-current] .ic, .tab[aria-expanded="true"] .ic { background: rgba(59, 130, 246, .2); color: var(--accent-2); }
        .more[data-current] { color: var(--text); }
        .more[data-current] .ic { background: rgba(59, 130, 246, .2); color: var(--accent-2); }
        .tab:active .ic { transform: scale(.9); }
        .tab:focus { outline: none; }
        .tab:focus-visible .ic { outline: 2px solid var(--accent); outline-offset: 2px; }
        @media (hover: hover) { .tab:hover { color: var(--text); } }
        .scrim { position: fixed; inset: 0; z-index: 2147482400; background: rgba(5, 7, 12, .55); opacity: 0; visibility: hidden; transition: opacity .25s, visibility .25s; }
        .sheet { position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147482500; max-height: min(72vh, 560px); overflow: auto; overscroll-behavior: contain;
                 padding: 10px 14px calc(84px + env(safe-area-inset-bottom)); background: var(--bg); border: 1px solid var(--line); border-bottom: 0;
                 border-radius: 24px 24px 0 0; box-shadow: 0 -18px 48px rgba(0, 0, 0, .45); transform: translateY(100%); visibility: hidden;
                 transition: transform .3s cubic-bezier(.2, .8, .2, 1), visibility .3s; }
        :host(.open) .scrim { opacity: 1; visibility: visible; }
        :host(.open) .sheet { transform: none; visibility: visible; }
        .grab { display: block; width: 40px; height: 4px; border-radius: 4px; background: var(--line); margin: 0 auto 12px; }
        .kicker { font: 500 11px/1.4 'JetBrains Mono', ui-monospace, monospace; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin: 0 4px 10px; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
        .item { display: grid; justify-items: center; gap: 8px; padding: 14px 6px; border-radius: 16px; color: var(--text); text-decoration: none; font-size: 13px; text-align: center; }
        .item .tile { width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center; background: var(--bg-2); border: 1px solid var(--line); color: var(--accent-2); }
        .item svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .item[aria-current] .tile { background: #2563eb; border-color: #2563eb; color: #fff; }
        .item:active { background: var(--bg-2); }
        .item:focus { outline: none; }
        .item:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
        .item span:last-child { max-width: 100%; overflow-wrap: anywhere; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        /* Tablets: a floating island instead of a docked bar */
        @media (min-width: 640px) and (max-width: 1024px) {
            .bar { left: 50%; right: auto; bottom: calc(14px + env(safe-area-inset-bottom)); width: min(560px, calc(100% - 32px)); transform: translateX(-50%);
                   padding: 6px; border: 1px solid var(--line); border-radius: 24px; box-shadow: 0 18px 48px rgba(0, 0, 0, .45); }
            :host(.kb) .bar { transform: translate(-50%, calc(100% + 40px)); }
            .sheet { left: 50%; right: auto; width: min(560px, calc(100% - 32px)); bottom: calc(14px + env(safe-area-inset-bottom)); border-bottom: 1px solid var(--line);
                     border-radius: 24px; padding-bottom: 90px; transform: translate(-50%, calc(100% + 40px)); }
            :host(.open) .sheet { transform: translateX(-50%); }
            .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (prefers-reduced-motion: reduce) { .bar, .sheet, .scrim, .ic { transition: none; } }
    `;
    let TAB_SHEET = null;
    try { TAB_SHEET = new CSSStyleSheet(); TAB_SHEET.replaceSync(TAB_CSS); } catch { TAB_SHEET = null; }

    // Single-page apps (Developers) change the URL without a page load: tell the tab bar
    if (!history.__esTabbar) {
        history.__esTabbar = true;
        for (const fn of ['pushState', 'replaceState']) {
            const orig = history[fn];
            history[fn] = function (...args) { const r = orig.apply(this, args); dispatchEvent(new Event('es-locationchange')); return r; };
        }
    }

    class EsTabbar extends HTMLElement {
        connectedCallback() {
            // Live at the end of <body>: never inside a header the app hides on small screens, and the host reserves the bar's space
            if (this.parentNode !== document.body || this.nextElementSibling) {
                const move = () => { if (this.isConnected) document.body.appendChild(this); };
                document.body ? queueMicrotask(move) : addEventListener('DOMContentLoaded', move, { once: true });
                return;
            }
            // One bar per page (Developers renders the tag twice: navbar and mobile sidebar)
            if ([...document.querySelectorAll('es-tabbar')].some((el) => el !== this && el.__ready)) { this.remove(); return; }
            if (this.__ready) return;
            this.__ready = true;
            this.setup();
        }

        setup() {
            this.attachShadow({ mode: 'open' });
            if (TAB_SHEET) this.shadowRoot.adoptedStyleSheets = [TAB_SHEET];

            // Page-level rules: hide the app's own small-screen menu; the browser chrome and safe areas match the bar
            const hide = this.getAttribute('hide');
            if (hide) {
                try {
                    const page = new CSSStyleSheet();
                    page.replaceSync(`@media (max-width: 1024px) { ${hide} { display: none !important; } }`);
                    document.adoptedStyleSheets = [...document.adoptedStyleSheets, page];
                } catch { /* very old browser: the app's own menu stays */ }
            }
            const vp = document.querySelector('meta[name="viewport"]');
            if (vp && !/viewport-fit/.test(vp.content)) vp.content += ', viewport-fit=cover';
            if (!document.querySelector('meta[name="theme-color"]')) {
                const m = document.createElement('meta');
                m.name = 'theme-color'; m.content = '#090b10';
                document.head.appendChild(m);
            }

            this.links = this.collect();
            this.render();

            const update = () => { this.__moved = true; this.markCurrent(); };
            addEventListener('popstate', update);
            addEventListener('es-locationchange', update);
            addEventListener('hashchange', update);
            // The on-screen keyboard: get out of its way while a field has focus
            const field = (el) => el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)
                && !/^(checkbox|radio|button|submit|reset|range|color|file|image)$/.test(el.type));
            document.addEventListener('focusin', (e) => { if (field(e.composedPath()[0])) this.classList.add('kb'); });
            document.addEventListener('focusout', () => setTimeout(() => {
                let a = document.activeElement; while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
                if (!field(a)) this.classList.remove('kb');
            }, 0));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && this.classList.contains('open')) this.toggle(false, true); });
            matchMedia('(max-width: 1024px)').addEventListener?.('change', (e) => { if (!e.matches) this.toggle(false); });
        }

        collect() {
            const seen = new Set();
            const out = [];
            const add = (a, fromSource) => {
                const raw = a.getAttribute('href');
                if (!raw || raw.startsWith('#') || /^javascript:/i.test(raw)) return;
                const url = new URL(raw, location.href);
                const key = url.origin + url.pathname.replace(/\/$/, '') + url.search;
                if (seen.has(key)) return;
                seen.add(key);
                const label = (a.getAttribute('data-label') || a.textContent || a.getAttribute('aria-label') || '').replace(/[↗→]/g, '').replace(/\s+/g, ' ').trim();
                if (!label) return;
                out.push({
                    url, label, icon: a.getAttribute('data-icon') || '', match: a.getAttribute('data-match') || '',
                    target: a.getAttribute('target') || '', rel: a.getAttribute('rel') || '', type: a.getAttribute('type') || '', fromSource,
                    current: a.getAttribute('aria-current') === 'page' || (fromSource && /(^|[\s_-])(active|current)/.test(a.className)),
                });
            };
            this.querySelectorAll(':scope > a').forEach((a) => add(a, false));
            const source = this.getAttribute('source');
            if (source) document.querySelectorAll(source).forEach((root) => root.querySelectorAll('a[href]').forEach((a) => add(a, true)));
            return out;
        }

        render() {
            const moreBtn = this.getAttribute('more-button');
            const all = this.links;
            const own = all.filter((l) => !l.fromSource);
            // Up to five tabs; otherwise four and More. Links from `source` always go under More.
            const fits = !moreBtn && all.length <= 5 && all.length === own.length;
            const tabs = fits ? all : own.slice(0, 4);
            this.rest = fits ? [] : all.filter((l) => !tabs.includes(l));
            this.hasMore = !!moreBtn || this.rest.length > 0;

            const a = (l, cls, inner) => `<a class="${cls}" href="${esc(l.url.href)}"${l.target ? ` target="${esc(l.target)}"` : ''}`
                + `${l.target === '_blank' ? ` rel="${esc(l.rel || 'noopener')}"` : ''}${l.type ? ` type="${esc(l.type)}"` : ''}>${inner}`
                + `${l.target === '_blank' ? '<span class="sr">(opens in a new tab)</span>' : ''}</a>`;
            this.shadowRoot.innerHTML = `${TAB_SHEET ? '' : `<style>${TAB_CSS}</style>`}
                <div class="scrim" part="scrim"></div>
                ${this.rest.length && !moreBtn ? `<div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-label="More">
                    <span class="grab" aria-hidden="true"></span>
                    <p class="kicker">More</p>
                    <div class="grid">${this.rest.map((l) => a(l, 'item', `<span class="tile">${tabIcon(l.icon, l.label)}</span><span>${esc(l.label)}</span>`)).join('')}</div>
                </div>` : ''}
                <nav class="bar" aria-label="Main">
                    ${tabs.map((l) => a(l, 'tab', `<span class="ic">${tabIcon(l.icon, l.label)}</span><span class="lb">${esc(l.label)}</span>`)).join('')}
                    ${this.hasMore ? `<button class="tab more" type="button" aria-expanded="false"${moreBtn ? '' : ' aria-controls="sheet" aria-haspopup="dialog"'}>
                        <span class="ic">${tabIcon('more', '')}</span><span class="lb">More</span></button>` : ''}
                </nav>`;
            this.tabs = tabs;

            const root = this.shadowRoot;
            root.querySelector('.more')?.addEventListener('click', () => {
                if (moreBtn) { document.querySelector(moreBtn)?.click(); return; }
                this.toggle(!this.classList.contains('open'), true);
            });
            root.querySelector('.scrim').addEventListener('click', () => this.toggle(false));
            root.querySelectorAll('.item').forEach((el) => el.addEventListener('click', () => this.toggle(false)));
            this.markCurrent();
        }

        toggle(open, focus) {
            if (!this.shadowRoot.querySelector('.sheet')) return;
            this.classList.toggle('open', open);
            const more = this.shadowRoot.querySelector('.more');
            more?.setAttribute('aria-expanded', String(open));
            if (open && focus) this.shadowRoot.querySelector('.item')?.focus();
            else if (!open && focus) more?.focus();
        }

        markCurrent() {
            const here = location.pathname.replace(/\/$/, '') || '/';
            const score = (l) => {
                if (l.url.origin !== location.origin) return -1;
                if (l.match) return here.startsWith(l.match.replace(/\/$/, '')) ? l.match.length : -1;
                const p = l.url.pathname.replace(/\/$/, '') || '/';
                if (p === here) return p.length + 1;
                return p !== '/' && here.startsWith(`${p}/`) ? p.length : -1;
            };
            // On load the page says which link is current; after a client-side navigation (or with no hint) the longest matching path wins
            let current = this.__moved ? null : (this.links.find((l) => l.current && !l.fromSource) || this.links.find((l) => l.current));
            if (!current) {
                let best = -1;
                for (const l of this.links) { const s = score(l); if (s > best) { best = s; current = l; } }
            }
            const i = this.links.indexOf(current);
            this.shadowRoot.querySelectorAll('.tab[href], .item').forEach((el) => el.removeAttribute('aria-current'));
            const els = [...this.shadowRoot.querySelectorAll('.tab[href]'), ...this.shadowRoot.querySelectorAll('.item')];
            const order = [...this.tabs, ...this.rest];
            const j = order.indexOf(this.links[i]);
            if (j >= 0) els[j].setAttribute('aria-current', 'page');
            this.shadowRoot.querySelector('.more')?.toggleAttribute('data-current', j >= this.tabs.length);
        }
    }

    customElements.define('es-tabbar', EsTabbar);

    // Page loading bar: a thin ES-blue bar across the top while a page loads, and from the moment a link is
    // clicked or a form is sent until the next page shows. Styled through the CSSOM, so app CSPs allow it.
    const loader = (() => {
        const bar = document.createElement('div');
        bar.setAttribute('aria-hidden', 'true');
        Object.assign(bar.style, {
            position: 'fixed', top: '0', left: '0', right: '0', height: '3px', zIndex: '2147483647', pointerEvents: 'none',
            background: '#3b82f6', boxShadow: '0 0 10px rgba(59, 130, 246, .7)',
            transformOrigin: 'left', transform: 'scaleX(0)', opacity: '0', transition: 'transform .3s ease, opacity .3s ease',
        });
        let timer = 0, safety = 0, p = 0;
        const set = (v) => { p = v; bar.style.transform = `scaleX(${v})`; };
        const start = () => {
            if (!bar.isConnected) document.documentElement.appendChild(bar);
            clearInterval(timer); clearTimeout(safety);
            bar.style.transition = 'none'; set(0.04); bar.style.opacity = '1';
            void bar.offsetWidth; bar.style.transition = 'transform .3s ease, opacity .3s ease';
            set(0.2);
            // creep towards 90% so it never looks finished before it is
            timer = setInterval(() => set(p + (0.9 - p) * 0.08), 200);
            safety = setTimeout(done, 15000);
        };
        const done = () => {
            if (bar.style.opacity === '0') return;
            clearInterval(timer); clearTimeout(safety);
            set(1);
            setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => set(0), 300); }, 200);
        };
        return { start, done };
    })();
    if (document.readyState !== 'complete') {
        loader.start();
        addEventListener('load', loader.done, { once: true });
    }
    document.addEventListener('click', (e) => {
        // composedPath: links inside <es-tabbar>'s shadow root count too
        const t = e.composedPath ? e.composedPath()[0] : e.target;
        const a = t && t.closest && t.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return;
        const url = new URL(a.href, location.href);
        if (!/^https?:$/.test(url.protocol)) return;
        if (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search && url.hash) return;
        // let the app handle the click first; client-side routers (Developers) call preventDefault
        setTimeout(() => { if (!e.defaultPrevented) loader.start(); }, 0);
    });
    document.addEventListener('submit', (e) => { setTimeout(() => { if (!e.defaultPrevented) loader.start(); }, 0); });
    addEventListener('pageshow', loader.done);
    addEventListener('popstate', loader.done);
})();
