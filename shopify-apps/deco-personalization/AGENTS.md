# Deco Personalization Shopify App rules

- This directory owns only the `deco-personalization` Shopify App configuration, dependencies, extensions, validation scripts, and documentation.
- Keep all business logic in DecoAdmin using `Extension / App Home -> Controller -> Business Service -> Model / Shopify API`.
- Follow the verified Student Discount App engineering pattern for App Home identity, App Proxy verification, installation lifecycle, App Center integration, environment isolation, validation, and backend-first release order.
- Do not copy Student Discount business logic, routes, tables, discount scopes, assets, or release coupling.
- There is no runnable Local Shopify App. `shopify.app.local.toml` is a non-runnable structural placeholder and must never contain a client ID, secret, URL, webhook, callback, proxy, or deploy path.
- Test is the first runnable environment. Production configuration remains a non-runnable placeholder until a separately authorized future production process.
- Never use a temporary Cloudflare URL or add `dev`, `deploy:local`, `build:local`, or `deploy:production` scripts.
- Runtime shop, Organization, and Store resolution must be dynamic. Never hardcode a shop domain in App Home, extensions, backend routes, or configuration.
- `macfoxebike` / Macfox Bike is a permanent denylist target for every write, install, theme, extension, pixel, webhook, permission, configuration, or test operation.
- Before any Test-side action, resolve the target shop and fail closed unless it is the specifically authorized test store.
- Never commit Client Secrets, access tokens, refresh tokens, cookies, authorization headers, customer data, or environment credentials.
- Publishing, installing, changing scopes, changing themes, or changing an external environment must stay within the user's explicit Test authorization and must never touch Production or another Shopify App.
