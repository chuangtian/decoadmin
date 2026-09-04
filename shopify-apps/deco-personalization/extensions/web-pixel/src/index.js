import {register} from '@shopify/web-pixels-extension';

const IMPRESSION = 'deco_personalization:impression';
const CLICK = 'deco_personalization:click';
const ADD_TO_CART = 'deco_personalization:add_to_cart';
const ADD_SUCCESS = 'deco_personalization:add_success';
const ADD_FAILED = 'deco_personalization:add_failed';
const CHECKOUT_RECOMMENDATION_IMPRESSION = 'deco_personalization:checkout_recommendation_impression';
const CHECKOUT_RECOMMENDATION_CLICK = 'deco_personalization:checkout_recommendation_click';
const CHECKOUT_RECOMMENDATION_ADD_SUCCESS = 'deco_personalization:checkout_recommendation_add_success';
const CHECKOUT_RECOMMENDATION_ADD_FAILED = 'deco_personalization:checkout_recommendation_add_failed';
const CHECKOUT_RECOMMENDATION_SEQUENCE_COMPLETED = 'deco_personalization:checkout_recommendation_sequence_completed';
const PRODUCT_VIEWED = 'product_viewed';
const CHECKOUT_COMPLETED = 'checkout_completed';

register(async ({analytics, browser, settings}) => {
  const endpoint = safeEndpoint(settings?.endpoint);
  if (!endpoint) return;
  const sessionId = await session(browser);

  [
    IMPRESSION,
    CLICK,
    ADD_TO_CART,
    ADD_SUCCESS,
    ADD_FAILED,
    CHECKOUT_RECOMMENDATION_IMPRESSION,
    CHECKOUT_RECOMMENDATION_CLICK,
    CHECKOUT_RECOMMENDATION_ADD_SUCCESS,
    CHECKOUT_RECOMMENDATION_ADD_FAILED,
    CHECKOUT_RECOMMENDATION_SEQUENCE_COMPLETED,
  ].forEach((eventName) => {
    analytics.subscribe(eventName, (event) => {
      const customData = event?.customData;
      if (!customData || typeof customData !== 'object' || Array.isArray(customData)) return;
      void send(endpoint, {
        event_id: string(event?.id, 191),
        event_name: eventName,
        client_id: string(event?.clientId, 512),
        session_id: sessionId,
        occurred_at: string(event?.timestamp, 64),
        component_uuid: string(customData.component_uuid, 64),
        strategy_uuid: string(customData.strategy_uuid, 64),
        strategy_version_uuid: string(customData.strategy_version_uuid, 64),
        placement: string(customData.placement, 24),
        products: products(customData.products),
      });
    });
  });

  analytics.subscribe(PRODUCT_VIEWED, (event) => {
    const variant = event?.data?.productVariant;
    const productId = shopifyId(variant?.product?.id, 'Product');
    if (!productId) return;
    void send(endpoint, {
      event_id: string(event?.id, 191),
      event_name: PRODUCT_VIEWED,
      client_id: string(event?.clientId, 512),
      session_id: sessionId,
      occurred_at: string(event?.timestamp, 64),
      products: [{
        product_id: productId,
        variant_id: shopifyId(variant?.id, 'ProductVariant'),
        rank: 1,
        rule_id: '',
      }],
    });
  });

  analytics.subscribe(CHECKOUT_COMPLETED, (event) => {
    void send(endpoint, {
      event_id: string(event?.id, 191),
      event_name: CHECKOUT_COMPLETED,
      client_id: string(event?.clientId, 512),
      session_id: sessionId,
      occurred_at: string(event?.timestamp, 64),
      shopify_order_id: string(event?.data?.checkout?.order?.id, 64),
    });
  });
});

async function session(browser) {
  const key = 'deco_personalization_session_v1';
  const existing = string(await browser.sessionStorage.getItem(key), 512);
  if (existing) return existing;
  const created = `${Date.now()}-${Math.random().toString(36).slice(2, 22)}`;
  await browser.sessionStorage.setItem(key, created);
  return created;
}

async function send(endpoint, payload) {
  if (!payload.event_id || !payload.client_id || !payload.session_id || !payload.occurred_at) return;
  try {
    await fetch(endpoint, {
      method: 'POST',
      headers: {'Content-Type': 'text/plain;charset=UTF-8'},
      keepalive: true,
      body: JSON.stringify(payload),
    });
  } catch {
    // Analytics must never interrupt the storefront or checkout journey.
  }
}

function products(value) {
  return Array.isArray(value)
    ? value.slice(0, 50).map((item, index) => ({
      product_id: numericId(item?.product_id),
      variant_id: numericId(item?.variant_id),
      rank: boundedRank(item?.rank, index + 1),
      rule_id: uuid(item?.rule_id),
    })).filter((item) => item.product_id)
    : [];
}

function numericId(value) {
  const normalized = string(value, 64);
  return /^\d+$/.test(normalized) ? normalized : '';
}

function shopifyId(value, resource) {
  const normalized = string(value, 128);
  if (/^\d+$/.test(normalized)) return normalized;
  const match = normalized.match(new RegExp(`^gid://shopify/${resource}/(\\d+)$`));
  return match?.[1] ?? '';
}

function boundedRank(value, fallback) {
  const rank = Number(value);
  return Number.isInteger(rank) && rank >= 1 && rank <= 100 ? rank : fallback;
}

function uuid(value) {
  const normalized = string(value, 64);
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(normalized) ? normalized : '';
}

function string(value, maximum) {
  return typeof value === 'string' ? value.trim().slice(0, maximum) : '';
}

function safeEndpoint(value) {
  try {
    const endpoint = new URL(String(value || ''));
    if (endpoint.protocol !== 'https:'
      || endpoint.username || endpoint.password || endpoint.hash
      || !/^\/api\/shopify-app\/personalization\/events\/[0-9a-f-]{36}$/i.test(endpoint.pathname)) {
      return '';
    }
    return endpoint.toString();
  } catch {
    return '';
  }
}
