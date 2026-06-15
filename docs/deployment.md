# Deployment

This project is designed to sit behind the shared Nginx Proxy Manager stack from `~/workspace/datasparklab/core-reverse-proxy-webapp`.

## Network and ports

- The application container joins the external `milkyway` Docker network.
- The compose files do not publish or expose ports.
- Configure Nginx Proxy Manager to forward to `edfolio-v2-php` on port `80` over the `milkyway` network.

Because Nginx Proxy Manager and this application share the same Docker network, it can reach Apache inside the container directly without a host-level port mapping.

## First admin user

This project does not expose public self-registration. Create the first admin user from the running application container:

```bash
docker exec -it edfolio-v2-php php bin/console app:user:create admin admin@blog.edilsonsantos.local --role=admin --env=prod
```

The command prompts for the password securely when `--password` is omitted. After the first admin can sign in, additional users can be managed from the admin area.

## Theme readiness

- Theme choices shown in Admin → Configuration are driven by `config/themes.yaml`.
- Keep `default` selected unless a manually ported theme has Twig templates, public assets, and a catalog entry.
- Add manually ported public themes under the `public:` section with `slug`, `name`, `status`, `template_path`, `asset_path`, and `description`.
- Use `status: placeholder` for themes that are documented but not ready for go-live selection, and `status: active` only after local Twig/container validation passes.
