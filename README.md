# ES Blog

<table>
  <tr>
    <th align="center"><a href="https://github.com/danpros/htmly">HTMLy</a> (original)</th>
    <th align="center">ES Blog (ours)</th>
  </tr>
  <tr>
    <td><img src="docs/readme/original.jpg" alt="HTMLy original" width="420"></td>
    <td><img src="docs/readme/es.jpg" alt="ES Blog" width="420"></td>
  </tr>
  <tr>
    <td colspan="2" align="center"><img src="docs/readme/compare.gif" alt="Slider comparing HTMLy with ES Blog" width="840"><br>
    <sub>Before/after slider: HTMLy on the left, ES Blog on the right. Original image: HTMLy default (Tailwind) theme screenshot, from the HTMLy repository (HTMLy is GPL-2.0 licensed).</sub></td>
  </tr>
</table>

A self-hosted blog and publishing workspace built on Symfony 7.4 LTS. Part of the ES apps alongside
[ES Portfolio](https://github.com/edilsonsantos-co-za/es-portfolio), ES Store (ES Marketplace) and ES Journal.
Source: [edilsonsantos-co-za/es-blog](https://github.com/edilsonsantos-co-za/es-blog).

> ES Blog started as a fork of [HTMLy](https://github.com/danpros/htmly) by
> [Danang Probo Sayekti (danpros)](https://github.com/danpros), licensed under GPL-2.0 (or later).
> [Version 1](https://github.com/edilsonsantos-co-za/edfolio-blog) (archived) kept HTMLy's codebase and moved
> storage to MySQL. Version 2, this repository, rewrites the application on Symfony and Doctrine while
> keeping HTMLy's content model: posts, pages, categories, tags, menus and authors. Credit for the original
> design goes to HTMLy; its licence and copyright files are preserved in `licenses/`.

## ES Blog vs HTMLy

This compares upstream HTMLy's current implementation with ES Blog's code and development history, not just
HTMLy's early releases. Search, RSS, roles, MFA and scheduled posts already exist upstream; their implementations
changed here rather than being invented by ES Blog.

| Area | HTMLy (original) | ES Blog |
|---|---|---|
| Storage | Databaseless: content files, INI user/config files and file-based indexes/cache | MySQL storage introduced in v1; Doctrine ORM entities and versioned schema migrations in v2 |
| Application framework | Procedural PHP routing and PHP view files | Symfony 7.4 LTS rewrite with controllers, services, dependency injection and Twig templates |
| Roles and permissions | Admin/editor roles and per-author ownership checks | Admin/editor/author hierarchy with Symfony voters for posts, users and configuration; publishing restricted to editors/admins |
| Admin workspace | Built-in dashboard, content editor, users, menus and configuration | Rewritten admin with an Atlas bento dashboard, linked status counts, most-read post, content management, nested menus and tabbed automation settings |
| Two-factor authentication | Google2FA-based MFA with an authenticator secret per user | scheb/2fa TOTP configuration and profile enable/disable screens; the active sign-in flow is SSO-only |
| Legacy passwords | Local password authentication and automatic hash upgrades | Legacy v1 SHA-512 password hasher and bcrypt migration support retained; no public password-login form in the current SSO-only setup |
| Single sign-on | Local username/password login, with optional MFA | DataSparkLab Logto integration using OpenID Connect authorization code + PKCE; verified email must match an existing blog account |
| Shared ES app sessions | Independent blog login/logout | Shared Logto sign-in across ES apps and parent-domain sign-out marker: signing out in another ES app ends the blog session on its next request |
| ES apps navigation | Blog navigation and user links | ES apps launcher and account menu in the public masthead and admin, linking ES Portfolio, ES Blog, ES Store and ES Journal |
| Atlas design | Selectable PHP themes | Built-in Atlas design coordinated with ES Portfolio; matching palette and typography for public, admin and auth templates |
| Colour mode | Theme-specific styling | Core Atlas and admin/auth styling are dark-only; the theme-mode toggle has been removed |
| Editorial homepage | Theme-defined post listings | Flat editorial bento: accent masthead, newest post lead, next two stories, topic pills, post count and RSS tile; deterministic generative covers when images are missing |
| Theme installation | Select themes already present under `themes/` | Admin marketplace picker or uploaded ZIP; SHA-256 file checks, Ed25519 signatures trusted through `config/theme-keys/`, app/version checks and installation under `var/themes/<id>/`; Atlas fallback and core-only admin templates |
| Search | Keyword search using a file-backed `content/data/search.json` index | Published-post search through Doctrine using SQL `LIKE` over title, body and description, exposed at `/search?q=...` |
| RSS | Built-in RSS, including author feeds | RSS 2.0 feed rebuilt at `/feed`, listing the latest 20 published posts with dated permalinks and an Atom self-link |
| Scheduled publishing | Scheduled posts published during application requests | Console publishing command and Messenger handler; worker runs the scheduler every five minutes between queue-consumer sessions |
| Background jobs | Request-driven publishing; no Symfony Messenger worker | Separate worker for scheduled publishing, LinkedIn shares, welcome mail, thumbnails and cache cleanup; Doctrine queue with retries and failed transport |
| Feed automation | Blog RSS/OPML output and content import tools | Feeder.co OPML subscription import, scheduled RSS/Atom fetching, topic scoring and review-first drafts; optional Claude drafting with attribution and GD-generated banners |
| LinkedIn publishing | Configurable LinkedIn social-profile link | OAuth profile connection, encrypted tokens, expiry display and queued one-time sharing when a post becomes published |
| Docker and deployment | ZIP/online installer on a PHP web server | PHP 8.4/Apache image, local and production Compose stacks on shared `milkyway` services, persistent uploads/themes, startup migrations/queue setup/cache warmup and static-only uploads/assets |
| CI and releases | Upstream source distribution and releases | GitHub Actions SSH deployment on every push to `main`, building on the VPS; `VERSION`-checked tag workflow generates GitHub Releases (tests are run separately) |
| Automated tests | No root PHPUnit suite in the inspected upstream tree | PHPUnit service tests for theme signatures/installed-theme resolution, feed parsing, generated banners, LinkedIn token/client behavior and application versioning; Twig/container lint commands for development |

Upstream references: [README](https://github.com/danpros/htmly/blob/master/README.md),
[routes](https://github.com/danpros/htmly/blob/master/system/htmly.php),
[content/search/scheduling functions](https://github.com/danpros/htmly/blob/master/system/includes/functions.php),
[MFA settings](https://github.com/danpros/htmly/blob/master/system/admin/views/edit-mfa.html.php) and
[theme picker](https://github.com/danpros/htmly/blob/master/system/admin/views/theme.html.php).
ES Blog's implementations live in `src/`, `templates/`, `tests/`, `docker/` and `.github/workflows/`;
[its commit history](https://github.com/edilsonsantos-co-za/es-blog/commits/main/) records the changes.

More themes install under **Admin → Settings → Themes** without manual unzipping, file copying or cache clearing.
Production persists `var/themes/` in a volume. `THEME_STORE_URL` sets the store; `THEMES_ALLOW_UNSIGNED=1` bypasses
signature enforcement (not recommended: templates are code). Admin pages always use core templates; an unknown
or removed public theme falls back to Atlas.

## Quick start

Requirements: Docker with Compose.

```bash
cp .env.example .env.local        # set APP_SECRET, DATABASE_URL and LOGTO_* for your environment
docker compose up -d --build     # the entrypoint runs migrations and warms the cache
docker exec -it edfolio-v2-php php bin/console app:user:create admin you@example.com --role=admin
```

`app:user:create` asks for the password when `--password` is left out. Sign in at `/login`, which redirects
to Logto; the SSO account must have the same verified email as the blog user. Site settings live under
**Admin → Configuration**. Without Logto configuration, sign-in returns 503 rather than offering a password form.

The compose files publish no ports. The container joins an external Docker network and is meant to sit behind a
reverse proxy. See [docs/deployment.md](docs/deployment.md).

## Configuration

| Variable | Purpose |
|---|---|
| `APP_SECRET`, `DATABASE_URL` | Symfony secret and MySQL connection |
| `SITE_NAME`, `SITE_TITLE`, `SITE_URL` | Site name, authenticator label and URL defaults; public title/URL can be edited in the admin |
| `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN` | Mail and queue transports |
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `LINKEDIN_REDIRECT_URI` | LinkedIn sharing. The redirect URI is `<site>/admin/linkedin/callback`. Optional `LINKEDIN_API_VERSION` (default `202608`). |
| `ANTHROPIC_API_KEY`, `AUTOMATION_AI_MODEL` | Feed automation drafting with Claude (default `claude-opus-5-5`). Without a key, drafts are skeletons to fill in. |
| `LOGTO_ENDPOINT`, `LOGTO_APP_ID`, `LOGTO_APP_SECRET` | Required for sign-in through Logto; set all three. |
| `LOGTO_INTERNAL_ENDPOINT` | Optional server-to-server Logto URL, for example a Docker service name |

To set up SSO, create a **Traditional web** application in Logto with the redirect URI `<site>/login/sso/callback`
and the post sign-out redirect URI `<site>/` (also allow `<site>/login` for failed sign-in recovery). Use
`DEFAULT_URI` for the registered public base URL. Only users who already exist in the blog can sign in: the
verified email must match their account. Production uses the shared DataSparkLab Logto service; signing out
through the ES apps menu also invalidates the other ES app sessions on their next request.

## Feed automation

**Admin → Maintenance → Feed Automation**

1. In Feeder.co, open **Library → Import & Export → Export to OPML** and upload the file (or add feeds one by one).
   Feeder has no public API, so re-import when you follow new feeds; feeds already in the list are skipped.
2. Set the topics, how often to run, how many posts per run, and whether new posts are drafts (the default) or
   published straight away. Turn on **Run on a schedule**.
3. **Run automation** reads the feeds now. Each item in the list can also be turned into a draft on its own.

Each run reads every enabled feed, scores new articles (a topic in the title scores 2, in the summary 1), and writes the
best ones. Claude reads the source article and writes an original post that credits it, a meta description, tags and
the LinkedIn text; the blog draws a 1200×630 banner with GD (fonts in `resources/fonts`, OFL). Runs are logged on the
page. The same thing from the console:

```bash
php bin/console app:feeds:import feeder.opml
php bin/console app:automation:run --posts=1     # --posts=0 only reads feeds
```

The `worker` container runs `app:scheduler:tick` every five minutes (scheduled posts plus the automation when it is
due) between `messenger:consume` sessions. Without it, scheduled posts and LinkedIn shares never happen.

## LinkedIn

Create an app in the LinkedIn Developer Portal, add the **Share on LinkedIn** and **Sign In with LinkedIn using
OpenID Connect** products (both self-serve), add the redirect URL `<site>/admin/linkedin/callback`, and set the three
`LINKEDIN_*` variables. Then use **Connect LinkedIn** in the admin. Tokens are encrypted with a key derived from
`APP_SECRET` and last 60 days; the page shows the expiry date. Posts are shared once, when they become published.

## Development

```bash
docker exec edfolio-v2-php php vendor/bin/phpunit
docker exec edfolio-v2-php php bin/console lint:twig templates
docker exec edfolio-v2-php php bin/console lint:container
```

Versioning is described in [docs/versioning.md](docs/versioning.md).

## Credits and licences

- Based on [HTMLy](https://github.com/danpros/htmly) by danpros. HTMLy is GPL-2.0 (or later), and its licence and copyright
  files are kept in `licenses/`.
