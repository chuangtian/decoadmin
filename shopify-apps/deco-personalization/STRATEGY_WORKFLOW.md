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

The recommendation card exposes two deterministic modes: **预设规则** and **自定义规则（N）**. Presets are limited to the six already approved non-AI algorithms. Manual selection remains the default. There is no AI label or behavior, merchant-facing maximum-result field, placement step, preview step, publish action, or version-restore action.

- A strategy has a stable UUID and is autosaved with optimistic locking and UUID idempotency keys.
- The picker supports search, selection-time ordering, drag reordering, at most 24 unique candidate products per strategy, and a minimum purchase quantity from 1 to 999 per selected product.
- Each selected product stores its canonical product GID, selected variant GID, selection time, persisted position, and minimum purchase quantity. Storefront and Checkout add the same minimum quantity that the merchant configured.
- Canceling the picker discards its temporary state. Confirming applies the selection to the strategy draft.
- Pinned products are ordered before normal products and are automatically added to the candidate list if needed.
- Draft, archived, unpublished, out-of-stock, and unavailable products remain visible in the merchant picker with their real state. Storefront recommendation always skips unsafe products.
- Exclusions cover cart/order history, explicit products, tags, Collections, vendors, and purchase-option values. No arbitrary expression builder is exposed.

## Deterministic custom rule engine

Custom rules are stored inside the versioned strategy snapshot with stable UUIDs. Each rule contains a name, persisted priority, AND/OR condition group, deterministic conditions, ordered manual action products, simple action filters, and `exit_on_match`. Conditions cover cart product, Collection, tag, and vendor facts. Missing surface context or a stale product/Collection reference makes only that rule non-matching and produces a diagnostic; it never interrupts lower-priority rules or checkout.

Rules execute from top to bottom. A match appends action products in saved order. Products returned by several rules keep their first position, minimum quantity, and highest-priority rule ID. `exit_on_match` stops lower-priority rules. An enabled fallback appends eligible products only after the normal rule pass and never reintroduces a duplicate, excluded, or unavailable product.

Every surface consumes `PersonalizationRecommendationService`; extensions do not reimplement rule evaluation. The service accepts the trusted Store and strategy plus surface, current product, cart/order product context, Market, currency, and language. It returns final ordered products, the selected variant, minimum quantity, strategy version, rule ID, optional validated discount, and bounded diagnostics.

Final processing order is: rule candidates and de-duplication; hard storefront/variant availability; context and merchant exclusions; then available pinned products at the front. Pinned products can bypass ordinary tag/Collection/vendor filters, but never hard availability, the current product, or cart/order duplicate protection.

Autosave applies the strategy's name, deterministic rules, ordered products, minimum quantities, and discount reference. The merchant binds a Checkout placement separately under 概览 by selecting a strategy name and saving. That action creates or updates the internal Checkout component, activates the binding, and makes the strategy list show Checkout under “用于”. Shopify's Checkout Editor block remains the final shopper-visible placement and safely hides when no eligible product remains.

## Discounts

The editor defaults to disabled. Enabling requires a selected Shopify discount. The discount dialog can list existing basic code discounts and create or edit a percentage discount for the currently selected recommendation products. The provided default is **九折优惠** (`10% off`).

Listing requires `read_discounts`; creation and editing require `write_discounts`. Both scopes belong only to the independent Personalization App and must be granted through the Test installation before discount management is available. For an active percentage discount, the recommendation response includes the original amount, discount percentage, discounted amount, currency, and code. Checkout attempts to apply the code after adding the product; a missing, inactive, or rejected discount never breaks the underlying recommendation or checkout.

## Permanent deletion

There is no merchant-visible recycle bin, archive action, restore route, retention badge, or recovery workflow.

- The strategy row exposes a single **删除** action.
- A standard destructive modal names the strategy, states that deletion cannot be recovered, and warns when component locations will stop recommending.
- Confirmation is authenticated, Store/Organization scoped, RBAC protected, audited, idempotent, and transactionally removes component bindings before permanently deleting strategy configuration.
- Event and attribution foreign keys are nulled by the database; anonymous aggregates and a minimal strategy UUID/name deletion snapshot can remain for analytics and audit. No deleted strategy configuration can be reconstructed from that snapshot.
- Storefront and Checkout callers receive no active component after deletion and hide without rendering a broken empty frame.

## Security and rollback

Every Business Service validates the authenticated user, Organization, Store, RBAC permission, active tenant state, and permanent shop denylist. Tokens, customer PII, and raw authorization data are never stored in strategy configuration or audit metadata.

Code rollback can restore a previous application build, but intentionally cannot restore strategies that merchants permanently deleted. Removing the Checkout block from Shopify's editor removes its shopper-visible placement. Smart Cart retains its independent safety switch and Shopify-default-cart recovery path.
