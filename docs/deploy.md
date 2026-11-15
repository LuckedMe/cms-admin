# Deploying `cms-admin` to the production VPS

Step-by-step guide to deploy, update, back up and roll back `cms-admin` (admin + API) on the VPS. The server itself (Docker, Traefik, Cloudflare Tunnel, swap, ufw) is prepared once by `vps-setup/docs/vps-setup.md`; the project-specific part is `vps-setup/docs/projects/cms.md` (step 22). This file is the project-side, self-contained version of it.

`docs/main-dockerfile-fix.md` explains **why** the `Dockerfile` and the prod compose file look the way they do. Read it if the build or the start fails.

> Conventions: the examples use the domain `cms.lucked.me`, the server user `dad` and the project folder `/home/dad/workspace/www/cms`. Replace them with your own values.

## 1. Architecture

```
Browser ─https─► Cloudflare (TLS, redirect to https) ─tunnel─► cloudflared ─http─► traefik:80
      Host: cms.lucked.me, Path(/) or PathPrefix /admin /api /assets /bundles /uploaded
                                  ▼
                       nginx ─fastcgi─► php-fpm (+ messenger worker) ─► mysql
```

- The VPS has no public IP. Cloudflare terminates TLS, so nginx in the stack listens on plain HTTP `:80`: no certificates and no http→https redirect in the project.
- **One domain, split by path**: `/admin` (Sonata admin, elFinder), `/api` (site API with JWT), and the static prefixes `/assets`, `/bundles`, `/uploaded`. The app serves all of them itself; Traefik only forwards these paths to the stack.
- `docker-compose.prod.yaml` (project name `cms-admin-prod`) starts `php-fpm`, `nginx`, `mysql` and, optionally, `messenger-worker` (profile `worker`). Both images are **built on the server** from the root `Dockerfile` (targets `app` and `nginx`); Node and Composer are not needed on the host.
- Persistent data lives in named volumes: `mysql`, `storage`, `sessions`, `uploaded`, `jwt`.

## 2. Prerequisites

On the server (done once, see `vps-setup.md`):

- [ ] Docker with the Compose plugin; the `reverse-proxy` network; Traefik with the **file provider** (folder `infrastructure/dynamic`) and `extra_hosts: host.docker.internal`.
- [ ] `cloudflared` running; a tunnel (here `vps`) pointing to `traefik:80`.
- [ ] **Swap enabled.** The image build runs webpack; without swap a small VPS kills the build (exit 137).
- [ ] Free disk space: `df -h /home` (the first build needs a few GB).
- [ ] The domain is in Cloudflare, with "Always use HTTPS" on.

On your side:

- [ ] Access to the GitHub repo `vtsot/cms-admin` and to the server over ssh.
- [ ] The latest code is pushed to the branch the server will pull (`main`).

## 3. Cloudflare: public hostname

Zero Trust → Networks → Tunnels → `vps` → **Published application routes** → **Add**:

| Subdomain | Domain | Path | Type | URL |
|---|---|---|---|---|
| `cms` | `lucked.me` | *(empty)* | `HTTP` | `traefik:80` |

Leave **Path** empty: one route sends the whole domain to Traefik, which splits the paths itself (section 7). Cloudflare creates the proxied CNAME `cms` automatically; delete an existing DNS record with this name first.

## 4. Clone the repository on the server

The server needs read access to the repo. GitHub accepts one public key as a Deploy key on **one** repository only, so create a dedicated key:

```bash
ssh-keygen -t ed25519 -C "dad@vps-main cms" -f ~/.ssh/id_ed25519_cms -N ""
cat ~/.ssh/id_ed25519_cms.pub     # GitHub: vtsot/cms-admin → Settings → Deploy keys → Add (read-only)

cat >> ~/.ssh/config <<'CFG'
Host github-cms
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_cms
    IdentitiesOnly yes
CFG
chmod 600 ~/.ssh/config

ssh -T git@github-cms             # "Hi vtsot/cms-admin! You've successfully authenticated"
cd /home/dad/workspace/www
git clone git@github-cms:vtsot/cms-admin.git cms
cd cms
```

> Do **not** rename the compose project (`name: cms-admin-prod`) after the first start: Docker would create new, empty volumes and the database and uploads would appear to be lost.

