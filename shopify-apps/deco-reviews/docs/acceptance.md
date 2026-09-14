# Deco Reviews acceptance matrix

Updated: 2026-09-13

All live acceptance targets only **macfox-test-app**, test backend Organization 1 / Store 1, and its unpublished Horizon draft theme `164659659000`; never publish a theme or operate Macfox Bike. Allow-listed synthetic-order invitation tests to the user-authorized internal recipient are permitted. Safety prohibitions below still apply to real stores/recipients and do not narrow this explicitly approved test scope. See `macfox-test-acceptance-20260913.md` for actual results; this matrix is not proof that a listed pending case has passed.

This document tracks the standalone `deco-reviews` Shopify app against the read-only Loox audit. It is an engineering checklist, not a claim of full feature parity or a reduction of the requested scope.

## Status legend

| Status | Meaning |
|---|---|
| Implemented | Code exists in the repository; this alone does not prove end-to-end behavior. |
| Covered | Focused automated coverage exists in `tests/ReviewsTest.php`; the current release must still record an actual passing run. |
| Pending verification | Implemented behavior still requires safe local or authorized test-environment verification. |
| Pending implementation | No complete production-ready implementation exists yet. |

## Non-negotiable safety boundary

- Never modify, publish, or activate any Macfox Bike theme.
- Shopify theme testing is limited to the **macfox-test-app** unpublished Horizon draft theme ID **`164659659000`**. Confirm the exact store, theme ID, and draft role before every operation. Never publish it.
- Do not send email to real customers. Test delivery must remain disabled or restricted to an explicit internal allow-list with preview/simulation as the default.
- Do not create, activate, update, or revoke real Shopify discounts.
- Do not run live order-triggered invitation, reminder, reward, referral, or other automation.
- Do not use production customer data as fixtures. Keep customer email, order details, access tokens, signatures, and webhook payloads out of logs, screenshots, reports, and this document.
- Test and production app identities, secrets, installations, URLs, data, webhooks, themes, and release actions remain separate.
- A successful build, API read, or draft-theme preview does not authorize installation, publishing, email delivery, discount writes, or order automation.
- Any Shopify read must first prove the app identity, installation, store mapping, Organization/Store scope, and minimum required scope. Fail closed when any proof is missing.

## Current implementation matrix

