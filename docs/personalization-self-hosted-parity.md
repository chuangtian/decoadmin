# Deco Personalization self-hosted parity specification

## Objective

Build and operate a fully self-hosted recommendation system. AfterShip is a behavioral reference only. Runtime recommendations, event ingestion, identity, training, ranking, discounts, storefront rendering, analytics, and model governance must remain inside DecoAdmin and its dedicated Shopify App.

The target is observable behavioral parity on an approved test store, measured with repeatable fixtures and ranking metrics. The production `macfoxebike` AfterShip workspace is read-only and must never be used to create experiments, alter strategies, install apps, or generate synthetic visitor behavior.

## Approved storefront surfaces

The product scope excludes the home page, product detail page, collection page, and Smart Collections.

Supported surfaces are:

1. Cart page
2. Cart drawer
3. Smart cart
4. Cart popup
5. Checkout
6. Post-purchase
7. Thank you
8. Order status / customer account order page
9. Branded tracking page

Each surface has a separate activation state, component configuration, analytics dimension, and compatibility check. A strategy is reusable across surfaces, but publishing one surface must not silently replace or enable another surface.

## Merchandising rules observed in AfterShip

### Conditions

- Anything
- Cart value
- Number of products in cart
- Products in cart
- Product on product detail page (retained only for compatibility fixtures; not exposed in the approved Deco scope)
- Product collections
- Product types
- Product tags
- Product vendors
- Product created date
- Product stock
- UTM source, medium, campaign, term, content, and ID
- URL
- Country or region resolved by Shopify Markets
- Country or region resolved from the customer IP address

Conditions support ordered rules, AND/OR matching, field-specific comparison operators, `exit if matched`, and an optional fallback rule. References are always resolved inside the current organization and store.

### Actions

- Next product sequence model (Deco-owned model; never label a static blend as an LLM)
- Free-shipping upsell
- Similar products
- Substitutes
- New arrivals
- Complete the look
- Manual selection
- Frequently bought together
- Complements
- Best sellers
- Recently viewed
- Same-product upsell
- Frequently viewed together
- All products
- Product collection, type, tag, and vendor candidate sets

Actions support candidate filters. The store policy disables discount stacking, removes unavailable or out-of-stock merchandise, removes subscription products, and currently does not require merchant-managed pins or exclusions. Existing pins and exclusions remain readable for backward compatibility until a deliberate migration removes them.

## Recommendation pipeline

Every request follows the same stages:

1. Resolve the trusted organization, store, Shopify Market, locale, presentment currency, session, customer consent, and surface.
2. Build eligible candidates from products published and available in the buyer's market.
3. Remove out-of-stock variants, subscription-only products, cart duplicates where required, and products invalid for the current surface.
4. Evaluate ordered merchandising rules and the fallback rule.
5. Generate candidates from the selected algorithm and at least one independent fallback algorithm.
6. Rank candidates with calibrated purchase probability, expected net revenue, market fit, inventory confidence, discount cost, return risk, freshness, and diversity.
7. Return reason codes, model version, feature snapshot version, rule ID, rank, price in presentment currency, and deterministic request diagnostics.
8. Record eligible exposure only after the component is actually rendered in the viewport.

The online serving path must use precomputed features and Redis. It must not train models or scan raw orders synchronously.

## Model families

- Best sellers: time-decayed units and net revenue, segmented by market with store-wide fallback.
- Trending: short-window acceleration of views, recommendation clicks, adds, checkouts, and paid orders, with bot and anomaly suppression.
- Frequently bought together: order-basket co-occurrence with support, confidence, lift, recency, refunds, and quantity.
- Frequently viewed together: consented session co-view transitions with position and time decay.
- Complements: cross-type purchase and view graph with compatibility constraints.
- Similar products and substitutes: multilingual text embeddings, structured attributes, price band, and image embeddings. Similarity and substitutability are trained and scored separately.
- Complete the look: visual and cross-category compatibility embeddings plus purchase evidence.
- Recently viewed: consented per-identity sequence, excluding bought and currently carted products.
- Same-product upsell: available higher-value variants or successor products with compatibility checks.
- Free-shipping upsell: presentment-currency threshold gap, add probability, margin, inventory, and minimum quantity.
- Next product: a Deco-owned sequential model trained from consented view, search, recommendation, cart, checkout, and paid-order sequences. Static weighted blending is only a cold-start fallback and must be labeled as such.
- Dynamic selection: contextual bandit chooses an eligible model/strategy for a surface and explores only inside configured safety bounds.

