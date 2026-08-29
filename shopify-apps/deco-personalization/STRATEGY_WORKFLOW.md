# Personalization strategy workflow

This document describes the merchant-facing strategy workflow implemented in DecoAdmin. It does not authorize a Production release.

## Information architecture

The Personalization management page has exactly three top-level entries:

1. **概览** — setup progress, launch checks, alerts, and global settings.
2. **策略** — strategy search, draft creation, editing, duplication, publication, disabling, recycling, and version restoration.
3. **分析** — impressions, clicks, add-to-carts, orders, attributed revenue, and breakdowns by strategy, strategy version, placement, and component.

Checkout, Smart Cart, trust items, default copy, and attribution settings remain available under 概览. Component placement and display settings live inside the strategy editor; none of the existing storefront capabilities were removed.

## Draft and publication contract

- `personalization_recommendation_strategies.uuid` is the stable strategy identity.
- Every editable snapshot is a `personalization_strategy_versions` row with an immutable version number and a stable UUID.
- Creating a strategy immediately creates version 1 as a draft named `未命名策略`.
- Autosave updates only a draft, uses an optimistic `lock_version`, and requires a UUID idempotency key. A stale lock returns `DRAFT_VERSION_CONFLICT`; the UI reports failure and provides a retry action.
- Opening an enabled strategy creates or reuses a new draft copied from the published version. The live strategy, rules, products, and components remain unchanged until explicit publication.
- Publication validates the draft, snapshots deterministic rules and product choices, writes the live configuration in one transaction, supersedes the previous published version, and records the new version on every active component.
- Restoring an old version copies it into a new version and publishes that copy. Historical rows are not rewritten or destroyed.
- One active strategy is allowed per store and placement in this first version. Publication reports the currently bound component and requires explicit replacement confirmation.

## Editor behavior

The editor is a full-screen application surface with six steps: basic information, recommendation rules, pinned/excluded products, discount association, usage scenes, and preview/publication.

- It closes only through the fixed close action. Escape triggers the same guarded close flow; the backdrop never closes the editor.
- Pending edits are saved before a normal close. Failed saves are never presented as successful, and the user can retry or explicitly close while retaining the last server-side draft.
- Only the six approved deterministic algorithms are available. There is no arbitrary rule builder or generative recommendation surface.
- Discount configuration only associates an existing discount reference. It never creates or changes a Shopify discount and requires no additional Shopify scope.
- Removing a placement from the draft and publishing soft-deletes the old component binding. A strategy with any remaining component binding cannot be recycled.

## Preview and failure safety

Preview uses Commerce Hub data for the current Organization and Store. It accepts a real seed product plus simulated cart and purchased-product lists, then returns ordered recommendations and structured skip reasons:

- `already_in_cart`
- `already_purchased`
- `excluded_by_strategy`
- `out_of_stock_or_market_unavailable`

Checkout preview remains one card at a time. A merchant may optionally set a sequence maximum from 1 to 1000; leaving it empty traverses the selected Collection until exhaustion. The value is not a collection of fixed product slots. Empty results hide the storefront component safely.

## Recycling and retention

Only a strategy without page or component bindings can be moved to the recycle bin. Recycling uses Laravel soft deletion, records `archived_at`, and sets `purge_after` to 30 days. Restoration clears those fields and returns the strategy as a draft or disabled published strategy. The existing retention job permanently removes expired recycled strategies and their already-unlinked components.

## Security and analytics

- Every workflow entry point validates authenticated user access, Organization, Store, RBAC permission, active tenant state, and the permanent shop denylist in the backend Business Service.
- Writes are idempotent, audited, bounded, and transactionally applied. Browser-provided Organization or Store identifiers are never trusted by the Business Service.
- Events and attributions now retain `strategy_version_id`; daily metrics retain `strategy_version_key`. Analytics therefore preserves historical version and placement boundaries.
- No customer name, email, phone, address, payment data, Shopify token, Session Token, Client Secret, or raw authorization header is stored in strategy snapshots or returned by preview.

## Rollback

- UI rollback: revert the Personalization page while leaving strategy/version tables intact.
- Publication rollback: restore the preceding published version, which creates a new auditable version.
- Placement rollback: publish a draft with the unwanted placement removed or explicitly restore the previous placement version.
- Checkout and Smart Cart retain their existing independent off switches and Shopify-default-cart recovery path.