| Area | Current repository evidence | Automated coverage present | Current status | Acceptance boundary / remaining work |
|---|---|---|---|---|
| Tenant and RBAC isolation | Management routes require authenticated organization/store access plus app/product permissions; services scope reviews, invitations, imports, products, and orders by Organization and Store. | Membership/permission, cross-store product, and all-or-none moderation tests are defined. | Implemented; pending full test run | Tenant tests must pass and route-model mismatches must return 404/403 without revealing another tenant's existence. |
| Review model and moderation | Product/store reviews, rating, title/body, encrypted email, status, scheduling, featured flag, reply, source, verification source, incentive flag, and audit records exist. | Auto-publish parity, pending visibility, encryption, idempotency, moderation isolation tests are defined. | Implemented; pending verification | Confirm create, publish, unpublish, scheduled publish, reply, bulk update, low-rating parity, and audit behavior. Public buyer submissions must not bypass media/content moderation policy. |
| Public feed | App-proxy feed supports product/store kind, product external ID resolution, rating, sort, page, summary distribution, and public serialization. | Indirect model/service coverage only. | Implemented; pending verification | Verify signed app-proxy identity, disabled state, empty state, page limits, cache/performance, product and store feeds, and public-field allow-list. |
| Buyer submission | Signed invitation pages and independently enabled signed app-proxy product/store forms support consent, configurable questions, text fields, and up to five images or one video. Invitation identity is immutable; both public forms keep email encrypted and submissions unverified/pending. The management form tab exposes the current store's public store-review link without a customer identifier. | Identity/product binding, private email, mandatory moderation, per-email rate limit, store-review separation and public-field tests are defined. | Implemented; pending live public-form verification | Verify expired/cancelled/completed/replayed/forwarded invitation links, both public-form honeypot/validation paths, accessibility, upload failure cleanup, and that submitted product/source cannot cross store or be spoofed. |
| Media | JPEG, PNG, WebP, MP4, and WebM validation exists; image processing and authenticated/public delivery paths exist. | Media-specific adversarial tests are not yet evident. | Partially implemented | Add/verify decoded pixel limits, image re-encoding, video codec/duration/resolution inspection or quarantine, moderation gating, malware controls, orphan cleanup, and safe headers. No raw or pending media may become public. |
| Import and export | Deco custom CSV plus Loox, Judge.me, Yotpo, Okendo and Shopify Product Reviews core text mappings are bounded to 15 MB and 1,000 rows per batch. Explicit provider selection, unambiguous-only detection, scoped product resolution, batch attribution, deduplication, seven-day reversible withdrawal, a privacy-minimized failure report, and formula-safe streamed export are implemented. The management export adds bounded immutable custom-answer snapshots as one structured JSON column with explicit visibility. | Provider mapping/detection, authorization, tenant isolation, numeric-handle disambiguation, management validation, scoped failure-report privacy, import errors/deduplication/undo, custom-answer export and spreadsheet formula tests are present. | Implemented core-provider subset | Remote image import, full provider fields, 100,000-review lifecycle, async chunking/progress, store reviews, replies, verification/reward flags and historical provider mappings remain pending. |
| Invitation creation | Merchant action creates idempotent per-order/product records. Shopify read client and order query include cancellation, payment, customer marketing state, shipping/shop country, and fulfillment lines. Records fail closed until verification succeeds. | Scoped valid order, idempotency, cancelled/refunded cases are defined. | Partially implemented | Verify app installation/token/scopes, fulfillment completeness and pagination, fulfilled quantities, domestic/international delay, consent policy, suppression, refunds, cancellation, duplicate products, resends, and historical orders. No sender execution is accepted under the current safety boundary. |
| Email and scheduling | Seven independently configured message stages now exist, including reward delivery and one optional reward reminder. Recipients are encrypted, delivery is idempotent, and uncertain SMTP results are held without blind retry. Explicit scoped `sent`/`not_sent` reconciliation records a conclusion without resending. | Invitation, lifecycle, reward delivery/reminder, encryption, allow-list, idempotency, uncertainty and no-retry reconciliation tests are present. | Implemented subset; synthetic reward email verified | Complete global style/per-message overrides, localization, order-tag exclusions, bounce/complaint handling and delivery analytics. Real-customer sending remains forbidden. |
| Publishing preferences and transparency | Name format, order/manual/unverified provenance, import origin, incentive fields, automatic publish delay, merchant reply, and truthful storefront disclosures exist. Manual verification is reversible, cannot replace order verification, and never qualifies for order-verified rewards. | Core publication and manual-verification tests are defined. | Partially implemented | Add purchase-date disclosure policy, suspicious-content flags, store-review follow-up, Shopify Customer synchronization policy, and complete preference controls. Verification and incentive disclosures must never be deceptively hidden. |
| Storefront base widget | Native JavaScript renders aggregate stars, histogram, review cards, sorting/rating filtering, pagination, replies, media, disclosure badges, and accessible lightbox navigation. Feed settings drive star color, layout, heading, and corner style. | Twenty storefront tests cover form behavior, disclosure, carousel/video controls, accessible autoplay, keyboard/focus behavior, reduced motion, sidebar disclosure, gallery navigation/empty state, multiple blocks, failure cleanup and section teardown. | Implemented; pending draft-theme browser verification | Verify responsive visual layout, multiple blocks per page, theme-editor reload, native media failures and real focus behavior in the authorized draft theme. |
| Widget mode prototypes | Theme block exposes `reviews`, `stars`, `carousel`, `trust`, `snippets`, `gallery`, `video`, `sidebar`, and `floating` modes. Carousel has manual controls and opt-in autoplay that pauses for accessibility and user interaction; sidebar/floating have accessible launcher/close behavior and page-session-only scoped state; gallery suppresses text-only shells and presents one current main media item with keyboard-selectable thumbnails; video is a bounded review-only slider and never autoplays. | Storefront interaction tests cover the newly interactive modes, duplicate-script guard, isolated blocks and unload/reload cleanup. | Improved prototype subset | These modes do not equal the audited 17 Loox widgets. Rich gallery/video editors, launcher frequency policy, separate widget editors and independent theme acceptance remain pending. |
| Embedded management UI | Reviews, invitations, settings, imports, widget preview, statistics, product list, moderation, export, and audit-backed actions are represented. | Backend tests cover selected service behavior. | Implemented subset; pending UI verification | Complete audited filters (media/date/source/featured/widget/reply/verified), layouts, page-size controls, detail view, complete bulk actions, store reviews, request history, editor workflows, performance overview, attribution, and error/help states. |
| App identity and uninstall | Embedded bootstrap/token exchange, environment-scoped installation storage, webhook HMAC validation, and uninstall disable/cancel behavior exist. | Dedicated identity/webhook tests are not recorded here. | Partially implemented | Verify test app identity and scopes, replay resistance, topic/shop validation, token rotation, uninstall data policy, privacy webhooks if required, and environment isolation. Do not install or alter production. |

