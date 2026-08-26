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

- `local`: `https://wendy-interim-classic-segment.trycloudflare.com`, a temporary
  Cloudflare tunnel used for development only.
- `test`: `https://testadmin.decomkt.com`, used for acceptance. The Shopify App
  does not exist yet, so `client_id` is intentionally empty.
- `production`: `https://admin.decomkt.com`, used only after explicit approval.
  The Shopify App does not exist yet, so `client_id` is intentionally empty.

Create the test and production apps in the Dev Dashboard first, then run
`npm run config:link:test` or `npm run config:link:production` to populate
`client_id`. Until then the backend refuses to call Shopify and returns
`INSTAGRAM_FEED_APP_NOT_CONFIGURED`.

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

- `extensions/app-home` is an Admin UI extension. It verifies the store is linked
  to DecoAdmin, calls `bootstrap` to establish this app's Shopify session, and
  then sends the merchant to DecoAdmin. Publishing depends on that session, so
  the merchant must open the app in Shopify Admin at least once per store.
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
- Do not add code or assets belonging to Student Discount, decoAfterShip, or
  another Shopify App.
- Do not restore the Remix, Prisma, or Node backend into this project.
  `npm run check:project` fails on `app/`, `build/`, `prisma/`, `public/`,
  `.react-router/`, `Dockerfile`, `vite.config.ts`, `tsconfig.json`, `env.d.ts`,
  `.graphqlrc.ts`, `.eslintrc.cjs`, `shopify.web.toml`, `package-lock.json`, on
  any `.env*` file, and on any `.sqlite` file.
- A request to develop or release this app never authorizes changes or releases
  for another app or environment.

## Rotating the local tunnel URL

The Cloudflare quick tunnel has no stable hostname. When it changes, update all
of these and redeploy, because CLI URL rewriting is disabled:

1. `shopify.app.toml` and `shopify.app.local.toml`: `application_url`, both
   `webhooks.subscriptions.uri` entries, and `auth.redirect_urls`.
2. The `configurations` map in `scripts/validate-project.mjs`.
3. The local `app_url` in the root `config/instagram_feed.php`.
4. The Meta dashboard OAuth redirect URI, Deauthorize callback, and Data deletion
   callback.

Steps 1 and 3 must agree, otherwise `bootstrap` succeeds against one origin while
Meta redirects to another.

For exact setup and release commands, read
[`shopify-apps/instagram-feed/README.md`](../shopify-apps/instagram-feed/README.md).
