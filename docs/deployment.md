# DecoAdmin Staging / Production Deployment

This guide prepares a single VPS deployment using Docker Compose. The VPS should terminate HTTPS with a host-level reverse proxy (for example, Caddy or Nginx) and forward traffic to `127.0.0.1:8080`. MySQL and Redis remain internal to the Docker network and are never published to the host.

## Deployment layout

- `compose.yaml`: local development with source mounts, Vite, Mailpit, Pulse and optional Cloudflare Tunnel.
- `compose.production.yaml`: immutable Staging/Production images with Laravel PHP-FPM, Nginx, MySQL, Redis, Horizon and Scheduler.
- `app-production` image: application code, production Composer dependencies and built Vite assets.
- `nginx-production` image: Nginx configuration and the same built public assets.

The production Compose file does not mount the repository into containers. Only persistent application storage, Laravel bootstrap cache, MySQL data and Redis data use named volumes.

## VPS prerequisites

### Configured SSH access (local workstation)

- Host: `45.76.77.146`, SSH user: `root`, port: `22`.
- User-provided private-key path: `/Users/tianchuang/Downloads/key-uh9wq9m7.pem`.
- Record the path only. Never copy private-key contents into this repository, logs, chat, or committed configuration. Keep file permissions restricted to the current user (`0600`).
- This path is workstation-specific. Use it for already-authorized deployments without asking for the key again; if authentication fails, diagnose and report the failure without exposing the key.
- Select the requested environment before any server write. Test: `/opt/decoadmin/staging`, environment file `/opt/decoadmin/staging/.env.staging`, Compose project `decoadmin-staging`, URL `https://testadmin.decomkt.com`. Production: `/opt/decoadmin/production`; a test deployment never authorizes production changes.

```bash
ssh -i /Users/tianchuang/Downloads/key-uh9wq9m7.pem -o IdentitiesOnly=yes root@45.76.77.146
```

1. Install Docker Engine with the Compose plugin.
2. Clone the private repository on the VPS.
3. Configure DNS for the Staging or Production hostname.
4. Configure a host reverse proxy with a valid TLS certificate and proxy to `http://127.0.0.1:8080`.
5. Allow public inbound traffic only on ports 80 and 443. Do not expose ports 3306 or 6379.

## Environment file

Copy the template without committing the result:

```bash
cp .env.example .env.staging
chmod 600 .env.staging
```

At minimum, replace these values:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.example.com
APP_PORT=8080
APP_ENV_FILE=.env.staging
COMPOSE_PROJECT_NAME=decoadmin-staging
APP_IMAGE=decoadmin-app:staging
NGINX_IMAGE=decoadmin-nginx:staging

APP_KEY=base64:generate-a-real-key
DB_DATABASE=decoadmin
DB_USERNAME=decoadmin
DB_PASSWORD=generate-a-strong-password
MYSQL_ROOT_PASSWORD=generate-another-strong-password

REDIS_PASSWORD=generate-a-strong-password
REDIS_PREFIX=decoadmin_staging_database_
CACHE_PREFIX=decoadmin_staging_cache

LOG_CHANNEL=stack
LOG_STACK=stderr
LOG_LEVEL=info
LOG_DEPRECATIONS_CHANNEL=stderr

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=staging.example.com

HORIZON_NAME="DecoAdmin Staging"
HORIZON_PROCESSES=5
HORIZON_FAST_TERMINATION=true
HORIZON_ALLOWED_EMAILS=admin@example.com

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=replace-me
MAIL_PASSWORD=replace-me
MAIL_FROM_ADDRESS=admin@example.com
MAIL_EHLO_DOMAIN=staging.example.com

SHOPIFY_CLIENT_ID=replace-me
SHOPIFY_CLIENT_SECRET=replace-me
SHOPIFY_APP_URL=https://staging.example.com
SHOPIFY_REDIRECT_URI=https://staging.example.com/shopify/oauth/callback
```

Generate `APP_KEY` without putting it into shell history:

```bash
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Never commit `.env.staging`, access tokens, Shopify secrets, SMTP passwords or database credentials. The Docker build context excludes all `.env*` files except `.env.example`.

## Storage

The default `FILESYSTEM_DISK=local` stores private files in the persistent `storage` volume. For S3-compatible storage, set `FILESYSTEM_DISK=s3` and configure the `AWS_*` variables. Keep object-storage credentials only in the untracked environment file or a VPS secret manager.

## Initial deployment

Use the same environment file for Compose interpolation and container runtime configuration:

```bash
docker compose --env-file .env.staging -f compose.production.yaml build
docker compose --env-file .env.staging -f compose.production.yaml run --rm setup
docker compose --env-file .env.staging -f compose.production.yaml up -d app nginx horizon scheduler
docker compose --env-file .env.staging -f compose.production.yaml ps
```

The one-shot `setup` service waits for MySQL and Redis, runs migrations, and caches Laravel configuration, routes, events and views. Run it for every release before starting the updated services.

Optional Pulse server monitoring can be started separately:

```bash
docker compose --env-file .env.staging -f compose.production.yaml --profile monitoring up -d pulse
```

## Health checks

Two endpoints are available:

- `/up`: Laravel process liveness.
- `/health`: deployment readiness for Laravel, Database and Redis.

