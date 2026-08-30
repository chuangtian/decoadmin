(() => {
  const root = document.querySelector('[data-deco-smart-cart-root]');
  const PROXY_PATTERN = /^\/(?:a|apps|community|tools)\/[A-Za-z0-9_-]{1,30}$/;
  const RECENT_KEY = 'deco_personalization_recent_products_v1';
  let enabled = false;
  let drawer = null;
  let lastFocus = null;
  let impressionKey = '';

  const compatibility = () => ({
    themeUrl: window.location.pathname,
    checks: [
      {key: 'browser_dialog', label: 'Browser dialog support', passed: typeof HTMLDialogElement !== 'undefined'},
      {key: 'cart_link', label: 'Theme cart link detected', passed: [...document.querySelectorAll('a[href]')].some(isCartLink)},
      {key: 'cart_routes', label: 'Shopify cart routes available', passed: typeof window.fetch === 'function'},
      {key: 'app_embed', label: 'Smart Cart App Embed loaded', passed: root instanceof HTMLElement},
    ],
  });

  Object.defineProperty(window, 'DecoPersonalizationSmartCart', {
    configurable: true,
    value: Object.freeze({compatibility}),
  });

  if (!(root instanceof HTMLElement)) return;
  const proxyPath = normalizeProxyPath(root.dataset.proxyPath);
  if (!proxyPath) return;

  void initialize(proxyPath);

  async function initialize(path) {
    try {
      const cart = await requestShopifyJson(cartRoute('cart.js'));
      const configUrl = new URL(`${path}/smart-cart`, window.location.origin);
      productIds(cart.items).forEach((id) => configUrl.searchParams.append('cart_product_ids[]', id));
      recentProducts().forEach((id) => configUrl.searchParams.append('recently_viewed_product_ids[]', id));
      const config = await requestProxyJson(configUrl.toString());
      if (config.enabled !== true || config.fallback_mode !== 'shopify_default') return;

      enabled = true;
      drawer = buildDrawer();
      document.body.append(drawer);
      bindThemeEvents();
      await render(cart, config, false);
    } catch {
      restoreThemeCart();
    }
  }

  function buildDrawer() {
    const dialog = document.createElement('dialog');
    dialog.className = 'deco-smart-cart';
    dialog.setAttribute('aria-label', 'Shopping cart');

    const panel = document.createElement('div');
    panel.className = 'deco-smart-cart__panel';
    const header = document.createElement('header');
    header.className = 'deco-smart-cart__header';
    const heading = document.createElement('h2');
    heading.textContent = 'Your cart';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'deco-smart-cart__close';
    close.setAttribute('aria-label', 'Close cart');
    close.textContent = '×';
    close.addEventListener('click', closeDrawer);
    header.append(heading, close);

    const status = document.createElement('div');
    status.className = 'deco-smart-cart__status';
    status.dataset.decoCartStatus = '';
    status.setAttribute('aria-live', 'polite');
    const lines = document.createElement('div');
    lines.dataset.decoCartLines = '';
    const recommendations = document.createElement('div');
    recommendations.dataset.decoCartRecommendations = '';
    const footer = document.createElement('footer');
    footer.className = 'deco-smart-cart__footer';
    footer.dataset.decoCartFooter = '';
    panel.append(header, status, lines, recommendations, footer);
    dialog.append(panel);
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDrawer();
    });
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      closeDrawer();
    });
    return dialog;
  }

  function bindThemeEvents() {
    document.addEventListener('click', handleCartLink, true);
    document.addEventListener('submit', handleAddForm, true);
    document.addEventListener('deco-personalization:cart-updated', handleExternalCartUpdate);
  }

  function handleCartLink(event) {
    if (!enabled || !(event.target instanceof Element)) return;
    const link = event.target.closest('a[href]');
    if (!link || !isCartLink(link) || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    void refreshAndOpen(link);
  }

  function handleAddForm(event) {
    if (!enabled || !(event.target instanceof HTMLFormElement)) return;
    const action = String(event.target.getAttribute('action') || '');
    if (!/\/cart\/add(?:\.js)?(?:\?|$)/.test(action)) return;
    window.setTimeout(() => void refreshAndOpen(event.target), 500);
  }

  function handleExternalCartUpdate() {
    if (enabled) window.setTimeout(() => void refreshAndOpen(document.activeElement), 150);
  }

  async function refreshAndOpen(source) {
    if (!enabled || !(drawer instanceof HTMLDialogElement)) return;
    lastFocus = source instanceof HTMLElement ? source : document.activeElement;
    try {
      const cart = await requestShopifyJson(cartRoute('cart.js'));
      const url = new URL(`${proxyPath}/smart-cart`, window.location.origin);
      productIds(cart.items).forEach((id) => url.searchParams.append('cart_product_ids[]', id));
      recentProducts().forEach((id) => url.searchParams.append('recently_viewed_product_ids[]', id));
      const config = await requestProxyJson(url.toString());
      if (config.enabled !== true) {
        restoreThemeCart();
        return;
      }
      if (!drawer.open) drawer.showModal();
      document.documentElement.classList.add('deco-smart-cart-open');
      await render(cart, config, true);
      drawer.querySelector('.deco-smart-cart__close')?.focus();
    } catch {
      restoreThemeCart();
      window.location.assign(cartRoute('cart'));
    }
  }

  function closeDrawer() {
    if (!(drawer instanceof HTMLDialogElement)) return;
    drawer.close();
    document.documentElement.classList.remove('deco-smart-cart-open');
    impressionKey = '';
    if (lastFocus instanceof HTMLElement) lastFocus.focus();
  }

  async function render(cart, config, publishImpression = false) {
    if (!(drawer instanceof HTMLDialogElement)) return;
    const lines = drawer.querySelector('[data-deco-cart-lines]');
    const recommendations = drawer.querySelector('[data-deco-cart-recommendations]');
    const footer = drawer.querySelector('[data-deco-cart-footer]');
    if (!(lines instanceof HTMLElement) || !(recommendations instanceof HTMLElement) || !(footer instanceof HTMLElement)) return;

    lines.replaceChildren();
    if (!Array.isArray(cart.items) || cart.items.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'deco-smart-cart__empty';
      empty.textContent = 'Your cart is empty.';
      lines.append(empty);
    } else {
      cart.items.slice(0, 100).forEach((item) => lines.append(createCartLine(item, cart.currency)));
    }

    recommendations.replaceChildren();
    const recommendation = nextRecommendation(config?.recommendations?.items, cart);
    if (recommendation) {
      const heading = document.createElement('h3');
      heading.className = 'deco-smart-cart__recommendation-heading';
      heading.textContent = String(config.heading || 'You may also like');
      const list = document.createElement('div');
      list.className = 'deco-smart-cart__recommendations';
      list.append(createRecommendation(recommendation.product, recommendation.variant, config));
      recommendations.append(heading, list);
      const trackedProduct = eventProduct(recommendation.product, recommendation.variant);
      const nextImpressionKey = `${config?.recommendations?.strategy?.uuid || ''}:${trackedProduct.selected_variant_id}`;
      if (publishImpression && nextImpressionKey !== impressionKey) {
        impressionKey = nextImpressionKey;
        publishRecommendationEvent('impression', config, [trackedProduct]);
      }
    } else {
      impressionKey = '';
    }

    footer.replaceChildren();
    const total = document.createElement('div');
    total.className = 'deco-smart-cart__total';
    total.append(document.createTextNode('Subtotal'), document.createTextNode(money(cart.total_price, cart.currency)));
    const checkout = document.createElement('a');
    checkout.className = 'deco-smart-cart__checkout';
    checkout.href = '/checkout';
    checkout.textContent = 'Checkout';
    footer.append(total, checkout);
  }

  function createCartLine(item, currency) {
    const line = document.createElement('article');
    line.className = 'deco-smart-cart__line';
    const image = document.createElement('img');
    image.className = 'deco-smart-cart__line-image';
    image.src = safeImageUrl(item?.image);
    image.alt = String(item?.product_title || '');
    image.loading = 'lazy';
    image.width = 96;
    image.height = 96;

    const details = document.createElement('div');
    details.className = 'deco-smart-cart__line-details';
    const title = document.createElement('a');
    title.href = safeProductPath(item?.url);
    title.textContent = String(item?.product_title || item?.title || 'Product');
    title.className = 'deco-smart-cart__line-title';
    const variant = document.createElement('p');
    variant.className = 'deco-smart-cart__line-variant';
    variant.textContent = String(item?.variant_title && item.variant_title !== 'Default Title' ? item.variant_title : '');
    const controls = document.createElement('div');
    controls.className = 'deco-smart-cart__line-controls';
    const decrease = quantityButton('−', 'Decrease quantity', () => changeLine(item.key, Math.max(0, Number(item.quantity) - 1)));
    const quantity = document.createElement('span');
    quantity.textContent = String(Math.max(0, Number(item.quantity) || 0));
    const increase = quantityButton('+', 'Increase quantity', () => changeLine(item.key, Number(item.quantity) + 1));
    const remove = quantityButton('Remove', 'Remove item', () => changeLine(item.key, 0));
    remove.classList.add('deco-smart-cart__remove');
    controls.append(decrease, quantity, increase, remove);
    details.append(title, variant, controls);

    const price = document.createElement('p');
    price.className = 'deco-smart-cart__line-price';
    price.textContent = money(item?.final_line_price, currency);
    line.append(image, details, price);
    return line;
  }

  function createRecommendation(product, variant, config) {
    const card = document.createElement('article');
    card.className = 'deco-smart-cart__recommendation';
    const imageUrl = safeImageUrl(variant?.image?.url || product?.storefront?.image?.url);
    const image = imageUrl ? document.createElement('img') : document.createElement('div');
    image.className = 'deco-smart-cart__recommendation-image';
    if (image instanceof HTMLImageElement) {
      image.src = imageUrl;
      image.alt = String(variant?.image?.alt || product?.storefront?.image?.alt || product?.title || '');
      image.loading = 'lazy';
      image.width = 88;
      image.height = 88;
    } else {
      image.setAttribute('aria-hidden', 'true');
    }

    const details = document.createElement('div');
    details.className = 'deco-smart-cart__recommendation-details';
    const title = document.createElement('a');
    title.href = safeProductPath(product?.storefront?.path);
    title.textContent = String(product?.title || 'Product');
    title.addEventListener('click', () => publishRecommendationEvent('click', config, [eventProduct(product, variant)]));
    details.append(title);

    if (variant?.title && variant.title !== 'Default Title') {
      const variantTitle = document.createElement('p');
      variantTitle.className = 'deco-smart-cart__recommendation-variant';
      variantTitle.textContent = String(variant.title);
      details.append(variantTitle);
    }
    const pricing = recommendationPricing(product, config);
    if (pricing.discounted) {
      const offer = document.createElement('p');
      offer.className = 'deco-smart-cart__recommendation-offer';
      offer.textContent = `${pricing.percentage}% off`;
      const prices = document.createElement('div');
      prices.className = 'deco-smart-cart__recommendation-prices';
      const original = document.createElement('s');
      original.textContent = pricing.original;
      const discounted = document.createElement('strong');
      discounted.textContent = pricing.discounted;
      prices.append(original, discounted);
      details.append(offer, prices);
    } else if (pricing.original) {
      const price = document.createElement('p');
      price.className = 'deco-smart-cart__recommendation-price';
      price.textContent = pricing.original;
      details.append(price);
    }

    const add = document.createElement('button');
    add.type = 'button';
    add.textContent = 'Add';
    add.addEventListener('click', () => void addRecommendation(add, variant.shopify_variant_id, product, variant, config));
    card.append(image, details, add);
    return card;
  }

  function quantityButton(text, label, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = text;
    button.setAttribute('aria-label', label);
    button.addEventListener('click', () => void action());
    return button;
  }

  async function changeLine(key, quantity) {
    try {
      const cart = await requestShopifyJson(cartRoute('cart/change.js'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: String(key || ''), quantity: Math.max(0, Number(quantity) || 0)}),
      });
      const config = await fetchConfig(cart);
      await render(cart, config, true);
    } catch {
      restoreThemeCart();
      window.location.assign(cartRoute('cart'));
    }
  }

  async function addRecommendation(button, variantId, product, variant, config) {
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    const id = numericId(variantId);
    if (!id) return;
    button.disabled = true;
    button.textContent = 'Adding…';
    showStatus('');
    const trackedProduct = eventProduct(product, variant);
    publishRecommendationEvent('click', config, [trackedProduct]);
    try {
      await requestShopifyJson(cartRoute('cart/add.js'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({items: [{id: Number(id), quantity: boundedQuantity(product?.minimum_purchase_quantity)}]}),
      });
      publishRecommendationEvent('add_to_cart', config, [trackedProduct]);
      let cart = await requestShopifyJson(cartRoute('cart.js'));
      const discountCode = activeDiscountCode(config);
      if (discountCode && !cartHasDiscountCode(cart, discountCode)) {
        try {
          cart = await requestShopifyJson(cartRoute('cart/update.js'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({discount: [...cartDiscountCodes(cart), discountCode].join(',')}),
          });
          if (!cartHasDiscountCode(cart, discountCode)) {
            showStatus(`Item added. Apply discount code ${discountCode} to receive the offer.`);
          }
        } catch {
          showStatus(`Item added. Apply discount code ${discountCode} to receive the offer.`);
          cart = await requestShopifyJson(cartRoute('cart.js'));
        }
      }
      await render(cart, await fetchConfig(cart), true);
    } catch {
      button.disabled = false;
      button.textContent = 'Add';
      showStatus('This item could not be added. Please try again.');
      publishRecommendationEvent('add_failed', config, [trackedProduct]);
    }
  }

  function showStatus(message) {
    const status = drawer?.querySelector('[data-deco-cart-status]');
    if (!(status instanceof HTMLElement)) return;
    status.textContent = message;
    status.hidden = message === '';
  }

  async function fetchConfig(cart) {
    const url = new URL(`${proxyPath}/smart-cart`, window.location.origin);
    productIds(cart.items).forEach((id) => url.searchParams.append('cart_product_ids[]', id));
    recentProducts().forEach((id) => url.searchParams.append('recently_viewed_product_ids[]', id));
    return requestProxyJson(url.toString());
  }

  function restoreThemeCart() {
    enabled = false;
    document.removeEventListener('click', handleCartLink, true);
    document.removeEventListener('submit', handleAddForm, true);
    document.removeEventListener('deco-personalization:cart-updated', handleExternalCartUpdate);
    document.documentElement.classList.remove('deco-smart-cart-open');
    impressionKey = '';
    if (drawer instanceof HTMLDialogElement) drawer.remove();
    drawer = null;
  }

  function publishRecommendationEvent(action, config, products) {
    if (root?.dataset?.designMode === 'true') return;
    const publish = window.Shopify?.analytics?.publish;
    if (typeof publish !== 'function') return;
    const payload = {
      component_uuid: '',
      strategy_uuid: String(config?.recommendations?.strategy?.uuid || ''),
      strategy_version_uuid: String(config?.recommendations?.strategy?.version_uuid || ''),
      placement: 'smart_cart',
      products: Array.isArray(products) ? products.slice(0, 50).map((product, index) => ({
        product_id: numericId(product?.shopify_product_id),
        variant_id: numericId(product?.selected_variant_id),
        rank: boundedRank(product?.rank, index + 1),
        rule_id: String(product?.rule_id || ''),
      })).filter((product) => product.product_id) : [],
    };
    try {
      const result = publish.call(window.Shopify.analytics, `deco_personalization:${action}`, payload);
      if (result && typeof result.catch === 'function') result.catch(() => {});
    } catch {
      // Analytics never blocks Smart Cart behavior or native-cart fallback.
    }
  }

  async function requestShopifyJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: {Accept: 'application/json', ...(options.headers || {})},
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('Request failed');
    return payload;
  }

  async function requestProxyJson(url, options = {}) {
    const payload = await requestShopifyJson(url, options);
    if (!payload.data || typeof payload.data !== 'object' || Array.isArray(payload.data)) throw new Error('Request failed');
    return payload.data;
  }

  function productIds(items) {
    return Array.isArray(items)
      ? [...new Set(items.slice(0, 100).map((item) => numericId(item?.product_id)).filter(Boolean))]
      : [];
  }

  function recentProducts() {
    try {
      const values = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
      return Array.isArray(values) ? [...new Set(values.slice(0, 20).map(numericId).filter(Boolean))] : [];
    } catch {
      return [];
    }
  }

  function numericId(value) {
    const normalized = String(value || '').trim();
    return /^\d+$/.test(normalized) ? normalized : '';
  }

  function resourceId(value, resource) {
    const normalized = String(value || '').trim();
    const match = normalized.match(new RegExp(`^gid://shopify/${resource}/(\\d+)$`));
    return match ? match[1] : numericId(normalized);
  }

  function nextRecommendation(items, cart) {
    if (!Array.isArray(items)) return null;
    const cartProducts = new Set(productIds(cart?.items));
    for (const product of items.slice(0, 24)) {
      const productId = resourceId(product?.shopify_product_id, 'Product');
      const variants = Array.isArray(product?.variants) ? product.variants : [];
      const selectedId = resourceId(product?.selected_variant_gid, 'ProductVariant');
      const variant = variants.find((item) => resourceId(item?.shopify_variant_id, 'ProductVariant') === selectedId && item?.available_for_sale === true)
        || variants.find((item) => resourceId(item?.shopify_variant_id, 'ProductVariant') && item?.available_for_sale === true);
      if (productId && !cartProducts.has(productId) && variant) return {product, variant};
    }
    return null;
  }

  function eventProduct(product, variant) {
    return {
      ...product,
      selected_variant_id: resourceId(variant?.shopify_variant_id, 'ProductVariant'),
      rule_id: String(product?.rule_id || ''),
    };
  }

  function recommendationPricing(product, config) {
    const originalAmount = decimalAmount(product?.pricing?.original_amount);
    const discountedAmount = decimalAmount(product?.pricing?.discounted_amount);
    const currency = /^[A-Z]{3}$/.test(String(product?.pricing?.currency || ''))
      ? String(product.pricing.currency)
      : '';
    const percentage = Number(product?.pricing?.discount_percentage ?? config?.recommendations?.discount?.percentage);
    const discount = config?.recommendations?.discount;
    const validDiscount = discount && typeof discount === 'object'
      && Number.isFinite(percentage) && percentage > 0 && percentage < 100
      && Number.isFinite(discountedAmount) && discountedAmount >= 0
      && Number.isFinite(originalAmount) && discountedAmount < originalAmount;
    return {
      original: majorMoney(originalAmount, currency),
      discounted: validDiscount ? majorMoney(discountedAmount, currency) : '',
      percentage: validDiscount ? percentage : null,
    };
  }

  function activeDiscountCode(config) {
    const discount = config?.recommendations?.discount;
    const code = String(discount?.code || '').trim();
    return discount && typeof discount === 'object' && /^[A-Za-z0-9_-]{1,80}$/.test(code) ? code : '';
  }

  function cartDiscountCodes(cart) {
    return Array.isArray(cart?.cart_level_discount_applications)
      ? [...new Set(cart.cart_level_discount_applications
        .filter((discount) => discount?.type === 'discount_code')
        .map((discount) => String(discount?.title || '').trim())
        .filter((code) => /^[A-Za-z0-9_-]{1,80}$/.test(code)))]
      : [];
  }

  function cartHasDiscountCode(cart, code) {
    return cartDiscountCodes(cart).some((current) => current.toLowerCase() === String(code).toLowerCase());
  }

  function boundedQuantity(value) {
    const quantity = Number(value);
    return Number.isInteger(quantity) && quantity >= 1 && quantity <= 999 ? quantity : 1;
  }

  function cartRoute(path) {
    const root = String(window.Shopify?.routes?.root || '/');
    return `${root.endsWith('/') ? root : `${root}/`}${String(path || '').replace(/^\//, '')}`;
  }

  function isCartLink(link) {
    if (!(link instanceof HTMLAnchorElement)) return false;
    try {
      const target = new URL(link.href, window.location.origin);
      const expected = new URL(cartRoute('cart'), window.location.origin);
      return target.origin === window.location.origin
        && target.pathname.replace(/\/$/, '') === expected.pathname.replace(/\/$/, '');
    } catch {
      return false;
    }
  }

  function boundedRank(value, fallback) {
    const rank = Number(value);
    return Number.isInteger(rank) && rank >= 1 && rank <= 100 ? rank : fallback;
  }

  function normalizeProxyPath(value) {
    const normalized = String(value || '').trim().replace(/\/$/, '');
    return PROXY_PATTERN.test(normalized) ? normalized : '';
  }

  function safeProductPath(value) {
    const path = String(value || '').split('?')[0];
    return /^\/products\/[A-Za-z0-9_-]+$/.test(path) ? path : '#';
  }

  function safeImageUrl(value) {
    try {
      const normalized = String(value || '').trim();
      if (!normalized) return '';
      const url = new URL(normalized, window.location.origin);
      return url.protocol === 'https:' ? url.toString() : '';
    } catch {
      return '';
    }
  }

  function money(cents, currency) {
    const amount = Number(cents) / 100;
    const code = /^[A-Z]{3}$/.test(String(currency || '')) ? String(currency) : 'USD';
    return Number.isFinite(amount) ? new Intl.NumberFormat(undefined, {style: 'currency', currency: code}).format(amount) : '';
  }

  function majorMoney(value, currency) {
    const amount = decimalAmount(value);
    const code = /^[A-Z]{3}$/.test(String(currency || '')) ? String(currency) : '';
    if (!Number.isFinite(amount) || amount < 0 || !code) return '';
    try {
      return new Intl.NumberFormat(document.documentElement.lang || undefined, {
        style: 'currency', currency: code, currencyDisplay: 'name', minimumFractionDigits: 2, maximumFractionDigits: 2,
      }).format(amount);
    } catch {
      return `${code} ${amount.toFixed(2)}`;
    }
  }

  function decimalAmount(value) {
    if (value === null || value === undefined || value === '') return Number.NaN;
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : Number.NaN;
  }
})();
