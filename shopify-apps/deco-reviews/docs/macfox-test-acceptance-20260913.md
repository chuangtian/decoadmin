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

## Follow-up release and theme acceptance

- Verified running test-backend source: `4257674469d5d53d70b609007f2eedc3b26c6cdf`; app/nginx tags are `deco-reviews-form-4257674469d5d53d70b609007f2eedc3b26c6cdf`. Backup uses the same full SHA under `/opt/decoadmin/backups/deco-reviews-form-...`.
- Shopify fix version: `deco-reviews-qa-20260913-4257674`, ID `1126458851329`.
- Running storefront.js and Index.vue hashes match the clean source. Health passed and production container IDs remained identical.
- Added only the Deco Reviews app section to **Horizon draft 164659659000** in macfox-test-app, default product template. Saved the draft; after reload the section remains present and Save is disabled. Never clicked Publish; the store's active theme remains separate.
- Test product preview: `macfox-m16-brake-levers`. Real Shopify App Proxy returns the four published synthetic reviews in the authenticated browser. The pending fixture is absent.
- After refreshing following deployment, the theme widget displays four reviews / average 3.5. All merchant fixtures correctly show **Source not verified**, not Verified purchase.
- Selecting five stars reduces the displayed list to the sole five-star fixture. Mobile theme preview visually checked: readable card content, disclosure and pagination fit inside the narrow preview without horizontal overflow.
- Storefront password protection was not removed. A cookie-less HTTP probe redirects to the password page; the authorized browser's proxy request succeeds.
- This accepts installation, form save/preview, real proxy feed, basic theme placement, filtering and narrow-screen layout only. Full Loox parity, live invite/email delivery, media end-to-end, rewards, referrals and channel syndication remain outside this completed batch.

## Invitation automation release and live acceptance in progress

- Test backend source: `11e370bf2f3d13005a9d00be054735f357881148`; Shopify version `deco-reviews-invites-20260913-11e370b`, ID `1126679445505`. Scope upgrade was confirmed by the user. No production release.
- Added activation-date bounded discovery, one-time reminders, scoped email previews and HTML invitation rendering. Backend checks: 40 tests / 246 assertions; frontend runtime: 6 tests; type-check, typography and production build passed for this release.
- Global delivery remains disabled. Acceptance delivery uses a process-local exact-store / exact-recipient allowlist, not a persistent sending switch.
- Gmail was inspected through a subject-filtered search only. The single `DEMO Deco Reviews — email preview acceptance` message was received and its body/preview links were inspected. This proves preview SMTP delivery, not a real invitation or completed buyer review.
- Created authorized synthetic customer `9624843059448` without marketing subscription. After separate final confirmation, converted zero-dollar draft `#D2` (`1346923102456`) to order `#1007` (`7094226354424`), tagged `DECO_REVIEWS_QA`.
- Order contains one Macfox M16 Brake Levers unit with a full test discount. Shopify shows paid USD 0.00. Shopify automatically sent its order-confirmation email to the authorized recipient.
- Recorded simulated fulfillment `#1007-F1` with no tracking and the shipping-notification checkbox off; no physical shipment was arranged. Only this test store's order/inventory workflow was exercised.
- The next scheduled synchronization imported order `#1007` as local order `4947`, paid/fulfilled, with one item and USD 0.00. No core sync code or other app was changed.
- The exact-order acceptance runner discovered invitation `143aa85e-446d-4815-a990-d8d43495b87e` and recorded `sent` at `2026-09-13T08:01:02Z`, without an error. This is SMTP acceptance; actual invitation inbox arrival, photo/video submission and moderation end-to-end remain pending until separately verified.
- Gmail subsequently received the actual invitation. Its signed link opened the correct product and required private question. Defect found: actual invitation sender display name inherits `Student Discount`; override this only inside the new review app, not shared mail settings.
- User authorized synthetic content. Submitted an explicitly labelled three-star DEMO review with one generated non-personal PNG and the private answer `Neutral`. Browser displayed “Thank you. Your review has been received.”
- Read-only staging verification: review `6`, Store 1 / product 1 / order `4947`, source `email`, verified source `order`, one media item, status `pending`. Invitation changed to `completed` at `2026-09-13T08:11:54Z`. This verifies initial invitation → actual email → signed form → image submission → persisted pending review. Publishing, video and live reminder acceptance remain unverified.

