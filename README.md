# Shopify Commerce Hub

Laravel 13 / PHP 8.4 foundation for a multi-organization, multi-store Shopify operations platform.

## Local development

Docker and Docker Compose are the only host requirements. Start every service with:

```bash
docker compose up -d --build
```

The one-time `setup` service creates `.env` from `.env.example` when needed, generates a local application key, copies the production frontend build, and runs pending migrations. It never writes credentials into `.env.example`.

- Application: <http://localhost:8000>
- Horizon: <http://localhost:8000/horizon>
- Pulse: <http://localhost:8000/pulse>
- Mailpit: <http://localhost:8025>
- Vite: <http://localhost:5173>
- MySQL: `127.0.0.1:33060`
- Redis: `127.0.0.1:6379`

The application, Horizon, Pulse server monitor, scheduler, Mailpit, Vite server, MySQL 8.4, Redis 7.4, and Nginx run as separate services. Horizon consumes queues in this order:

```text
shopify-webhook > shopify-high > default > shopify-sync > notifications
```

## Validation

```bash
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan migrate:status
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
docker compose exec app php artisan horizon:status
npm run type-check
npm run build
```

Session, cache, and queue use Redis by default. Horizon is the primary queue worker. Database-backed cache/job migrations remain in the Laravel skeleton as an optional fallback, but are not selected by `.env.example`. Local mail uses Mailpit at `mailpit:1025`; no message is relayed externally.

This foundation intentionally does not include RBAC seed data, Shopify OAuth behavior, or token refresh behavior.
