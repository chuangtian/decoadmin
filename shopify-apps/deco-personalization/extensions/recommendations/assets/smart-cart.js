(() => {
  const root = document.querySelector('[data-deco-smart-cart-root]');
  const PROXY_PATTERN = /^\/(?:a|apps|community|tools)\/[A-Za-z0-9_-]{1,30}$/;
  const RECENT_KEY = 'deco_personalization_recent_products_v1';
  const HOST_SELECTOR = '[data-deco-native-cart-recommendation]';
  const DRAWER_SELECTORS = '#t4s-mini_cart, #CartDrawer, cart-drawer, [data-cart-drawer], .cart-drawer, .mini-cart';
  let proxyPath = '';
  let timer = 0;
  let syncing = false;
  let rerun = false;
  let impressionKey = '';

  Object.defineProperty(window, 'DecoPersonalizationSmartCart', {
    configurable: true,
    value: Object.freeze({
      compatibility: () => ({
        mode: 'native_cart_embed',
        nativeCartFound: Boolean(document.querySelector(DRAWER_SELECTORS)),
        placementFound: Boolean(nativePlacement()),
      }),
    }),
  });

  if (!(root instanceof HTMLElement)) return;
  proxyPath = normalizeProxyPath(root.dataset.proxyPath);
  if (!proxyPath) return;

  const observer = new MutationObserver((mutations) => {
    if (mutations.every(isOwnMutation)) return;
    queueSync(120);
  });
  observer.observe(document.documentElement, {childList: true, subtree: true});
  document.addEventListener('click', handleNativeCartChange, true);
  document.addEventListener('change', handleNativeCartChange, true);
  document.addEventListener('deco-personalization:cart-updated', () => queueSync(80));
  queueSync(0);

  function handleNativeCartChange(event) {
    if (!(event.target instanceof Element)) return;
    const drawer = event.target.closest(DRAWER_SELECTORS);
    if (!drawer || event.target.closest(HOST_SELECTOR)) return;
    if (event.type === 'change' || event.target.closest('[data-cart-remove], [data-quantity-selector], [data-action-change]')) queueSync(650);
  }

  function queueSync(delay = 100) {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => void sync(), delay);
  }

  async function sync() {
    if (syncing) { rerun = true; return; }
    const host = ensureHost();
    if (!(host instanceof HTMLElement)) return;
    syncing = true;
    try {
      const cart = await requestShopifyJson(cartRoute('cart.js'));
      const config = await fetchConfig(cart);
      render(host, cart, config, true);
    } catch {
      host.replaceChildren();
      host.hidden = true;
    } finally {
      syncing = false;
      if (rerun) { rerun = false; queueSync(80); }
    }
  }

  function nativePlacement() {
    const drawer = document.querySelector(DRAWER_SELECTORS);
    if (!(drawer instanceof HTMLElement)) return null;
    const exact = drawer.querySelector('[data-personalization-id="00007"]');
    if (exact instanceof HTMLElement) return {drawer, anchor: exact, mode: 'after'};
    const items = drawer.querySelector('[data-cart-items], .cart-drawer__items, [data-mini-cart-items], .mini-cart__items');
    if (items instanceof HTMLElement) return {drawer, anchor: items, mode: 'append'};
    const footer = drawer.querySelector('.t4s-drawer__bottom, .drawer__footer, [data-cart-footer]');
    if (footer instanceof HTMLElement && footer.parentElement) return {drawer, anchor: footer, mode: 'before'};
    return null;
  }

  function ensureHost() {
    const placement = nativePlacement();
    if (!placement) return null;
    const existing = placement.drawer.querySelector(HOST_SELECTOR);
    if (existing instanceof HTMLElement) return existing;
    const host = document.createElement('section');
    host.dataset.decoNativeCartRecommendation = '';
    host.className = 'deco-native-cart-recommendation';
    host.hidden = true;
    host.setAttribute('aria-live', 'polite');
    if (placement.mode === 'after') placement.anchor.after(host);
    else if (placement.mode === 'before') placement.anchor.before(host);
    else placement.anchor.append(host);
    return host;
  }

  function render(host, cart, config, publishImpression) {
    host.replaceChildren();
    const recommendation = config?.enabled === true ? nextRecommendation(config?.recommendations?.items, cart) : null;
    if (!recommendation) {
      host.hidden = true;
      impressionKey = '';
      return;
    }
    host.hidden = false;
    const heading = document.createElement('h3');
    heading.className = 'deco-native-cart-recommendation__heading';
    heading.textContent = String(config.heading || 'You may also like');
    const status = document.createElement('p');
    status.className = 'deco-native-cart-recommendation__status';
    status.hidden = true;
    const card = createRecommendation(recommendation.product, recommendation.variant, config, status);
    host.append(heading, status, card);
    const tracked = eventProduct(recommendation.product, recommendation.variant);
    const nextImpressionKey = `${config?.recommendations?.strategy?.uuid || ''}:${tracked.selected_variant_id}`;
    if (publishImpression && nextImpressionKey !== impressionKey) {
      impressionKey = nextImpressionKey;
      publishRecommendationEvent('impression', config, [tracked]);
    }
  }

  function createRecommendation(product, variant, config, status) {
    const card = document.createElement('article');
    card.className = 'deco-native-cart-recommendation__card';
    const imageUrl = safeImageUrl(variant?.image?.url || product?.storefront?.image?.url);
    const image = imageUrl ? document.createElement('img') : document.createElement('div');
    image.className = 'deco-native-cart-recommendation__image';
    if (image instanceof HTMLImageElement) {
      image.src = imageUrl;
      image.alt = String(variant?.image?.alt || product?.storefront?.image?.alt || product?.title || '');
      image.loading = 'lazy'; image.width = 88; image.height = 88;
    } else image.setAttribute('aria-hidden', 'true');

    const details = document.createElement('div');
    details.className = 'deco-native-cart-recommendation__details';
    const title = document.createElement('a');
    title.className = 'deco-native-cart-recommendation__title';
    title.href = safeProductPath(product?.storefront?.path);
    title.textContent = String(product?.title || 'Product');
    title.addEventListener('click', () => publishRecommendationEvent('click', config, [eventProduct(product, variant)]));
    details.append(title);
    if (variant?.title && variant.title !== 'Default Title') {
      const variantTitle = document.createElement('p');
      variantTitle.className = 'deco-native-cart-recommendation__variant';
      variantTitle.textContent = String(variant.title);
      details.append(variantTitle);
    }
    const pricing = recommendationPricing(product, config);
    if (pricing.discounted) {
      const offer = document.createElement('p');
      offer.className = 'deco-native-cart-recommendation__offer';
      offer.textContent = `${pricing.percentage}% off`;
      const prices = document.createElement('div');
      prices.className = 'deco-native-cart-recommendation__prices';
      const original = document.createElement('s'); original.textContent = pricing.original;
      const discounted = document.createElement('strong'); discounted.textContent = pricing.discounted;
      prices.append(original, discounted); details.append(offer, prices);
    } else if (pricing.original) {
      const price = document.createElement('p');
      price.className = 'deco-native-cart-recommendation__price';
      price.textContent = pricing.original; details.append(price);
    }
    const minimum = boundedQuantity(product?.minimum_purchase_quantity);
    if (minimum > 1) {
      const quantity = document.createElement('p');
      quantity.className = 'deco-native-cart-recommendation__minimum';
      quantity.textContent = `Minimum quantity: ${minimum}`;
      details.append(quantity);
    }
    const add = document.createElement('button');
    add.type = 'button'; add.textContent = 'Add';
    add.addEventListener('click', () => void addRecommendation(add, variant.shopify_variant_id, product, variant, config, status));
    card.append(image, details, add);
    return card;
  }

  async function addRecommendation(button, variantId, product, variant, config, status) {
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    const id = numericId(variantId);
    if (!id) return;
    button.disabled = true; button.textContent = 'Adding…';
    status.hidden = true; status.textContent = '';
    const tracked = eventProduct(product, variant);
    publishRecommendationEvent('click', config, [tracked]);
    try {
      await requestShopifyJson(cartRoute('cart/add.js'), {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({items: [{id: Number(id), quantity: boundedQuantity(product?.minimum_purchase_quantity)}]}),
      });
      publishRecommendationEvent('add_to_cart', config, [tracked]);
      let cart = await requestShopifyJson(cartRoute('cart.js'));
      const discountCode = activeDiscountCode(config);
      let discountWarning = '';
      if (discountCode && !cartHasDiscountCode(cart, discountCode)) {
        try {
          cart = await requestShopifyJson(cartRoute('cart/update.js'), {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({discount: [...cartDiscountCodes(cart), discountCode].join(',')}),
          });
          if (!cartHasDiscountCode(cart, discountCode)) discountWarning = `Item added. Apply discount code ${discountCode} to receive the offer.`;
        } catch {
          discountWarning = `Item added. Apply discount code ${discountCode} to receive the offer.`;
          cart = await requestShopifyJson(cartRoute('cart.js'));
        }
      }
      const [nextConfig, nativeSection] = await Promise.all([fetchConfig(cart), fetchNativeCartSection()]);
      refreshNativeCart(nativeSection, cart);
      const host = ensureHost();
      if (host) {
        render(host, cart, nextConfig, true);
        const nextStatus = host.querySelector('.deco-native-cart-recommendation__status');
        if (discountWarning && nextStatus instanceof HTMLElement) {
          nextStatus.textContent = discountWarning;
          nextStatus.hidden = false;
        }
      }
      document.dispatchEvent(new CustomEvent('deco-personalization:cart-updated', {detail: {source: 'smart_cart'}}));
    } catch {
      button.disabled = false; button.textContent = 'Add';
      status.textContent = 'This item could not be added. Please try again.';
      status.hidden = false;
      publishRecommendationEvent('add_failed', config, [tracked]);
    }
  }

  async function fetchConfig(cart) {
    const url = new URL(`${proxyPath}/smart-cart`, window.location.origin);
    setIdQuery(url, 'cart_product_ids', productIds(cart?.items));
    setIdQuery(url, 'recently_viewed_product_ids', recentProducts());
    return requestProxyJson(url.toString());
  }

  async function fetchNativeCartSection() {
    const url = new URL(cartRoute(''), window.location.origin);
    url.searchParams.set('section_id', 'mini_cart');
    const response = await fetch(url.toString(), {credentials: 'same-origin', headers: {Accept: 'text/html'}});
    if (!response.ok) return null;
    const documentNode = new DOMParser().parseFromString(await response.text(), 'text/html');
    return documentNode.querySelector('#t4s-mini_cart');
  }

  function refreshNativeCart(remote, cart) {
    const current = document.querySelector('#t4s-mini_cart');
    if (current instanceof HTMLElement && remote instanceof HTMLElement) {
      for (const selector of ['[data-cart-items]', '.t4s-drawer__bottom']) {
        const from = remote.querySelector(selector); const to = current.querySelector(selector);
        if (from instanceof HTMLElement && to instanceof HTMLElement) to.replaceChildren(...[...from.childNodes].map(node => node.cloneNode(true)));
      }
    }
    document.querySelectorAll('[data-cart-count], .t4s-pr-count').forEach((node) => { node.textContent = String(cart?.item_count ?? ''); });
  }

  function isOwnMutation(mutation) {
    const target = mutation.target instanceof Element ? mutation.target : mutation.target.parentElement;
    return Boolean(target?.closest(HOST_SELECTOR));
  }

  function nextRecommendation(items, cart) {
    if (!Array.isArray(items)) return null;
    const cartProducts = new Set(productIds(cart?.items));
    for (const product of items.slice(0, 24)) {
      const productId = resourceId(product?.shopify_product_id, 'Product');
      const variants = Array.isArray(product?.variants) ? product.variants : [];
      const selectedId = resourceId(product?.selected_variant_gid, 'ProductVariant');
      const variant = variants.find(item => resourceId(item?.shopify_variant_id, 'ProductVariant') === selectedId && item?.available_for_sale === true)
        || variants.find(item => resourceId(item?.shopify_variant_id, 'ProductVariant') && item?.available_for_sale === true);
      if (productId && !cartProducts.has(productId) && variant) return {product, variant};
    }
    return null;
  }

  function recommendationPricing(product, config) {
    const originalAmount = decimalAmount(product?.pricing?.original_amount);
    const discountedAmount = decimalAmount(product?.pricing?.discounted_amount);
    const currency = /^[A-Z]{3}$/.test(String(product?.pricing?.currency || '')) ? String(product.pricing.currency) : '';
    const percentage = Number(product?.pricing?.discount_percentage ?? config?.recommendations?.discount?.percentage);
    const valid = config?.recommendations?.discount && Number.isFinite(percentage) && percentage > 0 && percentage < 100
      && Number.isFinite(discountedAmount) && discountedAmount >= 0 && Number.isFinite(originalAmount) && discountedAmount < originalAmount;
    return {original: majorMoney(originalAmount, currency), discounted: valid ? majorMoney(discountedAmount, currency) : '', percentage: valid ? percentage : null};
  }

  function activeDiscountCode(config) {
    const code = String(config?.recommendations?.discount?.code || '').trim();
    return /^[A-Za-z0-9_-]{1,80}$/.test(code) ? code : '';
  }
  function cartDiscountCodes(cart) {
    return Array.isArray(cart?.cart_level_discount_applications) ? [...new Set(cart.cart_level_discount_applications
      .filter(discount => discount?.type === 'discount_code').map(discount => String(discount?.title || '').trim())
      .filter(code => /^[A-Za-z0-9_-]{1,80}$/.test(code)))] : [];
  }
  function cartHasDiscountCode(cart, code) { return cartDiscountCodes(cart).some(current => current.toLowerCase() === String(code).toLowerCase()); }
  function setIdQuery(url, key, ids) { const values = numericIds(ids, 20); if (values.length) url.searchParams.set(key, values.join(',')); }
  function productIds(items) { return Array.isArray(items) ? [...new Set(items.slice(0, 100).map(item => numericId(item?.product_id)).filter(Boolean))] : []; }
  function recentProducts() { try { const values = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(values) ? numericIds(values.slice(0, 20), 20) : []; } catch { return []; } }
  function numericIds(values, limit) { return [...new Set((Array.isArray(values) ? values : []).map(numericId).filter(Boolean))].slice(0, limit); }
  function numericId(value) { const normalized = String(value || '').trim(); return /^\d+$/.test(normalized) ? normalized : ''; }
  function resourceId(value, resource) { const normalized = String(value || '').trim(); const match = normalized.match(new RegExp(`^gid://shopify/${resource}/(\\d+)$`)); return match ? match[1] : numericId(normalized); }
  function boundedQuantity(value) { const quantity = Number(value); return Number.isInteger(quantity) && quantity >= 1 && quantity <= 999 ? quantity : 1; }
  function cartRoute(path) { const base = String(window.Shopify?.routes?.root || '/'); return `${base.endsWith('/') ? base : `${base}/`}${String(path || '').replace(/^\//, '')}`; }
  function normalizeProxyPath(value) { const normalized = String(value || '').trim().replace(/\/$/, ''); return PROXY_PATTERN.test(normalized) ? normalized : ''; }
  function safeProductPath(value) { const path = String(value || '').split('?')[0]; return /^\/products\/[A-Za-z0-9_-]+$/.test(path) ? path : '#'; }
  function safeImageUrl(value) { try { const url = new URL(String(value || '').trim(), window.location.origin); return url.protocol === 'https:' ? url.toString() : ''; } catch { return ''; } }
  function decimalAmount(value) { if (value === null || value === undefined || value === '') return Number.NaN; const amount = Number(value); return Number.isFinite(amount) ? amount : Number.NaN; }
  function majorMoney(value, currency) { const amount = decimalAmount(value); if (!Number.isFinite(amount) || amount < 0 || !/^[A-Z]{3}$/.test(currency)) return ''; try { return new Intl.NumberFormat(document.documentElement.lang || undefined, {style: 'currency', currency, currencyDisplay: 'name', minimumFractionDigits: 2, maximumFractionDigits: 2}).format(amount); } catch { return `${currency} ${amount.toFixed(2)}`; } }

  function eventProduct(product, variant) { return {...product, selected_variant_id: resourceId(variant?.shopify_variant_id, 'ProductVariant'), rule_id: String(product?.rule_id || '')}; }
  function publishRecommendationEvent(action, config, products) {
    if (root?.dataset?.designMode === 'true') return;
    const publish = window.Shopify?.analytics?.publish;
    if (typeof publish !== 'function') return;
    const payload = {component_uuid: '', strategy_uuid: String(config?.recommendations?.strategy?.uuid || ''), strategy_version_uuid: String(config?.recommendations?.strategy?.version_uuid || ''), placement: 'smart_cart', products: (Array.isArray(products) ? products : []).slice(0, 50).map((product, index) => ({product_id: numericId(product?.shopify_product_id), variant_id: numericId(product?.selected_variant_id), rank: Number(product?.rank) || index + 1, rule_id: String(product?.rule_id || '')})).filter(product => product.product_id)};
    try { const result = publish.call(window.Shopify.analytics, `deco_personalization:${action}`, payload); if (result?.catch) result.catch(() => {}); } catch { /* analytics never blocks the native cart */ }
  }
  async function requestShopifyJson(url, options = {}) { const response = await fetch(url, {credentials: 'same-origin', ...options, headers: {Accept: 'application/json', ...(options.headers || {})}}); const payload = await response.json().catch(() => ({})); if (!response.ok || !payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('Request failed'); return payload; }
  async function requestProxyJson(url) { const payload = await requestShopifyJson(url); if (!payload.data || typeof payload.data !== 'object' || Array.isArray(payload.data)) throw new Error('Request failed'); return payload.data; }
})();
