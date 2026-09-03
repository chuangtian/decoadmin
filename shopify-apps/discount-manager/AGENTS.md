# Deco Discount Manager Shopify App rules

- This directory owns only the independent Discount Manager Shopify App.
- Its only business purpose is listing, creating, and editing Shopify discount codes for the current DecoAdmin store.
- Keep monitoring, expiry alerts, Student Discount, Personalization, and Commerce Hub behavior outside this App.
- Required scopes are exactly `read_discounts`, `write_discounts`, and `read_products`.
- Local, Test, and Production are separate environments with separate client IDs and secrets.
- Never reuse another Shopify App token, client secret, installation, or release lifecycle.
- Keep all business logic in DecoAdmin and enforce User, Organization, Store, and RBAC checks on the backend.
- Publishing, installing, changing scopes, or activating an environment requires explicit authorization for this App and exact environment.
- Never commit client secrets, access tokens, refresh tokens, cookies, authorization headers, or customer data.
