# Deployment

This project is designed to sit behind the shared Nginx Proxy Manager stack from `~/workspace/datasparklab/core-reverse-proxy-webapp`.

## Network and ports

- The application container joins the external `milkyway` Docker network.
- The compose files do not publish or expose ports.
- Configure Nginx Proxy Manager to forward to `edfolio-v2-php` on port `80` over the `milkyway` network.
- `edfolio-v2-worker` runs from the same image with no web server: queued jobs and the scheduler. It needs no proxy entry.
- Uploads (post images and generated banners) live on the `uploads` volume shared by both containers.

Because Nginx Proxy Manager and this application share the same Docker network, it can reach Apache inside the container directly without a host-level port mapping.

## First admin user

This project does not expose public self-registration. Create the first admin user from the running application container:

```bash
docker exec -it edfolio-v2-php php bin/console app:user:create admin admin@blog.edilsonsantos.local --role=admin --env=prod
```

The command prompts for the password securely when `--password` is omitted. After the first admin can sign in, additional users can be managed from the admin area.

