(() => {
  const ROOT_SELECTOR = '[data-deco-recommendations]';
  const INITIALIZED = 'decoRecommendationsInitialized';
  const RECENT_KEY = 'deco_personalization_recent_products_v1';
  const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  const PROXY_PATTERN = /^\/(?:a|apps|community|tools)\/[A-Za-z0-9_-]{1,30}$/;

  function initialize(root = document) {
    root.querySelectorAll(ROOT_SELECTOR).forEach((element) => {
      if (!(element instanceof HTMLElement) || element.dataset[INITIALIZED] === 'true') return;
      element.dataset[INITIALIZED] = 'true';
      void loadRecommendations(element);
    });
  }

  async function loadRecommendations(element) {
    const status = element.querySelector('[data-deco-recommendations-status]');
    const content = element.querySelector('[data-deco-recommendations-content]');
    const componentUuid = String(element.dataset.componentUuid || '').trim();
    const proxyPath = normalizeProxyPath(element.dataset.proxyPath);
    const productId = numericId(element.dataset.productId);
    const cartProductIds = numericIds(String(element.dataset.cartProductIds || '').split(','), 20);
    const designMode = element.dataset.designMode === 'true';

    rememberProduct(productId);
    if (!UUID_PATTERN.test(componentUuid) || !proxyPath) {
      showStatus(status, designMode ? 'Choose a component and confirm the App Proxy path in the block settings.' : '');
      return;
    }

    const url = new URL(`${proxyPath}/recommendations/${componentUuid}`, window.location.origin);
    if (productId) url.searchParams.set('seed_product_id', productId);
    cartProductIds.forEach((id) => url.searchParams.append('cart_product_ids[]', id));
    recentProducts().forEach((id) => url.searchParams.append('recently_viewed_product_ids[]', id));

    try {
      const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: {Accept: 'application/json'},
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload || typeof payload.data !== 'object' || !Array.isArray(payload.data.items)) {
        throw new Error(publicMessage(payload?.error?.message));
      }
      renderRecommendations(element, content, status, payload.data);
    } catch (error) {
      const message = error instanceof Error ? publicMessage(error.message) : 'Recommendations are unavailable.';
      showStatus(status, designMode ? message : '');
    }
  }

  function renderRecommendations(element, content, status, data) {
    if (!(content instanceof HTMLElement)) return;
    const items = data.items.slice(0, 50);
    if (items.length === 0) {
      showStatus(status, element.dataset.designMode === 'true' ? 'No products match this component and preview context.' : '');
      return;
    }

    const component = data.component || {};
    const style = component.style || {};
    const tokens = style.tokens || {};
    element.style.setProperty('--deco-rec-text', safeColor(tokens.text_color, '#111827'));
    element.style.setProperty('--deco-rec-background', safeColor(tokens.background_color, '#ffffff'));
    element.style.setProperty('--deco-rec-button', safeColor(tokens.button_color, '#111827'));
    element.style.setProperty('--deco-rec-button-text', safeColor(tokens.button_text_color, '#ffffff'));
    element.style.setProperty('--deco-rec-radius', `${boundedNumber(tokens.border_radius, 0, 48, 12)}px`);
    element.style.setProperty('--deco-rec-gap', `${boundedNumber(tokens.gap, 0, 48, 16)}px`);
    element.style.setProperty('--deco-rec-desktop-columns', String(boundedNumber(style.desktop_columns, 1, 6, 4)));
    element.style.setProperty('--deco-rec-mobile-columns', String(boundedNumber(style.mobile_columns, 1, 3, 2)));

    const heading = document.createElement('h2');
    heading.className = 'deco-recommendations__heading';
    heading.textContent = String(element.dataset.headingOverride || component.heading || 'Recommended for you').trim();

    const list = document.createElement('div');
    list.className = `deco-recommendations__list deco-recommendations__list--${style.layout === 'grid' ? 'grid' : 'carousel'}`;
    items.forEach((product) => list.append(createProductCard(element, product, component, data.strategy, style)));

    content.replaceChildren(heading, list);
    content.hidden = false;
    if (status instanceof HTMLElement) status.hidden = true;
    publishRecommendationEvent(element, 'impression', component, data.strategy, items);
  }

  function createProductCard(element, product, component, strategy, style) {
    const card = document.createElement('article');
    card.className = 'deco-recommendations__card';

    const link = document.createElement('a');
    link.className = 'deco-recommendations__link';
    link.href = safeProductPath(product?.storefront?.path);
    link.setAttribute('aria-label', String(product?.title || 'View product'));
    link.addEventListener('click', () => {
      publishRecommendationEvent(element, 'click', component, strategy, [product]);
    });

    if (style.show_image !== false) {
      const media = document.createElement('div');
      media.className = 'deco-recommendations__media';
      const imageUrl = safeImageUrl(product?.storefront?.image?.url);
      if (imageUrl) {
        const image = document.createElement('img');
        image.src = imageUrl;
        image.alt = String(product?.storefront?.image?.alt || product?.title || '');
        image.loading = 'lazy';
        image.width = 640;
        image.height = 640;
        media.append(image);
      }
      link.append(media);
    }

    if (style.show_vendor === true && product?.vendor) {
      const vendor = document.createElement('p');
      vendor.className = 'deco-recommendations__vendor';
      vendor.textContent = String(product.vendor);
      link.append(vendor);
    }

    const title = document.createElement('h3');
    title.className = 'deco-recommendations__title';
    title.textContent = String(product?.title || 'Product');
    link.append(title);

    if (style.show_price !== false) {
      const price = document.createElement('p');
      price.className = 'deco-recommendations__price';
      price.textContent = money(product?.price?.minimum, product?.price?.currency);
      link.append(price);
    }
    card.append(link);

    const variant = Array.isArray(product?.variants)
      ? product.variants.find((item) => item?.available_for_sale === true && numericId(item?.shopify_variant_id))
      : null;
    if (style.show_add_to_cart !== false && variant) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'deco-recommendations__button';
      button.textContent = String(component?.button_label || 'Add to cart');
      button.addEventListener('click', () => void addToCart(
        element,
        button,
        variant.shopify_variant_id,
        product,
        component,
        strategy,
      ));
      card.append(button);
    }

    return card;
  }

  async function addToCart(element, button, variantId, product, component, strategy) {
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    const id = numericId(variantId);
    if (!id) return;
    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Adding…';
    try {
      const response = await fetch('/cart/add.js', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', Accept: 'application/json'},
        body: JSON.stringify({items: [{
          id: Number(id),
          quantity: boundedNumber(product?.minimum_purchase_quantity, 1, 999, 1),
        }]}),
      });
      if (!response.ok) throw new Error('Cart request failed');
      button.textContent = 'Added';
      publishRecommendationEvent(element, 'add_to_cart', component, strategy, [{
        ...product,
        selected_variant_id: id,
      }]);
      document.dispatchEvent(new CustomEvent('deco-personalization:cart-updated'));
    } catch {
      button.textContent = 'Try again';
    } finally {
      window.setTimeout(() => {
        button.disabled = false;
        button.textContent = original;
      }, 1800);
    }
  }

  function rememberProduct(productId) {
    if (!productId) return;
    try {
      const next = [productId, ...recentProducts().filter((id) => id !== productId)].slice(0, 20);
      window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
      // Recommendations continue without persistent recently viewed context.
    }
  }

  function publishRecommendationEvent(element, action, component, strategy, products) {
    if (element?.dataset?.designMode === 'true') return;
    const publish = window.Shopify?.analytics?.publish;
    if (typeof publish !== 'function') return;
    const payload = {
      component_uuid: String(component?.uuid || ''),
      strategy_uuid: String(strategy?.uuid || ''),
      placement: String(component?.placement || ''),
      products: Array.isArray(products) ? products.slice(0, 50).map((product, index) => ({
        product_id: numericId(product?.shopify_product_id),
        variant_id: numericId(product?.selected_variant_id),
        rank: boundedNumber(product?.rank, 1, 100, index + 1),
      })).filter((product) => product.product_id) : [],
    };
    try {
      const result = publish.call(window.Shopify.analytics, `deco_personalization:${action}`, payload);
      if (result && typeof result.catch === 'function') result.catch(() => {});
    } catch {
      // Analytics never blocks recommendation rendering or cart actions.
    }
  }

  function recentProducts() {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
      return numericIds(parsed, 20);
    } catch {
      return [];
    }
  }

  function numericIds(values, maximum) {
    if (!Array.isArray(values)) return [];
    return [...new Set(values.slice(0, maximum).map(numericId).filter(Boolean))];
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
    const path = String(value || '').trim();
    return /^\/products\/[A-Za-z0-9_-]+$/.test(path) ? path : '#';
  }

  function safeImageUrl(value) {
    try {
      const url = new URL(String(value || ''));
      return url.protocol === 'https:' ? url.toString() : '';
    } catch {
      return '';
    }
  }

  function safeColor(value, fallback) {
    const color = String(value || '');
    return /^#[0-9a-f]{6}$/i.test(color) ? color : fallback;
  }

  function boundedNumber(value, minimum, maximum, fallback) {
    const number = Number(value);
    return Number.isFinite(number) ? Math.min(maximum, Math.max(minimum, number)) : fallback;
  }

  function money(value, currency) {
    const amount = Number(value);
    const code = /^[A-Z]{3}$/.test(String(currency || '')) ? String(currency) : 'USD';
    return Number.isFinite(amount)
      ? new Intl.NumberFormat(undefined, {style: 'currency', currency: code}).format(amount)
      : '';
  }

  function publicMessage(value) {
    const message = String(value || '').trim();
    return message && message.length <= 180 ? message : 'Recommendations are unavailable.';
  }

  function showStatus(status, message) {
    if (!(status instanceof HTMLElement)) return;
    status.textContent = message;
    status.hidden = message === '';
  }

  initialize();
  document.addEventListener('shopify:section:load', (event) => initialize(event.target));
})();
