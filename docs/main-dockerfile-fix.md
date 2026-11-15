# Main (production) Dockerfile: instructions

How to make and keep the root `Dockerfile` of `cms-admin` ready for the VPS. Used by the Claude skill `main-dockerfile-fix` (`.claude/skills/main-dockerfile-fix/SKILL.md`), and readable on its own.

Server-side steps (clone, secrets, Traefik override, first start, updates, backups) are **not** repeated here: see `vps-setup/docs/projects/cms.md`, steps 22.0 to 22.10.

## 1. What the VPS expects

```
Browser ─https─► Cloudflare (TLS, redirect to https) ─tunnel─► cloudflared ─http─► traefik:80
      Host: cms.lucked.me, PathPrefix /admin /api /assets /bundles /uploaded
                                  ▼
                       nginx ─fastcgi─► php-fpm (+ messenger worker) ─► mysql
```

| Fact | Consequence for the image / compose |
|---|---|
| The VPS has no public IP; Cloudflare terminates TLS | nginx listens on plain `:80` only. No certificates, no http→https redirect in nginx. |
| Traefik routes by `Host` + `PathPrefix`, labels live in a server-side `docker-compose.traefik.yaml` | `docker-compose.prod.yaml` must not publish ports to be usable (the override resets `ports`). The nginx service must be named `nginx` and listen on 80. |
| One domain; the app itself serves `/admin/*` and `/api/*` | No `server_name` / host-based logic in nginx. `ADMIN_DOMAIN` equals the public domain. |
| Static files live under `/assets`, `/bundles`, `/uploaded` | The nginx image contains `public/`; `uploaded` is a shared volume. These are exactly the prefixes Traefik sends to the stack. |
| The image is **built on the server** (`cms up -d --build`) | Small RAM: the webpack heap must be tunable (`NODE_MAX_OLD_SPACE`), layers must be cache-friendly, Node/Composer are not installed on the host. |
| Compose project name is fixed to `cms-admin-prod` | Volume names (`mysql`, `storage`, `sessions`, `uploaded`, `jwt`) must not be renamed after the first start, or data "disappears". |
| Symfony trusts `X-Forwarded-*` from private ranges (`config/packages/framework.yaml`, `trusted_proxies`) | Nothing to do in nginx; the headers reach PHP through fastcgi. |

## 2. Reference layout of the `Dockerfile`

| Stage | Base | Purpose |
|---|---|---|
| `php-base` | `php:8.4-fpm` | PHP extensions (`@composer zip opcache intl pdo_mysql bcmath gd exif xsl amqp redis`), user `app`, `php.ini` + `php-fpm.d` + `php.prod.ini`, `APP_ENV=prod`, `APP_DEBUG=0` |
| `vendor` | `php-base` | `composer install --no-dev --no-autoloader --no-scripts`, only `composer.json`/`lock`/`symfony.lock` are copied (cached until they change) |
| `assets` | `node:24-alpine` | `yarn install --frozen-lockfile`, `yarn run build` (webpack Encore → `public/assets`); `ARG NODE_MAX_OLD_SPACE` sets `NODE_OPTIONS=--max-old-space-size` |
| `app` | `php-base` | code + `vendor` + built assets; `composer dump-autoload --classmap-authoritative`; `post-install-cmd` and `install-editor` (bundle assets, CKEditor, elFinder into `public/bundles`); creates `var/*`, `public/uploaded`, `config/jwt`, owned by `app`; entrypoint; `USER app`; volumes; `php-fpm` |
| `nginx` | `nginx:mainline-alpine` | `nginx.conf`, `conf.d/default.conf`, `public/` copied from `app` |
| (last) | `FROM app` | keeps `app` the default target of a plain `docker build .` |

If the `Dockerfile` is missing, rebuild it from this table. Keep these stage names: `docker-compose.prod.yaml` uses `target: app` and `target: nginx`.

## 3. Audit checklist

Mark each item OK / fixed / needs a decision.