## Audited 17-widget parity checklist

The current shared renderer is a foundation only. None of the following should be marked fully equivalent until the individual acceptance cases pass.

| Audited widget | Current mapping | Status / gap |
|---|---|---|
| Reviews Widget | `reviews` with grid/list/mosaic feed layout | Partial; full editor controls, write-review entry, expanded/minimal headers, variant/date controls, and theme compatibility pending. |
| AI Review Stories | None | Pending implementation; AI generation, eligibility threshold, page hiding, regeneration, safety, and cost controls required. |
| Star Rating Widget | `stars` | Prototype; product/collection/cart contexts and editor placement verification pending. |
| Checkout Star Rating Widget | None | Pending implementation; requires a dedicated eligible Checkout UI extension and Shopify approval/scope validation. |
| Pop-up Widget | `floating` is not equivalent | Pending implementation; relevance rules, timing/frequency, activation controls, navigation, and accessibility required. |
| Dynamic Carousel Widget | `carousel` | Prototype; dynamic media behavior, controls, autoplay policy, and configuration pending. |
| Reviews Sidebar Widget | `sidebar` | Prototype; launcher/open/close behavior and activation controls pending. |
| Loox Trust Badge | `trust` | Prototype; store-wide aggregate semantics and placements pending. |
| Snippets Widget | `snippets` | Prototype; selection/rotation/editor behavior pending. |
| Happy Customers Page | Signed App Proxy page | Implemented core page with an independent default-off switch, store/product aggregation, rating/sort filters, pagination and public-field allow-list; custom domain/SEO controls and live storefront acceptance remain pending. |
| Video Slider Widget | `video` | Improved prototype with bounded controls, keyboard navigation, no autoplay and empty fallback; thumbnail editor and independent theme acceptance pending. |
| Cards Carousel Widget | `carousel` | Shared prototype only; distinct card editor and carousel behavior pending. |
| Testimonials Carousel Widget | `carousel`/`snippets` | Shared prototype only; quotation-first semantics and controls pending. |
| Gallery Carousel Widget | `gallery` | Improved prototype with current main media plus roving thumbnail navigation; richer mixed-media/editor controls and independent theme acceptance pending. |
| Collection Reviews Widget | None | Pending implementation; collection product aggregation and Liquid collection context required. |
| Cart Reviews Widget | None | Pending implementation; cart-line mapping, dynamic cart updates, and theme compatibility required. |
| Floating Reviews Widget | `floating` | Prototype; launcher, non-obstructive positioning, persistence, mobile behavior, and customization pending. |