Every `docker compose` command for this project needs `-f docker-compose.prod.yaml --env-file .env.prod.local`: `-f` skips the dev compose files, `--env-file` feeds the `${...}` values. Run them from the project folder.

## 5. Configure `.env.prod.local`

```bash
cd /home/dad/workspace/www/cms
cp .env.prod.local.dist .env.prod.local
chmod 600 .env.prod.local         # secrets: never commit this file

openssl rand -hex 32              # → APP_SECRET
openssl rand -hex 24              # → MYSQL_PASSWORD
openssl rand -hex 24              # → MYSQL_ROOT_PASSWORD
openssl rand -hex 24              # → JWT_PASSPHRASE

nano .env.prod.local
```

| Variable | Value on the VPS |
|---|---|
| `APP_IMAGE` / `APP_TAG` | leave `cms-admin` / `latest` |
| `HTTP_PORT` | `172.17.0.1:8081`: publishes nginx on the docker0 gateway only, where Traefik reaches it. **Not `80`** (it collides with Traefik and bypasses ufw) |
| `RUN_MIGRATIONS` | `1`: doctrine migrations run when `php-fpm` starts |
| `MYSQL_DATABASE` / `MYSQL_USER` | `cms_admin` / `cms_admin` |
| `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` | generated with `openssl rand -hex 24` (hex does not break the `DATABASE_URL`) |
| `APP_SECRET` | output of `openssl rand -hex 32` |
| `TIMEZONE` | `UTC` or `Europe/Kyiv` |
| `APP_DOMAIN` | `cms.lucked.me`: **must equal the public host**. The admin routes and the admin firewall are bound to it; if it is wrong, every `/admin` URL returns 404 |
| `DEFAULT_URI` | `https://cms.lucked.me/` |
| `DEFAULT_LOCALE` / `LOCALES` | as in the dist file |
| `CORS_ALLOW_ORIGIN` | `^https://cms\.lucked\.me$`; add other browser origins with `\|` in the regex |
| `MAILER_DSN` | `null://null` until a mail server is set up |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` |
| `JWT_PASSPHRASE` | generated (see above); used to create and read the JWT private key |
| `JWT_TOKEN_TTL` | `3600` |

Check that no `!ChangeMe!` placeholder is left:

```bash
grep -n 'ChangeMe' .env.prod.local     # must print nothing
grep '^HTTP_PORT' .env.prod.local      # HTTP_PORT=172.17.0.1:8081
echo "$HTTP_PORT"                      # must be empty: a shell variable overrides the env file
```

## 6. Open the port for Traefik

Traefik (a container) reaches nginx through the docker0 gateway. Check the address and allow the docker networks only:

```bash
ip -4 addr show docker0 | grep inet                           # 172.17.0.1/16; if different, use it in HTTP_PORT
sudo ufw allow from 172.16.0.0/12 to any port 8081 proto tcp
```

## 7. Traefik route

The route lives on the server, not in this repo. Create `/home/dad/workspace/infrastructure/dynamic/cms.yaml`:

```yaml
http:
  routers:
    cms:
      rule: Host(`cms.lucked.me`) && (Path(`/`) || PathPrefix(`/admin`) || PathPrefix(`/api`) || PathPrefix(`/assets`) || PathPrefix(`/bundles`) || PathPrefix(`/uploaded`))
      entryPoints: [web]
      service: cms
  services:
    cms:
      loadBalancer:
        servers:
          - url: "http://host.docker.internal:8081"