## Follow-up fixes in working tree (not deployed yet)

- Invitation sender display name is now scoped to the store plus `Reviews`, without changing the shared SMTP address or global sender name. Applies to both initial and reminder delivery. Added a plain-text alternative alongside HTML.
- Buyer form blocks concurrent requests and further submits after success; validation/network failures remain retryable, and success feedback receives keyboard focus.
- Added mixed-media rejection and partial-upload rollback tests. Full backend suite before the final two media tests: 41 tests / 258 assertions. Updated targeted suites: invitation automation 6 tests / 69 assertions; review lifecycle 17 tests / 67 assertions. Frontend runtime: 8 tests passed.
- Type-check, typography, asset build and production frontend build passed. Vite restarted and ready at port 5173; manifest exists. Shopify test configuration valid.
- Live pending-media viewer displays the re-encoded synthetic PNG; Escape closes it and restores focus to the original media button. Publishing is prepared but not saved, pending action-time confirmation.

## Follow-up test release

- Deployed exact `origin/test` commit `224ece1eb4fd592ff8c7b0c7bdc8d9e6b8ee86b5` from a clean archive. Backup: `/opt/decoadmin/backups/deco-reviews-form-224ece1eb4fd592ff8c7b0c7bdc8d9e6b8ee86b5`. No new migrations.
- Health (application/database/Redis) and Horizon passed. Runtime InvitationDelivery, storefront source and plain-text template hashes match the exported source. Production container IDs remain unchanged. Global automated sending is disabled.
- Shopify test version `deco-reviews-fixes-20260913-224ece1`, ID `1126721388545`, released without scope changes or theme publication.
- Final combined backend suite: 43 tests / 267 assertions passed. Frontend runtime: 8 tests passed. Type-check, typography, production build, Vite readiness and Shopify config/theme checks passed.
- Publishing the synthetic review remains unsaved until the outstanding action-time confirmation. This release does not constitute full Loox parity or full video/reminder/channel acceptance.

## Confirmed publication acceptance

- Following the user's action-time confirmation, saved review `6` as published. Management showed “评价已更新” and it disappeared from the pending filter.
- Public widget preview shows five published reviews, average 3.4, with the three-star DEMO first. It shows the order verification disclosure and an abbreviated author name. The private `Neutral` answer is absent.
- Opened the published synthetic image and visually verified its decoded content in the public widget viewer.
- Shopify Horizon draft `164659659000` in macfox-test-app renders the same five reviews / 3.4 average through its actual App Proxy, including the synthetic image, DEMO text and order verification disclosure. The theme remains labelled draft and Save is disabled; no theme publication or edits were performed.
- This completes the single synthetic order → invitation → inbox → signed form → photo review → moderation → Shopify draft storefront lifecycle only. It does not complete remaining video, live-reminder, reward, referral, widget-parity or channel-integration work.

## Video follow-up in progress

- Generated a three-second 320x180 synthetic color-bar MP4, without audio or personal content. SHA-256 `9dbcdca967eb8f81c719ac97644becdaad97627bd10c3a9d3462c0c57b51eb98`.
- Scoped acceptance runner created review `7` (`48fd1cc6-a725-4986-8e72-fa3b382a7fe7`) only in Store 1, with one video, pending status and no auto-publish time. This uses the same creation service, not the signed buyer browser upload; do not conflate the two.
- Local, not-yet-deployed media responses now use binary-file delivery for byte ranges. Regression verifies private preview ranges, published ranges and rejection after withdrawal; existing authorization remains before file delivery.
- Live video playback remains unverified: Chrome reported it had been changed externally, and the dedicated browser provider failed to load its request-header policy. Do not read an unconfirmed foreground page or call this playback accepted.
- After the user restored the intended page, Chrome loaded review 7's private video preview and played the synthetic animation to 0:03/0:03. Screenshot and player time confirmed completion. Initial buffering text cleared after load; do not treat it as a proven playback failure.
- Publishing this synthetic video is prepared but unsaved, pending separate action-time confirmation. The earlier photo-publication confirmation is not reused for this video.
- Combined backend regression for range support: 44 tests / 280 assertions passed. The small video's existing full-download playback does not itself prove range support; the new 206/416 regression covers the response behavior.
