# Deco Referral

Internal referral and affiliate marketing App for stores in the E-LINK TECHNOLOGY CO LTD Shopify Plus organization.

DecoAdmin owns programs, promoters, attribution, commission and reward ledgers, risk review, reporting, and payouts. This directory owns only Shopify App configuration, dependencies, extensions, and release commands.

## Environment status

Local, Test, and Production are intentionally separate. Local and Production remain non-runnable placeholders. Test uses the independent `Deco Referral Test` App (Dev Dashboard ID `420468817921`) and the staging URL. Version `referral-test-home-20260908` was published on 2026-09-08. Installation on `macfox-test-app` and live independent authorization bootstrap were verified on that date; the Shopify App Home successfully opens the matching DecoAdmin store workspace.

## Validation

```shell
npm run check
```

## Planned minimum scopes

The first working version is expected to need `read_orders`, `read_products`, `read_discounts`, `write_discounts`, and storefront extension scopes that are confirmed when the extensions are implemented. Customer scopes are added only when customer referral needs them.

## Current pilot boundary

Only `macfox-test-app.myshopify.com` may be used for backend and Shopify testing. All other stores and the production runtime are rejected by `AffiliateShopGuard`, including direct service calls. This guard does not replace User, Organization, Store, or RBAC authorization.

App credentials use environment-specific `REFERRAL_LOCAL_CLIENT_ID`, `REFERRAL_LOCAL_CLIENT_SECRET`, `REFERRAL_TEST_CLIENT_ID`, and `REFERRAL_TEST_CLIENT_SECRET` secret configuration. No cross-environment fallback is allowed. Each installation and registered App must match `referral.environment`; the registered App must have `settings.managed_by = referral_config`. Token refresh updates only the matching Referral installation and never falls back to the Commerce Hub token.

The backend installation bootstrap endpoint is implemented at `POST /api/shopify-app/referral/bootstrap`. It requires a verified Referral App identity token, exchanges it for an independent offline token, verifies Shopify's returned shop identity and scopes, and records only the matching Referral installation. The test App's own credentials must be configured before real installation can be verified.

The Shopify embedded entry is `GET /shopify-app/referral`; its public shell exposes no store records and the bootstrap endpoint verifies identity separately. `GET /shopify-app/referral/manage` requires DecoAdmin login and store permission. App Home source lives in this App's `resources/` directory.

Discount synchronization is implemented for the test pilot. Approval, membership/program transitions, and store feature changes enqueue reconciliation on the dedicated `affiliate` queue. A manual retry is available on the promoter page. Synchronization uses only the Referral installation token, recovers an existing owned code after an interrupted create, and refuses unrelated code collisions. The worker retries failures four times; exhausted failures remain visible for manual retry. Checkout attribution, paid-order processing, commission/refund ledgers, payouts, and the customer portal remain unfinished.

The pilot currently creates non-combinable discounts for all products and customers; fixed amounts support USD, CAD, EUR, GBP, AUD and HKD in the matching store currency. Changes are asynchronous: a pending state means the Shopify state has not yet been confirmed. Do not treat a pending disable as already disabled.

The dedicated test worker requires both Compose files, run from the repository root with the staging environment explicitly selected: `-f compose.production.yaml -f shopify-apps/deco-referral/compose.worker.test.yaml`. It consumes only `affiliate`; existing Horizon services are not changed.

The current backend foundation was deployed to staging on 2026-09-08. See [the staging verification record](docs/staging-2026-09-08.md).
