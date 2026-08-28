import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const root = new URL('./', import.meta.url);
const liquid = await readFile(new URL('blocks/recommendations.liquid', root), 'utf8');
const javascript = await readFile(new URL('assets/recommendations.js', root), 'utf8');
const stylesheet = await readFile(new URL('assets/recommendations.css', root), 'utf8');

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
