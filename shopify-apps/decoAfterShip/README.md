# decoAfterShip Shopify CLI project

This directory is the Shopify CLI boundary for decoAfterShip. It versions the
Shopify App configuration and future Extensions while the repository root keeps
the Laravel application and all hosted business behavior.

## Runtime boundary

Shopify opens the non-embedded App at:

```text
/shopify/aftership/launch
```

Shopify OAuth returns to:

```text
/shopify/aftership/oauth/callback
```

Both endpoints are implemented by the Laravel application at the repository
root. AfterShip uses its own environment-specific Client ID and Secret and
stores its offline Admin API token on the matching `AppInstallation`; it never
replaces the store's shared Commerce Hub token. The existing decoAdmin login,
organization/store authorization, database, Webhooks, Application Center, and
six marketing modules remain there. This directory intentionally contains no
PHP application, database, authentication flow, or duplicated admin UI.

## Environments

| Configuration | Hosted backend | Purpose |
| --- | --- | --- |
| `test` | `https://testadmin.decomkt.com` | Development, integration, and acceptance testing |
| `production` | `https://admin.decomkt.com` | Explicitly approved production releases |

`shopify.app.toml` is a safe bootstrap configuration for project discovery and
mirrors the test URLs. Operational commands always select `test` or `production`
explicitly. There is no local Shopify App configuration in this project.

## First setup on another computer

Clone decoAdmin and start from its canonical development branch:

```bash
git clone https://github.com/chuangtian/decoadmin.git
cd decoadmin
git switch test
git pull --ff-only origin test
cd shopify-apps/decoAfterShip
corepack enable
pnpm install --frozen-lockfile
```

Node.js 22.12 or newer is required. Sign in with the Shopify account that can
access the existing decoAfterShip App in the Dev Dashboard, then link the test
configuration:

```bash
pnpm run config:link:test
pnpm run config:use:test
pnpm run config:pull:test
pnpm run verify
```

`config link` pulls the selected Dashboard App into
`shopify.app.test.toml`. Review the resulting diff and keep the test App URL,
OAuth redirect URL, non-embedded mode, scopes, and API version aligned with the
versioned template. Never add a Client Secret, Admin API Access Token, session
token, store token, or root Laravel `.env` value to this directory.

## Test-first development and release

The normal workflow is:

1. Make Laravel and/or Extension changes on Git branch `test`.
2. Run the relevant root Laravel tests and `pnpm run verify` here.
3. Commit and push `test` only after review.
4. Deploy the Laravel root project to `https://testadmin.decomkt.com` using
   [`../../docs/deployment.md`](../../docs/deployment.md).
5. Release the test Shopify App configuration and Extensions:

```bash
pnpm run config:use:test
pnpm run deploy:test
```

6. Test the real Shopify entry, OAuth callback, Webhooks, and six module links
   against the test site.

`shopify app deploy` creates and releases a Shopify App version containing App
configuration and Extensions. It does **not** deploy the Laravel backend; the
root application must be deployed separately before Shopify-side testing.

## Extension development

Create future extensions only inside `extensions/` and against the test App:

```bash
pnpm run config:use:test
pnpm run generate:extension
pnpm run verify
```

The six current marketing modules are hosted Laravel pages, not Shopify
Extensions, so they remain in `resources/js/Pages/Marketing` at the repository
root.

## Production release

Production work starts only after the test release passes and the user explicitly
approves a production release. From an approved `main` checkout:

```bash
cd shopify-apps/decoAfterShip
pnpm install --frozen-lockfile
pnpm run config:link:production
pnpm run config:use:production
pnpm run config:pull:production
pnpm run config:validate:production
pnpm run build:production
pnpm run deploy:production
```

Confirm the command summary names the production App and
`https://admin.decomkt.com` before approving the release. Deploying the root
Laravel application remains a separate operation.
