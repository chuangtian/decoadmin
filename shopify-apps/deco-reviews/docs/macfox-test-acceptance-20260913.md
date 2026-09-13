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

## Media range test release

- Test backend deployed from clean `origin/test` commit `638bc39c6fe15f88974bf6d2d70315cde3202b6c`. Backup: `/opt/decoadmin/backups/deco-reviews-form-638bc39c6fe15f88974bf6d2d70315cde3202b6c`. No migrations or Shopify scope/extension changes.
- Health and Horizon passed; runtime media controller hashes match the clean source. Production container IDs unchanged; automated sending remains disabled globally.
- Unauthenticated external HTTPS probe for the published test image with `Range: bytes=0-9` returns HTTP 206 and exactly 10 bytes. Same probe for pending test video returns 404.
- Reprocessing the completed test invitation for order 4947 leaves one completed record, original sent/completed timestamps unchanged, reminder timestamp null and no error. It did not reopen the completed invitation.
- Public video publication/playback still awaits the user-facing action-time confirmation; do not mark it accepted or claim full feature completion.

## Confirmed video publication and next safety increment

- Following the user's separate confirmation, published synthetic review 7. The management list refreshed to `已发布`.
- Public video widget shows six reviews / 3.33 average and opens the DEMO video. Chrome played the public video to 0:03/0:03 over the deployed range-capable endpoint.
- Shopify draft-theme playback could not yet be rechecked because Shopify redirected to a Cloudflare human-verification page. The user must complete that challenge; no attempt was made to solve or bypass it.
- Added, but not yet deployed in this record, a bounded `ffprobe` video inspector for real MIME/container, one video stream, supported codecs, duration and 4K limits. Videos remain manual-review only. App-owned test image installs ffmpeg; shared Docker/runtime recipes and other apps are unchanged.
- Reopening a completed signed invite now renders an explicit already-received page without another form. Invitation management now shows the human order number/product and completion timestamp instead of presenting only an internal numeric order ID.
- Reviewed combined source: 50 backend tests / 308 assertions, 8 storefront runtime tests, type-check, app asset build and typography passed. This does not complete transcoding, malware scanning, full video-browser matrix, reminder inbox E2E or full Loox parity.

## Video safety release and management filters

- Deployed exact `origin/test` commit `3ac2ec18833b75fe8800f5838429b365bd9365ec`. Backup: `/opt/decoadmin/backups/deco-reviews-form-3ac2ec18833b75fe8800f5838429b365bd9365ec`. Health and Horizon passed; no migration, Shopify scope or theme changes; production containers unchanged.
- Re-ran the exact SHA-256-guarded synthetic MP4 through the deployed creation service. Real `ffprobe` accepted it and deduplication returned existing review 7; it did not create another review.
- External completed-invitation probe returned 200, contained the already-used completion copy and no `<form>` element. Signed URL was not logged.
- Added management filters for product, media, source, purchase verification, featured, merchant reply, incentivized and inclusive review dates. The same validated filters drive CSV export. Invitation rows now show the store's order number, product and completion time.
- Combined source after filter work: 51 backend tests / 310 assertions passed; storefront runtime 8 passed; type-check and typography passed.
- Deployed exact `origin/test` commit `f1e93e8d529433116351c4eb25c8beb77f72cdef`. Backup: `/opt/decoadmin/backups/deco-reviews-form-f1e93e8d529433116351c4eb25c8beb77f72cdef`. Application, database, Redis and Horizon checks passed; the production source hashes remained unchanged.
- Live Chrome acceptance on Store 1 (`macfox-test-app`) confirmed the combined `有图片或视频` + `邀评邮件` filter retained both selected values after reload, returned only the expected verified invitation review, and included `media=with&source=email` in the current-filter CSV export URL.

## Branded reminder and public product form increment

