# Checkout UI Extension decision record

This document records the Shopify platform constraints used by the P0 Checkout implementation. It is not a Production release authorization.

## Locked platform version

- The app and Checkout UI Extension use Shopify API `2026-07`.
- Checkout UI extensions on the information, shipping, and payment checkout surfaces require Shopify Plus. External validation is limited to the explicitly authorized Plus Test store.
- The Test App remains the first runnable Shopify environment. There is no runnable Local Shopify App, local Client ID/Secret, Cloudflare preview, or Local Shopify installation.

## Supported targets

Both blocks use Shopify's movable `purchase.checkout.block.render` target:

- Trust badges recommend the legal `WALLETS1` placement, immediately above native accelerated checkout buttons. The placement is unavailable when Shopify doesn't render accelerated checkout.
- Sequential recommendations recommend `ORDER_SUMMARY2`, immediately below checkout line items in the order summary.

The merchant must add and enable both blocks in the Checkout and accounts editor. Backend configuration is off by default and never edits a Checkout profile automatically.

Official reference: <https://shopify.dev/docs/api/checkout-ui-extensions/2026-07/targets/checkout/block>

## Rendering and cart changes

- The extension renders only Shopify Polaris Checkout web components. It does not access the Checkout DOM, render arbitrary HTML, override component CSS, or depend on page selectors.
- Cart lines are read through the reactive Cart Lines API.
- An offer is added with `applyCartLinesChange({type: 'addCartLine', merchandiseId, quantity: minimum_purchase_quantity})` only when `instructions.lines.canAddCartLine` is true.
- Accelerated checkout or another Shopify instruction can reject cart changes. The extension must hide or disable the offer and must never block checkout.
- Checkout sends its reactive cart lines, Market, currency, and language to the same backend Recommendation Strategy Service used by other surfaces. The extension does not evaluate strategy rules or choose products from a Collection itself.
- The recommendation area renders exactly one service-ranked offer at a time. After a successful add it requests a fresh result and renders the next eligible product. A failed add leaves the current offer available for retry.
- Existing cart products, unavailable variants, excluded products, and duplicates are removed by the service. The area hides when the service returns no candidate. It never loops or renders a broken empty frame.

Official references:

- <https://shopify.dev/docs/api/checkout-ui-extensions/2026-07/target-apis/checkout-apis/cart-lines-api>
- <https://shopify.dev/docs/api/checkout-ui-extensions/2026-07/web-components>

## Backend identity and network access

- Dynamic recommendations use the Checkout UI Extension `network_access` capability.
- The recommendation block uses `network_access` with a fresh Session Token for the bounded strategy request. The backend resolves the signed shop, trusted Organization/Store, active strategy version, products, variants, exclusions, and traceability fields. `api_access` remains available for Shopify-supported extension data but is not used to duplicate recommendation logic.
- The configuration endpoint is read from the declarative Shop app-owned metafield `$app:deco_personalization.checkout_configuration_url`; Checkout UI extensions cannot read AppInstallation-owned app-data metafields. Its definition is deployed from the environment-specific Shopify App TOML with Storefront read access, while the value is written during App Home bootstrap. The extension does not hardcode a Test or Production origin.
- Both blocks subscribe through Shopify's `useAppMetafields` API. They remain hidden while the Shop metafield is unavailable and reload configuration automatically when Shopify supplies or updates it; they do not poll or fall back to a hardcoded origin.
- Every backend call obtains a fresh Shopify Session Token and sends it as a bearer token.
- DecoAdmin validates the signed token audience, destination, lifetime, and signature, resolves the shop from signed claims, and then resolves the trusted Organization and Store server-side. Shopify's documented Checkout Session Token contract does not define an `iss` claim, so an optional undocumented issuer value is not used as an authorization input.
- The request never supplies or stores customer email, name, phone, address, payment data, raw Session Token, or arbitrary Organization/Store identifiers.
- Checkout endpoints return `Access-Control-Allow-Origin: *` as required for the extension worker, while authorization still depends on the signed Session Token.

Official references:

- <https://shopify.dev/docs/api/checkout-ui-extensions/2026-07/target-apis/platform-apis/session-token-api>
- <https://shopify.dev/docs/apps/build/checkout/capabilities>

## Events and failure behavior

The extension publishes privacy-gated custom analytics events to the existing Web Pixel pipeline:

- `checkout_recommendation_impression`
- `checkout_recommendation_click`
- `checkout_recommendation_add_success`
- `checkout_recommendation_add_failed`
- `checkout_recommendation_sequence_completed`

No event can block rendering or checkout. Network, parsing, configuration, or cart-mutation failure hides the affected offer or shows a non-blocking retry state. Attribution continues to use the existing seven-day, last-recommendation-click, click-only model with refund and cancellation reversal.

Recommendation events include the server-resolved strategy, strategy version, rule ID, placement, product, variant, and rank. Customer identity and raw checkout context are never added to event payloads.

Official reference: <https://shopify.dev/docs/api/checkout-ui-extensions/2026-07/target-apis/platform-apis/analytics-api>

## Explicit exclusions

- No Post-purchase, Thank you, Order status, Customer Account, Checkout Function, payment customization, generative recommendation, or Production work.
- Native Shop Pay, PayPal, Apple Pay, and Google Pay buttons remain entirely owned by Shopify.
- The permanent production-store denylist remains enforced for every backend and release action.
