# decoAfterShip Shopify CLI operations

The Shopify CLI project lives at `shopify-apps/decoAfterShip`. It is not a
separate repository and does not contain a second Laravel application.

## Ownership boundary

| Concern | Location |
| --- | --- |
| Shopify App configuration and Extensions | `shopify-apps/decoAfterShip` |
| App entry `/shopify/aftership/launch` | Root Laravel routes and `ShopifyAfterShipAppController` |
| OAuth callback `/shopify/aftership/oauth/callback` | Root Laravel routes and `ShopifyAfterShipAppController` |
| Webhook receipt and registration | Root Laravel Shopify services/controllers |
| Accounts, organizations, stores, permissions, and database | Root Laravel application |
| Application Center and six marketing modules | Root Laravel controllers and Inertia pages |

The child project has no hosted web process. Shopify-facing URLs always point to
the already deployed Laravel environment.

## Supported targets

- `test`: `https://testadmin.decomkt.com`, used for development and acceptance.
- `production`: `https://admin.decomkt.com`, used only after explicit approval.

The test-first operating sequence is code change, root and child validation,
commit to Git `test`, deploy the root Laravel application to the test site,
`pnpm run deploy:test`, then test in Shopify. A production release is a separate
decision after acceptance succeeds.

## Safety rules

- Never store Shopify Client Secrets or Access Tokens in TOML, source code, or
  documentation.
- Configure only `AFTERSHIP_<ENV>_CLIENT_SECRET` in the matching untracked
  backend environment. AfterShip OAuth stores its token on `AppInstallation`
  and must never replace the shared `ShopifyConnection` token.
- Keep `embedded = false`; users authenticate with their existing decoAdmin
  account after Shopify opens `/shopify/launch`.
- Keep `use_legacy_install_flow = true` while Laravel owns the OAuth request and
  callback flow.
- Keep automatic URL rewriting disabled so CLI commands cannot replace hosted
  URLs unexpectedly.
- Do not add code or assets belonging to Student Discount or another Shopify App.
- `shopify app deploy` releases Shopify configuration and Extensions only; it
  never deploys Laravel.

For exact setup and release commands, read
[`shopify-apps/decoAfterShip/README.md`](../shopify-apps/decoAfterShip/README.md).
