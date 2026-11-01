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
 *          manage-url="/admin/profile">            optional "Your profile" link
 * </es-apps>
 */
(() => {
    if (customElements.get('es-apps')) {
        return;
    }

    const APPS = [
        { id: 'portfolio', name: 'Portfolio', url: 'https://edilsonsantos.co.za/', icon: 'M4 5h16v11H4z M8 20h8 M12 16v4' },
        { id: 'blog', name: 'Blog', url: 'https://blog.edilsonsantos.co.za/', icon: 'M5 4h10l4 4v12H5z M14 4v5h5 M8 13h8 M8 17h5' },
        { id: 'store', name: 'Store', url: 'https://store.edilsonsantos.co.za/', icon: 'M4 7h16l-1.5 12h-13z M8 7a4 4 0 0 1 8 0' },
        { id: 'journal', name: 'Journal', url: 'https://journal.edilsonsantos.co.za/', private: true, icon: 'M6 3h11a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6z M9 3v18 M12 8h4 M12 12h4' },
    ];

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const initials = (name, email) => (name || email || '?').trim().split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
    const icon = (d) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>`;

    const CSS = `
        :host { --bg: #ffffff; --bg-2: #f5f7fb; --text: #0f172a; --muted: #5b6478; --line: #dde3ee; --accent: #2563eb; --shadow: 0 18px 48px rgba(15, 23, 42, .16);
                display: inline-flex; align-items: center; gap: 6px; font: 600 14px/1.4 Manrope, system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--text); position: relative; }
        :host([data-dark]) { --bg: #121620; --bg-2: #181d29; --text: #e8edf6; --muted: #8f9ab0; --line: #232a38; --accent: #3b82f6; --shadow: 0 18px 48px rgba(0, 0, 0, .5); }
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
        .signin { padding: 8px 14px; border-radius: 12px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 700; }
        form { margin: 0; }
        @media (max-width: 480px) { .panel { position: fixed; top: 64px; left: 12px; right: 12px; min-width: 0; } }
    `;

    let SHEET = null;
    try { SHEET = new CSSStyleSheet(); SHEET.replaceSync(CSS); } catch { SHEET = null; }

    class EsApps extends HTMLElement {
        static get observedAttributes() { return ['current', 'signed-in', 'user-name', 'user-email', 'avatar']; }

        constructor() {
            super();
            this.attachShadow({ mode: 'open' });
            // A constructed stylesheet works under a strict CSP (no 'unsafe-inline' for styles)
            if (SHEET) this.shadowRoot.adoptedStyleSheets = [SHEET];
            this.onDocClick = (e) => { if (!this.contains(e.target) && !e.composedPath().includes(this)) this.close(); };
            this.onKey = (e) => { if (e.key === 'Escape') this.close(true); };
        }

        connectedCallback() {
            this.syncTheme();
            this.themeWatch = new MutationObserver(() => this.syncTheme());
            this.themeWatch.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme'] });
            matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => this.syncTheme());
            document.addEventListener('click', this.onDocClick);
            document.addEventListener('keydown', this.onKey);
            this.render();
        }

        disconnectedCallback() {
            this.themeWatch?.disconnect();
            document.removeEventListener('click', this.onDocClick);
            document.removeEventListener('keydown', this.onKey);
        }

        attributeChangedCallback() { if (this.shadowRoot.childElementCount) this.render(); }

        syncTheme() {
            const root = document.documentElement;
            const explicit = root.getAttribute('data-theme') || root.getAttribute('data-bs-theme');
            const dark = root.classList.contains('dark-mode') || root.classList.contains('dark') || explicit === 'dark'
                || (!explicit && !root.classList.contains('light-mode') && matchMedia('(prefers-color-scheme: dark)').matches && !root.classList.length);
            this.toggleAttribute('data-dark', dark);
        }

        avatarHtml(large) {
            const name = this.getAttribute('user-name') || '';
            const email = this.getAttribute('user-email') || '';
            const src = this.getAttribute('avatar');
            const cls = large ? 'avatar lg' : 'avatar';
            const fallback = `<span class="${cls}" aria-hidden="true">${esc(initials(name, email))}</span>`;
            return src ? `<img class="${cls}" src="${esc(src)}" alt="" referrerpolicy="no-referrer" data-fallback='${esc(fallback)}'>` : fallback;
        }

        render() {
            const current = this.getAttribute('current') || '';
            const signedIn = this.hasAttribute('signed-in');
            const name = this.getAttribute('user-name') || '';
            const email = this.getAttribute('user-email') || '';
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
            const signOutItem = !signOut ? '' : method === 'post'
                ? `<form method="post" action="${esc(signOut)}"><input type="hidden" name="${esc(this.getAttribute('csrf-name') || '_token')}" value="${esc(this.getAttribute('csrf-token') || '')}"><button type="submit">Sign out</button></form>`
                : `<a href="${esc(signOut)}">Sign out</a>`;
            const manage = this.getAttribute('manage-url');

            const account = hasUser ? `
                <button class="trigger" part="account" aria-label="Account: ${esc(label)}" aria-haspopup="true" aria-expanded="false" data-for="account">${this.avatarHtml(false)}</button>
                <div class="panel" id="account" role="menu" hidden>
                    <div class="who">${this.avatarHtml(true)}<strong>${esc(label)}</strong>${name && email ? `<span>${esc(email)}</span>` : ''}<span class="sso">Signed in with DataSparkLab SSO</span></div>
                    <div class="menu">${manage ? `<a href="${esc(manage)}">Your profile</a>` : ''}${signOutItem}</div>
                </div>`
                : (this.getAttribute('sign-in-url') ? `<a class="signin" href="${esc(this.getAttribute('sign-in-url'))}">Sign in</a>` : '');

            this.shadowRoot.innerHTML = `${SHEET ? '' : `<style>${CSS}</style>`}
                <button class="trigger" part="launcher" aria-label="ES apps" aria-haspopup="true" aria-expanded="false" data-for="apps">${icon('M5 5h3v3H5zM10.5 5h3v3h-3zM16 5h3v3h-3zM5 10.5h3v3H5zM10.5 10.5h3v3h-3zM16 10.5h3v3h-3zM5 16h3v3H5zM10.5 16h3v3h-3zM16 16h3v3h-3z')}</button>
                <div class="panel" id="apps" role="menu" hidden><p class="kicker">Edilson Santos apps</p><div class="grid">${appGrid}</div></div>
                ${account}`;

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
