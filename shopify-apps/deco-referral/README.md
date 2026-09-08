# Deco Referral

Internal referral and affiliate marketing App for stores in the E-LINK TECHNOLOGY CO LTD Shopify Plus organization.

DecoAdmin owns programs, promoters, attribution, commission and reward ledgers, risk review, reporting, and payouts. This directory owns only Shopify App configuration, dependencies, extensions, and release commands.

## Environment status

Local, Test, and Production are intentionally separate. Their TOML files are structural placeholders until the corresponding App is created or selected in the E-LINK Dev Dashboard and the matching DecoAdmin backend endpoints are available.

No Shopify version is published from this foundation stage.

## Validation

```shell
npm run check
```

## Planned minimum scopes

The first working version is expected to need `read_orders`, `read_products`, `read_discounts`, `write_discounts`, and storefront extension scopes that are confirmed when the extensions are implemented. Customer scopes are added only when customer referral needs them.

## Current pilot boundary

Only `macfox-test-app.myshopify.com` may be used for backend and Shopify testing. All other stores and the production runtime are rejected by `AffiliateShopGuard`, including direct service calls. This guard does not replace User, Organization, Store, or RBAC authorization.

App credentials use environment-specific `REFERRAL_LOCAL_CLIENT_ID`, `REFERRAL_LOCAL_CLIENT_SECRET`, `REFERRAL_TEST_CLIENT_ID`, and `REFERRAL_TEST_CLIENT_SECRET` secret configuration. No cross-environment fallback is allowed. Each installation and registered App must match `referral.environment`; the registered App must have `settings.managed_by = referral_config`. Token refresh updates only the matching Referral installation and never falls back to the Commerce Hub token.

Shopify installation/bootstrap and discount synchronization are not yet connected. The coupon records remain pending and cannot currently be redeemed in Shopify.