## Training and continuous adjustment

- Stream events into an append-only ingestion queue with idempotency, batching, retry, backpressure, and a dead-letter path.
- Build immutable daily feature snapshots and versioned training datasets.
- Retrain co-occurrence and popularity features daily; refresh short-window trending features at least hourly.
- Retrain learned ranking and sequence models only after data-quality, offline ranking, calibration, fairness, and latency gates pass.
- Promote models through shadow, canary, and controlled experiment stages. Roll back by model version without rolling back storefront code.
- Detect feature drift, catalog drift, event loss, model drift, cold-start coverage, and market-level degradation.
- Never train on canceled, fully refunded, test, fraudulent, or staff orders.

## Identity and privacy

The merchant allows consented anonymous-to-customer and cross-device identity stitching. Raw identifiers are never stored in recommendation event tables; store-scoped keyed hashes and revocable identity links are used.

When Shopify permits preferences and analytics processing, collect page/product/collection/cart/search/recommendation/checkout/order events, view duration, recommendation exposure/click/add position, market, locale, currency, device class, session transitions, and consent state. Marketing attribution is processed only when marketing permission permits it.

When a visitor rejects non-essential processing:

- Keep only the current request context required to render the requested cart or checkout service.
- Permit transient cart lines, current market, locale, presentment currency, availability, request ID, response status, latency, abuse prevention, and security logs.
- Do not create a persistent visitor profile, cross-session identity, cross-device link, browsing history, dwell-time profile, recommendation-learning event, marketing attribution, or interest segment.
- Do not write non-essential cookies or local-storage identifiers.

Default retention:

- Raw consented clickstream: 90 days.
- Session and recommendation exposure detail: 180 days.
- Revocable identity links: 12 months after last activity.
- De-identified aggregate features: 24 months.
- Order and financial attribution: the merchant's finance retention policy.
- Privacy deletion requests remove identifiable links and retrain or tombstone affected training rows as required.

## Markets, currencies, and languages

- Candidate availability, inventory, publications, price lists, and discount eligibility are evaluated in the active Shopify Market.
- Thresholds and buyer-facing prices use presentment currency. Store analytics retain original currency and the order-time FX snapshot, and aggregate in the store base currency.
- Product text is embedded from available localized Shopify content and mapped back to a canonical product ID. Display copy always uses the buyer's active locale.
- Sparse markets use hierarchical fallback to store-wide signals; market-specific rankers take over only after minimum evidence and validation gates are met.

## Discounts

- Recommendation discounts cannot combine with other discounts.
- The server validates active dates, market, currency, customer eligibility, product/variant eligibility, usage limits, and the recommendation rule before returning an offer.
- Checkout and post-purchase surfaces must revalidate at application time.
- Analytics stores gross sales, discount amount, net revenue, quantity, and attributed order/item counts separately.

## Benchmark and acceptance

Black-box comparison must use an isolated AfterShip-connected test store. Production stores are forbidden test targets.

The benchmark contains versioned fixtures across cart composition, quantity, subtotal, market, currency, locale, URL, UTM, inventory, catalog changes, consent, new/returning identity, and each approved surface. Capture the reference response and Deco response at the same catalog and time snapshot.

Report:

- Rule match and fallback parity.
- Eligibility/exclusion parity.
- Top-K overlap, NDCG, mean reciprocal rank, diversity, coverage, and empty-result rate.
- Recommendation latency at p50, p95, and p99.
- Add-to-cart, conversion, net revenue per session, discount cost, refund rate, and statistical confidence from controlled experiments.
- Differences by market, currency, locale, device, and surface.

No implementation is described as equivalent based on screenshots or a few examples. Algorithm labels shown in the UI must correspond to the actual serving implementation and model version.

## Current implementation gaps at `dbf2fdd`

- Custom conditions cover only cart product, collection, tag, and vendor sets.
- Custom actions are manual product selections; algorithm actions inside custom rules are missing.
- Cart value/count, product metadata, UTM/URL, market, IP-country, numeric/date operators, and fallback algorithms are missing.
- `next_llm` is a fixed blend and has no learned sequence model.
- Event collection covers recommendation events, product view, and checkout completion but lacks the complete consent-aware sequence and durable asynchronous ingestion pipeline.
- Cart popup, post-purchase, and branded tracking placements are absent.
- Market-specific contextual pricing, localized embeddings, subscription exclusion, and cross-device identity are incomplete.
- There is no feature store, model registry, training pipeline, shadow evaluation, canary promotion, or drift monitoring.
- There is no isolated AfterShip-connected test store for output comparison.

