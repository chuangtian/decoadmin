(() => {
  const root = document.querySelector('[data-deco-smart-cart-root]');
  if (!(root instanceof HTMLElement)) return;

  const PROXY_PATTERN = /^\/(?:a|apps|community|tools)\/[A-Za-z0-9_-]{1,30}$/;
  const DRAWER_SELECTOR = '#t4s-mini_cart, #CartDrawer, cart-drawer, [data-cart-drawer], .cart-drawer, .mini-cart';
  const ITEMS_SELECTOR = '[data-cart-items], .t4s-mini_cart__items, .t4s-cart-items, .cart-drawer__items, [data-mini-cart-items], .mini-cart__items';
  const LINE_SELECTOR = '[data-cart-item], [data-cart-line], [data-line-item-key], .t4s-mini_cart__item, .t4s-cart-item, .cart-item';
  const TOTAL_SELECTOR = '[data-cart-total], [data-cart-subtotal], .t4s-cart__totalPrice, .t4s-mini_cart__totalPrice, .t4s-mini_cart__total_price, .t4s-mini_cart__total .money, .cart-drawer__subtotal .money, .totals__subtotal-value';
  const HEADER_TOTAL_SELECTOR = '[data-cart-tt-price], .t4s-h-cart__total';
  const COUNT_SELECTOR = '[data-cart-count], .t4s-pr-count, .t4s-cart-count, .cart-count-bubble span:first-child';
  const HOST_SELECTOR = '[data-deco-native-cart-recommendation]';
  const RECENT_KEY = 'deco_personalization_recent_products_v1';
  const REFRESH_RETRY_DELAYS = [1200, 3000, 7000];
  const proxyPath = normalizeProxyPath(root.dataset.proxyPath);
  if (!proxyPath) return;

  let busy = false;
  let syncing = false;
  let refreshQueued = false;
  let refreshTimer = 0;
  let refreshRetryTimer = 0;
  let refreshRetryCount = 0;
  let recoveryTimer = 0;
  let nativeChangeGeneration = 0;
  let suppressCartClicksUntil = 0;
  let impressionKey = '';
  let renderedKey = '';

  Object.defineProperty(window, 'DecoPersonalizationSmartCart', {
    configurable: true,
    value: Object.freeze({
      compatibility: () => ({
        mode: 'native_cart_embed',
        nativeCartFound: Boolean(findDrawer()),
        placementFound: Boolean(nativePlacement()),
      }),
    }),
  });

  const drawerObserver = new MutationObserver((mutations) => {
    if (mutations.some((mutation) => drawerTreeChanged(mutation) || nativeCartItemsChanged(mutation))) queueRefresh(100);
  });
  drawerObserver.observe(document.documentElement, {childList: true, subtree: true});

  window.addEventListener('click', handleCartClick, true);
  window.addEventListener('keydown', blockLockedInteraction, true);
  document.addEventListener('change', handleCartChange, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') queueRefresh(0);
  });
  window.addEventListener('pageshow', () => queueRefresh(0));
  document.addEventListener('deco-personalization:cart-updated', () => queueRefresh(80));
  queueRefresh(0);

  function handleCartClick(event) {
    if (!(event.target instanceof Element)) return;
    const drawer = event.target.closest(DRAWER_SELECTOR);
    if (!drawer) {
      if (event.target.closest('a[href="/cart"], a[href$="/cart"], [data-open-cart], [data-cart-drawer-trigger]')) {
        queueRefresh(180);
      }
      return;
    }
    if (Date.now() < suppressCartClicksUntil) {
      stopEvent(event);
      return;
    }
    if (isLocked(drawer)) {
      stopEvent(event);
      return;
    }
    if (event.target.closest(HOST_SELECTOR)) return;

    const remove = event.target.closest('a[href*="/cart/change"][href*="quantity=0"], .t4s-mini_cart__remove, [data-cart-remove]');
    const line = remove?.closest(LINE_SELECTOR);
    const lineId = remove instanceof HTMLElement ? removeLineId(remove) : '';
    if (remove instanceof HTMLElement && line instanceof HTMLElement && lineId) {
      stopEvent(event);
      void removeCartLine(line, remove, lineId);
      return;
    }
    if (event.target.closest('[data-quantity-selector], [data-action-change], [name="plus"], [name="minus"]')) {
      scheduleNativeChangeRefresh();
    }
  }

  function handleCartChange(event) {
    if (!(event.target instanceof Element)) return;
    const drawer = event.target.closest(DRAWER_SELECTOR);
    if (!drawer) return;
    if (isLocked(drawer)) {
      stopEvent(event);
      return;
    }
    if (!event.target.closest(HOST_SELECTOR)) scheduleNativeChangeRefresh();
  }

  function blockLockedInteraction(event) {
    if (!(event.target instanceof Element)) return;
    const drawer = event.target.closest(DRAWER_SELECTOR);
    if (drawer instanceof HTMLElement && isLocked(drawer)) stopEvent(event);
  }

  function stopEvent(event) {
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
  }

  function scheduleNativeChangeRefresh() {
    const generation = ++nativeChangeGeneration;
    for (const delay of [350, 900]) {
      window.setTimeout(() => {
        if (generation === nativeChangeGeneration) queueRefresh(0);
      }, delay);
    }
  }

  function queueRefresh(delay = 100) {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(() => void refreshRecommendation(), delay);
  }

  async function refreshRecommendation() {
    if (busy || syncing) {
      refreshQueued = true;
      return;
    }
    const host = ensureHost();
    if (!(host instanceof HTMLElement)) return;
    syncing = true;
    try {
      const cart = await getCart();
      const config = await getConfig(cart).catch(() => null);
      if (busy) {
        refreshQueued = true;
        return;
      }
      syncTotalsAndCount(null, cart);
      renderRecommendation(ensureHost(), cart, config, true);
      refreshRetryCount = 0;
      window.clearTimeout(refreshRetryTimer);
    } catch {
      scheduleRefreshRetry();
    } finally {
      syncing = false;
      if (refreshQueued && !busy) {
        refreshQueued = false;
        queueRefresh(100);
      }
    }
  }

  function scheduleRefreshRetry() {
    if (refreshRetryCount >= REFRESH_RETRY_DELAYS.length) return;
    const delay = REFRESH_RETRY_DELAYS[refreshRetryCount];
    refreshRetryCount += 1;
    window.clearTimeout(refreshRetryTimer);
    refreshRetryTimer = window.setTimeout(() => queueRefresh(0), delay);
  }

  function beginOperation(statusControl, statusText) {
    if (busy) return false;
    busy = true;
    if (statusControl instanceof HTMLElement) {
      statusControl.dataset.decoOriginalText = statusControl.textContent || '';
      statusControl.setAttribute('aria-busy', 'true');
      if ('disabled' in statusControl) statusControl.disabled = true;
      statusControl.textContent = statusText;
    }
    lockDrawer(true);
    return true;
  }

  function finishOperation(statusControl) {
    if (statusControl instanceof HTMLElement && statusControl.isConnected) {
      statusControl.removeAttribute('aria-busy');
      if ('disabled' in statusControl) statusControl.disabled = false;
      if (statusControl.dataset.decoOriginalText !== undefined) {
        statusControl.textContent = statusControl.dataset.decoOriginalText;
        delete statusControl.dataset.decoOriginalText;
      }
    }
    busy = false;
    suppressCartClicksUntil = Date.now() + 400;
    lockDrawer(false);
    if (refreshQueued) {
      refreshQueued = false;
      queueRefresh(100);
    }
  }

  function lockDrawer(locked) {
    document.querySelectorAll(DRAWER_SELECTOR).forEach((drawer) => {
      if (!(drawer instanceof HTMLElement)) return;
      if (locked) {
        drawer.dataset.decoPreviousInert = drawer.inert ? 'true' : 'false';
        drawer.dataset.decoCartLocked = 'true';
        drawer.setAttribute('aria-busy', 'true');
        drawer.inert = true;
      } else if (drawer.dataset.decoCartLocked === 'true') {
        drawer.inert = drawer.dataset.decoPreviousInert === 'true';
        delete drawer.dataset.decoPreviousInert;
        delete drawer.dataset.decoCartLocked;
        drawer.removeAttribute('aria-busy');
      }
    });
  }

  function isLocked(drawer) {
    return busy || drawer?.dataset?.decoCartLocked === 'true';
  }

  async function addRecommendation(button, product, variant, config) {
    if (!(button instanceof HTMLButtonElement) || !beginOperation(button, 'Adding…')) return;
    const variantId = numericId(variant?.shopify_variant_id);
    const quantity = boundedQuantity(product?.minimum_purchase_quantity);
    const lineOrder = renderedLineOrder();
    const tracked = eventProduct(product, variant);
    publishEvent('click', config, [tracked]);
    try {
      if (!variantId) throw failure('invalid_variant');
      const before = await getCart();
      const previousQuantity = variantQuantity(before, variantId);
      const {response: added, cart: confirmedCart} = await addCartItemConfirmed(variantId, quantity, previousQuantity);
      let cart = confirmedCart;
      if (variantQuantity(cart, variantId) < previousQuantity + quantity) throw failure('add_not_confirmed');
      let section = sectionFromResponse(added);

      const discountCode = activeDiscountCode(config);
      if (discountCode && !cartHasDiscountCode(cart, discountCode)) {
        const updated = await requestJson(cartRoute('cart/update.js'), {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            discount: [...cartDiscountCodes(cart), discountCode].join(','),
            sections: ['mini_cart'],
            sections_url: window.location.pathname,
          }),
          timeoutMs: 15000,
          retries: 1,
        });
        section = sectionFromResponse(updated) || section;
        cart = await getCart();
        if (variantQuantity(cart, variantId) < previousQuantity + quantity) throw failure('add_not_confirmed');
      }

      section = await completeNativeSection(section, cart);
      const nextConfig = await getConfig(cart).catch(() => null);
      commitNativeCart(section, cart, nextConfig, lineOrder);
      publishEvent('add_to_cart', config, [tracked]);
      document.dispatchEvent(new CustomEvent('deco-personalization:cart-updated', {detail: {source: 'smart_cart_add'}}));
    } catch {
      publishEvent('add_failed', config, [tracked]);
      scheduleRecovery();
    } finally {
      finishOperation(button);
    }
  }

  async function addCartItemConfirmed(variantId, quantity, previousQuantity) {
    const expectedQuantity = previousQuantity + quantity;
    const request = () => requestJson(cartRoute('cart/add.js'), {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        items: [{id: Number(variantId), quantity}],
        sections: ['mini_cart'],
        sections_url: window.location.pathname,
      }),
      timeoutMs: 15000,
    });

    try {
      const response = await request();
      return {response, cart: await getCart()};
    } catch (error) {
      if (error?.code !== 'verification_required') throw error;
      let cart = await getCart();
      if (variantQuantity(cart, variantId) >= expectedQuantity) return {response: null, cart};
      await delay(1200);
      const response = await request();
      cart = await getCart();
      return {response, cart};
    }
  }

  async function removeCartLine(line, control, lineId) {
    if (!(line instanceof HTMLElement) || !(control instanceof HTMLElement)) return;
    const lineOrder = renderedLineOrder();
    const label = document.createElement('span');
    label.className = 'deco-native-cart-removing';
    label.textContent = 'Removing…';
    label.setAttribute('role', 'status');
    const titleSample = cartItemTextSample(line);
    const titleColor = titleSample instanceof Element ? window.getComputedStyle(titleSample).color : '';
    if (titleColor) label.style.color = titleColor;
    control.after(label);
    if (!beginOperation(control, '')) {
      label.remove();
      return;
    }
    try {
      const changed = await requestJson(cartRoute('cart/change.js'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          id: lineId,
          quantity: 0,
          sections: ['mini_cart'],
          sections_url: window.location.pathname,
        }),
        timeoutMs: 15000,
        retries: 1,
      });
      const cart = await getCart();
      if (cartContainsLine(cart, lineId)) throw failure('remove_not_confirmed');
      const section = await completeNativeSection(sectionFromResponse(changed), cart);
      const config = await getConfig(cart).catch(() => null);
      commitNativeCart(section, cart, config, lineOrder);
      document.dispatchEvent(new CustomEvent('deco-personalization:cart-updated', {detail: {source: 'smart_cart_remove'}}));
    } catch {
      scheduleRecovery();
    } finally {
      label.remove();
      finishOperation(control);
    }
  }

  function scheduleRecovery() {
    window.clearTimeout(recoveryTimer);
    recoveryTimer = window.setTimeout(() => void recoverNativeCartOnce(), 1200);
  }

  async function recoverNativeCartOnce() {
    if (busy) {
      scheduleRecovery();
      return;
    }
    try {
      const cart = await getCart();
      const section = await fetchNativeSection();
      if (!(section instanceof HTMLElement) || !sectionMatchesCart(section, cart)) return;
      const config = await getConfig(cart).catch(() => null);
      commitNativeCart(section, cart, config);
    } catch { /* one recovery attempt only; never start a refresh loop */ }
  }

  async function completeNativeSection(section, cart) {
    let resolved = section;
    if (!(resolved instanceof HTMLElement) || !sectionMatchesCart(resolved, cart)) {
      resolved = await fetchNativeSection();
    }
    if (!(resolved instanceof HTMLElement) || !sectionMatchesCart(resolved, cart)) {
      throw failure('native_section_not_confirmed');
    }
    return resolved;
  }

  function commitNativeCart(remote, cart, config, preferredLineOrder = renderedLineOrder()) {
    const current = findDrawer();
    const sourceItems = remote?.querySelector(ITEMS_SELECTOR);
    const targetItems = current?.querySelector(ITEMS_SELECTOR);
    if (!(current instanceof HTMLElement) || !(sourceItems instanceof HTMLElement) || !(targetItems instanceof HTMLElement)) {
      throw failure('native_cart_missing');
    }
    orderNativeLines(sourceItems, preferredLineOrder);
    current.querySelectorAll(HOST_SELECTOR).forEach((host) => host.remove());
    targetItems.replaceChildren(...[...sourceItems.childNodes].map((node) => node.cloneNode(true)));
    syncTotalsAndCount(remote, cart);
    renderRecommendation(ensureHost(), cart, config, true);
  }

  function renderedLineOrder() {
    const drawer = findDrawer();
    if (!(drawer instanceof HTMLElement)) return [];
    return [...drawer.querySelectorAll(LINE_SELECTOR)]
      .filter((line) => line instanceof HTMLElement && !line.closest(HOST_SELECTOR))
      .map(lineIdentity)
      .filter(Boolean);
  }

  function orderNativeLines(sourceItems, preferredLineOrder) {
    if (!(sourceItems instanceof HTMLElement) || !Array.isArray(preferredLineOrder) || !preferredLineOrder.length) return;
    const lines = [...sourceItems.querySelectorAll(LINE_SELECTOR)]
      .filter((line) => line instanceof HTMLElement && !line.closest(HOST_SELECTOR));
    if (lines.length < 2) return;
    const parent = lines[0].parentElement;
    if (!(parent instanceof HTMLElement) || !lines.every((line) => line.parentElement === parent)) return;
    const preferred = new Map(preferredLineOrder.map((id, index) => [id, index]));
    const original = new Map(lines.map((line, index) => [line, index]));
    lines.sort((left, right) => {
      const leftRank = preferred.has(lineIdentity(left)) ? preferred.get(lineIdentity(left)) : preferred.size + original.get(left);
      const rightRank = preferred.has(lineIdentity(right)) ? preferred.get(lineIdentity(right)) : preferred.size + original.get(right);
      return leftRank - rightRank;
    });
    const markers = lines.map(() => document.createComment('deco-cart-line-slot'));
    [...parent.children].filter((child) => lines.includes(child)).forEach((line, index) => line.replaceWith(markers[index]));
    markers.forEach((marker, index) => marker.replaceWith(lines[index]));
  }

  function lineIdentity(line) {
    if (!(line instanceof HTMLElement)) return '';
    for (const value of [line.dataset.lineItemKey, line.dataset.cartItemKey, line.dataset.key]) {
      if (typeof value === 'string' && /^\d+(?::[A-Za-z0-9_-]+)?$/.test(value)) return value;
    }
    const remove = line.querySelector('a[href*="/cart/change"][href*="quantity=0"], .t4s-mini_cart__remove, [data-cart-remove]');
    return remove instanceof HTMLElement ? removeLineId(remove) : '';
  }

  function sectionMatchesCart(section, cart) {
    const sourceItems = section?.querySelector(ITEMS_SELECTOR);
    if (!(sourceItems instanceof HTMLElement)) return false;
    const lines = [...sourceItems.querySelectorAll(LINE_SELECTOR)]
      .filter((line) => line instanceof HTMLElement && !line.closest(HOST_SELECTOR));
    return lines.length === (Array.isArray(cart?.items) ? cart.items.length : 0);
  }

  function sectionFromResponse(payload) {
    const html = payload?.sections?.mini_cart;
    if (typeof html !== 'string' || !html.trim()) return null;
    const documentNode = new DOMParser().parseFromString(html, 'text/html');
    return documentNode.querySelector(DRAWER_SELECTOR);
  }

  async function fetchNativeSection() {
    const url = new URL(cartRoute(''), window.location.origin);
    url.searchParams.set('section_id', 'mini_cart');
    url.searchParams.set('_deco_refresh', String(Date.now()));
    const response = await requestText(url.toString(), {timeoutMs: 12000});
    const documentNode = new DOMParser().parseFromString(response, 'text/html');
    return documentNode.querySelector(DRAWER_SELECTOR);
  }

  function findDrawer() {
    return document.querySelector(DRAWER_SELECTOR);
  }

  function nativePlacement() {
    const drawer = findDrawer();
    if (!(drawer instanceof HTMLElement)) return null;
    const exact = drawer.querySelector('[data-personalization-id="00007"]');
    if (exact instanceof HTMLElement) {
      exact.hidden = true;
      exact.setAttribute('aria-hidden', 'true');
      return {drawer, anchor: exact, mode: 'after'};
    }
    const items = drawer.querySelector(ITEMS_SELECTOR);
    if (items instanceof HTMLElement) return {drawer, anchor: items, mode: 'append'};
    const footer = drawer.querySelector('.t4s-drawer__bottom, .drawer__footer, [data-cart-footer]');
    if (footer instanceof HTMLElement && footer.parentElement) return {drawer, anchor: footer, mode: 'before'};
    return null;
  }

  function ensureHost() {
    const placement = nativePlacement();
    if (!placement) return null;
    const hosts = [...placement.drawer.querySelectorAll(HOST_SELECTOR)].filter((node) => node instanceof HTMLElement);
    const existing = hosts.shift();
    hosts.forEach((node) => node.remove());
    if (existing instanceof HTMLElement) {
      applyThemeAppearance(existing, placement.drawer);
      return existing;
    }
    const host = document.createElement('section');
    host.dataset.decoNativeCartRecommendation = '';
    host.className = 'deco-native-cart-recommendation';
    host.hidden = true;
    host.setAttribute('aria-live', 'polite');
    if (placement.mode === 'after') placement.anchor.after(host);
    else if (placement.mode === 'before') placement.anchor.before(host);
    else placement.anchor.append(host);
    applyThemeAppearance(host, placement.drawer);
    return host;
  }

  function applyThemeAppearance(host, drawer) {
    const sample = cartItemTextSample(drawer);
    if (!(sample instanceof Element)) return;
    const style = window.getComputedStyle(sample);
    if (style.fontFamily) host.style.setProperty('--deco-native-font-family', style.fontFamily);
    if (style.color) host.style.setProperty('--deco-native-text-color', style.color);
  }

  function cartItemTextSample(scope) {
    if (!(scope instanceof Element)) return null;
    return [...scope.querySelectorAll('a[href*="/products/"], .cart-item__name')]
      .find((node) => node instanceof HTMLElement && Boolean(node.textContent?.trim())) || null;
  }

  function renderRecommendation(host, cart, config, publishImpression) {
    if (!(host instanceof HTMLElement)) return;
    const recommendation = config?.enabled === true ? nextRecommendation(config?.recommendations?.items, cart) : null;
    if (!recommendation) {
      clearRecommendation(host);
      return;
    }
    const key = recommendationKey(recommendation, config);
    if (key === renderedKey && host.childElementCount && !host.hidden) return;
    const heading = document.createElement('h3');
    heading.className = 'deco-native-cart-recommendation__heading';
    heading.textContent = String(config.heading || 'You may also like');
    const card = recommendationCard(recommendation.product, recommendation.variant, config);
    host.replaceChildren(heading, card);
    host.hidden = false;
    renderedKey = key;

    const tracked = eventProduct(recommendation.product, recommendation.variant);
    const nextImpression = `${config?.recommendations?.strategy?.uuid || ''}:${tracked.selected_variant_id}`;
    if (publishImpression && nextImpression !== impressionKey) {
      impressionKey = nextImpression;
      publishEvent('impression', config, [tracked]);
    }
  }

  function clearRecommendation(host = ensureHost()) {
    if (!(host instanceof HTMLElement)) return;
    host.replaceChildren();
    host.hidden = true;
    renderedKey = '';
    impressionKey = '';
  }

  function recommendationCard(product, variant, config) {
    const card = document.createElement('article');
    card.className = 'deco-native-cart-recommendation__card';
    const imageUrl = safeImageUrl(variant?.image?.url || product?.storefront?.image?.url);
    const image = imageUrl ? document.createElement('img') : document.createElement('div');
    image.className = 'deco-native-cart-recommendation__image';
    if (image instanceof HTMLImageElement) {
      image.src = imageUrl;
      image.alt = String(variant?.image?.alt || product?.storefront?.image?.alt || product?.title || '');
      image.loading = 'lazy';
      image.width = 88;
      image.height = 88;
    } else image.setAttribute('aria-hidden', 'true');

    const details = document.createElement('div');
    details.className = 'deco-native-cart-recommendation__details';
    const title = document.createElement('a');
    title.className = 'deco-native-cart-recommendation__title';
    title.href = safeProductPath(product?.storefront?.path);
    title.textContent = String(product?.title || 'Product');
    title.addEventListener('click', () => publishEvent('click', config, [eventProduct(product, variant)]));
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
      const discounted = document.createElement('strong');
      discounted.textContent = pricing.discounted;
      const original = document.createElement('s');
      original.textContent = pricing.original;
      prices.append(discounted, original);
      details.append(offer, prices);
    } else if (pricing.original) {
      const price = document.createElement('p');
      price.className = 'deco-native-cart-recommendation__price';
      price.textContent = pricing.original;
      details.append(price);
    }

    const minimum = boundedQuantity(product?.minimum_purchase_quantity);
    if (minimum > 1) {
      const quantity = document.createElement('p');
      quantity.className = 'deco-native-cart-recommendation__minimum';
      quantity.textContent = `Minimum quantity: ${minimum}`;
      details.append(quantity);
    }

    const add = document.createElement('button');
    add.type = 'button';
    add.textContent = 'Add';
    add.addEventListener('click', () => void addRecommendation(add, product, variant, config));
    card.append(image, details, add);
    return card;
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

  function recommendationKey(recommendation, config) {
    return JSON.stringify([
      config?.recommendations?.strategy?.uuid || '',
      resourceId(recommendation?.variant?.shopify_variant_id, 'ProductVariant'),
      recommendation?.product?.title || '',
      recommendation?.product?.pricing?.discounted_amount ?? '',
      config?.heading || '',
    ]);
  }

  function recommendationPricing(product, config) {
    const originalAmount = decimalAmount(product?.pricing?.original_amount);
    const discountedAmount = decimalAmount(product?.pricing?.discounted_amount);
    const currency = /^[A-Z]{3}$/.test(String(product?.pricing?.currency || '')) ? String(product.pricing.currency) : '';
    const percentage = Number(product?.pricing?.discount_percentage ?? config?.recommendations?.discount?.percentage);
    const discounted = config?.recommendations?.discount && Number.isFinite(percentage) && percentage > 0 && percentage < 100
      && Number.isFinite(originalAmount) && Number.isFinite(discountedAmount) && discountedAmount >= 0 && discountedAmount < originalAmount;
    return {
      original: money(originalAmount, currency),
      discounted: discounted ? money(discountedAmount, currency) : '',
      percentage: discounted ? percentage : null,
    };
  }

  function syncTotalsAndCount(remote, cart) {
    const drawer = findDrawer();
    const total = cartTotalText(cart);
    if (drawer instanceof HTMLElement && total) {
      for (const selector of TOTAL_SELECTOR.split(',').map((value) => value.trim())) {
        drawer.querySelectorAll(selector).forEach((node) => {
          const remoteText = remote?.querySelector(selector)?.textContent;
          const next = remoteText || total;
          if (node.textContent !== next) node.textContent = next;
        });
      }
    }
    if (total) {
      document.querySelectorAll(HEADER_TOTAL_SELECTOR).forEach((node) => {
        if (node.textContent !== total) node.textContent = total;
      });
    }
    const count = String(Math.max(0, Number(cart?.item_count) || 0));
    document.querySelectorAll(COUNT_SELECTOR).forEach((node) => updateCountNode(node, count));
  }

  function updateCountNode(node, count) {
    if (!(node instanceof HTMLElement)) return;
    if (node.childElementCount === 0) {
      if (node.textContent !== count) node.textContent = count;
      return;
    }
    for (const child of node.childNodes) {
      if (child.nodeType === Node.TEXT_NODE && /^\s*\d+\s*$/.test(child.textContent || '')) {
        if (child.textContent !== count) child.textContent = count;
        return;
      }
    }
    for (const child of node.querySelectorAll('[data-hulkapps-cart-total], [data-cart-count-value], span')) {
      if (child.childElementCount === 0 && /^\s*\d+\s*$/.test(child.textContent || '')) {
        if (child.textContent !== count) child.textContent = count;
        return;
      }
    }
  }

  function getCart() {
    return requestJson(cartRoute('cart.js'), {cache: 'no-store', timeoutMs: 10000, retries: 1});
  }

  function getConfig(cart) {
    const url = new URL(`${proxyPath}/smart-cart`, window.location.origin);
    setIdQuery(url, 'cart_product_ids', productIds(cart?.items));
    setIdQuery(url, 'recently_viewed_product_ids', recentProducts());
    url.searchParams.set('_deco_refresh', String(Date.now()));
    return requestJson(url.toString(), {cache: 'no-store', timeoutMs: 10000}).then((payload) => {
      if (!payload?.data || typeof payload.data !== 'object' || Array.isArray(payload.data)) throw failure('invalid_config');
      return payload.data;
    });
  }

  async function requestJson(url, options = {}) {
    const {timeoutMs = 10000, retries = 0, ...requestOptions} = options;
    let lastError;
    for (let attempt = 0; attempt <= retries; attempt += 1) {
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          ...requestOptions,
          signal: controller.signal,
          headers: {Accept: 'application/json', ...(requestOptions.headers || {})},
        });
        const raw = await response.text();
        const type = String(response.headers.get('content-type') || '').toLowerCase();
        if (isVerificationResponse(response.status, type, raw)) throw failure('verification_required', true);
        let payload;
        try { payload = raw ? JSON.parse(raw) : null; } catch { payload = null; }
        if (!response.ok || !payload || typeof payload !== 'object' || Array.isArray(payload)) {
          throw failure('request_failed', response.status >= 500 || [408, 425, 429].includes(response.status));
        }
        return payload;
      } catch (error) {
        lastError = normalizeFailure(error);
        if (attempt >= retries || !lastError.retryable) throw lastError;
        await delay(350 * (attempt + 1));
      } finally {
        window.clearTimeout(timeout);
      }
    }
    throw lastError || failure('request_failed');
  }

  async function requestText(url, options = {}) {
    const {timeoutMs = 10000, ...requestOptions} = options;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...requestOptions,
        signal: controller.signal,
        headers: {Accept: 'text/html', ...(requestOptions.headers || {})},
      });
      const raw = await response.text();
      if (isVerificationResponse(response.status, String(response.headers.get('content-type') || ''), raw) || !response.ok) {
        throw failure('request_failed');
      }
      return raw;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  function isVerificationResponse(status, contentType, raw) {
    const html = contentType.includes('text/html') || /^\s*<!doctype html/i.test(raw);
    return html && ([403, 429, 503].includes(status)
      || /cloudflare|challenge-platform|connection needs to be verified|verify you are human/i.test(String(raw || '').slice(0, 5000)));
  }

  function failure(code, retryable = false) {
    const error = new Error(code);
    error.code = code;
    error.retryable = retryable === true;
    return error;
  }

  function normalizeFailure(error) {
    if (error?.code) return error;
    if (error?.name === 'AbortError' || error instanceof TypeError) return failure('network', true);
    return failure('request_failed');
  }

  function removeLineId(control) {
    try {
      const id = new URL(control.getAttribute('href') || '', window.location.origin).searchParams.get('id') || '';
      return /^\d+(?::[A-Za-z0-9_-]+)?$/.test(id) ? id : '';
    } catch { return ''; }
  }

  function cartContainsLine(cart, lineId) {
    const items = Array.isArray(cart?.items) ? cart.items : [];
    if (lineId.includes(':')) return items.some((item) => String(item?.key || '') === lineId);
    return items.some((item) => numericId(item?.variant_id || item?.id) === lineId);
  }

  function variantQuantity(cart, variantId) {
    return Array.isArray(cart?.items) ? cart.items.reduce((total, item) => total
      + (numericId(item?.variant_id || item?.id) === variantId ? Math.max(0, Number(item?.quantity) || 0) : 0), 0) : 0;
  }

  function activeDiscountCode(config) {
    const code = String(config?.recommendations?.discount?.code || '').trim();
    return /^[A-Za-z0-9_-]{1,80}$/.test(code) ? code : '';
  }

  function cartDiscountCodes(cart) {
    const applications = [
      ...(Array.isArray(cart?.cart_level_discount_applications) ? cart.cart_level_discount_applications : []),
      ...(Array.isArray(cart?.items) ? cart.items.flatMap((item) => [
        ...(Array.isArray(item?.line_level_discount_allocations) ? item.line_level_discount_allocations : []),
        ...(Array.isArray(item?.discount_allocations) ? item.discount_allocations : []),
      ].map((allocation) => allocation?.discount_application).filter(Boolean)) : []),
    ];
    return [...new Set(applications.filter((discount) => discount?.type === 'discount_code')
      .map((discount) => String(discount?.title || discount?.code || '').trim())
      .filter((code) => /^[A-Za-z0-9_-]{1,80}$/.test(code)))];
  }

  function cartHasDiscountCode(cart, code) {
    return cartDiscountCodes(cart).some((current) => current.toLowerCase() === code.toLowerCase());
  }

  function cartTotalText(cart) {
    const cents = Number(cart?.total_price);
    const currency = String(window.Shopify?.currency?.active || '').toUpperCase();
    if (!Number.isInteger(cents) || cents < 0 || !/^[A-Z]{3}$/.test(currency)) return '';
    try {
      return new Intl.NumberFormat(document.documentElement.lang || undefined, {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(cents / 100);
    } catch { return ''; }
  }

  function publishEvent(action, config, products) {
    if (root.dataset.designMode === 'true') return;
    const publish = window.Shopify?.analytics?.publish;
    if (typeof publish !== 'function') return;
    const payload = {
      component_uuid: '',
      strategy_uuid: String(config?.recommendations?.strategy?.uuid || ''),
      strategy_version_uuid: String(config?.recommendations?.strategy?.version_uuid || ''),
      placement: 'smart_cart',
      products: (Array.isArray(products) ? products : []).slice(0, 50).map((product, index) => ({
        product_id: numericId(product?.shopify_product_id),
        variant_id: numericId(product?.selected_variant_id),
        rank: Number(product?.rank) || index + 1,
        rule_id: String(product?.rule_id || ''),
      })).filter((product) => product.product_id),
    };
    try {
      const result = publish.call(window.Shopify.analytics, `deco_personalization:${action}`, payload);
      if (result?.catch) result.catch(() => {});
    } catch { /* analytics never blocks the cart */ }
  }

  function eventProduct(product, variant) {
    return {...product, selected_variant_id: resourceId(variant?.shopify_variant_id, 'ProductVariant'), rule_id: String(product?.rule_id || '')};
  }

  function drawerTreeChanged(mutation) {
    return [...mutation.addedNodes, ...mutation.removedNodes].some((node) => node instanceof Element
      && (node.matches(DRAWER_SELECTOR) || Boolean(node.querySelector(DRAWER_SELECTOR))));
  }

  function nativeCartItemsChanged(mutation) {
    const target = mutation.target instanceof Element ? mutation.target : mutation.target?.parentElement;
    if (!(target instanceof Element) || !target.closest(DRAWER_SELECTOR) || target.closest(HOST_SELECTOR)) return false;
    return [...mutation.addedNodes, ...mutation.removedNodes].some((node) => node instanceof Element
      && (node.matches(ITEMS_SELECTOR) || node.matches(LINE_SELECTOR)
        || Boolean(node.querySelector(ITEMS_SELECTOR)) || Boolean(node.querySelector(LINE_SELECTOR))));
  }

  function normalizeProxyPath(value) {
    const normalized = String(value || '').trim().replace(/\/$/, '');
    return PROXY_PATTERN.test(normalized) ? normalized : '';
  }

  function cartRoute(path) {
    const base = String(window.Shopify?.routes?.root || '/');
    return `${base.endsWith('/') ? base : `${base}/`}${String(path || '').replace(/^\//, '')}`;
  }

  function productIds(items) {
    return Array.isArray(items) ? [...new Set(items.slice(0, 100).map((item) => numericId(item?.product_id)).filter(Boolean))] : [];
  }

  function recentProducts() {
    try {
      const values = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
      return Array.isArray(values) ? numericIds(values.slice(0, 20), 20) : [];
    } catch { return []; }
  }

  function setIdQuery(url, key, ids) {
    const values = numericIds(ids, 20);
    if (values.length) url.searchParams.set(key, values.join(','));
  }

  function numericIds(values, limit) {
    return [...new Set((Array.isArray(values) ? values : []).map(numericId).filter(Boolean))].slice(0, limit);
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

  function boundedQuantity(value) {
    const quantity = Number(value);
    return Number.isInteger(quantity) && quantity >= 1 && quantity <= 999 ? quantity : 1;
  }

  function safeProductPath(value) {
    const path = String(value || '').split('?')[0];
    return /^\/products\/[A-Za-z0-9_-]+$/.test(path) ? path : '#';
  }

  function safeImageUrl(value) {
    try {
      const url = new URL(String(value || '').trim(), window.location.origin);
      return url.protocol === 'https:' ? url.toString() : '';
    } catch { return ''; }
  }

  function decimalAmount(value) {
    if (value === null || value === undefined || value === '') return Number.NaN;
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : Number.NaN;
  }

  function money(value, currency) {
    const amount = decimalAmount(value);
    if (!Number.isFinite(amount) || amount < 0 || !/^[A-Z]{3}$/.test(currency)) return '';
    try {
      return new Intl.NumberFormat(document.documentElement.lang || undefined, {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(amount);
    } catch { return `${currency} ${amount.toFixed(2)}`; }
  }

  function delay(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
  }
})();
