# Deco Referral & Affiliate Center

Internal custom Shopify App for the E-LINK TECHNOLOGY CO LTD organization. The current release is a test pilot: Shopify and backend writes are restricted to `macfox-test-app.myshopify.com` and `https://testadmin.decomkt.com`. Production is disabled by the backend guard.

## Implemented workflow

- Plans: create, edit, activate/pause, campaign dates, fixed/percentage commission, product/variant/collection rules, exclusions, member overrides, versioned rules and audit history.
- Promoters: application, one-time invitation, approval/rejection/waitlist/suspension, labels, notes and duplicate-safe CSV import.
- Tracking: short links, UTM, deep links, downloadable local QR codes, consent-aware first/last signed cart signals and affiliate coupons. Shopify coupon wins over click attribution; conflicting affiliate coupons require review.
- Accounting: integer currency amounts, immutable financial entries, partial/full refund and cancellation reversals, waiting periods, manual adjustments and correction of unsettled cash attribution with preserved prior snapshots.
- Settlement: reserved batches, cancellation, CSV, payment reference/private proof and idempotent paid confirmation. This records an external payment; it does not transfer money. Refunds after settlement create negative balances, offset before later payouts.
- Customer referral: paid-customer verification, first-order friend incentives, single-use customer-specific fixed/percentage/free-shipping rewards, product/collection restrictions, milestones and refund recovery. Usage is recorded from paid orders rather than relying solely on delayed Shopify usage counters.
- Invitations after purchase: opt-in per advocate plan, with a separate notification-template switch. Only currently paid, uncancelled orders with subscribed marketing consent qualify. Consent and order status are checked again before sending. Existing memberships are not repeatedly invited.
- Portal: isolated one-time-link sessions, links/QR/deep links, orders, reward history, balances, payouts, private assets and recently verified payment-profile changes.
- Reports: 7/30/90-day UTC ranges, refund-net sales, commissions, refund rates, top promoters, daily results and CSV. Organization aggregation is permission-scoped; live multi-store testing is intentionally prohibited in this pilot.
- Risk and operations: self-purchase checks, duplicate/out-of-order handling, order velocity, same-source and click-burst review flags, queued retries, scheduled release/reconciliation and data retention. Raw clicks older than 180 days become anonymous daily counts; old login/invitation tokens and notification/webhook contents are pruned without deleting financial records.

## Operating boundaries

- An already settled payout is never rewritten. Correct it with audited ledger adjustments. Reserved batches must be cancelled before changing attribution. Reward-order ownership requires reward/risk handling rather than cash-attribution reassignment.
- Incomplete historical customer data is held for review; it is not assumed to be a new customer. The App does not request `read_all_orders`.
- Tracking requires the theme embed and the visitor's applicable consent. Cross-device attribution is not guaranteed. Coupons remain an independent attribution signal.
- Email sending and post-purchase invitation are off by default. No real bank/PayPal transfers, cash/store-credit/gift-card/free-product automation, App Store listing or commercial billing is implemented in this internal scope.
- Test fixtures and actual Bogus orders are labelled in the acceptance report. Test payments are disabled after checkout regression.

## Development and verification

All App-owned dependencies, extension files and scripts live here. Backend business services live in `app/Domain/ReferralAffiliate` and enforce User, Organization, Store and permission boundaries.

```sh
npm ci
npm run check
npm run test:tracking
npm run build:portal
shopify app config validate --config test --json
shopify app build --config test
```

Use Docker for backend tests and frontend builds. Build `public/build`, restart Vite and verify readiness after frontend edits. Staging uses its normal Horizon `supervisor-referral` and scheduler; the old standalone worker override is a fallback and is not needed for ordinary staging deployment.

Staging-only integration scripts:

```sh
php shopify-apps/deco-referral/scripts/staging-regression.php
php shopify-apps/deco-referral/scripts/staging-portal-regression.php
```

The first script rolls back synthetic business fixtures and uses an in-memory mail transport. The second verifies real portal HTTP/session/invitation behavior, leaves a labelled rejected test applicant with a paused plan, and restores store settings. Neither sends external mail or performs a real transfer.

Credentials are environment-specific `REFERRAL_LOCAL_*`, `REFERRAL_TEST_*`, `REFERRAL_PRODUCTION_*`; no cross-environment fallback or shared Shopify App tokens. Never commit secrets or session tokens.

See [regression progress](docs/regression-progress-2026-09-08.md) and [completion checklist](docs/completion-checklist.md) for evidence and remaining acceptance status.
