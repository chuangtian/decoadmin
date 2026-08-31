const CONFIGURATION_PATH = '/api/shopify-app/personalization/checkout/configuration';
const RECOMMENDATIONS_PATH = '/api/shopify-app/personalization/checkout/recommendations';
const MAX_EVENT_PRODUCTS = 50;

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

export async function fetchRecommendations(api, configuration, lines, context = {}) {
  const endpoint = safeEndpoint(configuration?.recommendations_url, RECOMMENDATIONS_PATH);
  if (!endpoint) return [];
  const token = await api.sessionToken.get();
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json'},
    body: JSON.stringify({
      cart_lines: cartLines(lines),
      cart_subtotal_amount: boundedNumber(context.cartSubtotal, 0, 1000000, null),
      market: string(context.market, 80),
      currency: string(context.currency, 3),
      language: string(context.language, 20),
    }),
  });
  if (!response.ok) return [];
  const payload = await response.json();
  return normalizeServiceRecommendations(payload?.data);
}

export function normalizeConfiguration(value) {
  const component = value?.component;
  if (!value || value.enabled !== true
    || !component || !uuid(component.uuid) || !uuid(component.strategy_uuid)
    || component.placement !== 'checkout'
    || !safeEndpoint(value?.recommendations_url, RECOMMENDATIONS_PATH)) return null;
  return {
    component: {
      uuid: component.uuid,
      strategy_uuid: component.strategy_uuid,
      placement: 'checkout',
      heading: string(component.heading, 120) || 'Great Value Bundles for You',
      button_label: string(component.button_label, 60) || 'Add',
    },
    strategy_version_uuid: uuid(value?.strategy?.version_uuid) ? value.strategy.version_uuid : '',
    recommendations_url: safeEndpoint(value.recommendations_url, RECOMMENDATIONS_PATH),
  };
}

export function normalizeServiceRecommendations(value) {
  if (!value || value.enabled !== true || !Array.isArray(value.items)) return [];
  const strategyVersionUuid = uuid(value?.strategy?.version_uuid) ? value.strategy.version_uuid : '';
  return value.items.slice(0, 24).map((product, index) => {
    const productId = gid(product?.product_gid || `gid://shopify/Product/${numericId(product?.shopify_product_id, 'Product')}`, 'Product');
    const variants = Array.isArray(product?.variants) ? product.variants : [];
    const selectedGid = gid(product?.selected_variant_gid, 'ProductVariant');
    const selectedId = numericId(selectedGid, 'ProductVariant');
    const variant = variants.find((candidate) => String(candidate?.shopify_variant_id ?? '') === selectedId && candidate?.available_for_sale === true)
      ?? variants.find((candidate) => candidate?.available_for_sale === true);
    if (!productId || !variant) return null;
    const variantId = gid(`gid://shopify/ProductVariant/${numericId(variant.shopify_variant_id, 'ProductVariant')}`, 'ProductVariant');
    if (!variantId) return null;
    return {
      product_id: productId,
      variant_id: variantId,
      rank: Number.isInteger(Number(product?.rank)) ? Number(product.rank) : index + 1,
      title: string(product?.title, 200),
      variant_title: string(variant?.title, 200),
      available: true,
      image_url: safeImage(variant?.image?.url || product?.storefront?.image?.url),
      image_alt: string(variant?.image?.alt || product?.storefront?.image?.alt, 200) || string(product?.title, 200),
      amount: money(product?.pricing?.original_amount ?? variant?.price),
      discounted_amount: money(product?.pricing?.discounted_amount),
      currency: /^[A-Z]{3}$/.test(String(product?.pricing?.currency ?? product?.price?.currency ?? ''))
        ? String(product?.pricing?.currency ?? product?.price?.currency)
        : '',
      minimum_purchase_quantity: boundedInteger(product?.minimum_purchase_quantity, 1, 999, 1),
      reason_code: string(product?.reason_code, 64),
      rule_id: uuid(product?.rule_id) ? product.rule_id : '',
      strategy_version_uuid: strategyVersionUuid,
      discount: value?.discount && typeof value.discount === 'object' ? {
        title: string(value.discount.title, 80),
        summary: string(value.discount.summary, 160),
        code: string(value.discount.code, 80),
        percentage: boundedNumber(value.discount.percentage, 0.0001, 99.9999, null),
      } : null,
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
    && (candidate.reason_code === 'same_product_upsell' || !cartProducts.has(candidate.product_id))) ?? null;
}

export function eventPayload(configuration, products) {
  return {
    component_uuid: configuration.component.uuid,
    strategy_uuid: configuration.component.strategy_uuid,
    strategy_version_uuid: configuration.strategy_version_uuid,
    placement: 'checkout',
    products: products.slice(0, MAX_EVENT_PRODUCTS).map((product) => ({
      product_id: numericId(product.product_id, 'Product'),
      variant_id: numericId(product.variant_id, 'ProductVariant'),
      rank: product.rank,
      rule_id: uuid(product.rule_id) ? product.rule_id : '',
    })),
  };
}

export function formatMoney(amount, currency, locale = '') {
  const normalizedAmount = Number(amount);
  const normalizedCurrency = String(currency ?? '').trim().toUpperCase();
  if (!Number.isFinite(normalizedAmount) || normalizedAmount < 0 || !/^[A-Z]{3}$/.test(normalizedCurrency)) return '';
  try {
    return new Intl.NumberFormat(locale || undefined, {
      style: 'currency',
      currency: normalizedCurrency,
      currencyDisplay: 'narrowSymbol',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(normalizedAmount);
  } catch {
    return `${normalizedCurrency} ${normalizedAmount.toFixed(2)}`;
  }
}

function gid(value, resource) {
  const normalized = String(value ?? '').trim();
  return new RegExp(`^gid://shopify/${resource}/\\d+$`).test(normalized) ? normalized : '';
}

function numericId(value, resource) {
  const normalized = String(value ?? '').trim();
  if (/^\d+$/.test(normalized)) return normalized;
  return gid(normalized, resource).split('/').pop() ?? '';
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

function boundedNumber(value, minimum, maximum, fallback) {
  const normalized = Number(value);
  return Number.isFinite(normalized) && normalized >= minimum && normalized <= maximum ? normalized : fallback;
}

function safeImage(value) {
  try {
    const url = new URL(String(value ?? ''));
    return url.protocol === 'https:' ? url.toString() : '';
  } catch {
    return '';
  }
}

function safeEndpoint(value, path) {
  try {
    const endpoint = new URL(String(value ?? ''));
    if (endpoint.protocol !== 'https:' || endpoint.username || endpoint.password || endpoint.hash || endpoint.search || endpoint.pathname !== path) return '';
    return endpoint.toString();
  } catch {
    return '';
  }
}

function cartLines(lines) {
  if (!Array.isArray(lines)) return [];
  return lines.slice(0, 50).map((line) => {
    const merchandise = line?.merchandise;
    const productId = numericId(merchandise?.product?.id, 'Product');
    const variantId = numericId(merchandise?.id, 'ProductVariant');
    if (!productId || !variantId) return null;
    return {product_id: productId, variant_id: variantId, quantity: boundedInteger(line?.quantity, 1, 999, 1)};
  }).filter(Boolean);
}

function boundedInteger(value, minimum, maximum, fallback) {
  const normalized = Number(value);
  return Number.isInteger(normalized) && normalized >= minimum && normalized <= maximum ? normalized : fallback;
}