## Remaining Loox-equivalent product areas

### Analytics and attribution

- Performance overview time ranges, invitation/review/media metrics, attributed revenue, referral orders/revenue, timezone rules, processing delay, refunds, and attribution-window definitions.
- Recommendations, release notes, special offers, and plan-aware feature availability where these remain in scope.

### Review management

- Full filter set: media, date, source, featured, widget usage, reply state, and verified state.
- Store-review enablement, collection, management, response, display, and reporting.
- Review details, complete bulk actions, named icon actions, manual verification, scheduled publication management, and social-content workflow.

### Email program

- Seven audited messages: initial request, request reminders, photo/video reminder, reward reminder, product-review thank-you, store-review thank-you, and public-reply notification.
- Implemented in the current working batch: initial request, one request reminder, one later photo/video reminder, product/store thank-you, public-reply notification, reward delivery, and one optional reward reminder. Recipients are encrypted; each delivery is idempotent; the reward reminder is created only after confirmed initial delivery and before expiry; uncertain SMTP outcomes are held without blind retry.
- Global email styling and per-message overrides, rich content, variables, footer, banner/logo/font/color/corners, localization, test rendering, and delivery audit.
- First/reminder timing, delivery deferral, historical-order targeting, marketing versus transactional policy, Shopify-marketing unsubscribe interaction, notification recipients, and order-tag exclusion.

### Rewards and discounts

- Released to test, not production: separate photo/video switches; percentage, fixed-store-currency, and free-shipping discounts; one non-combinable, one-use code per eligible published order-verified email review; captured expiration; encrypted codes; scoped code-free management history; idempotent scheduling; and a separate fail-closed write gate. Eligibility is rechecked before the Shopify mutation, and uncertain results are held without retry.
- Implemented in the current follow-up: HMAC-verified and replay-idempotent `orders/paid`, `refunds/create`, and `orders/cancelled` processing records reward redemption and later refund/cancellation observations. Matching uses the current store plus the stored code hash; receipts store no raw payload or code. Tracking never issues, reissues, revokes or deletes a discount.
- Remaining: collection scope, subscription/payment rules, existing-code mode, explicit revoke/reissue policy, broader abuse controls, and live webhook delivery verification. Held Shopify and reward-email results require an explicit scoped administrator conclusion and never cause an automatic retry.

### Review form, custom questions, groups, and bundles

- Local implementation, not released: merchant copy editor, media toggles, non-submitting saved-form preview, single-choice/multiple-choice/rating-scale questions, required/public flags, product/store targeting, immutable encrypted answer snapshots, and public-field filtering. Form version checks protect both concurrent edits and stale buyer submissions. Covered by `tests/FormTest.php`; live-browser acceptance remains pending.
- Implemented in the current follow-up: an independently gated public store-review link using the signed Shopify App Proxy. It collects encrypted email, creates only unverified pending store reviews, shares configured store/all questions, and stops receiving immediately when either public display or store-review collection is disabled.
- Implemented in the current follow-up: the authorized streamed CSV export includes each review's immutable custom answers in a bounded `custom_answers_json` cell. Labels, type, value and public/private visibility are retained without leaking email or creating unstable columns across historical form versions.
- Implemented in the current follow-up: an independently gated Happy Customers App Proxy page aggregates only published product and store reviews, reuses the bounded public feed, and stops serving immediately when disabled.
- Implemented in the current follow-up: tenant-scoped product review groups let two to one hundred products share only their published review feed and aggregate score. A product can belong to one retained group, each review keeps its original product, disabling a group restores individual feeds, and collection, invitations, rewards and moderation remain bound to the original product.
- Implemented in the current follow-up: theme-editor branding for grid/list/mosaic layout, three corner styles, star/button/surface/text colors, plus complete built-in storefront and editor labels for English, Simplified Chinese, German, French and Spanish.
- Implemented in the current follow-up: an optional theme-configured after-submit page; the browser accepts only same-origin HTTP(S) navigation and otherwise keeps the accessible success message in place.
- Remaining: merchant-managed per-locale custom copy, QR code, collection targeting and Merchant API representation.
- Remaining for bundles: bundle review collection, bundle-page aggregate behavior, one-request multi-product bundle workflow, and Shopify bundle identity reconciliation.

