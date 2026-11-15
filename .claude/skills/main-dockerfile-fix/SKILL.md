---
name: main-dockerfile-fix
description: Audit and fix (or create) the main production Dockerfile in the project root and its prod stack (docker-compose.prod.yaml, .env.prod.local.dist, .dockerignore, nginx/php-fpm prod config) so the image runs on the VPS behind Traefik + Cloudflare Tunnel on one domain with /admin and /api paths. Use it whenever the user wants to deploy to the VPS / prod server, asks to "make", "fix", "check" or "update" the prod or main Dockerfile, hits a prod build/start failure (webpack out of memory, missing JWT keys, wrong host/CORS, no assets), or changes routes/hosts/env that the prod image depends on — even if they don't say "Dockerfile".
---

# main-dockerfile-fix

Make the root `Dockerfile` (multi-stage: `php-base` → `vendor` → `assets` → `app` → `nginx`) deployable on the VPS.

The full checklist, the reasons behind each item, the verification commands and the troubleshooting table are in
[`docs/main-dockerfile-fix.md`](../../../docs/main-dockerfile-fix.md). Read it first; this file is only the workflow.

## Workflow

1. **Learn the target.** Read `vps-setup/docs/projects/cms.md` (and `vps-setup/docs/vps-setup.md` if needed). It is in `/home/vitaly/workspace/www/nastiksot-vps/vps-setup`. Key facts: Traefik + Cloudflare Tunnel terminate TLS, there are no published ports, one domain serves `/admin` and `/api`, and the image is built on the server by `cms up -d --build`.
2. **Read the current state:** `Dockerfile`, `docker-compose.prod.yaml`, `.env.prod.local.dist`, `.dockerignore`, `.docker/nginx/config/conf.d/default.conf`, `.docker/php-fpm/docker-entrypoint.sh`, `.docker/php-fpm/config/php.prod.ini`. If the `Dockerfile` is missing, create it from the "Reference layout" in the doc.
3. **Run the audit checklist** from the doc. Report each item as OK / fixed / needs a decision.
4. **Fix** only what the checklist flags, with the smallest edits. Keep the stage names and the `app` default target. Do not create `docker-compose.traefik.yaml` in the repo: it stays on the server (cms.md 22.4). Do not touch the dev stack (`docker-compose.yaml`, `.docker/*/Dockerfile`).
5. **Verify** with the commands in the doc (build both targets, `about`, compose `config`, curl). If Docker is unavailable, say which checks were not run.
6. **Hand off:** list the server-side steps still needed (cms.md 22.3–22.8), including generating the JWT keypair once and `ADMIN_DOMAIN`/`CMS_DOMAIN` in `.env.prod.local`.

Never put real secrets into the repo files; `.env.prod.local.dist` holds placeholders only.
