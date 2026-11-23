# CMS Admin

Multi-site content management back office (Sonata admin) with a JWT-secured JSON API. Site managers edit pages, articles, navigation, banners and contact requests in `/admin`; the public websites read the content through `/api`.

**Backend**

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?style=for-the-badge&logo=symfony&logoColor=white)](https://symfony.com/)
[![Doctrine ORM](https://img.shields.io/badge/Doctrine_ORM-3.8-F58220?style=for-the-badge&logo=doctrine&logoColor=white)](https://www.doctrine-project.org/)
[![Sonata Admin](https://img.shields.io/badge/Sonata_Admin-4.44-1E88E5?style=for-the-badge)](https://docs.sonata-project.org/projects/SonataAdminBundle/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)

**Frontend**

[![Webpack Encore](https://img.shields.io/badge/Webpack_Encore-7-8DD6F9?style=for-the-badge&logo=webpack&logoColor=black)](https://symfony.com/doc/current/frontend/encore/index.html)
[![Sass](https://img.shields.io/badge/Sass-1.x-CC6699?style=for-the-badge&logo=sass&logoColor=white)](https://sass-lang.com/)
[![CKEditor](https://img.shields.io/badge/CKEditor-4.22.1-0287D0?style=for-the-badge&logo=ckeditor4&logoColor=white)](https://ckeditor.com/ckeditor-4/)

**Infrastructure**

[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)
[![Nginx](https://img.shields.io/badge/Nginx-mainline-009639?style=for-the-badge&logo=nginx&logoColor=white)](https://nginx.org/)
[![Node.js](https://img.shields.io/badge/Node.js-24-5FA04E?style=for-the-badge&logo=nodedotjs&logoColor=white)](https://nodejs.org/)

**Tooling**

[![PHP-CS-Fixer](https://img.shields.io/badge/PHP--CS--Fixer-3.95+-4B8BBE?style=for-the-badge)](https://cs.symfony.com/)
[![Composer](https://img.shields.io/badge/Composer-2-885630?style=for-the-badge&logo=composer&logoColor=white)](https://getcomposer.org/)
[![Yarn](https://img.shields.io/badge/Yarn-1.x-2C8EBB?style=for-the-badge&logo=yarn&logoColor=white)](https://yarnpkg.com/)

## Table of contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Usage](#usage)
- [Development](#development)
- [Testing](#testing)
- [Architecture](#architecture)
- [Background jobs](#background-jobs)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)
- [Documentation index](#documentation-index)
- [License](#license)

## Features

- Sonata admin at `/admin` with login, rate limiting and optional Google Authenticator two-step login.
- Several sites in one installation: pages, articles (with related articles), main and child navigation, banners, site settings and contact requests, all translatable (`LOCALES`).
- Role hierarchy: user, site manager, admin and supervisor (see `config/packages/security.yaml`); roles map to Sonata admin permissions.
- WYSIWYG editing (CKEditor 4) and file management (elFinder) with files stored under `public/uploaded`.
- JSON API for the public sites (`/api/sites/...`) secured with JWT and refresh tokens, with request rate limiting.
- OpenAPI documentation (NelmioApiDoc) at `/api/doc` and `/admin/api/doc`.
- On-the-fly image resizing and cropping (`/images/{type}/{crop}/{width}x{height}/{image}`, Intervention Image).
- Asynchronous processing with Symfony Messenger (three priority queues plus a notification queue).

## Tech stack

| Layer | Technology | Version | Purpose |
|---|---|---|---|
| Language | PHP | `>=8.4` (Docker image: 8.4) | Application runtime |
| Framework | Symfony | 8.1.* | HTTP kernel, security, Messenger, Serializer, Validator, rate limiter |
| Admin UI | Sonata Admin + Doctrine ORM admin | ^4.44 / ^4.22 | CRUD back office |
| Persistence | Doctrine ORM, Migrations | ^3.8 / ^4.0 | Entities and schema migrations |
| Database | MySQL | 8.0 | Data storage |
| Auth (API) | LexikJWTAuthenticationBundle, Gesdinet JWT refresh token | ^3.2 / ^3.1 | Site API tokens |
| Auth (admin) | Sonata Google Authenticator | ^2.3 | Two-step admin login |
| Editor | FOSCKEditorBundle, FMElfinderBundle | ^2.8 / ^14.0 | Rich text and file manager |
| Files | Flysystem, VichUploader, Intervention Image | ^3.36 / ^3.0 / ^4.4 | Storage, uploads, image processing |
| API docs | NelmioApiDocBundle, NelmioCorsBundle | ^5.13 / ^2.6 | OpenAPI and CORS |
| Frontend build | Webpack Encore, Sass | ^7.0 / ^1.106 | Admin assets from `assets/` to `public/assets` |
| Web server | Nginx | mainline | Static files, FastCGI to php-fpm |
| Containers | Docker Compose | n/a | Dev and production stacks |
| Code style | PHP-CS-Fixer | >=3.95.30 | `composer lint` / `lint:fix` |

## Quick start

### Prerequisites

- Docker with the Compose plugin, `make`.
- The dev stack uses two external Docker networks, `reverse-proxy` and `shared-smtp` (see `docker-compose.yaml`). A reverse proxy on `reverse-proxy` reads `VIRTUAL_HOST` and publishes `cms-admin.local`. Create the networks if they do not exist:

```bash
docker network create reverse-proxy
docker network create shared-smtp
```

### Clone and configure

```bash
git clone <repository-url> cms-admin
cd cms-admin
cp docker-compose.override.yaml.dist docker-compose.override.yaml   # publishes MySQL on host port 3307
```

Add the hosts to `/etc/hosts`:

```text
127.0.0.1 cms-admin.local cms-client.local
```

Put local overrides (database URL, `APP_ENV=dev`, JWT passphrase, secrets) into `.env.local`; it is git-ignored. See [Configuration](#configuration).

### First run

From the project root:

```bash
make docker-up          # build and start php-fpm, nodejs, nginx, mysql, phpmyadmin, adminer
make install            # composer install
make install-editor     # CKEditor and elFinder assets
make jwt-keys           # JWT keypair into config/jwt (one-time)
make migrate            # Doctrine migrations (dev and test databases)
make yarn-install       # JS dependencies
make yarn-dev           # build assets (or: make yarn-watch)
```

### URLs

| What | URL |
|---|---|
| Admin | `https://cms-admin.local/admin` |
| API documentation | `https://cms-admin.local/api/doc`, `https://cms-admin.local/admin/api/doc` |
| phpMyAdmin | `https://webmysql.cms-admin.local` |
| Adminer | `https://adminer.cms-admin.local` |
| MySQL from the host | `127.0.0.1:3307` (with the override file) |

There is no default account and no console command to create one. Create the first administrator by hashing a password with `make php-console` then `bin/console security:hash-password` and inserting the `user` row (the same approach is described in [docs/deploy.md](./docs/deploy.md), section 9), or restore a database dump.

## Configuration

Symfony loads `.env`, then `.env.local`, then `.env.$APP_ENV`, then `.env.$APP_ENV.local`. `.env` holds defaults only; real secrets go to `.env.local` (dev) or `.env.prod.local` (production, see [.env.prod.local.dist](./.env.prod.local.dist)).

| Variable | Meaning | Default in `.env` |
|---|---|---|
| `COMPOSE_PROJECT_NAME`, `COMPOSE_PHP_VERSION`, `COMPOSE_USER_ID`, `COMPOSE_GROUP_ID` | Docker Compose project name, PHP version and container user | `cms-admin`, `8.4`, `1000`, `1000` |
| `APP_ENV` | Symfony environment | `prod` (set `dev` in `.env.local`) |
| `APP_SECRET` | Symfony secret (**secret**) | set it yourself |
| `APP_SHARE_DIR` | Shared data dir | `var/share` |
| `TIMEZONE` | Application time zone | `UTC` |
| `DEFAULT_LOCALE`, `LOCALES` | Default and available locales (JSON array) | `en_GB`; `["en_GB","uk_UA","ru_UA"]` |
| `DEFAULT_URI` | Base URL for links generated in the CLI | `https://cms-admin.local/` |
| `DATABASE_URL` | Doctrine DSN (**secret**) | placeholder, override locally |
| `CORS_ALLOW_ORIGIN` | Regex of allowed CORS origins | localhost only |
| `MAILER_DSN` | Mailer transport | `null://null` |
| `MESSENGER_TRANSPORT_DSN` | Transport DSN for all queues | `doctrine://default?auto_setup=0` |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` | Paths of the JWT keypair | `config/jwt/*.pem` |
| `JWT_PASSPHRASE` | JWT private key passphrase (**secret**) | set it yourself |
| `JWT_TOKEN_TTL` | Token lifetime in seconds | set in `.env` |
| `RATE_LIMIT_ADMIN_LOGIN_MAX_ATTEMPTS`, `..._GLOBAL_MAX_ATTEMPTS`, `..._INTERVAL` | Admin login limiter | `5`, `5`, `5 minutes` |
| `RATE_LIMIT_API_SITE_LOGIN_MAX_ATTEMPTS`, `..._GLOBAL_MAX_ATTEMPTS`, `..._INTERVAL` | API login limiter | `50`, `100`, `5 minutes` |
| `RATE_LIMIT_API_SITE_MAX_REQUESTS`, `RATE_LIMIT_API_SITE_REQUESTS_INTERVAL` | API request limiter | `120`, `1 minute` |

Production-only variables (`APP_IMAGE`, `APP_TAG`, `HTTP_PORT`, `RUN_MIGRATIONS`, `MYSQL_*`, `APP_DOMAIN`) are listed in [.env.prod.local.dist](./.env.prod.local.dist).

## Usage

- **Admin**: `/admin` (login at `/admin/login`). Content is grouped into Content, Site, Navigation, User and Log sections.
- **Site API** (JWT, `ROLE_API_SITE`):

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/sites/auth/login` | Obtain a token |
| POST | `/sites/auth/refresh-token` | Refresh a token (route as declared in `AuthController`) |
| GET | `/api/sites/{id}` | Site details |
| GET | `/api/sites/{id}/pages` | Page collection |
| GET | `/api/sites/{id}/pages/{slug}` | Page details |
| GET | `/api/sites/{id}/sitemap` | Sitemap |
| GET | `/api/sites/{id}/banners/random` | Random banner |
| POST | `/api/sites/{id}/contacts` | Submit a contact request |

- Request examples live in [tests/_https](./tests/_https) (`.http` files for the IDE HTTP client).
- List all routes: `make php-console`, then `bin/console debug:router`.

## Development

Run `make help` for the full list. Frequently used targets:

| Target | Action |
|---|---|
| `make docker-up` / `docker-stop` / `docker-down` / `docker-restart` / `docker-ps` / `docker-logs` | Manage the dev stack |
| `make php-console` / `node-console` / `mysql-console` | Shells in the containers |
| `make install` / `update` | `composer install` / `composer update` |
| `make migrate` | Run Doctrine migrations (dev and test) |
| `make cache-clear` | Clear the Symfony cache |
| `make jwt-keys` | Generate the JWT keypair |
| `make yarn-install` / `yarn-dev` / `yarn-watch` / `yarn-build` | Frontend build |
| `make lint` / `make lint-fix` | PHP-CS-Fixer check / fix |

Schema changes go through migrations: generate with `bin/console make:migration` inside `make php-console`, then run `make migrate`. Migration formatting follows the project skill in `.claude/skills/fix-migrations`.

## Testing

`composer.json` defines Codeception scripts (`test`, `test:api`, `test:unit`, `test:web`, `test:coverage`) and matching `make test*` targets, but Codeception is **not** a dependency of the project and there is no PHP test suite in `tests/` yet, so these targets do not work as of now. What exists:

- Manual HTTP request collections in [tests/_https](./tests/_https) (API login, page, sitemap, banners, contact, cache clear), configured through `tests/_https/http-client.env.json`.
- Code style check: `make lint`.

## Architecture

Symfony application with the namespace `App\`, using Sonata for the admin UI and a separate API layer.

```text
src/
├── Admin/                 Sonata admin classes (Content, Site, Navigation, User, Log, traits)
├── Controller/            Admin (CRUD, login), Api (Site, Admin), General (images), Web
├── ControllerRequest/     Request DTOs for API actions (+ ControllerRequestModifier, ArgumentResolver)
├── DTO/, DTOFactory/      Response/data objects and their factories
├── Entity/                Doctrine entities (Site, Page, Navigation, Log, User, RefreshToken)
├── EntityListener/        Doctrine listeners (HTML sanitizing, user, page)
├── EventSubscriber/       JWT, locale, rate limiting, exception to error conversion
├── Message/, MessageHandler/   Command, Query and Event messages and handlers
├── Contract/              Interfaces (messages, repositories, DTOs, requests)
├── Security/, Service/, Repository/, Serializer/, Validator/, ValueObject/, Twig/
config/                    Symfony config; config/admin/*.yaml registers Sonata admins
assets/                    SCSS, images, CKEditor plugins (built by Webpack Encore)
migrations/                Doctrine migrations
.docker/                   Dev images (php-fpm, nodejs, nginx, mysql, ...)
Dockerfile                 Production image (targets: app, nginx)
```

Message buses (`config/packages/messenger.yaml`): `command.bus` (default, wraps handlers in a Doctrine transaction), `query.bus`, `event.bus` (handlers optional).

## Background jobs

Messages implementing `HighPriorityAsyncMessageInterface`, `StandardPriorityAsyncMessageInterface` or `LowPriorityAsyncMessageInterface` (in `src/Contract/Message`) are routed to `queue_high_priority`, `queue_standard_priority` and `queue_low_priority`; failed messages go to the `failed` transport. A `queue_notification` transport is defined but has no routing yet. The default transport is Doctrine (`MESSENGER_TRANSPORT_DSN`); the production image also contains the `amqp` and `redis` PHP extensions.

Run a worker in dev (inside `make php-console`):

```bash
bin/console messenger:consume queue_high_priority queue_standard_priority queue_low_priority queue_notification
```

In production the `messenger-worker` service (compose profile `worker`) does the same.

## Deployment

The root [Dockerfile](./Dockerfile) builds two images, `app` (php-fpm with code, vendors and built assets) and `nginx`; [docker-compose.prod.yaml](./docker-compose.prod.yaml) runs them with MySQL. On the VPS the stack sits behind Traefik and a Cloudflare Tunnel, one domain with `/admin` and `/api`.

```bash
cp .env.prod.local.dist .env.prod.local   # fill in passwords, APP_SECRET, domain, locales
docker compose -f docker-compose.prod.yaml --env-file .env.prod.local up -d --build
```

| Task | Command |
|---|---|
| Release an update on the VPS | `bin/deploy.sh` or `make prod-deploy` (`bin/deploy.sh --help` for options) |
| Build / start / stop | `make prod-build`, `make prod-up`, `make prod-down` |
| Migrations and messenger tables | `make prod-migrate` (migrations also run on start while `RUN_MIGRATIONS=1`) |
| JWT keypair (one-time, volume `jwt`) | `make prod-jwt-keys` |
| Logs, status, shell | `make prod-logs`, `make prod-ps`, `make prod-console` |
| Check the frontend build only | `make prod-assets` |
| Start the messenger worker | `docker compose -f docker-compose.prod.yaml --env-file .env.prod.local --profile worker up -d` |

Notes: the Symfony cache is rebuilt on every php-fpm start, so `LOCALES` and `DEFAULT_LOCALE` come from `.env.prod.local`. Data lives in the named volumes `mysql`, `storage`, `sessions`, `uploaded`, `jwt`. Full guide: [docs/deploy.md](./docs/deploy.md).

## Troubleshooting

Problems documented for the production build and start (webpack out of memory, missing JWT keys, wrong host or CORS, missing assets) are in [docs/main-dockerfile-fix.md](./docs/main-dockerfile-fix.md), section 6, and [docs/deploy.md](./docs/deploy.md), section 14.

- **Build killed with exit 137 on a small VPS**: enable swap or lower the webpack heap, for example `--build-arg NODE_MAX_OLD_SPACE=1024`.
- **`network reverse-proxy declared as external, but could not be found`**: create the external networks, see [Prerequisites](#prerequisites).
- **Locale changes have no effect in production**: restart php-fpm so the cache is rebuilt with the new `LOCALES`.

## Documentation index

- [docs/deploy.md](./docs/deploy.md): deploy, update, back up and roll back on the VPS.
- [docs/main-dockerfile-fix.md](./docs/main-dockerfile-fix.md): why the production Dockerfile and compose file look the way they do.
- [AGENTS.md](./AGENTS.md): conventions for AI coding agents working on the project.

## License

Proprietary (`"license": "proprietary"` in [composer.json](./composer.json)). All rights reserved.