### AI and content reuse

- Suggested replies with human approval, provenance, prompt/model versioning, PII minimization, policy controls, audit, retry/fallback, and cost limits.
- AI Review Stories eligibility, 5–8-page generation, grouping/bundle aggregation, regeneration threshold, page/story hiding, localization, and safe rendering.
- Social Studio image/video composition, selectable text/media, 9:16 output, typography/colors/stars, asynchronous rendering, notification, download, retention, and rights checks.
- Do not present deterministic matching, static snippets, or placeholder text as AI functionality.

### Referrals

- Full referral program initialization, friend offer, advocate reward, fulfillment-gated reward release, order-count limits, balance use, minimum spend, combination rules, subscription exclusions, fraud/refund/reversal handling, attribution, reporting, and audit.
- Entry points after review, after purchase, onsite, sidebar, and dedicated referral page, plus reminder/reward emails.
- Existing Deco Referral services may inform architecture but do not make this app's referral feature implemented and must not share app identities, tokens, or release lifecycle.

### Import ecosystem

- Judge.me, Yotpo, Okendo, AliExpress, Shopify Reviews, Stamped, Reviews.io, Junip, historical-source detection, and a documented custom format.
- Provider-specific source attribution, media retrieval safety, asynchronous progress, per-row errors, retry, undo semantics, volume caps, and reconciliation.

### Channels, integrations, API, and webhooks

- Shop, Google Shopping, Meta Shops, TikTok Shop, X, wholesale, shipping, marketing automation, loyalty, messaging, translation, Shopify Flow, themes, and page-builder integrations each require provider-specific eligibility, authorization, schemas, disclosure, retries, rate limits, and test evidence.
- Advertising OAuth for Google Ads, Meta Ads, or TikTok Ads is not review syndication authorization and must never be treated as such.
- Public Storefront API with a strict non-sensitive allow-list, authenticated Merchant API with scoped keys and rotation/revocation, documented pagination/errors/rate limits, and HTTPS webhooks with signing, replay protection, event selection, delivery history, retry, and endpoint management remain pending.
- Google search review snippets/structured data require eligibility and policy validation; they are not equivalent to Google Shopping review syndication.

### Branding, localization, domains, plans, and installation

- Logo handling, star icon variants, four audited corner styles, fonts, branding visibility, primary/multiple languages, translation workflow, external-domain allow-list, plan/usage enforcement, and safe theme-install guidance.
- Theme installation must remain an explicit merchant action. Acceptance testing may target only macfox-test-app draft `164659659000`, must confirm it remains a draft, and must never publish it.

## Acceptance cases

### A. Safety gates

1. Given any store or theme other than macfox-test-app draft `164659659000`, when a test operation is requested, the operation fails before mutation and records no change.
2. Given the authorized theme, the system verifies it is unpublished before every mutation; a published/live role fails closed.
3. No workflow exposes a Publish theme action or changes the live theme assignment.
4. Email transport defaults to disabled/preview. A non-allow-listed recipient can never reach a provider in test.
5. Discount and order-automation paths are disabled in test acceptance; attempts produce a stable blocked status without Shopify writes.
6. Logs and UI diagnostics contain no access token, signed URL query, raw webhook signature/body, customer email, or order address.

### B. Tenant and identity

