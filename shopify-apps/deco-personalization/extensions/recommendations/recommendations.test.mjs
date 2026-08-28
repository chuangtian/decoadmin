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
});

test('recently viewed context is anonymous, bounded and contains no customer identity', () => {
  assert.match(javascript, /slice\(0, 20\)/);
  assert.match(javascript, /recently_viewed_product_ids/);
  assert.doesNotMatch(javascript, /customer|email|phone|logged_in_customer_id/i);
  assert.match(stylesheet, /prefers-reduced-motion/);
});

test('Smart Cart is an App Embed that remains governed by the app-data proxy path', () => {
  assert.match(smartCartLiquid, /"target": "body"/);
  assert.match(smartCartLiquid, /deco_personalization\.proxy_path/);
  assert.match(smartCartLiquid, /"javascript": "smart-cart\.js"/);
  assert.match(smartCartLiquid, /"stylesheet": "smart-cart\.css"/);
  assert.match(smartCartLiquid, /data-deco-smart-cart-root/);
});

test('Smart Cart defaults off, reports compatibility and restores the native cart on failure', () => {
  assert.match(smartCartJavascript, /config\.enabled !== true/);
  assert.match(smartCartJavascript, /DecoPersonalizationSmartCart/);
  assert.match(smartCartJavascript, /compatibility/);
  assert.match(smartCartJavascript, /restoreThemeCart/);
  assert.match(smartCartJavascript, /window\.location\.assign\('\/cart'\)/);
  assert.match(smartCartJavascript, /\/cart\.js/);
  assert.match(smartCartJavascript, /\/cart\/change\.js/);
  assert.match(smartCartJavascript, /\/cart\/add\.js/);
  assert.match(smartCartJavascript, /window\.location\.origin/);
  assert.doesNotMatch(smartCartJavascript, /https?:\/\//);
  assert.doesNotMatch(smartCartJavascript, /innerHTML|eval\(|new Function/);
});

test('Smart Cart context stays anonymous and bounded', () => {
  assert.match(smartCartJavascript, /slice\(0, 20\)/);
  assert.match(smartCartJavascript, /slice\(0, 100\)/);
  assert.match(smartCartJavascript, /recently_viewed_product_ids/);
  assert.doesNotMatch(smartCartJavascript, /customer|email|phone|logged_in_customer_id/i);
  assert.match(smartCartStylesheet, /prefers-reduced-motion/);
});
