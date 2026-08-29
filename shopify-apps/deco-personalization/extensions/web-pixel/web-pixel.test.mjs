import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const root = new URL('./', import.meta.url);
const config = await readFile(new URL('shopify.extension.toml', root), 'utf8');
const runtime = await readFile(new URL('src/index.js', root), 'utf8');

test('Web Pixel runs in strict analytics-only privacy mode', () => {
  assert.match(config, /type = "web_pixel_extension"/);
  assert.match(config, /runtime_context = "strict"/);
  assert.match(config, /analytics = true/);
  assert.match(config, /marketing = false/);
  assert.match(config, /preferences = false/);
  assert.match(config, /sale_of_data = "disabled"/);
});

test('Web Pixel subscribes only to P0 recommendation and checkout events', () => {
  assert.match(runtime, /deco_personalization:impression/);
  assert.match(runtime, /deco_personalization:click/);
  assert.match(runtime, /deco_personalization:add_to_cart/);
  assert.match(runtime, /deco_personalization:checkout_recommendation_impression/);
  assert.match(runtime, /deco_personalization:checkout_recommendation_click/);
  assert.match(runtime, /deco_personalization:checkout_recommendation_add_success/);
  assert.match(runtime, /deco_personalization:checkout_recommendation_add_failed/);
  assert.match(runtime, /deco_personalization:checkout_recommendation_sequence_completed/);
  assert.match(runtime, /checkout_completed/);
  assert.doesNotMatch(runtime, /all_events|all_standard_events|all_custom_events/);
});

test('Web Pixel sends a bounded structured payload without customer identity or raw context', () => {
  assert.match(runtime, /slice\(0, 50\)/);
  assert.match(runtime, /text\/plain;charset=UTF-8/);
  assert.match(runtime, /keepalive: true/);
  assert.match(runtime, /https:/);
  assert.doesNotMatch(runtime, /email|phone|firstName|lastName|shippingAddress|billingAddress|document|referrer|ip|userAgent/i);
  assert.doesNotMatch(runtime, /console\.|localStorage|eval\(|new Function/);
});