1. A user without store membership or any required RBAC permission receives 403/404 for list, detail, media, export, settings, import, invitation, and moderation actions.
2. An ID/UUID from another Organization or Store never returns data and cannot be updated in single or bulk actions.
3. Shopify bootstrap accepts only a valid ID token for the configured test app and mapped test store; client/store/environment mismatches fail closed.
4. Webhooks require correct HMAC, configured app identity, accepted topic, and mapped shop; duplicates are idempotent.

### C. Review lifecycle

1. Product reviews require a product belonging to the same Organization and Store; store reviews contain no product reference.
2. Ratings 1–5 follow the same configured publication policy. Low ratings are never delayed, hidden, or rejected solely because of rating.
3. Pending and unpublished reviews and their media never appear in the public feed.
4. Publish, unpublish, scheduled publish, feature, and reply actions are atomic, auditable, and all-or-none for bulk requests.
5. Public output excludes email, email hash, moderation reason, internal IDs, fingerprint, storage path, and audit data.
6. Verified and incentivized labels reflect immutable backend provenance and remain truthfully disclosed.

### D. Signed buyer flow

1. Valid sent/scheduled invitations render the correct product without exposing the stored recipient email.
2. Expired, cancelled, unsubscribed, invalidly signed, and unsupported-status links fail without disclosing whether another tenant's invitation exists.
3. The submitted product, order, recipient, source, and verification status come only from the locked invitation and verified order state.
4. Repeated submission is idempotent and cannot attach an invitation to an unrelated pre-existing review.
5. CSRF-protected JavaScript and progressive form submission both work; validation errors preserve safe fields and never reveal internal exceptions.
6. Unsubscribe suppresses future invitations for that store, is idempotent, and does not silently alter Shopify marketing consent unless explicitly configured and authorized.

### E. Media

1. Accept at most five safe images or one safe video; mixed image/video uploads fail.
2. Extension-only, MIME-spoofed, malformed, oversized, excessive-pixel, unsupported-codec, and over-duration files fail before publication.
3. Images are decoded and safely re-encoded; video remains quarantined until inspection/transcoding and moderation complete.
4. Failed storage/database operations leave no orphan files or media rows.
5. Public media requires an enabled store and published same-tenant review; preview media uses authenticated scoped URLs.
6. Lightbox supports keyboard navigation, Escape, focus restoration, meaningful alternatives, and reduced-motion expectations.

### F. Feed and widgets

1. Product feed resolves only the current store's Shopify product ID; store feed never leaks product-scoped or other-store reviews.
2. Newest, oldest, highest, lowest, rating filters, summary, histogram, featured ordering, and pagination produce stable results.
3. Empty, loading, error, disabled, invalid-product, and last-page states are accessible and bounded.
4. Grid, list, and mosaic layouts and every widget mode are verified at desktop and mobile sizes in macfox-test-app draft `164659659000` only.
5. Each of the 17 audited widgets receives an independent checklist before it can be marked equivalent; sharing a renderer is insufficient.

### G. Import/export

1. Imports reject wrong type, excessive size/rows, duplicate headers, invalid dates/ratings/emails, unknown products, and cross-store handles with stable row codes only.
2. Duplicate files and duplicate reviews are idempotent; imports are processed in bounded chunks without long store-wide locks.
3. Undo within seven days unpublishes only reviews created by that same scoped batch; later undo fails safely.
4. Export respects filters, is bounded/streamed, neutralizes spreadsheet formulas, and excludes sensitive/internal fields.

### H. External prerequisites

The following are not accepted until independently provided and verified:

