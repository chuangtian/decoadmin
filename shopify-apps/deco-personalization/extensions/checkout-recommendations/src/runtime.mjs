const CONFIGURATION_PATH = '/api/shopify-app/personalization/checkout/configuration';

export const EVENTS = {
  impression: 'deco_personalization:checkout_recommendation_impression',
  click: 'deco_personalization:checkout_recommendation_click',
  addSuccess: 'deco_personalization:checkout_recommendation_add_success',
  addFailed: 'deco_personalization:checkout_recommendation_add_failed',
  sequenceCompleted: 'deco_personalization:checkout_recommendation_sequence_completed',
};

export function configurationEndpoint(entries) {
  if (!Array.isArray(entries)) return '';
  const entry = entries.find((candidate) => {
    const metafield = candidate?.metafield ?? candidate;
    return metafield?.namespace === '$app:deco_personalization'
      && metafield?.key === 'checkout_configuration_url';
  });
  const metafield = entry?.metafield ?? entry;
  try {
    const endpoint = new URL(String(metafield?.value ?? ''));
    if (endpoint.protocol !== 'https:'
      || endpoint.username || endpoint.password || endpoint.hash
      || endpoint.pathname !== CONFIGURATION_PATH
      || endpoint.search) return '';
    return endpoint.toString();
  } catch {
    return '';
  }
}

export async function fetchConfiguration(api, entries = api?.appMetafields?.value) {
  const endpoint = configurationEndpoint(entries);
  if (!endpoint) return null;
  const token = await api.sessionToken.get();
  const response = await fetch(endpoint, {
    method: 'GET',
    headers: {Authorization: `Bearer ${token}`, Accept: 'application/json'},
  });
  if (!response.ok) return null;
  const payload = await response.json();
  return normalizeConfiguration(payload?.data);
}

export function normalizeConfiguration(value) {
  const component = value?.component;
  const maximum = Number(value?.sequence?.maximum);
  const scanLimit = Number(value?.sequence?.scan_limit);
  if (!value || value.enabled !== true
    || !component || !uuid(component.uuid) || !uuid(component.strategy_uuid)
    || component.placement !== 'checkout'
    || !gid(value?.collection?.id, 'Collection')
    || !Number.isInteger(maximum) || maximum < 1 || maximum > 20
    || !Number.isInteger(scanLimit) || scanLimit < maximum || scanLimit > 250
    || value?.sequence?.order !== 'collection_default'
    || value?.sequence?.variant_fallback !== 'first_available') return null;
  return {
    component: {
      uuid: component.uuid,
      strategy_uuid: component.strategy_uuid,
      placement: 'checkout',
      heading: string(component.heading, 120) || 'Great Value Bundles for You',
      button_label: string(component.button_label, 60) || 'Add',
    },
    collection_id: value.collection.id,
    maximum_recommendations: maximum,
    scan_limit: scanLimit,
  };
}

export function normalizeCollectionProducts(collection) {
  if (!collection || collection.__typename !== 'Collection' || !Array.isArray(collection.products?.nodes)) return [];
  return collection.products.nodes.map((product, index) => {
    const variants = Array.isArray(product?.variants?.nodes) ? product.variants.nodes : [];
    const variant = variants.find((candidate) => candidate?.availableForSale === true);
    if (!gid(product?.id, 'Product') || !variant || !gid(variant.id, 'ProductVariant')) return null;
    return {
      product_id: product.id,
      variant_id: variant.id,
      rank: index + 1,
      title: string(product.title, 200),
      variant_title: string(variant.title, 200),
      available: true,
      image_url: safeImage(variant.image?.url || product.featuredImage?.url),
      image_alt: string(variant.image?.altText || product.featuredImage?.altText, 200) || string(product.title, 200),
      amount: money(variant.price?.amount),
      currency: /^[A-Z]{3}$/.test(String(variant.price?.currencyCode ?? '')) ? variant.price.currencyCode : '',
    };
  }).filter(Boolean);
}

export function selectNextCandidate(candidates, lines, dismissed = new Set()) {
  const cartVariants = new Set();
  const cartProducts = new Set();
  for (const line of Array.isArray(lines) ? lines : []) {
    if (line?.merchandise?.id) cartVariants.add(line.merchandise.id);
    if (line?.merchandise?.product?.id) cartProducts.add(line.merchandise.product.id);
  }
  return candidates.find((candidate) => candidate.available
    && !dismissed.has(candidate.variant_id)
    && !cartVariants.has(candidate.variant_id)
    && !cartProducts.has(candidate.product_id)) ?? null;
}

export function eventPayload(configuration, products) {
  return {
    component_uuid: configuration.component.uuid,
    strategy_uuid: configuration.component.strategy_uuid,
    placement: 'checkout',
    products: products.slice(0, configuration.maximum_recommendations).map((product) => ({
      product_id: numericId(product.product_id, 'Product'),
      variant_id: numericId(product.variant_id, 'ProductVariant'),
      rank: product.rank,
    })),
  };
}

function gid(value, resource) {
  const normalized = String(value ?? '').trim();
  return new RegExp(`^gid://shopify/${resource}/\\d+$`).test(normalized) ? normalized : '';
}

function numericId(value, resource) {
  return gid(value, resource).split('/').pop() ?? '';
}

function uuid(value) {
  return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

function string(value, maximum) {
  return typeof value === 'string' ? value.trim().slice(0, maximum) : '';
}

function money(value) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized >= 0 ? normalized.toFixed(2) : '';
}

function safeImage(value) {
  try {
    const url = new URL(String(value ?? ''));
    return url.protocol === 'https:' ? url.toString() : '';
  } catch {
    return '';
  }
}
