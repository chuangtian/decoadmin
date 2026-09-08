# Internal Referral completion checklist

Scope follows the user's functional requirements: internal custom App, same Shopify Plus organization, test writes exclusively on macfox-test-app and DecoAdmin staging. The Word document is a functional reference; its Public App distribution and example agent instructions do not override the user's request. No App Store billing/listing, automatic money transfer or multilevel commissions.

Checked items require implementation and stated evidence, not placeholders.

- [x] Independent test App, identity bootstrap, encrypted app token and refresh isolation.
- [x] Store-scoped foundation, plan/promoter creation and approval, pilot guard.
- [x] Links and signed tracking tokens; Shopify coupon creation/retry/deactivation.
- [x] Real checkout percentage discount and Bogus Gateway test order #1002.
- [ ] Plan editing, product/variant/collection rules, overrides and exclusions.
- [ ] Theme tracking, consent handling, cart tokens and checkout mapping.
- [ ] Verified webhook ingestion, paid-order attribution, conflicts and reconciliation.
- [ ] Integer money, line snapshots, append-only commissions, refunds/cancellation and waiting period.
- [ ] Manual attribution/adjustments and risk review with audit and permissions.
- [ ] Application/invitation, labels/notes/import and isolated one-time-link portal sessions.
- [ ] Promoter portal links/QR/deep links, orders, balances, payouts, assets and profile.
- [ ] Atomic payout reservations, CSV, proof/reference, paid/cancelled/failed states and negative balances.
- [ ] Merchant reports, per-store metrics and authorized organization aggregation.
- [ ] Customer referral eligibility, friend incentive, advocate reward lifecycle and milestones.
- [ ] Notification templates and safe test delivery.
- [ ] Uninstall/reinstall, privacy cleanup and operational retry/reconciliation.
- [ ] Full automated regression including cross-tenant access, duplicate/out-of-order events, money/refunds and payout concurrency.
- [ ] End-to-end staging regression covering installed extensions, application/portal, paid order, partial/full refund, cancellation, payout and uninstall/reinstall.

Known test evidence is recorded in staging-2026-09-08.md. The project must not be reported complete until every in-scope item above is implemented and verified, or a concrete external limitation is disclosed.
