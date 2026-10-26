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
- A LinkedIn integration for sharing posts

**Design**
- One built-in design, Atlas, shared with the EdFolio portfolio: a masthead, a featured-post hero, dated post rows,
  and light and dark modes. The admin and sign-in pages use the same palette and type.
- Older third-party themes were moved out to a separate archive repository (`edfolio-blog-themes`).

**Operations**
- Docker image (PHP 8.4 with Apache) and a production compose file. The container runs migrations and warms the cache on start.
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
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `LINKEDIN_REDIRECT_URI` | LinkedIn sharing |
| `LOGTO_ENDPOINT`, `LOGTO_APP_ID`, `LOGTO_APP_SECRET` | Single sign-on. Setting all three enables it. |
| `LOGTO_INTERNAL_ENDPOINT` | Optional server-to-server Logto URL, for example a Docker service name |

To set up SSO, create a **Traditional web** application in Logto with the redirect URI `<site>/login/sso/callback`
and the post sign-out redirect URI `<site>/`. Only users who already exist in the blog can sign in: the verified
email must match their account.

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
