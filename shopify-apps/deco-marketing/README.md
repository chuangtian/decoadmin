# Deco Marketing — test pilot

Independent marketing automation module integrated into DecoAdmin. Only `macfox-test-app.myshopify.com` and local/testing/test/staging are allowed by backend guards, including HTTP, CLI, workers and signed callbacks. `Macfox Bike`, MACFOX CRM and crm.macfoxbike.com are read-only references, never deployment targets.

## Current implementation

- Workspace overview, six configurable flows, versioned template drafts/test/publish, contact CSV preflight/import, consent/suppression, campaigns with scheduled start/pause/cancel and A/B cohort snapshots.
- Durable enrollment and per-step delivery records, encrypted PII/payloads, unique business identifiers, replay-safe send payloads, recipient allowlist, rate limits and circuit breaker. Resend ambiguous outcomes are held before its 24-hour deduplication window expires.
- Independent Shopify installation/token refresh, webhook inbox and authoritative pre-send checks; signed Resend delivery events, unsubscribe confirmation and click links.
- Default preview mode never delivers mail and is counted separately. Preview enrollments cannot become live by changing configuration.
- Contact imports do not trigger historical workflows and cannot silently overwrite withdrawn consent. Historical CRM data has NOT been migrated.

## Validation (Docker)

```
docker compose exec -T app composer dump-autoload --no-scripts
docker compose exec -T app php artisan test shopify-apps/deco-marketing/tests
docker compose exec -T vite ./node_modules/.bin/vue-tsc --noEmit -p shopify-apps/deco-marketing/tsconfig.json
docker compose exec -T vite npm run build
docker compose restart vite
```

Use `python3 scripts/validate-queries.py` with `NODE_BINARY` and `SHOPIFY_ADMIN_SKILL` pointing at the installed official tooling. Its schema validation is offline with telemetry disabled.

Shopify release requires `shopify app config validate --path shopify-apps/deco-marketing --config test --json`, matching backend routes and explicit test target. Local and production configurations are intentionally unconfigured. Never release them or substitute another app's credentials.

## Configuration

`MARKETING_APP_ENV=test`, `MARKETING_TRANSPORT=preview`, `MARKETING_SCHEDULED=false` are the pilot defaults. The independent app owns `MARKETING_TEST_CLIENT_ID` and `MARKETING_TEST_CLIENT_SECRET` in the private staging env. Do not commit `.env*`.

Real mail requires `MARKETING_TRANSPORT=resend`, dedicated `MARKETING_TEST_RESEND_KEY`, `MARKETING_TEST_RESEND_WEBHOOK_SECRET`, `MARKETING_TEST_MAIL_FROM`, and the explicit `MARKETING_TEST_RECIPIENTS` allowlist. Default authorized recipient is `jiushizheyike@gmail.com`. Configuration belongs only to staging. No old CRM credentials are imported.

Scheduled processing remains off until pilot verification. Manual engine execution: `php artisan marketing:run --store=<verified-local-test-store-id>`. Do not run database-refresh tests against a deployed database.

## Before replacement of the old system

- Obtain consent/suppression history, templates, send IDs, flow positions, campaign cohorts and old tracking URL mappings.
- Verify payment/recovery links, store integration, bounces/complaints and actual recipient delivery in the test store.
- Shopify synchronization processes 100 records per resource per run with durable cursors and overlapping incremental windows. Historical order access still depends on the installed app scopes; do not claim all old orders are available.
- Validate A/B outcome policy and tracking measurement semantics; recorded opens/clicks do not prove a human interaction.
- Define shutdown/drain/cutover and rollback with exactly one live sender. Existing historical links need explicit compatibility routing; that has not been enabled.
