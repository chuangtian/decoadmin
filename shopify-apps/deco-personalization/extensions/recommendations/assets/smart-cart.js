(() => {
  const root = document.querySelector('[data-deco-smart-cart-root]');
  const PROXY_PATTERN = /^\/(?:a|apps|community|tools)\/[A-Za-z0-9_-]{1,30}$/;
  const RECENT_KEY = 'deco_personalization_recent_products_v1';
  let enabled = false;
  let drawer = null;
  let lastFocus = null;

  const compatibility = () => ({
    themeUrl: window.location.pathname,
    checks: [
      {key: 'browser_dialog', label: 'Browser dialog support', passed: typeof HTMLDialogElement !== 'undefined'},
      {key: 'cart_link', label: 'Theme cart link detected', passed: Boolean(document.querySelector('a[href="/cart"], a[href^="/cart?"]'))},
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
      const cart = await requestShopifyJson('/cart.js');
      const configUrl = new URL(`${path}/smart-cart`, window.location.origin);
      productIds(cart.items).forEach((id) => configUrl.searchParams.append('cart_product_ids[]', id));
      recentProducts().forEach((id) => configUrl.searchParams.append('recently_viewed_product_ids[]', id));
      const config = await requestProxyJson(configUrl.toString());
      if (config.enabled !== true || config.fallback_mode !== 'shopify_default') return;

      enabled = true;
      drawer = buildDrawer();
      document.body.append(drawer);
      bindThemeEvents();
      await render(cart, config);
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
    const link = event.target.closest('a[href="/cart"], a[href^="/cart?"]');
    if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
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
      const cart = await requestShopifyJson('/cart.js');
      const url = new URL(`${proxyPath}/smart-cart`, window.location.origin);
      productIds(cart.items).forEach((id) => url.searchParams.append('cart_product_ids[]', id));
      recentProducts().forEach((id) => url.searchParams.append('recently_viewed_product_ids[]', id));
      const config = await requestProxyJson(url.toString());
      if (config.enabled !== true) {
        restoreThemeCart();
        return;
      }
      await render(cart, config);
      if (!drawer.open) drawer.showModal();
      document.documentElement.classList.add('deco-smart-cart-open');
      drawer.querySelector('.deco-smart-cart__close')?.focus();
    } catch {
      restoreThemeCart();
      window.location.assign('/cart');
    }
  }

  function closeDrawer() {
    if (!(drawer instanceof HTMLDialogElement)) return;
    drawer.close();
    document.documentElement.classList.remove('deco-smart-cart-open');
    if (lastFocus instanceof HTMLElement) lastFocus.focus();
  }

  async function render(cart, config) {
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
    const recommendationItems = config?.recommendations?.items;
    if (Array.isArray(recommendationItems) && recommendationItems.length > 0) {
      const heading = document.createElement('h3');
      heading.className = 'deco-smart-cart__recommendation-heading';
      heading.textContent = String(config.heading || 'You may also like');
      const list = document.createElement('div');
      list.className = 'deco-smart-cart__recommendations';
      recommendationItems.slice(0, 8).forEach((product) => list.append(createRecommendation(product)));
      recommendations.append(heading, list);
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

  function createRecommendation(product) {
    const card = document.createElement('article');
    card.className = 'deco-smart-cart__recommendation';
    const title = document.createElement('a');
    title.href = safeProductPath(product?.storefront?.path);
    title.textContent = String(product?.title || 'Product');
    const variant = Array.isArray(product?.variants)
      ? product.variants.find((item) => item?.available_for_sale === true && numericId(item?.shopify_variant_id))
      : null;
    card.append(title);
    if (variant) {
      const add = document.createElement('button');
      add.type = 'button';
      add.textContent = 'Add';
      add.addEventListener('click', () => void addRecommendation(add, variant.shopify_variant_id));
      card.append(add);
    }
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
      const cart = await requestShopifyJson('/cart/change.js', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: String(key || ''), quantity: Math.max(0, Number(quantity) || 0)}),
      });
      const config = await fetchConfig(cart);
      await render(cart, config);
    } catch {
      restoreThemeCart();
      window.location.assign('/cart');
    }
  }

  async function addRecommendation(button, variantId) {
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    const id = numericId(variantId);
    if (!id) return;
    button.disabled = true;
    try {
      await requestShopifyJson('/cart/add.js', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({items: [{id: Number(id), quantity: 1}]}),
      });
      const cart = await requestShopifyJson('/cart.js');
      await render(cart, await fetchConfig(cart));
    } catch {
      restoreThemeCart();
      window.location.assign('/cart');
    }
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
    if (drawer instanceof HTMLDialogElement) drawer.remove();
    drawer = null;
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
      const url = new URL(String(value || ''), window.location.origin);
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
})();
