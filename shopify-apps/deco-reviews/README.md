# Deco Reviews

Standalone product/store reviews app, independent of Community Reviews and Loox.

User-confirmed data boundary (2026-09-13): existing system reviews are unrelated to Deco Reviews. Never automatically read, import, synchronize, merge or link `reputation_mentions`, Community Reviews or other legacy review data into this app. Preserve their existing display and management. Any future migration requires separate explicit authorization. Shared Store/Product references do not imply shared review data.

## Current delivery

This is a **core test version, not full Loox parity**. The detailed remaining scope and acceptance plan live in [docs/acceptance.md](docs/acceptance.md).

Implemented: scoped DecoAdmin review management; product/store reviews; rating-neutral moderation and scheduled publishing; merchant replies and featured reviews; encrypted private email; image re-encoding and manual video moderation; signed order-bound buyer forms; signed app-proxy product review collection with mandatory moderation; exact product/fulfillment invitation verification; separate request and photo/video reminders; product/store thank-you and public-reply lifecycle messages with encrypted recipients, idempotency, audit and SMTP uncertainty hold; explicit per-store automation gates; custom CSV import/deduplication/seven-day withdrawal; formula-safe export; nine storefront display modes and media viewer. Carousel controls, gallery media-only rendering, sidebar/floating disclosure, keyboard focus restoration, reduced-motion behavior, error cleanup and theme-section teardown are implemented in the current unreleased widget increment; this is not a claim of 17-widget parity.

Form increment (released to test; not production): configurable buyer form copy, photo/video controls, up to ten single/multiple/scale questions, product/store targeting, mandatory answers, immutable encrypted answer snapshots, explicit public/private visibility, version conflict protection, and a non-submitting saved-form preview. This increment changes only files inside this app; it does not alter shared backend code or other apps.

Reward increment (implemented locally, not released): an eligible published photo/video review from an order-verified email invitation can create one non-combinable, one-use Shopify percentage, fixed-amount, or free-shipping code. Records are store-scoped and idempotent, codes and delivery recipients are encrypted and excluded from management history, Shopify writes require a separate fail-closed environment gate, and uncertain results are held without retry. A confirmed issue schedules one reward email; an optional reminder is scheduled once only after the first email is confirmed sent and before expiry. Administrators can explicitly record a held Shopify result as created or not created without triggering another Shopify call.

Not yet complete: all 17 Loox widgets and editors, general/store review links, collection-targeted questions and answer exports, product groups/bundles, redemption/refund/revoke handling and held-email reconciliation, referrals, AI/Studio, review syndication and Merchant API/webhooks. No placeholder is presented as a working external integration.

## Safety and environments

- Only the **test** identity is registered: client ID `a755a5ea264486246fd8836dab3e004c`.
- Test backend: `https://testadmin.decomkt.com`; management `/organizations/{organization}/stores/{store}/deco-reviews`.
- Local and production identities remain intentionally blank. Do not reuse test credentials or deploy their incomplete configurations.
- Current acceptance target (2026-09-13): only Shopify **macfox-test-app.myshopify.com**, mapped to test-backend store **#1**, and its unpublished **Horizon** draft theme ID **164659659000**. Never publish the theme. Do not perform Macfox Bike theme operations under this authorization. Use `testadmin.decomkt.com`, not local or production, for live acceptance.
- No real customer mail, live discount changes, or live order automation. Test environment defaults to sending disabled, empty store allowlist and empty recipient allowlist. The draft theme block cannot enable any automation.
- The test app configuration now requests `read_products,read_orders,read_customers,write_app_proxy,read_discounts,write_discounts`; an existing installation does not receive the added scopes until separately authorized. Local and production app configurations are unchanged.
- Credentials stay in ignored `.env.test` / server environment files. Never print them or add them to source.

## Development and validation

Run project commands in the existing Docker setup:

```sh
docker compose exec -T vite npm --prefix shopify-apps/deco-reviews ci
docker compose exec -T vite npm --prefix shopify-apps/deco-reviews run build:assets
docker compose exec -T app php artisan test shopify-apps/deco-reviews/tests
docker compose exec -T vite npm run type-check
docker compose exec -T vite npm run build
docker compose restart vite
```

The app owns its build dependencies. Asset build enforces a JavaScript size below Shopify's 10,000-byte theme threshold. Shopify CLI must validate `--config test`, then build this app, before an explicitly authorized test release.

For the backend test image, build from the exact clean Git export with `-f shopify-apps/deco-reviews/Dockerfile.test`. This app-owned recipe includes the new app's frontend in the existing build stages without editing the shared Dockerfile. It does not rebuild or release any other Shopify App identity.

## Data and operations

- User/Organization/Store/RBAC are checked on management routes and Services. Shopify session token uses this app's client ID/secret. App Proxy signature resolves the trusted store on the server.
- Public feeds never serialize email, email hashes, order IDs, moderation reasons or merchant fields. Private media preview requires the same authenticated store permission. Published media is unavailable when display is disabled or the review is withdrawn.
- Image uploads are decoded within a 12-million-pixel bound and re-encoded to WebP to remove metadata. MP4/WebM uploads are inspected with a bounded `ffprobe` process for real MIME/container, one video stream, supported codec, duration up to 120 seconds and resolution up to 3840x2160. Videos remain pending and are never auto-published; transcoding and malware scanning remain on the acceptance backlog.
- Public product-form submissions require a current Shopify App Proxy signature, a current-store product, email and publication consent. Email is encrypted and never serialized publicly. A honeypot plus a hashed store/product/email rate limit reduces automated abuse. These reviews are always unverified and pending manual moderation, even when automatic publishing is otherwise immediate.
- `deco-reviews:publish` runs every five minutes in batches of 500. It uses the policy captured when a review was created, equally for all star ratings.
- `deco-reviews:dispatch` verifies a bounded batch, using existing `notifications` queue priority without changing Horizon configuration. Explicit environment/shop/recipient gates are required before SMTP is attempted. Uncertain delivery is held for inspection, not retried.
- Automatic discovery is bounded to new paid orders after the server-owned activation timestamp. One-time reminders recheck fulfillment, suppression and completion; a completed invitation never sends a reminder. Initial and reminder messages have independent copy and preview routes. The full seven-message program remains pending.
- `deco-reviews:dispatch-rewards` processes only explicitly allow-listed active stores when the independent `DECO_REVIEWS_<ENV>_REWARD_WRITES_ENABLED` gate is true. Eligibility is rechecked immediately before each Shopify mutation. Unpublished reviews or disabled reward/media settings cancel only still-scheduled records; an in-flight or uncertain mutation is held for manual reconciliation and is never blindly retried.
- Custom CSV columns: `product_handle,rating,author_name,body,reviewed_at`; optional `author_email,title`. Maximum 15 MB and 1,000 rows per batch. Imports never infer verified purchase from CSV assertions. Undo withdraws records rather than deleting audit history.
- The local preview fixture command refuses non-local environments, only targets `macfox-test-app.myshopify.com`, and labels every row DEMO. It must not be run on staging or production.

## Release protocol

Commit reviewed source to `origin/test`; build the backend from a clean export of the exact fetched SHA; back up staging DB/config and previous images. Configure only the independent test App credentials. Verify routes/health/queues/schedules before deploying the Shopify test version. Record commit, app version, assertions, theme state and rollback details. Test deployment does not authorize production.
