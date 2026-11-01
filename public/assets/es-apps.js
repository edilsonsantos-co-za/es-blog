/*!
 * <es-apps>: the ES app launcher and account menu, shared by every ES app (portfolio, blog, journal, store).
 * Source of truth: es-marketplace/store/assets/es-apps.js. Apps ship a copy of this file (their CSP only
 * allows their own scripts); update it here and copy it over.
 *
 * <es-apps current="blog"                          which app this is (portfolio|blog|journal|store)
 *          signed-in                               the SSO user is signed in here (shows the private apps)
 *          user-name="…" user-email="…"            the account menu (shown whenever signed-in; "Sign in" otherwise)
 *          avatar="https://…"                      profile picture (Gravatar default); initials when missing
 *          sign-in-url="/login"                    where "Sign in" goes
 *          sign-out-url="/logout"                  where "Sign out" goes
 *          sign-out-method="post" csrf-name="_token" csrf-token="…"   for apps that sign out with a POST
 *          manage-url="/admin/profile"             optional "Your profile" link
 *          dashboard-url="/admin">                 optional "Dashboard" link (opens in a new tab)
 *
 * Signing out here also writes the shared `es-signout` cookie, so the other ES apps sign out too.
 * Signed in here, it publishes who you are (display only) in the shared `es-user` cookie, so apps where
 * you have no session of their own show your account instead of "Sign in".
 * On *.edilsonsantos.local the launcher links the local instances.
 * </es-apps>
 */
(() => {
    if (customElements.get('es-apps')) {
        return;
    }

    // Local instances (edilsonsantos.local, blog.edilsonsantos.local, …) link to each other, not to production
    const LOCAL = /(^|\.)edilsonsantos\.local$/.test(location.hostname);
    const site = (sub) => LOCAL ? `${location.protocol}//${sub}edilsonsantos.local/` : `https://${sub}edilsonsantos.co.za/`;
    const APPS = [
        { id: 'portfolio', name: 'Portfolio', url: site(''), icon: 'M4 5h16v11H4z M8 20h8 M12 16v4' },
        { id: 'blog', name: 'Blog', url: site('blog.'), icon: 'M5 4h10l4 4v12H5z M14 4v5h5 M8 13h8 M8 17h5' },
        { id: 'developers', name: 'Developers', url: site('developers.'), icon: 'M8 7l-5 5 5 5 M16 7l5 5-5 5 M14 4l-4 16' },
        { id: 'store', name: 'Store', url: site('store.'), icon: 'M4 7h16l-1.5 12h-13z M8 7a4 4 0 0 1 8 0' },
        { id: 'journal', name: 'Journal', url: site('journal.'), private: true, icon: 'M6 3h11a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6z M9 3v18 M12 8h4 M12 12h4' },
    ];

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const initials = (name, email) => (name || email || '?').trim().split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
    const icon = (d) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>`;

    const CSS = `
        :host { --bg: #121620; --bg-2: #181d29; --text: #e8edf6; --muted: #8f9ab0; --line: #232a38; --accent: #3b82f6; --shadow: 0 18px 48px rgba(0, 0, 0, .5);
                display: inline-flex; align-items: center; gap: 6px; font: 600 14px/1.4 Manrope, system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--text); position: relative; }
        button { font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
        .trigger { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; color: var(--muted); }
        .trigger:hover, .trigger[aria-expanded="true"] { background: var(--bg-2); color: var(--text); }
        .trigger:focus-visible, a:focus-visible, .menu button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
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
        .signin { padding: 8px 14px; border-radius: 12px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 700; white-space: nowrap; }
        form { margin: 0; }
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

    let SHEET = null;
    try { SHEET = new CSSStyleSheet(); SHEET.replaceSync(CSS); } catch { SHEET = null; }

    class EsApps extends HTMLElement {
        static get observedAttributes() { return ['current', 'signed-in', 'user-name', 'user-email', 'avatar', 'dashboard-url']; }

        constructor() {
            super();
            this.attachShadow({ mode: 'open' });
            // A constructed stylesheet works under a strict CSP (no 'unsafe-inline' for styles)
            if (SHEET) this.shadowRoot.adoptedStyleSheets = [SHEET];
            this.onDocClick = (e) => { if (!this.contains(e.target) && !e.composedPath().includes(this)) this.close(); };
            this.onKey = (e) => { if (e.key === 'Escape') this.close(true); };
            // Signed in or out in another tab or app meanwhile: show it when you come back
            this.onVisible = () => { if (document.visibilityState === 'visible' && !this.hasAttribute('signed-in')) this.render(); };
        }

        connectedCallback() {
            document.addEventListener('click', this.onDocClick);
            document.addEventListener('keydown', this.onKey);
            document.addEventListener('visibilitychange', this.onVisible);
            this.render();
        }

        disconnectedCallback() {
            document.removeEventListener('click', this.onDocClick);
            document.removeEventListener('keydown', this.onKey);
            document.removeEventListener('visibilitychange', this.onVisible);
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
                return { name: this.getAttribute('user-name') || '', email: this.getAttribute('user-email') || '', avatar: this.getAttribute('avatar') || '', local: true };
            }
            const shared = sharedUser();
            return shared ? { name: shared.n || '', email: shared.e || '', avatar: shared.a || '', local: false } : null;
        }

        publish(who) {
            if (!who?.local || !(who.name || who.email)) return;
            const value = encodeUser({ n: who.name, e: who.email, a: who.avatar });
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
            const apps = APPS.filter((a) => !a.private || signedIn);

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
                    <div class="menu">${dashboard ? `<a href="${esc(dashboard)}" target="_blank" rel="noopener">Dashboard</a>` : ''}${manage ? `<a href="${esc(manage)}">Your profile</a>` : ''}${signOutItem}</div>
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
                const panel = this.shadowRoot.getElementById(t.dataset.for);
                const open = panel.hidden;
                this.close();
                panel.hidden = !open;
                t.setAttribute('aria-expanded', String(open));
                if (open) panel.querySelector('a, button')?.focus();
            }));
        }

        close(refocus) {
            const opener = this.shadowRoot.querySelector('.trigger[aria-expanded="true"]');
            this.shadowRoot.querySelectorAll('.panel').forEach((p) => { p.hidden = true; });
            this.shadowRoot.querySelectorAll('.trigger').forEach((t) => t.setAttribute('aria-expanded', 'false'));
            if (refocus) opener?.focus();
        }
    }

    customElements.define('es-apps', EsApps);
})();
