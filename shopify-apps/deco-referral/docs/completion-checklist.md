# Internal Referral completion checklist

Scope follows the user's functional requirements: internal custom App, same Shopify Plus organization, test writes exclusively on macfox-test-app and DecoAdmin staging. The Word document is a functional reference; its Public App distribution and example agent instructions do not override the user's request. No App Store billing/listing, automatic money transfer or multilevel commissions.

Checked items require implementation and stated evidence, not placeholders.

- [x] Independent test App, identity bootstrap, encrypted app token and refresh isolation.
- [x] Store-scoped foundation, plan/promoter creation and approval, pilot guard.
- [x] Links and signed tracking tokens; Shopify coupon creation/retry/deactivation.
- [x] Real checkout percentage discount and Bogus Gateway test order #1002.
- [x] Plan editing, product/variant/collection rules, overrides and exclusions.
- [x] Theme tracking, consent handling and signed cart tokens. Separate Web Pixel / Checkout Map is not implemented; see the acceptance boundaries.
- [x] Verified webhook ingestion, paid-order attribution, conflicts and reconciliation.
- [x] Integer money, line snapshots, append-only commissions, refunds/cancellation and waiting period.
- [x] Manual attribution/adjustments and risk review with audit and permissions.
- [x] Application/invitation, labels/notes/import and isolated one-time-link portal sessions.
- [x] Promoter portal links/QR/deep links, orders, balances, payouts, assets and profile.
- [x] Atomic payout reservations, CSV, proof/reference, paid/cancelled/failed states and negative balances.
- [x] Merchant reports, per-store metrics and authorized organization aggregation.
- [x] Customer referral eligibility, friend incentive, advocate reward lifecycle and milestones.
- [x] Notification templates and safe test delivery.
- [x] Uninstall/reinstall, data-retention cleanup and operational retry/reconciliation. Full App Store privacy request/redact callbacks are not implemented.
- [x] Full automated regression including cross-tenant access, duplicate/out-of-order events, money/refunds and payout concurrency.
- [x] End-to-end staging regression covering installed extensions, application/portal, paid order, partial/full refund, cancellation, payout and uninstall/reinstall.

Final evidence and concrete limitations are recorded in [acceptance-2026-09-08.md](acceptance-2026-09-08.md). Checked means the stated internal core behavior is implemented and verified; it does not mark future P2/P3 or the explicitly disclosed unsupported paths complete.