`/health` returns HTTP 200 when all checks pass and HTTP 503 when Database or Redis is unavailable. It intentionally omits exception messages, credentials, hostnames and connection strings.

Verify from the VPS:

```bash
curl --fail --silent http://127.0.0.1:8080/health
```

The production Nginx container uses this endpoint as its Docker health check.

## Horizon and queues

Horizon runs as an independent container and supervises these Redis queues in priority order:

1. `shopify-webhook`
2. `shopify-sync`
3. `default`
4. `notifications`

Useful commands:

```bash
docker compose --env-file .env.staging -f compose.production.yaml exec horizon php artisan horizon:status
docker compose --env-file .env.staging -f compose.production.yaml exec app php artisan horizon:terminate
docker compose --env-file .env.staging -f compose.production.yaml logs --tail=200 horizon
```

Horizon remains protected by authentication. Super Admin users and comma-separated addresses in `HORIZON_ALLOWED_EMAILS` may access its dashboard.

## Scheduler

The `scheduler` container runs `php artisan schedule:work`. Confirm the registered schedule and execute one deployment check with:

```bash
docker compose --env-file .env.staging -f compose.production.yaml exec scheduler php artisan schedule:list
docker compose --env-file .env.staging -f compose.production.yaml exec app php artisan schedule:run --no-interaction
```

Only one Scheduler container should run for a single environment.

Shopify 自动同步默认开启。部署环境可通过以下变量调整节奏：

```dotenv
SHOPIFY_SCHEDULED_SYNC_ENABLED=true
SHOPIFY_SCHEDULED_SYNC_TIMEZONE=America/New_York
SHOPIFY_INCREMENTAL_SYNC_INTERVAL=15
SHOPIFY_INVENTORY_SYNC_INTERVAL=30
SHOPIFY_INCREMENTAL_OVERLAP_MINUTES=5
SHOPIFY_SYNC_MAX_ATTEMPTS=3
SHOPIFY_RECONCILIATION_TIME=03:00
SHOPIFY_FULL_SYNC_WEEKDAY=1
SHOPIFY_FULL_SYNC_TIME=02:00
SHOPIFY_SYNC_STALLED_AFTER_MINUTES=45
```

调度器每 5 分钟扫描店铺，实际是否创建任务由每类数据的 `next_sync_at` 决定。首次同步会自动使用全量模式；之后使用带 5 分钟重叠窗口的增量模式。每天执行一次一致性校准，每周执行一次全量同步，并每天校准 Webhook 订阅。同步任务通过店铺、类型、模式和时间窗口生成幂等键，同一店铺同一类型已有排队或运行任务时不会重复入队。

部署后应确认 `shopify:incremental-sync`、`shopify:daily-sync-reconciliation`、`shopify:weekly-full-sync` 和 `shopify:webhook-subscription-reconciliation` 均出现在调度列表中，并在“系统状态”页面确认 Scheduler、Horizon 与 `shopify-sync` 队列正常。

## Release procedure

### Source of truth and reproducibility

Use `origin/test` for test releases. The server repository is `/opt/decoadmin/repository`; `/opt/decoadmin/staging` may be an exported deployment directory without `.git`.

Fetch `origin/test`, record its full commit SHA, and export that exact commit to a new release directory (for example with `git archive`). Run validation and build images from that export. Keep the environment file outside the Git export and supply it separately to Compose. Never overlay source from an old container or a developer's uncommitted working tree onto the build context.

Label or record the same full source SHA for the app, nginx, and any workers being released. Deploy only within the explicitly authorized environment and service scope. After deployment, verify health and the source revision; a stale `RELEASE` file is not sufficient evidence. Reconcile unexpected source differences into Git before another release rather than carrying them forward as uncommitted patches.

The commands below assume a clean Git checkout. Do not run the Git commands in an exported staging directory. The reconciliation recorded in `test-source-reconciliation-20260909.md` did not deploy services.

```bash
git fetch --all --prune
git checkout test
git pull --ff-only

docker compose --env-file .env.staging -f compose.production.yaml build
docker compose --env-file .env.staging -f compose.production.yaml run --rm setup
docker compose --env-file .env.staging -f compose.production.yaml up -d --remove-orphans app nginx horizon scheduler
docker compose --env-file .env.staging -f compose.production.yaml exec app php artisan horizon:terminate
curl --fail --silent http://127.0.0.1:8080/health
```

After health verification, test Login, Store Context, Shopify Connection Health, Webhook receipt and one small Sync Job from the Staging UI.

## Logs and sensitive data

Staging/Production should use `LOG_STACK=stderr` so Docker captures application logs. The application log processor redacts token, secret, authorization, password, cookie, header and payload fields, including common Shopify token formats. Webhook payloads remain encrypted in the database and should not be copied into operational logs.

Inspect logs with:

```bash
docker compose --env-file .env.staging -f compose.production.yaml logs --tail=200 app horizon scheduler nginx
```

## Backup and rollback

- Back up the MySQL volume before every migration and run scheduled off-VPS database backups.
- Back up the `storage` volume when local storage is used.
- Keep the previous application and Nginx image tags until the release is accepted.
- To roll back application code, restore the previous image tags and run `up -d`; only reverse a migration after reviewing whether it is data-destructive.
- Never restore MySQL or Redis ports to public host bindings.
