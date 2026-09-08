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
