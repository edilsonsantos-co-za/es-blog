# EdFolio Blog

A self-hosted blog and publishing workspace built on Symfony 7.4 LTS.

> EdFolio Blog started as a fork of [HTMLy](https://github.com/danpros/htmly) by [Danang Probo Sayekti (danpros)](https://github.com/danpros).
> Version 1 kept HTMLy's codebase and moved its storage to MySQL. Version 2, this repository, rewrites the
> application on Symfony and Doctrine while keeping HTMLy's content model (posts, pages, categories, tags,
> menus and authors). Credit for the original design goes to the HTMLy project.

## What this version adds

**A Symfony rewrite**
- Symfony 7.4 LTS, Doctrine ORM and migrations, Twig and Symfony Messenger
- Role-based access with admin, editor and author roles, enforced by voters
- Optional TOTP two-factor sign-in (scheb/2fa), plus remember-me
- Passwords imported from v1 (SHA-512) are upgraded to bcrypt on the next sign-in
- Scheduled publishing through a console command and Messenger
- Single sign-on through [Logto](https://logto.io) (OpenID Connect, authorization code + PKCE). It is optional and works alongside passwords.

**Publishing**
- Markdown posts and pages with categories, tags, cover images and reading time
- Year and month archives, tag, category and author pages, search, and an RSS feed
- Nested navigation menus managed from the admin
- LinkedIn sharing: connect your profile once and every published post is shared with its banner and a link card
- Feed automation: import your feeds from Feeder.co (OPML), and the blog reads them on a schedule, picks the articles
  that match your topics and writes posts about them with a generated banner (drafts for review by default)

**Design**
- One built-in design, Atlas, shared with the EdFolio portfolio: a masthead, a cover-deck hero of the latest posts,
  dated post rows, and light and dark modes. The admin and sign-in pages use the same palette and type.
- More themes install from the ES Marketplace under Admin → Settings → Themes: pick one from the store or upload a
  downloaded package. No unzipping, file copying or cache clearing. Packages must be signed by a key in
  `config/theme-keys/` (each file is checked against the signed manifest) and land in `var/themes/<id>/`, which is
  a volume in production so themes survive redeploys. Admin pages always use the core templates, and an unknown or
  removed theme falls back to Atlas. `THEME_STORE_URL` sets the store; `THEMES_ALLOW_UNSIGNED=1` allows unsigned
  packages (not recommended: templates are code).

**Operations**
- Docker image (PHP 8.4 with Apache) and a production compose file. The container runs migrations and warms the cache on start.
- A worker container that runs queued jobs and the scheduler (scheduled posts, feed automation, LinkedIn shares)
- No public sign-up: the first admin is created from the console (see below)
- Static assets and uploads are static only. Apache refuses to run scripts from them.

## Quick start

Requirements: Docker with Compose.

```bash
cp .env.example .env.local        # then set APP_SECRET and DATABASE_URL
docker compose up -d --build     # the entrypoint runs migrations and warms the cache
docker exec -it edfolio-v2-php php bin/console app:user:create admin you@example.com --role=admin
```

`app:user:create` asks for the password when `--password` is left out. Sign in at `/login`; site settings live
under **Admin → Configuration**.

The compose files publish no ports. The container joins an external Docker network and is meant to sit behind a
reverse proxy. See [docs/deployment.md](docs/deployment.md).

## Configuration

| Variable | Purpose |
|---|---|
| `APP_SECRET`, `DATABASE_URL` | Symfony secret and MySQL connection |
| `SITE_NAME`, `SITE_URL` | Defaults for the site title and URL (both can be edited in the admin) |
| `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN` | Mail and queue transports |
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `LINKEDIN_REDIRECT_URI` | LinkedIn sharing. The redirect URI is `<site>/admin/linkedin/callback`. Optional `LINKEDIN_API_VERSION` (default `202608`). |
| `ANTHROPIC_API_KEY`, `AUTOMATION_AI_MODEL` | Feed automation drafting with Claude (default `claude-opus-5-5`). Without a key, drafts are skeletons to fill in. |
| `LOGTO_ENDPOINT`, `LOGTO_APP_ID`, `LOGTO_APP_SECRET` | Single sign-on. Setting all three enables it. |
| `LOGTO_INTERNAL_ENDPOINT` | Optional server-to-server Logto URL, for example a Docker service name |

To set up SSO, create a **Traditional web** application in Logto with the redirect URI `<site>/login/sso/callback`
and the post sign-out redirect URI `<site>/`. Only users who already exist in the blog can sign in: the verified
email must match their account.

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

- Based on [HTMLy](https://github.com/danpros/htmly) by danpros. HTMLy is GPL-2.0, and its licence and copyright
  files are kept in `licenses/`.
