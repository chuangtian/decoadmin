import assert from 'node:assert/strict';
import test from 'node:test';
import {configurationEndpoint, normalizeConfiguration} from './runtime.mjs';

test('trust configuration endpoint accepts only the signed backend path over HTTPS', () => {
  const entries = [{metafield: {
    namespace: 'deco_personalization',
    key: 'checkout_configuration_url',
    value: 'https://test.example/api/shopify-app/personalization/checkout/configuration',
  }}];
  assert.equal(configurationEndpoint(entries), 'https://test.example/api/shopify-app/personalization/checkout/configuration');
  assert.equal(configurationEndpoint([{metafield: {...entries[0].metafield, value: 'http://test.example/api/shopify-app/personalization/checkout/configuration'}}]), '');
  assert.equal(configurationEndpoint([{metafield: {...entries[0].metafield, value: 'https://test.example/other'}}]), '');
});

test('trust configuration remains off and bounded without valid enabled items', () => {
  assert.equal(normalizeConfiguration({enabled: false, trust_items: [{title: 'Hidden'}]}), null);
  const value = normalizeConfiguration({enabled: true, trust_items: Array.from({length: 8}, (_, index) => ({
    key: `trust_${index}`,
    icon: 'star',
    title: index === 1 ? '' : `Trust ${index}`,
    position: 8 - index,
  }))});
  assert.equal(value.trust_items.length, 5);
  assert.equal(value.trust_items[0].title, 'Trust 5');
});
