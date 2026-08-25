# Extensions

Shopify CLI generates every decoAfterShip extension in this directory. Each
extension must live in its own folder and include `shopify.extension.toml`.

Generate an extension against the test configuration from the parent directory:

```bash
pnpm run generate:extension
```

Do not place Laravel controllers, models, routes, credentials, or code belonging
to another Shopify App in this directory.
