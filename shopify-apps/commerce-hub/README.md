# Commerce Hub Shopify App

This is the configuration-only Shopify CLI project for DecoAdmin's primary Commerce Hub connection. The Laravel backend remains in the repository root; this directory owns only Shopify App configuration and future extensions.

## Environment isolation

| Environment | Client ID | App URL |
| --- | --- | --- |
| local | `8060ab64cae19ff20a05814f1276d716` | `https://consistent-menu-herself-telephony.trycloudflare.com` |
| test | `c6921ca2233c5069033577d3cd1759ea` | `https://testadmin.decomkt.com` |
| production | `52f415f03423fe1f1838dcfa7a0fca9a` | `https://admin.decomkt.com` |

The three environments are separate Shopify Apps. Never reuse IDs, secrets, URLs, data, or release actions between them.

## Scope and installation contract

Commerce Hub requires exactly:

- `read_products`
- `read_inventory`
- `read_orders`
- `read_customers`
- `read_locations`
- `read_reports`

Discount scopes belong to `shopify-apps/student-discount/` and must never be added here. DecoAdmin currently implements the authorization-code callback at `/shopify/oauth/callback`, so every configuration must keep `use_legacy_install_flow = true`.

## Validation

```bash
npm run check
npm run check:config
npm run check:config:test
npm run check:config:production
```

`npm run check` also verifies the Laravel default scopes and `.env.example`, so backend and Shopify configuration cannot silently drift apart.

## Release

Deploy the Laravel backend first. Confirm that the target environment uses the same six `SHOPIFY_REQUESTED_SCOPES`, refresh Laravel's configuration cache, and only then publish the matching Shopify App configuration:

```bash
npm run deploy:local
npm run deploy:test
npm run deploy:production
```

Each deploy command affects only its named Shopify App environment and requires explicit authorization.
