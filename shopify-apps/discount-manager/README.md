# Deco Discount Manager

Independent Shopify App authorization boundary for DecoAdmin Business Center discount management.

The Laravel backend owns the UI and business logic. This App grants one store-scoped offline token with only:

- `read_discounts`
- `write_discounts`
- `read_products`

No monitoring or expiry notifications are included in this stage.

Test and Production Dev Dashboard applications have not been created or published by this repository change. Before either environment can run, create the matching Shopify App, replace that environment's non-runnable TOML placeholder with the assigned public client ID and URLs, configure the matching untracked secret in DecoAdmin, validate, and obtain explicit release authorization.
