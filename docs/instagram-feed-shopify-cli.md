# instagram-feed Shopify CLI operations

The Shopify CLI project lives at `shopify-apps/instagram-feed`. It is not a
separate repository and does not contain a second Node, Remix, or Prisma
application. The project was originally scaffolded as a standalone Remix app; all
of that backend now lives in the root Laravel application.

## Ownership boundary

| Concern | Location |
| --- | --- |
| Shopify App configuration and Extensions | `shopify-apps/instagram-feed` |
| Admin entry `/shopify-app/instagram-feed` | Root Laravel `ShopifyInstagramFeedAppController` |
| App Bridge `connection` and `bootstrap` | Root Laravel `ShopifyInstagramFeedAppController` |
| Meta OAuth and compliance callbacks | Root Laravel `InstagramFeedMetaCallbackController` |
| Webhook receipt | Root Laravel `ShopifyInstagramFeedWebhookController` |
| Accounts, sync, R2 mirroring, galleries, publishing | Root Laravel `app/Services/InstagramFeed` |
| Admin pages | Root Laravel `resources/js/Pages/InstagramFeed` |
| Users, organizations, stores, permissions, database | Root Laravel application |

The child project has no hosted web process. Shopify-facing URLs always point to
the already deployed Laravel environment.

## Supported targets

There is a single Shopify App (`deco-instagram-feed`, client id
`d3446448682d2950aa75cea4a399d50f`) shared by both targets:

- `test`: `https://testadmin.decomkt.com`, declared in `shopify.app.test.toml`.
  Currently the live target.
- `production`: `https://admin.decomkt.com`, declared in
  `shopify.app.production.toml`. Currently parked.

Because one Shopify App holds one `application_url` and one webhook endpoint set,
the two targets are mutually exclusive: `deploy:test`
parks production and `deploy:production` parks test. The local Cloudflare tunnel
target was removed and `shopify.app.local.toml` must not come back.

`shopify.app.toml` is the CLI selected configuration and must mirror whichever
target is live, and `scripts/validate-project.mjs` enforces that. Running the app
against a target whose `INSTAGRAM_FEED_<ENV>_CLIENT_ID` and `_CLIENT_SECRET` are
missing makes the backend return `INSTAGRAM_FEED_APP_NOT_CONFIGURED`.

Do not use `shopify app config link`: it overwrites the local TOML with Dev
Dashboard defaults. The repository TOML files are the source of truth and are
pushed by `deploy:test` / `deploy:production`.

The test-first operating sequence is code change, root and child validation,
commit to Git `test`, deploy the root Laravel application to the test site,
`npm run deploy:test`, then test in Shopify. A production release is a separate
decision after acceptance succeeds.

## Two-sided release order

Publishing is always backend first, Shopify second:

1. Deploy the root Laravel application so every route in
   [`docs/instagram-feed-integration.md`](instagram-feed-integration.md) responds.
2. Run migrations so the five `instagram_*` tables exist.
3. Seed permissions so `instagram_feed.*` is assignable.
4. Confirm the Meta credentials and the five `INSTAGRAM_FEED_R2_*` variables are
   present in that environment.
5. Only then run `npm run deploy:<env>`.

`shopify app deploy` releases Shopify configuration and Extensions only; it never
deploys Laravel.

## Extensions

App Home is not an extension. It uses the self-hosted iframe model, so the page is
served by DecoAdmin at `/shopify-app/instagram-feed`. Do not add an
`admin.app.home.render` extension: it would take over the App Home surface and the
iframe page would become unreachable. `scripts/validate-project.mjs` blocks it.

The merchant still has to open the app in Shopify Admin at least once per store,
because that is when the session token is exchanged for the offline token that
publishing needs.
- `extensions/instagram-videos` is a Theme App Extension. It renders server-side
  from `app.metafields.instagram_videos.feed` and never calls DecoAdmin, so the
  storefront keeps working when the backend or tunnel is down. Merchants pick
  which gallery to render with the `gallery_handle` block setting.

## Safety rules

- Never store Shopify Client Secrets, Access Tokens, Meta credentials, or
  Cloudflare R2 keys in TOML, source code, or documentation.
- Keep `embedded = true` and `use_legacy_install_flow = false`. Installation is
  Shopify-managed and sessions use App Bridge session tokens with token exchange.
- Keep automatic URL rewriting disabled so CLI commands cannot replace hosted
  URLs unexpectedly.
- Leave `client_id` empty in the test and production configurations until the
  matching app is linked, so a fresh clone cannot point them at the development
  app.
- Do not add code or assets belonging to Student Discount or any other Shopify
  App.
- Do not restore the Remix, Prisma, or Node backend into this project.
  `npm run check:project` fails on `app/`, `build/`, `prisma/`, `public/`,
  `.react-router/`, `Dockerfile`, `vite.config.ts`, `tsconfig.json`, `env.d.ts`,
  `.graphqlrc.ts`, `.eslintrc.cjs`, `shopify.web.toml`, `package-lock.json`, on
  any `.env*` file, and on any `.sqlite` file.
- A request to develop or release this app never authorizes changes or releases
  for another app or environment.

## Changing an environment origin

CLI URL rewriting is disabled on purpose, so an origin change has to be made in
every place that hard-codes it:

1. The target TOML plus `shopify.app.toml` when that target is the selected one:
   `application_url` and both `[[webhooks.subscriptions]]` URIs.
2. The `configurations` map in `scripts/validate-project.mjs`.
3. The matching `app_url` in the root `config/instagram_feed.php`.
4. The Meta dashboard OAuth redirect URI, Deauthorize callback, and Data deletion
   callback.

Steps 1 and 3 must agree, otherwise the App Home page loads against one origin
while Meta redirects to another. `npm run check:project` enforces 1 and 2.

There are no OAuth redirect URLs to update: installation is Shopify-managed and
DecoAdmin no longer exposes an authorization-code callback.

For exact setup and release commands, read
[`shopify-apps/instagram-feed/README.md`](../shopify-apps/instagram-feed/README.md).
