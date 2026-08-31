import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import {
  EVENTS,
  configurationEndpoint,
  eventPayload,
  formatMoney,
  normalizeConfiguration,
  normalizeServiceRecommendations,
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
  recommendations_url: 'https://test.example/api/shopify-app/personalization/checkout/recommendations',
});

test('configuration uses one backend strategy binding without merchant collection or limit controls', () => {
  assert.equal(configuration.component.strategy_uuid, 'aeed57d4-2ace-41d4-8a41-2b8f23687808');
  assert.equal('collection_id' in configuration, false);
  assert.equal('maximum_recommendations' in configuration, false);
  assert.equal(normalizeConfiguration({enabled: false}), null);
  assert.equal(normalizeConfiguration({
    enabled: true,
    component: configuration.component,
    recommendations_url: 'https://test.example/admin',
  }), null);
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

test('cached candidates advance immediately when the Shopify cart line updates', () => {
  const candidates = [{
    product_id: 'gid://shopify/Product/1',
    variant_id: 'gid://shopify/ProductVariant/101',
    available: true,
  }, {
    product_id: 'gid://shopify/Product/2',
    variant_id: 'gid://shopify/ProductVariant/102',
    available: true,
  }];
  const lines = [{
    quantity: 1,
    merchandise: {
      id: candidates[0].variant_id,
      product: {id: candidates[0].product_id},
    },
  }];

  assert.equal(selectNextCandidate(candidates, lines)?.variant_id, candidates[1].variant_id);
});

test('the final candidate is no longer rendered after its cart line appears', () => {
  const candidate = {
    product_id: 'gid://shopify/Product/1',
    variant_id: 'gid://shopify/ProductVariant/101',
    available: true,
  };
  const lines = [{
    quantity: 1,
    merchandise: {
      id: candidate.variant_id,
      product: {id: candidate.product_id},
    },
  }];

  assert.equal(selectNextCandidate([candidate], lines), null);
});

test('checkout prices are localized and invalid money never renders as a discount price', () => {
  assert.equal(formatMoney('1699', 'USD', 'zh-CN'), '$1,699.00');
  assert.equal(formatMoney('1599', 'USD', 'en-US'), '$1,599.00');
  assert.equal(formatMoney('invalid', 'USD', 'en-US'), '');
  assert.equal(formatMoney('1599', 'not-a-currency', 'en-US'), '');
});

test('candidate row advances without unmounting during background revalidation', async () => {
  const source = await readFile(new URL('./Recommendations.jsx', import.meta.url), 'utf8');
  assert.match(source, /const \[advancing, setAdvancing\] = useState\(false\)/);
  assert.match(source, /const \[lastCandidate, setLastCandidate\] = useState\(null\)/);
  assert.match(source, /setLastCandidate\(selectNextCandidate\(items, lines, dismissed\)\)/);
  assert.match(source, /selectNextCandidate\(\[lastCandidate\], lines, dismissed\)/);
  assert.match(source, /const displayedCandidate = current \?\? retainedCandidate/);
  assert.match(source, /setBusy\(true\);\s*setAdvancing\(true\);/);
  assert.match(source, /<s-grid key=\{displayedCandidate\.variant_id\}/);
  assert.match(source, /gridTemplateColumns="96px 1fr auto"/);
  assert.match(source, /const \[refreshing, setRefreshing\] = useState\(false\)/);
  assert.match(source, /const configurationUrl = configurationEndpoint\(configurationMetafields\)/);
  assert.match(source, /}, \[configurationUrl\]\);/);
  assert.doesNotMatch(source, /}, \[configurationMetafields\]\);/);
  assert.match(source, /setRefreshing\(true\);/);
  assert.match(source, /if \(!configurationLoaded \|\| !recommendationsLoaded \|\| !configuration \|\| !displayedCandidate\) return null/);
  assert.match(source, /loading=\{busy \|\| advancing \|\| \(refreshing && !current\)\}/);
  assert.match(source, /inlineSize="fit-content"/);
  assert.match(source, /<s-box paddingInline="large-200">/);
  assert.match(source, /<s-stack alignItems="center">/);
  assert.match(source, /<s-stack gap="small-200" minInlineSize="0">/);
  assert.match(source, /<s-stack direction="inline" gap="small-200" alignItems="center">/);
  assert.match(source, /<s-text type="strong">\{formatMoney\(displayedCandidate\.discounted_amount/);
  assert.match(source, /<s-text type="redundant">\{formatMoney\(displayedCandidate\.amount/);
  assert.match(source, /Number\(displayedCandidate\.discounted_amount\) < Number\(displayedCandidate\.amount\)/);
  assert.match(source, /fetchRecommendations\(shopify/);
  assert.match(source, /quantity: current\.minimum_purchase_quantity/);
  assert.match(source, /useCartLines\(\)/);
  assert.match(source, /useInstructions\(\)/);
  assert.match(source, /useDiscountCodes\(\)/);
  assert.match(source, /useApplyCartLinesChange\(\)/);
  assert.match(source, /useApplyDiscountCodeChange\(\)/);
  assert.doesNotMatch(source, /setRecommendationsLoaded\(false\);\s*refreshRecommendations/);
  assert.doesNotMatch(source, /shopify\.lines\.value/);
});

test('shared strategy results preserve order, minimum quantity, rule and version traceability', () => {
  const ruleId = 'bb4e3f45-cd54-4f83-8a12-00492ea7d4e1';
  const versionId = '95d37e9d-0de2-43df-b50c-d9664d2d77bc';
  const rows = normalizeServiceRecommendations({
    enabled: true,
    strategy: {version_uuid: versionId},
    items: [{
      shopify_product_id: '91',
      selected_variant_gid: 'gid://shopify/ProductVariant/911',
      title: 'Mirror',
      rank: 1,
      minimum_purchase_quantity: 3,
      rule_id: ruleId,
      price: {currency: 'USD'},
      pricing: {currency: 'USD', original_amount: '19.00', discount_percentage: 10, discounted_amount: '17.10'},
      storefront: {image: {url: 'https://cdn.example/mirror.jpg', alt: 'Mirror'}},
      variants: [{shopify_variant_id: '911', title: 'Default', available_for_sale: true, price: '19.00', image: {url: null, alt: null}}],
    }],
    discount: {title: 'Ten off', summary: '10% off', code: 'DECO10', percentage: 10},
  });
  assert.equal(rows.length, 1);
  assert.equal(rows[0].minimum_purchase_quantity, 3);
  assert.equal(rows[0].rule_id, ruleId);
  assert.equal(rows[0].strategy_version_uuid, versionId);
  assert.equal(rows[0].amount, '19.00');
  assert.equal(rows[0].discounted_amount, '17.10');
  assert.equal(rows[0].discount.percentage, 10);
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
