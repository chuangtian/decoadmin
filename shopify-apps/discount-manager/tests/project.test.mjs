import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const files = ['shopify.app.toml', 'shopify.app.local.toml', 'shopify.app.test.toml', 'shopify.app.production.toml'];

test('environment placeholders remain non-runnable and secret free', async () => {
  for (const file of files) {
    const contents = await readFile(new URL(file, root), 'utf8');
    assert.doesNotMatch(contents, /client_secret|access_token|refresh_token/i);
    assert.doesNotMatch(contents, /^\s*client_id\s*=/m);
  }
});

test('documentation keeps the app read/write scope isolated', async () => {
  const rules = await readFile(new URL('AGENTS.md', root), 'utf8');
  assert.match(rules, /read_discounts/);
  assert.match(rules, /write_discounts/);
  assert.match(rules, /read_products/);
  assert.match(rules, /Never reuse another Shopify App token/);
});