- Created Shopify test order `#1008` (`7094332719352`) only in `macfox-test-app`: USD 0.00 after a 100% DEMO discount, paid, fulfilled, no fulfillment notification, internal recipient only, tagged `DECO_REVIEWS_QA`.
- Executed the separately confirmed Store 1 order incremental sync. The sanitized test connector returned `#1008`, USD 0.00, `paid`, `fulfilled`; local order ID is 4948.
- Initial invitation `097c91f0-6df5-4ef8-b94f-3dc87a3c3efe` was delivered once. A subject-scoped Gmail check showed the new sender name `macfox-test-app Reviews`; the old `Student Discount` sender remained only on the earlier historical message.
- Deployed exact `origin/test` commit `61b838cad1c81864b5991eb701d3091ee000e158`. Backup: `/opt/decoadmin/backups/deco-reviews-form-61b838cad1c81864b5991eb701d3091ee000e158`. Health and Horizon checks passed; production source hashes remained unchanged.
- The guarded reminder run sent exactly once, setting `reminder_sent_at` to `2026-09-13T10:24:35Z`. An immediate second run returned the same timestamp and did not send again. Subject-scoped Gmail search returned exactly one reminder from `macfox-test-app Reviews`.
- Added a signed App Proxy product-review form behind an explicit store setting and per-block visibility setting. Public submissions require a current-store product, consent and private email; use a honeypot plus hashed store/product/email rate limit; never auto-publish; and are always stored as `organic`, unverified and pending moderation.
- Deployed exact `origin/test` commit `476ebce7ba47fb63928f27c180be8ec8b1d0b07f`. Backup: `/opt/decoadmin/backups/deco-reviews-form-476ebce7ba47fb63928f27c180be8ec8b1d0b07f`. Health, database, Redis and Horizon passed; production containers remained unchanged.
- Released Shopify test-app version `deco-reviews-organic-20260913-476ebce`, ID `1126801080321`, only for Deco Reviews (Test). No theme was published.
- Enabled organic collection only for test-backend Organization 1 / Store 1 and retained the per-block public-form toggle in Horizon draft `164659659000`.
- Submitted one clearly synthetic four-star public-form review from the authorized test browser. The storefront displayed `Thank you. Your review has been received.` and disabled further submission.
- Live management and a read-only database check show review `8` as `source=organic`, `status=pending`, `verified_source=none`, with its private custom answer visible only in management. Filtering by `店铺公开表单` returns this review alone.
- The Horizon draft storefront remains at six published reviews / 3.33 average after submission. The new pending organic review and its private form answer are absent from the public widget. The theme remains a draft and was not published.

## Lifecycle email working batch

- Added a second, independently timed photo/video reminder after the ordinary request reminder. Initial, request-reminder and media-reminder timestamps and uncertainty states are separate, and completed/cancelled/unsubscribed invitations cannot continue through the sequence.
- Added product-review thank-you, store-review thank-you and public-reply notification templates, private previews, encrypted recipient storage, store-scoped idempotency, bounded dispatch, audit records and held-on-uncertainty behavior.
- All new lifecycle-email switches default off. Existing global delivery, exact test-store allow-list and non-production recipient allow-list still gate every external email. This batch sent no live email and performed no Shopify write.
- Full Deco Reviews backend regression: 58 tests / 386 assertions. Frontend type-check, typography audit, Shopify test-app build and DecoAdmin production frontend build passed. Vite was restarted and became ready on port 5173.
- Reward discount issuance and reward reminder remain pending; this batch must not be described as the complete seven-message/reward program.
- Deployed exact `origin/test` commit `507afe3ba53d8145ef36afbf334bb965503f7520`. Backup: `/opt/decoadmin/backups/deco-reviews-form-507afe3ba53d8145ef36afbf334bb965503f7520`. Application, database, Redis and Horizon passed; production container inventory was unchanged.
- Live Chrome verification showed the media-reminder section plus all three lifecycle templates and private preview links. New switches remained off, and the lifecycle history rendered without exposing a recipient address. No message was sent during this verification.