## AfterShip test-store baseline (2026-09-16)

The isolated Shopify store `macfox-test-app.myshopify.com` is connected to a new AfterShip Personalization workspace with a 90-day trial. The production `macfoxebike` workspace was not changed.

Onboarding inputs:

- Industry: Other (electric bicycle store).
- Goals: average order value, average basket size, conversion rate, and lifetime value.
- Selected scenarios: Smart cart, Cart popup, Checkout page, Post-purchase page, and Thank you page.
- Explicitly unselected: Home page, Product page, Bundle, Notifications, and Smart collections.

Generated discount defaults:

| Scenario | Discount |
| --- | ---: |
| Smart cart / cart drawer | 5% |
| Cart popup | 10% |
| Checkout | 10% |
| Post-purchase | 20% |
| Thank you | 20% |

The onboarding summary described Smart cart and Post-purchase as Frequently bought together, Checkout as Complements, and Thank you as New arrivals. After generation, all five underlying reusable strategies showed `Next LLM` as the selected pre-made rule. This difference must be treated as a testable template-to-strategy transformation rather than copied as static UI text.

The generated Cart Drawer widget used strategy `00005`, enabled exclusion of products already in the cart or order, and had no merchant pins or extra exclusions. Its first cold-start editor preview returned, in order:

1. Macfox E-bike X7 Battery & Dual Battery Upgrade Kit
2. Macfox Bar Pad
3. Macfox Hat

The Cart Drawer display editor exposed:

- Widget border and responsive padding.
- Title text, padding, and alignment.
- Separate mobile and desktop product count/layout/column controls.
- Product/content padding, alignment, and radius.
- Product or dynamic-variant image source, aspect ratio, border, and radius.
- Product name, description, reviews, price, compare-at price, and discount value.
- Dynamic variant selector, dropdown layout, variant preselection, smart variant match, subscription options, quantity editing, and selector radius.
- Select-variant and primary action labels, primary action behavior, success message, and button radius.
- A/B test creation and installation guidance.

The style editor exposed automatic Shopify-theme synchronization, per-device title typography, product typography and colors, variant/quantity selector colors, action-button typography and colors, and custom CSS. The observed defaults used 16 px title and product text and 14 px action-button text.

### Widget editor parity requirement

The Deco widget editor must reproduce the observed AfterShip editor information architecture and behavior instead of introducing a Deco-specific control model. It is a dedicated navigation entry and uses the same three sections:

1. **Settings**: status, recommendation strategy, installation instructions, copy/install action, and A/B test entry.
2. **Display**: widget border; separate mobile and desktop top/bottom and left/right padding; widget title, title padding and alignment; mobile and desktop product count and layout; desktop columns; product padding; product-content alignment and unified alignment; content radius; image source, aspect ratio, border and radius; product name, description, reviews, price, compare-at price and discount value; discount text; variant-selector display, layout, preselection, Smart variant match, subscription options, quantity editing and radius; select-variant label, primary action, primary label, success message and button radius.
3. **Styles**: automatic Shopify-theme synchronization and reset; widget background; separate mobile and desktop widget-title font, size, weight and color; product font, size, weight, background, name, description, price, compare-at-price and discount colors; selector background, border and text; action-button font, size, weight, background, border and text; custom CSS; A/B test entry.

Controls, defaults, validation ranges, responsive behavior, preview behavior, save/draft lifecycle and storefront rendering must be derived from the authorized AfterShip test workspace. Do not add substitute presets or remove observed controls. Deco branding may wrap the page, but the editor's hierarchy and interaction model remain equivalent.

Environment constraints observed during onboarding:

- The test shop is not Shopify Plus, so the AfterShip Checkout offer UI is disabled even though onboarding generated a reusable Checkout strategy.
- Post-purchase requires selecting AfterShip Personalization as the store's post-purchase app in Shopify Settings. This was not changed because it can replace another post-purchase provider.
- Onboarding generated reusable strategy records for Post-purchase and Thank you, but did not create active offers in those scenario lists.
- The Customer account / Order status page had no generated offer and requires a separate create-offer flow.
- The branded Tracking page requires AfterShip Tracking Pro. It is unavailable in the current test subscription and must be compared from documented behavior or a separately licensed sandbox.

These constraints are part of the benchmark evidence. A generated strategy record is not proof that a storefront surface is installed, enabled, rendering, or collecting data.
