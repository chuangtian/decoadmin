import assert from 'node:assert/strict';
import test from 'node:test';
import {
  EVENTS,
  configurationEndpoint,
  eventPayload,
  normalizeCollectionProducts,
  normalizeConfiguration,
  selectNextCandidate,
} from './runtime.mjs';

const configuration = normalizeConfiguration({
  enabled: true,
  component: {
    uuid: 'cae0d8cc-12c4-4b85-b8ce-6aa6d372af5b',
    strategy_uuid: 'aeed57d4-2ace-41d4-8a41-2b8f23687808',
    placement: 'checkout',
    heading: 'Bundles',
    button_label: 'Add',
  },
  collection: {id: 'gid://shopify/Collection/99'},
  sequence: {
    maximum: 5,
    mode: 'sequential',
    order: 'collection_default',
    variant_fallback: 'first_available',
    scan_limit: 25,
  },
});

test('configuration uses a Collection with a merchant-set maximum instead of fixed slots', () => {
  assert.equal(configuration.collection_id, 'gid://shopify/Collection/99');
  assert.equal(configuration.maximum_recommendations, 5);
  assert.equal(normalizeConfiguration({enabled: false}), null);
  assert.equal(normalizeConfiguration({
    enabled: true,
    component: configuration.component,
    collection: {id: 'gid://shopify/Collection/99'},
    sequence: {...configuration, maximum: 21},
  }), null);
});

test('collection order chooses the first market-available variant and skips cart products without looping', () => {
  const collection = {
    __typename: 'Collection',
    products: {nodes: [1, 2, 3].map((id) => ({
      id: `gid://shopify/Product/${id}`,
      title: `Product ${id}`,
      featuredImage: null,
      variants: {nodes: [
        {id: `gid://shopify/ProductVariant/${id * 10}`, title: 'Unavailable', availableForSale: false, price: {amount: '9.00', currencyCode: 'USD'}},
        {id: `gid://shopify/ProductVariant/${id * 10 + 1}`, title: 'Available', availableForSale: id !== 1, price: {amount: '10.00', currencyCode: 'USD'}},
      ]},
    }))},
  };
  const variants = normalizeCollectionProducts(collection);
  const lines = [{merchandise: {id: 'gid://shopify/ProductVariant/999', product: {id: 'gid://shopify/Product/2'}}}];
  assert.equal(selectNextCandidate(variants, lines)?.variant_id, 'gid://shopify/ProductVariant/31');
  assert.equal(selectNextCandidate(variants, lines, new Set(['gid://shopify/ProductVariant/31'])), null);
});

test('analytics payload is anonymous and uses the five checkout event names', () => {
  assert.deepEqual(Object.keys(EVENTS), ['impression', 'click', 'addSuccess', 'addFailed', 'sequenceCompleted']);
  const products = Array.from({length: 7}, (_, index) => ({
    product_id: `gid://shopify/Product/${index + 1}`,
    variant_id: `gid://shopify/ProductVariant/${index + 10}`,
    rank: index + 1,
  }));
  const payload = eventPayload(configuration, products);
  assert.deepEqual(payload.products.map((product) => product.product_id), ['1', '2', '3', '4', '5']);
  assert.equal(JSON.stringify(payload).includes('email'), false);
});

test('configuration endpoint refuses non-HTTPS and arbitrary paths', () => {
  const entry = (value) => [{metafield: {namespace: 'deco_personalization', key: 'checkout_configuration_url', value}}];
  assert.equal(configurationEndpoint(entry('https://test.example/api/shopify-app/personalization/checkout/configuration')), 'https://test.example/api/shopify-app/personalization/checkout/configuration');
  assert.equal(configurationEndpoint(entry('http://test.example/api/shopify-app/personalization/checkout/configuration')), '');
  assert.equal(configurationEndpoint(entry('https://test.example/admin')), '');
});
