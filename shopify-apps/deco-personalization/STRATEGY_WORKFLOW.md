# Personalization strategy management

This document describes the merchant-facing strategy management implemented in DecoAdmin. It does not authorize a Production release.

## Information architecture

The management page has exactly three top-level entries: **概览**, **策略**, and **分析**. Checkout, Smart Cart, trust items, default copy, and attribution remain under 概览. Page and component bindings are managed at their own configuration surfaces, not inside the strategy editor.

## Compact editor

The full-screen editor contains only five merchant-visible sections:

1. 策略名称
2. 推荐规则
3. 置顶产品
4. 除外条款
5. 优惠促销

The recommendation rule is always deterministic manual selection. There is no algorithm selector, maximum-result field, custom rule builder, AI label, placement step, preview step, publish action, or version-restore action.

- A strategy has a stable UUID and is autosaved with optimistic locking and UUID idempotency keys.
- The picker supports search, selection-time ordering, at most 24 products, and a minimum purchase quantity from 1 to 999 per selected product.
- Canceling the picker discards its temporary state. Confirming applies the selection to the strategy draft.
- Pinned products are ordered before normal products and are automatically added to the candidate list if needed.
- Draft, archived, unpublished, out-of-stock, and unavailable products remain visible in the merchant picker with their real state. Storefront recommendation always skips unsafe products.
- Exclusions cover cart/order history, explicit products, tags, Collections, vendors, and purchase-option values. No arbitrary expression builder is exposed.

Autosave applies the strategy's name, deterministic rules, ordered products, minimum quantities, and discount reference. It does not create a placement or enable a component. Existing components remain the only source of storefront visibility and safely hide when no eligible product remains.

## Discounts

The editor defaults to disabled. Enabling requires a selected Shopify discount. The discount dialog can list existing basic code discounts and create or edit a percentage discount for the currently selected recommendation products. The provided default is **九折优惠** (`10% off`).

Listing requires `read_discounts`; creation and editing require `write_discounts`. Both scopes belong only to the independent Personalization App and must be granted through the Test installation before discount management is available. A missing, inactive, or failed discount never breaks the underlying recommendation response.

## Permanent deletion

There is no merchant-visible recycle bin, archive action, restore route, retention badge, or recovery workflow.

- The strategy row exposes a single **删除** action.
- A standard destructive modal names the strategy, states that deletion cannot be recovered, and warns when component locations will stop recommending.
- Confirmation is authenticated, Store/Organization scoped, RBAC protected, audited, idempotent, and transactionally removes component bindings before permanently deleting strategy configuration.
- Event and attribution foreign keys are nulled by the database; anonymous aggregates and a minimal strategy UUID/name deletion snapshot can remain for analytics and audit. No deleted strategy configuration can be reconstructed from that snapshot.
- Storefront and Checkout callers receive no active component after deletion and hide without rendering a broken empty frame.

## Security and rollback

Every Business Service validates the authenticated user, Organization, Store, RBAC permission, active tenant state, and permanent shop denylist. Tokens, customer PII, and raw authorization data are never stored in strategy configuration or audit metadata.

Code rollback can restore a previous application build, but intentionally cannot restore strategies that merchants permanently deleted. Checkout and Smart Cart retain their independent off switches and Shopify-default-cart recovery path.