### Build context
- [ ] `.dockerignore` excludes `.git`, `.idea`, `vendor`, `node_modules`, `var`, `public/assets`, `public/bundles`, `tests`, `.env.local`, `.env.*.local`, `config/jwt/*.pem`, `.docker/mysql/data`, `docker-compose*.yaml`. Secrets and the local DB must never enter the image.

### `php-base` / `vendor`
- [ ] `APP_ENV=prod`, `APP_DEBUG=0` set as `ENV`.
- [ ] `composer install` uses `--no-dev` and the BuildKit cache mount; `composer.lock` and `symfony.lock` are copied.
- [ ] Every PHP extension the app needs is installed. Check `composer.json` `require` (`ext-*`) against the `install-php-extensions` line, and remove dev-only ones (pcov, xdebug).

### `assets`
- [ ] `yarn install --frozen-lockfile` then `yarn run build`; the files copied before it are what webpack needs (`package.json`, `yarn.lock`, `webpack.config.js`, `assets/`). Add anything else the config imports.
- [ ] `ARG NODE_MAX_OLD_SPACE` with a default, exported as `NODE_OPTIONS=--max-old-space-size=${NODE_MAX_OLD_SPACE}`. cms.md 22.5 tells the operator to pass `--build-arg NODE_MAX_OLD_SPACE=1024` on a small VPS, so the arg must exist.

### `app`
- [ ] `composer dump-autoload --no-dev --classmap-authoritative` runs after `COPY . .`.
- [ ] `composer run-script --no-dev post-install-cmd` and `install-editor` run, so `public/bundles` has Sonata, CKEditor and elFinder files.
- [ ] `var/cache/prod` is removed at build (the entrypoint warms it at start, because `config/packages/locale_requirement.php` reads `LOCALES`/`DEFAULT_LOCALE` from the runtime environment).
- [ ] `var/{cache,log,session,storage,share}`, `public/uploaded` and `config/jwt` exist and are `chown app:app`.
- [ ] `VOLUME` lists `var/storage`, `var/session`, `public/uploaded`, `config/jwt`.
- [ ] `USER app:app`; the entrypoint (`.docker/php-fpm/docker-entrypoint.sh`) runs `cache:warmup`, and the migrations when `RUN_MIGRATIONS=1`.
- [ ] `config/preload.php` exists (`php.prod.ini` has `opcache.preload` pointing at it). If it does not, remove the setting, or php-fpm will not start.

### `nginx`
- [ ] `default.conf` has `root /var/www/html/public`, `try_files $uri /index.php$is_args$args`, `fastcgi_pass` to `php-fpm:9000`, and `internal` on the `index.php` location, so other `.php` files return 404.
- [ ] No `listen 443`, no `ssl_*`, no redirect, no `server_name` other than `_`.
- [ ] `public/` is copied `--from=app`.

### Routing and domain (one domain with paths)
- [ ] No leftover host-based routing: `grep -rn "api_domain\|API_DOMAIN\|admin\.\(cms\|example\)" config src .env* docker-compose*.yaml Dockerfile` finds nothing, except `admin_domain` / `ADMIN_DOMAIN`.
- [ ] `ADMIN_DOMAIN` is set in `.env.prod.local.dist`. It must equal the public domain, because the admin routes and the admin firewall have `host: %admin_domain%`. `.env` defaults to `cms-admin.local`, so if it is missing in prod, **every `/admin` URL returns 404**.
- [ ] `DEFAULT_URI=https://<domain>/` and `CMS_DOMAIN=<domain>` (the Traefik router rule uses `CMS_DOMAIN`).
- [ ] `CORS_ALLOW_ORIGIN=^https://<domain-escaped>$` (single origin).

