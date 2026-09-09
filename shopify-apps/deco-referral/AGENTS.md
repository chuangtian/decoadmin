# Deco Referral Shopify App rules

- This directory owns only the Deco Referral Shopify App configuration, dependencies, extensions, validation scripts, and documentation.
- Keep referral and affiliate business logic in DecoAdmin using `Extension / App Home -> Controller -> ReferralAffiliate Service -> Model / Shopify API`.
- The App is for stores in the E-LINK TECHNOLOGY CO LTD Shopify Plus organization and uses custom distribution.
- Local, test, and production are separate App configurations and credentials. Never reuse client IDs, secrets, tokens, URLs, or release actions between environments.
- Configuration files remain non-runnable placeholders until the matching backend identity, webhook, proxy, and installation routes exist.
- Never hardcode a store domain. Resolve the store, Organization, installation, and permissions from verified Shopify identity or signatures.
- Publishing, installing, changing scopes, or modifying a Shopify environment requires explicit authorization for this App and that environment.
- Never commit Client Secrets, access tokens, refresh tokens, cookies, webhook payloads containing customer data, or environment credentials.

## Authorized test scope

- Only `macfox-test-app.myshopify.com` is authorized for this App's backend testing, installation, theme changes, webhook operations, and Shopify writes.
- Production and all other stores are excluded, including other stores in the same Plus organization.
- Verify the trusted Shopify identity and backend Store mapping before any operation; a browser-provided shop name is not authorization.
