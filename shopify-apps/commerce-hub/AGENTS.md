# Commerce Hub Shopify App rules

- This directory owns the Shopify configuration for the primary DecoAdmin Commerce Hub App.
- Keep local, test, and production as three distinct Shopify Apps with different client IDs and URLs.
- The required scopes are exactly `read_products`, `read_inventory`, `read_orders`, `read_customers`, `read_locations`, and `read_reports`.
- Never add `read_discounts` or `write_discounts`; those belong to the independent Student Discount App.
- Keep `use_legacy_install_flow = true` while DecoAdmin uses the authorization-code callback at `/shopify/oauth/callback`.
- Do not switch to Shopify-managed installation until the backend has been explicitly migrated to session-token exchange.
- Run `npm run check` and the matching `npm run check:config:<environment>` before publishing.
- Publishing any environment requires explicit authorization for this App and that exact environment.
- Never store client secrets, access tokens, refresh tokens, cookies, or credentials in this directory.
