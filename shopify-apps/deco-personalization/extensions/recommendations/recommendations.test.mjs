import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const root = new URL('./', import.meta.url);
const liquid = await readFile(new URL('blocks/recommendations.liquid', root), 'utf8');
const javascript = await readFile(new URL('assets/recommendations.js', root), 'utf8');
const stylesheet = await readFile(new URL('assets/recommendations.css', root), 'utf8');
const smartCartLiquid = await readFile(new URL('blocks/smart_cart.liquid', root), 'utf8');
const smartCartJavascript = await readFile(new URL('assets/smart-cart.js', root), 'utf8');
const smartCartStylesheet = await readFile(new URL('assets/smart-cart.css', root), 'utf8');

test('recommendation block is a section App Block backed by its own app-data proxy path', () => {
  assert.match(liquid, /"target": "section"/);
  assert.match(liquid, /deco_personalization\.proxy_path/);
  assert.match(liquid, /"javascript": "recommendations\.js"/);
  assert.match(liquid, /"stylesheet": "recommendations\.css"/);
  assert.match(liquid, /component_uuid/);
});

test('storefront runtime uses only same-origin signed App Proxy and cart routes', () => {
  assert.match(javascript, /window\.location\.origin/);
  assert.match(javascript, /\/recommendations\/\$\{componentUuid\}/);
  assert.match(javascript, /\/cart\/add\.js/);
  assert.match(javascript, /shopify:section:load/);
  assert.doesNotMatch(javascript, /https?:\/\//);
  assert.doesNotMatch(javascript, /innerHTML|eval\(|new Function/);
  assert.match(javascript, /minimum_purchase_quantity/);
  assert.match(javascript, /selected_variant_gid/);
  assert.match(javascript, /resourceId\(product\?\.selected_variant_gid, 'ProductVariant'\)/);
  assert.match(javascript, /quantity: boundedNumber/);
  assert.match(javascript, /setIdQuery\(url, 'cart_product_ids', cartProductIds\)/);
  assert.match(javascript, /cart_subtotal_amount/);
  assert.match(liquid, /data-cart-subtotal-cents/);
  assert.doesNotMatch(javascript, /cart_product_ids\[\]/);
});

test('recently viewed context is anonymous, bounded and contains no customer identity', () => {
  assert.match(javascript, /slice\(0, 20\)/);
  assert.match(javascript, /recently_viewed_product_ids/);
  assert.doesNotMatch(javascript, /customer|email|phone|logged_in_customer_id/i);
  assert.match(stylesheet, /prefers-reduced-motion/);
});

test('recommendation block publishes only structured custom analytics events', () => {
  assert.match(javascript, /Shopify\?\.analytics\?\.publish/);
  assert.match(javascript, /deco_personalization:\$\{action\}/);
  assert.match(javascript, /'impression'/);
  assert.match(javascript, /'click'/);
  assert.match(javascript, /'add_to_cart'/);
  assert.match(javascript, /products\.slice\(0, 50\)/);
});

test('Smart Cart is an App Embed inside the native cart drawer', () => {
  assert.match(smartCartLiquid, /"target": "body"/);
  assert.match(smartCartLiquid, /deco_personalization\.proxy_path/);
  assert.match(smartCartLiquid, /"javascript": "smart-cart\.js"/);
  assert.match(smartCartLiquid, /"stylesheet": "smart-cart\.css"/);
  assert.match(smartCartJavascript, /mode: 'native_cart_embed'/);
  assert.match(smartCartJavascript, /data-personalization-id="00007"/);
  assert.match(smartCartJavascript, /window\.Shopify\?\.routes\?\.root/);
  assert.match(smartCartJavascript, /window\.location\.origin/);
  assert.doesNotMatch(smartCartJavascript, /HTMLDialogElement|showModal\(|window\.location\.reload|window\.location\.assign/);
  assert.doesNotMatch(smartCartStylesheet, /dialog|backdrop|deco-smart-cart__panel/);
  assert.doesNotMatch(smartCartJavascript, /https?:\/\//);
  assert.doesNotMatch(smartCartJavascript, /innerHTML|eval\(|new Function/);
});

test('Smart Cart observes drawer and native line replacements without observing its own recommendation host', () => {
  assert.match(smartCartJavascript, /mutations\.some\(\(mutation\) => drawerTreeChanged\(mutation\) \|\| nativeCartItemsChanged\(mutation\)\)/);
  assert.match(smartCartJavascript, /nativeCartItemsChanged\(mutation\)/);
  assert.match(smartCartJavascript, /target\.closest\(HOST_SELECTOR\)/);
  assert.match(smartCartJavascript, /node\.matches\(ITEMS_SELECTOR\) \|\| node\.matches\(LINE_SELECTOR\)/);
  assert.match(smartCartJavascript, /node\.matches\(DRAWER_SELECTOR\) \|\| Boolean\(node\.querySelector\(DRAWER_SELECTOR\)\)/);
  assert.doesNotMatch(smartCartJavascript, /observer\.observe\([^)]*attributes: true/);
  assert.doesNotMatch(smartCartJavascript, /syncFailureCount|nativeRepairAttempts|cartStateRevision/);
  assert.match(smartCartJavascript, /one recovery attempt only; never start a refresh loop/);
  assert.match(smartCartJavascript, /if \(node\.textContent !== next\) node\.textContent = next/);
  assert.match(smartCartJavascript, /REFRESH_RETRY_DELAYS = \[1200, 3000, 7000\]/);
  assert.match(smartCartJavascript, /refreshRetryCount >= REFRESH_RETRY_DELAYS\.length/);
  assert.match(smartCartJavascript, /catch \{\s*scheduleRefreshRetry\(\);\s*\}/);
  assert.doesNotMatch(smartCartJavascript, /catch \{\s*clearRecommendation\(host\)/);
});

test('Smart Cart uses one serialized backend-first transaction for add and remove', () => {
  assert.match(smartCartJavascript, /let busy = false/);
  assert.match(smartCartJavascript, /function beginOperation\(statusControl, statusText\)/);
  assert.match(smartCartJavascript, /if \(busy\) return false/);
  assert.match(smartCartJavascript, /beginOperation\(button, 'Adding…'\)/);
  assert.match(smartCartJavascript, /label\.textContent = 'Removing…'/);
  assert.match(smartCartJavascript, /cartRoute\('cart\/add\.js'\)/);
  assert.match(smartCartJavascript, /cartRoute\('cart\/change\.js'\)/);
  assert.match(smartCartJavascript, /cartRoute\('cart\/update\.js'\)/);
  assert.match(smartCartJavascript, /const before = await getCart\(\)/);
  assert.match(smartCartJavascript, /let cart = await getCart\(\)/);
  assert.match(smartCartJavascript, /variantQuantity\(cart, variantId\) < previousQuantity \+ quantity/);
  assert.match(smartCartJavascript, /if \(cartContainsLine\(cart, lineId\)\) throw failure\('remove_not_confirmed'\)/);
  assert.doesNotMatch(smartCartJavascript, /optimistic|upsertNativeCartLine|line\.remove\(\)/);
  assert.match(smartCartJavascript, /addCartItemConfirmed\(variantId, quantity, previousQuantity\)/);
  assert.match(smartCartJavascript, /error\?\.code !== 'verification_required'/);
  assert.match(smartCartJavascript, /variantQuantity\(cart, variantId\) >= expectedQuantity/);
  assert.match(smartCartJavascript, /await delay\(1200\)/);
  assert.doesNotMatch(smartCartJavascript, /requestJson\(cartRoute\('cart\/add\.js'\)[\s\S]{0,500}retries:/);
});

test('Smart Cart commits a complete Shopify native section only after every response is ready', () => {
  assert.match(smartCartJavascript, /sections: \['mini_cart'\]/);
  assert.match(smartCartJavascript, /section = await completeNativeSection\(section, cart\)/);
  assert.match(smartCartJavascript, /const nextConfig = await getConfig\(cart\)\.catch\(\(\) => null\)/);
  assert.match(smartCartJavascript, /commitNativeCart\(section, cart, nextConfig, lineOrder\)/);
  assert.match(smartCartJavascript, /targetItems\.replaceChildren\(\.\.\.\[\.\.\.sourceItems\.childNodes\]/);
  assert.match(smartCartJavascript, /renderRecommendation\(ensureHost\(\), cart, config, true\)/);
  assert.match(smartCartJavascript, /return lines\.length === \(Array\.isArray\(cart\?\.items\) \? cart\.items\.length : 0\)/);
  assert.doesNotMatch(smartCartJavascript, /sessionStorage|APPENDED_VARIANTS_KEY|rememberAppendedVariant|reorderRememberedCartLines/);

  const addStart = smartCartJavascript.indexOf('async function addRecommendation');
  const addRequest = smartCartJavascript.indexOf('addCartItemConfirmed(variantId, quantity, previousQuantity)', addStart);
  const addConfirm = smartCartJavascript.indexOf('let cart = confirmedCart', addRequest);
  const addSection = smartCartJavascript.indexOf('completeNativeSection(section, cart)', addConfirm);
  const addCommit = smartCartJavascript.indexOf('commitNativeCart(section, cart, nextConfig, lineOrder)', addSection);
  const addFinish = smartCartJavascript.indexOf('finishOperation(button)', addCommit);
  assert.ok(addRequest > addStart && addConfirm > addRequest && addSection > addConfirm && addCommit > addSection && addFinish > addCommit);

  const removeStart = smartCartJavascript.indexOf('async function removeCartLine');
  const removeRequest = smartCartJavascript.indexOf("cartRoute('cart/change.js')", removeStart);
  const removeConfirm = smartCartJavascript.indexOf('const cart = await getCart()', removeRequest);
  const removeCommit = smartCartJavascript.indexOf('commitNativeCart(section, cart, config, lineOrder)', removeConfirm);
  const removeFinish = smartCartJavascript.indexOf('finishOperation(control)', removeCommit);
  assert.ok(removeRequest > removeStart && removeConfirm > removeRequest && removeCommit > removeConfirm && removeFinish > removeCommit);
});

test('Smart Cart preserves the visible native line order and appends newly added recommendations', () => {
  assert.match(smartCartJavascript, /const lineOrder = renderedLineOrder\(\)/);
  assert.match(smartCartJavascript, /function commitNativeCart\(remote, cart, config, preferredLineOrder = renderedLineOrder\(\)\)/);
  assert.match(smartCartJavascript, /orderNativeLines\(sourceItems, preferredLineOrder\)/);
  assert.match(smartCartJavascript, /preferred\.has\(lineIdentity\(left\)\)/);
  assert.match(smartCartJavascript, /document\.createComment\('deco-cart-line-slot'\)/);
  assert.match(smartCartJavascript, /marker\.replaceWith\(lines\[index\]\)/);
  assert.doesNotMatch(smartCartJavascript, /sessionStorage|APPENDED_VARIANTS_KEY|rememberAppendedVariant/);
});

test('Smart Cart synchronizes nested header count and total without allowing a completed double click to leak', () => {
  assert.match(smartCartJavascript, /HEADER_TOTAL_SELECTOR = '\[data-cart-tt-price\], \.t4s-h-cart__total'/);
  assert.match(smartCartJavascript, /document\.querySelectorAll\(HEADER_TOTAL_SELECTOR\)/);
  assert.match(smartCartJavascript, /node\.querySelectorAll\('\[data-hulkapps-cart-total\], \[data-cart-count-value\], span'\)/);
  assert.match(smartCartJavascript, /suppressCartClicksUntil = Date\.now\(\) \+ 400/);
  assert.match(smartCartJavascript, /Date\.now\(\) < suppressCartClicksUntil/);
});

test('Smart Cart locks the entire drawer until the atomic commit finishes', () => {
  assert.match(smartCartJavascript, /lockDrawer\(true\)/);
  assert.match(smartCartJavascript, /drawer\.inert = true/);
  assert.match(smartCartJavascript, /drawer\.setAttribute\('aria-busy', 'true'\)/);
  assert.match(smartCartJavascript, /drawer\.removeAttribute\('aria-busy'\)/);
  assert.match(smartCartJavascript, /window\.addEventListener\('keydown', blockLockedInteraction, true\)/);
  assert.match(smartCartJavascript, /event\.stopImmediatePropagation\(\)/);
  assert.match(smartCartStylesheet, /\[data-deco-cart-locked="true"\].*pointer-events: none !important/s);
  assert.match(smartCartStylesheet, /\.deco-native-cart-removing \{ display: inline-block/);
  assert.match(smartCartJavascript, /const titleSample = cartItemTextSample\(line\)/);
  assert.match(smartCartJavascript, /label\.style\.color = titleColor/);
  assert.doesNotMatch(smartCartStylesheet, /deco-native-cart-operation/);
});

test('Smart Cart renders one sequential product with theme typography and required pricing', () => {
  assert.match(smartCartJavascript, /nextRecommendation\(config\?\.recommendations\?\.items, cart\)/);
  assert.match(smartCartJavascript, /recommendationCard\(recommendation\.product, recommendation\.variant, config\)/);
  assert.match(smartCartJavascript, /selected_variant_gid/);
  assert.match(smartCartJavascript, /reason_code === 'same_product_upsell'/);
  assert.match(smartCartJavascript, /minimum_purchase_quantity/);
  assert.match(smartCartJavascript, /deco-native-cart-recommendation__image/);
  assert.match(smartCartJavascript, /deco-native-cart-recommendation__prices/);
  assert.match(smartCartJavascript, /document\.createElement\('s'\)/);
  assert.match(smartCartJavascript, /prices\.append\(discounted, original\)/);
  assert.match(smartCartJavascript, /currencyDisplay: 'narrowSymbol'/);
  assert.match(smartCartJavascript, /applyThemeAppearance\(host, placement\.drawer\)/);
  assert.match(smartCartJavascript, /window\.getComputedStyle/);
  assert.match(smartCartJavascript, /Boolean\(node\.textContent\?\.trim\(\)\)/);
  assert.doesNotMatch(smartCartJavascript, /querySelector\('\.t4s-mini_cart__title/);
  assert.match(smartCartJavascript, /!cartProducts\.has\(productId\)/);
  assert.match(smartCartStylesheet, /grid-template-columns: 88px minmax\(0,1fr\) auto/);
  assert.match(smartCartStylesheet, /overflow-wrap: anywhere/);
  assert.match(smartCartStylesheet, /--deco-native-font-family/);
  assert.match(smartCartStylesheet, /padding: 20px 0 4px/);
  assert.match(smartCartStylesheet, /border-top: 0/);
  assert.match(smartCartStylesheet, /border-radius: 8px/);
  assert.match(smartCartStylesheet, /background: var\(--accent-color,#f2cb04\) !important; color: #111 !important/);
});

test('Smart Cart keeps anonymous bounded context and hides verification responses', () => {
  assert.match(smartCartJavascript, /slice\(0, 20\)/);
  assert.match(smartCartJavascript, /slice\(0, 100\)/);
  assert.match(smartCartJavascript, /recently_viewed_product_ids/);
  assert.match(smartCartJavascript, /cart_subtotal_amount/);
  assert.doesNotMatch(smartCartJavascript, /customer|email|phone|logged_in_customer_id/i);
  assert.match(smartCartJavascript, /isVerificationResponse\(response\.status, type, raw\)/);
  assert.match(smartCartJavascript, /connection needs to be verified/);
  assert.doesNotMatch(smartCartJavascript, /Store security verification interrupted|Item added\. Apply discount code/);
  assert.match(smartCartStylesheet, /prefers-reduced-motion/);
});

test('Smart Cart publishes bounded structured analytics events', () => {
  assert.match(smartCartJavascript, /Shopify\?\.analytics\?\.publish/);
  assert.match(smartCartJavascript, /placement: 'smart_cart'/);
  assert.match(smartCartJavascript, /strategy_version_uuid/);
  assert.match(smartCartJavascript, /rule_id/);
  assert.match(smartCartJavascript, /nextImpression !== impressionKey/);
  assert.match(smartCartJavascript, /deco_personalization:\$\{action\}/);
  assert.match(smartCartJavascript, /slice\(0, 50\)/);
});
