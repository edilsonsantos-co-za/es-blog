# ES Blog deployment

ES Blog is designed to sit behind the shared Nginx Proxy Manager stack from `~/workspace/datasparklab/core-reverse-proxy-webapp`.

## Network and ports

- The application container joins the external `milkyway` Docker network.
- The compose files do not publish or expose ports.
- Configure Nginx Proxy Manager to forward to `es-blog` on port `80` over the `milkyway` network.
- `es-blog-worker` runs from the same image with no web server: queued jobs and the scheduler. It needs no proxy entry.
- Uploads (post images and generated banners) live on the `uploads` volume shared by both containers.

Because Nginx Proxy Manager and this application share the same Docker network, it can reach Apache inside the container directly without a host-level port mapping.

## First admin user

This project does not expose public self-registration. Create the first admin user from the running application container:

```bash
docker exec -it es-blog php bin/console app:user:create admin admin@blog.edilsonsantos.local --role=admin --env=prod
```

The command prompts for the password securely when `--password` is omitted. After the first admin can sign in, additional users can be managed from the admin area.


## On the Kubernetes cluster (production since 16 Nov 2026)

**How it works.** A push to `main` ships the files to the server, and the workflow's last step runs
`deploy-k3s.sh` (workspace `config/kubernetes/scripts`). It builds `es-blog:<commit>` from `docker/php/Dockerfile`,
turns the server's `.env` into the Secret `es-blog-env`, and applies `k8s/`:

| File | What it runs |
|---|---|
| `k8s/deployment.yaml` | Two copies of the web server. Sessions are in the database and uploads are a shared folder, so either copy can answer any request. A deploy replaces them one at a time. |
| `k8s/worker.yaml` | One background worker (queued jobs and the scheduler tick), as `www-data`. A deploy stops the old one first: two would run the scheduler twice. |
| `k8s/service.yaml`, `k8s/ingress.yaml` | The name the copies answer under, and the route for `blog.edilsonsantos.co.za`. |

The pods reach MySQL, sign-in and the mail relay, which still run in Docker, through the cluster bridge
(workspace `config/kubernetes/bridge`) under the names the `.env` already uses. Uploads and themes are the same
folders the Docker containers used (`edfolio-blog-v2_uploads`, `edfolio-blog-v2_themes`): no data moved and the
nightly backups cover them as before. `docker-compose.prod.yml` is kept for going back and for other servers.

**Why two copies.** A deploy or a crashed copy no longer takes the blog down. The alternative, one copy as with the
Journal, would have been enough for the traffic but gives a pause on every deploy.

**Failure modes.** A build that never becomes ready: the script goes back to the previous version and the deploy
fails; visitors stay on the old copies. Database migrations run when a web copy starts; a migration that fails
keeps the new copy from becoming ready, with the same result. A value in `.env` that contains `$`: Docker Compose
would expand it, the Secret does not (none does today; all 19 values were compared on 16 Nov).

**Back to Docker.** In the app folder: `k3s kubectl -n apps scale deployment/es-blog-worker --replicas=0`,
`docker compose -f docker-compose.prod.yml up -d --wait`, then
`python3 /usr/local/lib/workspace/npm-route.py blog.edilsonsantos.co.za --to docker:es-blog:80`.

**Verify.** `k3s kubectl -n apps get pods -l part-of=es-blog` shows two `es-blog` pods and one `es-blog-worker`,
all `1/1 Running`; `https://blog.edilsonsantos.co.za/` returns 200. This does not prove a sign-in or a scheduled
post: check those in the browser and in Admin.

**Try it.** Run `k3s kubectl -n apps delete pod -l app=es-blog --wait=false` for one pod name only and reload the
blog a few times: predict whether any request fails, then check with `curl` in a loop.

Console commands now run in a pod: `k3s kubectl -n apps exec deploy/es-blog -- php bin/console <command>`.
