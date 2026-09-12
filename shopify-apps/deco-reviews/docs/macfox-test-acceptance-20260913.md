# macfox-test-app acceptance — 2026-09-13

## Authorized boundary

- Only Shopify `macfox-test-app.myshopify.com` and test-backend Organization 1 / Store 1.
- The user confirmed this is a test store without real customers and authorized new-app installation and test data/settings operations.
- Do not touch Macfox Bike, other Shopify Apps, shared backend source, production, or old reviews.

## Form release

- Backend source: `cc301c409ba32c3a1273bdc575b196e13804d394`, clean export fetched from `origin/test`.
- App-owned `Dockerfile.test` includes the new frontend directory omitted by the shared recipe; the shared Dockerfile was not edited.
- Staging backup: `/opt/decoadmin/backups/deco-reviews-form-cc301c409ba32c3a1273bdc575b196e13804d394`.
- Images: `decoadmin-app:deco-reviews-form-cc301c409ba32c3a1273bdc575b196e13804d394` and matching `decoadmin-nginx` tag.
- Shopify version: `deco-reviews-form-20260913-cc301c4`, ID `1126450954241`.
- Only the new form migration ran. App, nginx, Horizon, Scheduler and Reverb use the same source. Health and Horizon passed; runtime FormService/Index hashes match the commit; shared AppServiceProvider/AppLayout hashes and production container IDs did not change.
- First release invocation over SSH stdin stopped after a Compose one-off consumed stdin. Cache/service activation was completed explicitly. The app-owned release script now disables stdin for each Compose one-off.

## Verified live in Chrome and staging

- Installed **Deco Reviews (Test)** into **macfox-test-app** after the user's action-time confirmation; user completed Shopify's connection CAPTCHA.
- Embedded app shows Connected and links to `/organizations/1/stores/1/deco-reviews` on the test backend.
- Installation row is scoped to Store 1 / environment test / the independent test client ID.
- Saved a DEMO form title and a required private single-choice question; save feedback appeared and dirty state cleared.
- Saved-form preview shows the DEMO title, choices, and private visibility statement. Submitting filled preview displays “仅预览，没有提交或保存评价”; no review was created.
- Authorized fixture script created five clearly synthetic reviews against product 1 (`10264083103992`, `macfox-m16-brake-levers`). Four published; one pending. No order/email/customer writes.
- Widget preview shows exactly four published reviews, average 3.5 and zero published one-star reviews, matching the intentionally pending fixture. Low ratings are not rejected; two/three-star fixtures are published.
- Real delivery remains disabled and invitations disabled for these fixtures.

## Defect found and follow-up

- Live preview incorrectly rendered `Verified purchase · none` because the string `none` was truthy. Fix restricts purchase verification to `verified_source === "order"`; imported/manual/none/null remain explicitly unverified.
- Six frontend runtime tests cover each verification source and preview non-submission. Backend suite: 34 tests / 185 assertions. Type-check, typography and build passed before the form release; rerun for follow-up.
- Widget page's obsolete “theme blocks unavailable” copy corrected: theme app block exists, third-party integrations remain pending.
- Theme placement and full Loox-equivalent functionality are not yet accepted by this record.