- A dedicated test Shopify app identity, correct minimum scopes, test installation, token exchange, app-proxy signature, webhook secret, callback URLs, and environment mapping.
- Confirmation that macfox-test-app Horizon theme `164659659000` exists and is unpublished before any theme test.
- An internal-recipient-only email provider configuration with verified sender, preview mode, idempotency, and bounce/complaint webhooks. This does not authorize sending.
- A documented legal basis and consent policy for review requests, transactional versus marketing classification, retention, deletion, and unsubscribe behavior.
- Media scanning/transcoding capability and storage lifecycle policy.
- Provider approvals, accounts, scopes, contracts, feeds, and sandbox/test capability for every review-syndication channel.
- AI provider approval, data-processing terms, model/prompt governance, safety evaluation, cost budget, and human-approval workflow.
- Referral and discount business rules, fraud policy, accounting treatment, and a no-write simulator before any Shopify mutation is considered.

## Release evidence required before changing a status to verified

- Exact source commit and clean build output.
- Passing focused backend tests plus frontend syntax/build, accessibility, responsive, and browser interaction results.
- Verified test app identity, environment, store mapping, scopes, and target draft theme name/role.
- Proof that email, discount, referral, and order automation remained disabled and that the live theme was untouched.
- Sanitized screenshots or recordings from the authorized draft theme only, with no customer or credential data.
- Known limitations, rollback steps, and the person approving any later expansion of test authority.

## Reward increment validation (2026-09-13 to 2026-09-14)

- Latest combined Deco Reviews automated suite: 86 tests, 602 assertions passed.
- Storefront JavaScript suite: 20 tests passed; every generated JavaScript asset remains below the 10 KB extension limit.
- Full DecoAdmin Laravel suite completed successfully after the reward changes.
- Vue type-check, interface typography audit, Shopify test configuration validation, Shopify App build, and production frontend build passed.
- Shopify test versions `deco-reviews-test-9` (reward closure), `deco-reviews-test-10` (provider imports and widget lifecycle), `deco-reviews-test-11` (reward redemption/refund/cancellation webhooks, version ID `1127108214785`), and `deco-reviews-test-12` (gallery/video widget increment, version ID `1127119093761`) are released. The current macfox-test-app token was verified to include `read_discounts` and `write_discounts`.
- After explicit test-store authorization, a one-shot acceptance enabled the reward and delivery gates only for `macfox-test-app.myshopify.com` and the internal allow-listed recipient. Reward `473a6bef-a452-4b42-8de9-a4dc38ee27e7` is `issued`, Shopify read-back found the discount, and the reward email is `sent`. The settings were restored in `finally`; the scheduled reminder was consequently cancelled. No code, recipient, access token or Shopify discount ID is recorded here.
- The runtime defect discovered during that acceptance (`context.all` required the `ALL` enum) was fixed and deployed from exact `origin/test` commit `922908628e1d7504e9aa181c4e3ac7804926cc15`. Test health, database, Redis and Horizon passed; production was unchanged.
- Test release `deco-reviews-test-10`, Shopify version ID `1127020527617`, contains provider-aware import controls and the accessible widget lifecycle increment. Backend feature commit `cc520910c45516af70f6d8caa0d815d7fb5abdf2` was deployed from a clean `origin/test` archive; migration `2026_09_13_080000_add_provider_to_deco_review_imports` completed. Application, database, Redis and Horizon passed, and the release script verified production containers were unchanged.
- Test backend commit `28e2fd1e583633b7ff588bc3ae160227b7398161` and Shopify version `deco-reviews-test-11` add HMAC-verified reward lifecycle tracking. Migration `2026_09_14_090000_add_reward_lifecycle_tracking` completed; application, database, Redis and Horizon passed, source hashes matched the exact `origin/test` archive, and production containers were unchanged.
- Shopify version `deco-reviews-test-12` was released from commit `947bea862c0f8f01499ec44995756d27cf121d24`; it adds the gallery main-media/thumbnail presentation and the bounded video slider. Shopify Theme Check, the app build, 20 storefront tests and all extension JavaScript size limits passed. The Horizon theme remained a draft and was not published.
- Live browser visual acceptance remains open because the Chrome control connection repeatedly failed to load its request-header policy after release. Automated checks and server health passed, but they are not represented as human visual acceptance.