### Compose / env (`docker-compose.prod.yaml`, `.env.prod.local.dist`)
- [ ] `name: cms-admin-prod` is unchanged.
- [ ] Services `php-fpm`, `nginx`, `mysql` (and the `worker`-profile `messenger-worker`); `restart: unless-stopped`; mysql has a healthcheck and `php-fpm` waits for it.
- [ ] No `ports` on `php-fpm` or `mysql`. `nginx` publishes `${HTTP_PORT:-80}:80`, which the server-side Traefik override resets.
- [ ] **JWT keypair.** `config/jwt/*.pem` is excluded from the image and `.env` has empty `JWT_*` values, so without extra setup `/api/sites/auth/login` fails. Required:
  - volume `jwt:/var/www/html/config/jwt` on the `php-fpm` and worker services (the `x-app` anchor);
  - `JWT_SECRET_KEY` and `JWT_PUBLIC_KEY` pointing to `/var/www/html/config/jwt/{private,public}.pem` in `environment`;
  - `JWT_PASSPHRASE` and `JWT_TOKEN_TTL` in `.env.prod.local.dist`;
  - the one-time key generation step (section 5).
- [ ] `.env.prod.local.dist` has placeholders only (`!ChangeMe!`). Real values go to `.env.prod.local` on the server (`chmod 600`, never committed).

## 4. Fix procedure

1. Edit only what the checklist flags. Prefer the smallest change; keep the stage names.
2. Keep dev and prod separate: `docker-compose.yaml`, `docker-compose.override.yaml` and `.docker/*/Dockerfile` are the dev stack.
3. Do not add `docker-compose.traefik.yaml` to the repo (cms.md 22.4 keeps it on the server so that the standalone prod compose still works).
4. After editing, run the verification below.

## 5. Verification

Locally, from the project root (Docker with BuildKit):

```bash
# 1. both targets build; also with a lower webpack heap
docker build --target app   -t cms-admin:test .
docker build --target nginx -t cms-admin-nginx:test .
docker build --target app   -t cms-admin:test --build-arg NODE_MAX_OLD_SPACE=1024 .

# 2. the image is a prod image and has its static files
docker run --rm cms-admin:test php bin/console about | grep -E 'Environment|Debug'   # prod / false
docker run --rm cms-admin:test ls public/assets public/bundles

# 3. compose is valid
cp .env.prod.local.dist .env.prod.local     # throwaway local values; replace the ChangeMe placeholders
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local config > /dev/null && echo OK

# 4. run it (set ADMIN_DOMAIN=localhost and DEFAULT_URI=http://localhost/ in the throwaway env file)
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local exec php-fpm bin/console lexik:jwt:generate-keypair
curl -sI http://localhost/admin/login | head -1                                   # 200
curl -sI http://localhost/api/doc | head -1                                        # 401 (basic auth), not 404
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost/api/sites/auth/login   # 400/401/422, not 500
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local down -v      # clean up the test stack
```

`lexik:jwt:generate-keypair` runs as user `app`, and `config/jwt` in the image is owned by it, so a new named volume is writable. On the server, run it **once**, after the first start (`cms exec php-fpm bin/console lexik:jwt:generate-keypair`). Re-running overwrites the keys and invalidates issued tokens (add `--overwrite` only on purpose).

## 6. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Build killed (137) or `JavaScript heap out of memory` in the `assets` stage | Add swap on the server (vps-setup.md step 6); `--build-arg NODE_MAX_OLD_SPACE=1024` |
| Every `/admin/...` URL is 404 from Symfony | `ADMIN_DOMAIN` in `.env.prod.local` differs from the public host (the default is `cms-admin.local`) |
| `/api/sites/auth/login` returns 500 "Unable to create a signed JWT" / key not readable | the `jwt` volume is empty: generate the keypair (section 5); check `JWT_PASSPHRASE` |
| php-fpm restarts: `Failed opening ... preload.php` | `opcache.preload` points to a missing file: restore `config/preload.php` or drop the setting |
| Admin page without CSS/JS, or elFinder/CKEditor missing | `install-editor` did not run in the `app` stage, or Traefik rule lacks `/assets` / `/bundles` / `/uploaded` |
| Uploaded files return 404 | the `uploaded` volume is not mounted in the nginx service, or the Traefik rule lacks `/uploaded` |
| Browser CORS error from another site calling `/api` | add its origin to `CORS_ALLOW_ORIGIN` with `\|` in the regex |
| Data disappeared after a redeploy | the compose project name changed; keep `name: cms-admin-prod` |
