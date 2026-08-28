import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('./AppHome.jsx', import.meta.url), 'utf8');

test('App Home uses the Personalization identity and bootstrap endpoints', () => {
  assert.match(source, /\/api\/shopify-app\/personalization\/connection/);
  assert.match(source, /\/api\/shopify-app\/personalization\/bootstrap/);
  assert.match(source, /\/shopify-app\/personalization/);
  assert.match(source, /Authorization: `Bearer \$\{token\}`/);
  assert.match(source, /getIdToken/);
});

test('App Home does not modify themes or contain another environment', () => {
  assert.doesNotMatch(source, /themes\/current\/editor|addAppBlockId/);
  assert.doesNotMatch(source, /trycloudflare|https:\/\/admin\.decomkt\.com/);
  assert.doesNotMatch(source, /myshopify\.com/);
});
