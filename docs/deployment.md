# Deployment

This project is designed to sit behind the shared Nginx Proxy Manager stack from `~/workspace/datasparklab/core-reverse-proxy-webapp`.

## Network and ports

- The application container joins the external `milkyway` Docker network.
- The compose files do not publish or expose ports.
- Configure Nginx Proxy Manager to forward to `edfolio-v2-php` on port `80` over the `milkyway` network.

Because Nginx Proxy Manager and this application share the same Docker network, it can reach Apache inside the container directly without a host-level port mapping.
