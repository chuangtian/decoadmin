import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
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
    mode: 'sequential',
    order: 'collection_default',
    variant_fallback: 'first_available',
    page_size: 25,
    exhaustion: 'collection',
  },
});

test('configuration uses a Collection and supports an optional merchant maximum without fixed slots', () => {
  assert.equal(configuration.collection_id, 'gid://shopify/Collection/99');
  assert.equal(configuration.page_size, 25);
  assert.equal(configuration.maximum_recommendations, null);
  const bounded = normalizeConfiguration({
    enabled: true,
    component: configuration.component,
    collection: {id: 'gid://shopify/Collection/99'},
    sequence: {mode: 'sequential', order: 'collection_default', variant_fallback: 'first_available', page_size: 25, maximum_recommendations: 7, exhaustion: 'collection'},
  });
  assert.equal(bounded.maximum_recommendations, 7);
  assert.equal(normalizeConfiguration({enabled: false}), null);
  assert.equal(normalizeConfiguration({
    enabled: true,
    component: configuration.component,
    collection: {id: 'gid://shopify/Collection/99'},
    sequence: {mode: 'sequential', order: 'collection_default', variant_fallback: 'first_available', page_size: 251, exhaustion: 'collection'},
  }), null);
  assert.equal(normalizeConfiguration({
    enabled: true,
    component: configuration.component,
    collection: {id: 'gid://shopify/Collection/99'},
    sequence: {mode: 'sequential', order: 'collection_default', variant_fallback: 'first_available', page_size: 25, maximum_recommendations: 1001, exhaustion: 'collection'},
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
  const variants = normalizeCollectionProducts(collection, 250);
  const lines = [{merchandise: {id: 'gid://shopify/ProductVariant/999', product: {id: 'gid://shopify/Product/2'}}}];
  assert.equal(selectNextCandidate(variants, lines)?.variant_id, 'gid://shopify/ProductVariant/31');
  assert.equal(selectNextCandidate(variants, lines)?.rank, 253);
  assert.equal(selectNextCandidate(variants, lines, new Set(['gid://shopify/ProductVariant/31'])), null);
});

test('sequential selection continues beyond three products until every eligible candidate is exhausted', () => {
  const candidates = Array.from({length: 6}, (_, index) => ({
    product_id: `gid://shopify/Product/${index + 1}`,
    variant_id: `gid://shopify/ProductVariant/${index + 101}`,
    available: true,
  }));
  const dismissed = new Set();
  const offered = [];
  while (true) {
    const candidate = selectNextCandidate(candidates, [], dismissed);
    if (!candidate) break;
    offered.push(candidate.variant_id);
    dismissed.add(candidate.variant_id);
  }
  assert.equal(offered.length, 6);
  assert.equal(selectNextCandidate(candidates, [], dismissed), null);
});

test('candidate row remounts and fetches another Collection page when the sequence advances', async () => {
  const source = await readFile(new URL('./Recommendations.jsx', import.meta.url), 'utf8');
  assert.match(source, /<s-grid key=\{current\.variant_id\}/);
  assert.match(source, /products\(first: \$productsFirst, after: \$after\)/);
  assert.match(source, /pageInfo \{ hasNextPage endCursor \}/);
  assert.match(source, /!current && !maximumReached && nextCursor/);
  assert.match(source, /dismissed\.size >= configuration\.maximum_recommendations/);
});

test('analytics payload is anonymous and uses the five checkout event names', () => {
  assert.deepEqual(Object.keys(EVENTS), ['impression', 'click', 'addSuccess', 'addFailed', 'sequenceCompleted']);
  const products = Array.from({length: 60}, (_, index) => ({
    product_id: `gid://shopify/Product/${index + 1}`,
    variant_id: `gid://shopify/ProductVariant/${index + 10}`,
    rank: index + 1,
  }));
  const payload = eventPayload(configuration, products);
  assert.equal(payload.products.length, 50);
  assert.deepEqual(payload.products.slice(0, 3).map((product) => product.product_id), ['1', '2', '3']);
  assert.equal(JSON.stringify(payload).includes('email'), false);
});

test('configuration endpoint refuses non-HTTPS and arbitrary paths', () => {
  const entry = (value) => [{metafield: {namespace: '$app:deco_personalization', key: 'checkout_configuration_url', value}}];
  assert.equal(configurationEndpoint(entry('https://test.example/api/shopify-app/personalization/checkout/configuration')), 'https://test.example/api/shopify-app/personalization/checkout/configuration');
  assert.equal(configurationEndpoint(entry('http://test.example/api/shopify-app/personalization/checkout/configuration')), '');
  assert.equal(configurationEndpoint(entry('https://test.example/admin')), '');
});