```

Traefik watches the folder, so no restart is needed. Check:

```bash
cd /home/dad/workspace/infrastructure && docker compose logs --tail 20 traefik     # no errors
```

Notes:

- `Path(\`/\`)` makes the app answer `/` with a redirect to `/admin`. Remove it once the client website takes the root of the domain.
- No `redirectscheme` middleware and no certresolver: Cloudflare does TLS and the https redirect.
- Router and service names (`cms`) must be unique among all projects on the server.

## 8. First start

```bash
cd /home/dad/workspace/www/cms
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local config > /dev/null && echo OK                                    # compose file is valid
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local config | grep -A8 '^  nginx:' | grep -A2 ports                   # 172.17.0.1:8081 -> 80, not 0.0.0.0

docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d --build                                                    # first build: 5–15 minutes (composer + webpack)
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local ps                                                               # php-fpm, nginx Up; mysql healthy
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local logs -f php-fpm                                                  # migrations ran; Ctrl+C
```

On a small VPS lower the webpack heap if the build is killed or runs out of memory:

```bash
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local build --build-arg NODE_MAX_OLD_SPACE=1024
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d
```

### 8.1 Generate the JWT keypair (once)

The key files are excluded from the image and live in the `jwt` volume. Without them `/api/sites/auth/login` returns 500.

```bash
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm bin/console lexik:jwt:generate-keypair
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm ls -l config/jwt                                    # private.pem, public.pem
```

Run it **only once**. Running it again with `--overwrite` replaces the keys and invalidates all issued tokens.

### 8.2 Queue worker (optional)

Start it only if the project uses async messages:

```bash
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local --profile worker up -d
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local ps                                                               # messenger-worker Up
```

## 9. Administrator and first data

Look for a command that creates a user:

```bash
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm bin/console list | grep -i -E 'user|admin'
```

If there is none, either restore a dump from your machine:

```bash
# on your machine: make a dump of the local database, then
scp cms-admin.sql.gz dad@<server>:/home/dad/workspace/backups/dump/
# on the server
gunzip -c /home/dad/workspace/backups/dump/cms-admin.sql.gz \
  | docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

or insert the user manually: get the hash with `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm bin/console security:hash-password`, then `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec mysql mysql -uroot -p cms_admin`.

To copy existing uploaded files into the volume:

```bash
docker cp ./uploaded/. cms-admin-prod-php-fpm-1:/var/www/html/public/uploaded/
```

## 10. Verify

On the server (through Traefik, with the right `Host` header):

```bash
curl -sI -H 'Host: cms.lucked.me' http://127.0.0.1/admin/login | head -3   # 200, not 404
curl -sI -H 'Host: cms.lucked.me' http://127.0.0.1/api/doc      | head -3   # 401 (basic auth) or 200, not Traefik's 404
```

From outside:

```bash
curl -sI http://cms.lucked.me/admin/        | head -3                      # 301 to https (Cloudflare)
curl -sI https://cms.lucked.me/admin/login  | head -3                      # 200, valid certificate
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://cms.lucked.me/api/sites/auth/login   # 400/401/422, not 404/500
curl -sI https://cms.lucked.me/             | head -3                      # 302 to /admin/
```

In the browser: `https://cms.lucked.me/admin` loads with styles, the login works, an elFinder upload works and the file opens from `https://cms.lucked.me/uploaded/...`. `docker ps` shows no port published on `0.0.0.0` for the cms containers.

## 11. Updating (every release)

```bash
cd /home/dad/workspace/www/cms
git pull
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d --build           # migrations run automatically (RUN_MIGRATIONS=1)
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local ps
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local logs --tail 50 php-fpm  # no errors
docker image prune -f
```

- Take a database backup (section 12) before releases that contain migrations.
- A change of `.env.prod.local` needs only `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d` (containers are recreated with the new environment).
- If `composer.json`, `yarn.lock` or the frontend changed, `--build` rebuilds the affected layers; the unchanged ones come from the cache.

Roll back a bad release:

```bash
git log --oneline -5
git checkout <previous-commit>
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d --build
```

Migrations are not reverted automatically: restore the database from a backup if the failed release changed the schema (section 12).

## 12. Backups

Create `/home/dad/workspace/backups/backup-cms.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /home/dad/workspace/www/cms
STAMP=$(date +%F_%H%M)
docker compose -p cms-admin-prod -f docker-compose.prod.yaml --env-file .env.prod.local exec -T mysql \
  sh -c 'exec mysqldump --single-transaction --routines -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  | gzip > /home/dad/workspace/backups/dump/cms-admin-$STAMP.sql.gz
# uploaded files (volume cms-admin-prod_uploaded)
docker run --rm -v cms-admin-prod_uploaded:/src:ro -v /home/dad/workspace/backups/dump:/dst alpine \
  tar czf /dst/cms-admin-uploaded-$STAMP.tar.gz -C /src .
# JWT keys (without them issued tokens stop working after a restore)
docker run --rm -v cms-admin-prod_jwt:/src:ro -v /home/dad/workspace/backups/dump:/dst alpine \
  tar czf /dst/cms-admin-jwt-$STAMP.tar.gz -C /src .
find /home/dad/workspace/backups/dump \( -name 'cms-admin-*.sql.gz' -o -name 'cms-admin-*.tar.gz' \) -mtime +7 -delete
```

Enable it:

```bash
chmod 700 /home/dad/workspace/backups/backup-cms.sh
/home/dad/workspace/backups/backup-cms.sh && ls -lh /home/dad/workspace/backups/dump | tail -4
( crontab -l 2>/dev/null; echo '10 3 * * * /home/dad/workspace/backups/backup-cms.sh >> /home/dad/workspace/backups/backup.log 2>&1' ) | crontab -
```

`docker volume ls | grep cms-admin-prod` shows the exact volume names. Copy the dumps off the server too (a backup on the same disk does not survive a disk failure).

Restore the database:

```bash
gunzip -c /home/dad/workspace/backups/dump/cms-admin-<stamp>.sql.gz \
  | docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 13. Stop and remove

- Take the site offline only: delete the `cms` route in the tunnel, or `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local stop nginx`.
- Stop everything, keep the data: `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local down`.
- Full removal (**destroys the database and the uploads**; back up first): `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local down -v`, then `rm -rf /home/dad/workspace/www/cms`.

## 14. Troubleshooting

First look at `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local ps`, `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local logs --tail 100 php-fpm nginx` and, for application errors, `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm tail -n 100 var/log/prod.log`.

| Symptom | Cause / fix |
|---|---|
| `404 page not found` from Traefik on `/admin` or `/api` | `dynamic/cms.yaml` is missing or invalid (`docker compose logs traefik` in `infrastructure/`), or the host is not `cms.lucked.me` |
| `404 page not found` from Traefik on `/` | the router rule lacks ``Path(`/`)``: copy the rule from section 7 |
| Every `/admin/...` URL is a Symfony 404 | `APP_DOMAIN` in `.env.prod.local` differs from the public host (the default is `cms-admin.local`); fix it and run `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d` |
| Admin page without CSS/JS, broken images | `/assets`, `/bundles` or `/uploaded` is missing from the router rule |
| Uploaded files return 404 | the `uploaded` volume is not mounted in nginx, or the rule lacks `/uploaded` |
| `/api/sites/auth/login` returns 500 ("Unable to create a signed JWT", key not readable) | the `jwt` volume is empty: generate the keypair (8.1); check `JWT_PASSPHRASE` |
| `Bind for 0.0.0.0:80 failed: port is already allocated` | `HTTP_PORT` is still `80` (or a shell variable overrides it): set `HTTP_PORT=172.17.0.1:8081`, then `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d` |
| `502` from Cloudflare | the tunnel route URL is not `http://traefik:80`, or the `cms` hostname is missing in the tunnel |
| `502`/`504` from Traefik | nginx is not up, `HTTP_PORT` is not the docker0 address, or ufw blocks 8081 (section 6) |
| Links with `http://`, wrong client IP | `forwardedheaders.trustedips` is missing in Traefik |
| Login redirects to a wrong host | `DEFAULT_URI` is not `https://cms.lucked.me/`; fix it and run `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d` |
| Browser CORS error from another site calling `/api` | add its origin to `CORS_ALLOW_ORIGIN` with `\|` in the regex |
| Build killed (137) / `JavaScript heap out of memory` | enable swap; build with `--build-arg NODE_MAX_OLD_SPACE=1024` |
| php-fpm restarts: `Failed opening ... preload.php` | `opcache.preload` points to a missing file: restore `config/preload.php` |
| Data disappeared after a redeploy | the compose project name changed; keep `name: cms-admin-prod` |

## 15. Quick reference

```bash
cd /home/dad/workspace/www/cms
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local ps                                           # status
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local logs -f php-fpm                              # logs
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm bin/console <command>           # Symfony console
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm bin/console cache:clear         # clear cache
git pull && docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d --build                    # update
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local restart php-fpm nginx                        # restart
```
