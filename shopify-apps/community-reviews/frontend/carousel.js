(() => {
  if (typeof window === 'undefined') return;
  if (window.CommunityReviews) return;
  const instances = new WeakMap();
  const pending = new WeakMap();
  const loading = new WeakMap();
  const visitorIds = new Map();
  function visitorId(path) {
    const key = `community-reviews:visitor:v1:${location.host}:${path}`;
    if (visitorIds.has(key)) return visitorIds.get(key);
    let id;
    try {
      const stored = JSON.parse(localStorage.getItem(key) || 'null');
      if (stored?.expires > Date.now() && /^[a-f0-9-]{36}$/i.test(stored.id)) id = stored.id;
      if (!id) { id = crypto.randomUUID(); localStorage.setItem(key, JSON.stringify({id, expires:Date.now()+86400000})); }
    } catch { id = globalThis.crypto?.randomUUID?.(); }
    if (id) visitorIds.set(key, id);
    return id;
  }
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const safeUrl = (value) => {
    try { const url = new URL(value, location.origin); return url.protocol === 'https:' || (url.origin === location.origin && url.protocol === 'http:') ? url.href : ''; }
    catch { return ''; }
  };
  function card(item, data, root, copy, index) {
    const node = element('article', 'cr-card');
    node.dataset.reviewId = item.id;
    node.dataset.productId = item.product.id;
    const photo = element('img', 'cr-photo');
    photo.src = safeUrl(item.image.url); photo.alt = item.image.alt || item.product.label;
    photo.width = 640; photo.height = 520; photo.draggable = false; photo.decoding = 'async';
    photo.loading = !copy && index < 3 ? 'eager' : 'lazy';
    const body = element('div', 'cr-body');
    body.append(element('p', 'cr-name', item.name));
    const stars = element('div', 'cr-stars');
    stars.setAttribute('aria-label', `${item.rating} ${root.dataset.ratingLabel || 'out of 5 stars'}`);
    for (let i = 0; i < 5; i++) {
      const star = element('span', i < item.rating ? '' : 'cr-star-empty', '★'); star.setAttribute('aria-hidden', 'true'); stars.append(star);
    }
    body.append(stars, element('p', 'cr-quote', item.content));
    const moreUrl = data.read_more_url && safeUrl(data.read_more_url);
    if (moreUrl) {
      const more = element('a', 'cr-more', root.dataset.readMore || 'Read more');
      more.href = moreUrl; more.target = '_blank'; more.rel = 'noopener'; more.draggable = false;
      if (copy) more.tabIndex = -1;
      body.append(more);
    }
    const product = element('a', 'cr-product');
    product.href = safeUrl(item.product.url); product.draggable = false;
    if (copy) product.tabIndex = -1;
    if (item.product.image_url) {
      const image = element('img', 'cr-product-image');
      image.src = safeUrl(item.product.image_url); image.alt = ''; image.width = 60; image.height = 56;
      image.draggable = false; image.decoding = 'async'; image.loading = !copy && index < 3 ? 'eager' : 'lazy'; product.append(image);
    }
    const label = element('div', 'cr-product-copy');
    label.append(element('span', 'cr-model', item.product.label), element('span', 'cr-series', item.product.series || ''));
    product.append(label, element('span', 'cr-view', root.dataset.viewBike || 'View Bike'));
    body.append(product); node.append(photo, body); return node;
  }
  function mount(root, data) {
    instances.get(root)?.destroy();
    const viewport = root.querySelector('[data-cr-viewport]');
    const track = root.querySelector('[data-cr-track]');
    const heading = root.querySelector('[data-cr-heading]');
    if (!viewport || !track) return;
    const items = (data.cards || []).filter(item => [4, 5].includes(item.rating) && item.product?.url && item.image?.url);
    if (!heading.textContent.trim()) heading.textContent = data.heading || '';
    root.querySelector('[data-cr-notice]')?.remove();
    track.replaceChildren();
    if (!items.length) {
      if (root.dataset.editor === 'true') root.append(element('p', 'cr-notice', '请在 DecoAdmin 配置本店的在售车型、素材和四星/五星评论，然后启用展示。'));
      else root.hidden = true;
      return;
    }
    root.hidden = false;
    root.dataset.loaded = 'true';
    let looping = items.length > 1, canDrag = false;
    const content = [...items];
    for (let groupIndex = 0; groupIndex < (looping ? 3 : 1); groupIndex++) {
      const group = element('div', 'cr-group');
      if (groupIndex !== 0) group.setAttribute('aria-hidden', 'true');
      content.forEach((item, index) => {
        const copy = groupIndex !== 0 || index >= items.length;
        const node = card(item, data, root, copy, index);
        if (copy) node.setAttribute('aria-hidden', 'true'); group.append(node);
      });
      track.append(group);
    }
    const events = new AbortController();
    const listen = (target, name, handler, options = {}) => target.addEventListener(name, handler, {...options, signal: events.signal});
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    let width = 0, offset = 0, velocity = 0, frame = 0, previous = 0, pointer = null;
    let hover = false, focus = false, keyboardFocus = false, visible = true, suppressed = false, resumeAt = 0, destroyed = false;
    const autoplay = root.dataset.autoplay === 'true';
    const speed = Math.min(50, Math.max(10, Number(root.dataset.speed) || 20));
    const render = () => {
      if (looping && width > 0) offset = ((offset % width) + width) % width;
      else offset = Math.max(0, Math.min(offset, width - viewport.clientWidth));
      track.style.transform = `translate3d(${-offset}px,0,0)`;
    };
    const tick = (now) => {
      frame = 0;
      const dt = previous ? Math.min(40, now - previous) : 16.67; previous = now;
      if (!pointer) {
        offset += velocity * dt;
        velocity *= Math.pow(0.94, dt / 16.67);
        if (Math.abs(velocity) < .008 || reduced.matches) velocity = 0;
        if (autoplay && looping && visible && !hover && !focus && !document.hidden && !reduced.matches && now >= resumeAt) offset += speed * dt / 1000;
      }
      render();
      if (!destroyed && (Math.abs(velocity) > 0 || (autoplay && looping && visible && !document.hidden && !reduced.matches))) frame = requestAnimationFrame(tick);
    };
    const animate = () => { if (!frame && !destroyed) { previous = 0; frame = requestAnimationFrame(tick); } };
    const measure = () => {
      width = track.firstElementChild.getBoundingClientRect().width;
      const cardWidth = track.firstElementChild.firstElementChild.getBoundingClientRect().width;
      canDrag = width > viewport.clientWidth;
      looping = items.length > 1 && width >= viewport.clientWidth + cardWidth;
      [...track.children].forEach((group, index) => { group.hidden = index > 0 && !looping; });
      if (!looping) velocity = 0;
      render(); animate();
    };
    const resize = new ResizeObserver(measure); resize.observe(viewport); measure();
    const intersection = new IntersectionObserver(entries => { visible = entries[0].isIntersecting; if (visible) animate(); }); intersection.observe(root);
    listen(viewport, 'pointerdown', event => {
      if (!canDrag || (event.pointerType === 'mouse' && event.button !== 0)) return;
      keyboardFocus = false;
      velocity = 0; suppressed = false;
      pointer = {id:event.pointerId, x:event.clientX, y:event.clientY, last:event.clientX, time:performance.now(), moved:false};
      resumeAt = performance.now() + 5000;
    });
    listen(viewport, 'pointermove', event => {
      if (!pointer || pointer.id !== event.pointerId) return;
      const dx = event.clientX - pointer.x, dy = event.clientY - pointer.y;
      if (!pointer.moved && Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 7) { pointer = null; return; }
      if (!pointer.moved && Math.abs(dx) < 5) return;
      if (!pointer.moved) { pointer.moved = true; viewport.setPointerCapture(event.pointerId); viewport.classList.add('cr-dragging'); }
      event.preventDefault();
      const now = performance.now(), delta = pointer.last - event.clientX, dt = Math.max(8, now - pointer.time);
      offset += delta; velocity = .65 * velocity + .35 * Math.max(-3, Math.min(3, delta / dt));
      pointer.last = event.clientX; pointer.time = now; render();
    });
    const release = event => {
      if (!pointer || pointer.id !== event.pointerId) return;
      const moved = pointer.moved;
      if (performance.now() - pointer.time > 100 || event.type === 'pointercancel') velocity = 0;
      pointer = null; suppressed = moved; viewport.classList.remove('cr-dragging');
      if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
      resumeAt = performance.now() + 4000; animate();
    };
    listen(viewport, 'pointerup', release); listen(viewport, 'pointercancel', release); listen(viewport, 'lostpointercapture', release);
    listen(viewport, 'click', event => { if (suppressed) { event.preventDefault(); event.stopPropagation(); suppressed = false; } }, {capture:true});
    listen(viewport, 'dragstart', event => event.preventDefault());
    listen(viewport, 'wheel', event => {
      const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.shiftKey ? event.deltaY : 0;
      if (!delta || !canDrag) return;
      event.preventDefault(); velocity = 0; offset += delta; resumeAt = performance.now() + 4000; render();
    }, {passive:false});
    listen(viewport, 'keydown', event => {
      if (!['ArrowLeft','ArrowRight'].includes(event.key) || !canDrag) return;
      event.preventDefault(); const step = track.firstElementChild.firstElementChild.getBoundingClientRect().width + 16;
      const direction = event.key === 'ArrowRight' ? 1 : -1;
      if (reduced.matches) { offset += direction * step; render(); }
      else { velocity = direction * step * .0037; animate(); }
      resumeAt = performance.now() + 5000;
    });
    listen(root, 'mouseenter', () => { hover = true; }); listen(root, 'mouseleave', () => { hover = false; animate(); });
    listen(root, 'focusin', event => {
      focus = true; velocity = 0;
      const active = event.target.closest('.cr-card');
      if (active && keyboardFocus) { offset = looping && width > 0 ? active.offsetLeft % width : active.offsetLeft; render(); }
    });
    listen(root, 'focusout', event => { focus = root.contains(event.relatedTarget); if (!focus) animate(); });
    listen(document, 'pointerdown', () => { keyboardFocus = false; }, {capture:true});
    listen(document, 'keydown', event => { if (event.key === 'Tab') keyboardFocus = true; }, {capture:true});
    listen(document, 'visibilitychange', () => { velocity = 0; if (!document.hidden) animate(); });
    listen(reduced, 'change', () => { velocity = 0; animate(); });
    const instance = {destroy() { destroyed = true; cancelAnimationFrame(frame); events.abort(); resize.disconnect(); intersection.disconnect(); instances.delete(root); }};
    instances.set(root, instance); return instance;
  }
  async function load(root) {
    if (instances.has(root) || loading.has(root) || !root.dataset.feedUrl) return;
    pending.get(root)?.disconnect(); pending.delete(root);
    const controller = new AbortController(); loading.set(root, controller);
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const url = new URL(root.dataset.feedUrl, location.origin);
      if (url.origin !== location.origin) return;
      const visitor = visitorId(url.pathname); if (visitor) url.searchParams.set('visitor', visitor);
      const response = await fetch(url.href, {headers:{Accept:'application/json'}, cache:'no-store', signal:controller.signal});
      const result = await response.json();
      if (!response.ok || !result.data) throw new Error('Unavailable');
      mount(root, result.data);
    } catch {
      const notice = root.querySelector('[data-cr-notice]');
      if (notice) notice.textContent = '请先连接 Community Reviews，并在 DecoAdmin 启用此店铺的买家秀。';
      else root.hidden = true;
    } finally { clearTimeout(timeout); loading.delete(root); }
  }
  function scan(scope = document) {
    scope.querySelectorAll('[data-community-reviews]').forEach(root => {
      if (instances.has(root) || pending.has(root) || loading.has(root)) return;
      if (root.dataset.editor === 'true' || !('IntersectionObserver' in window)) { load(root); return; }
      const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) load(root);
      }, {rootMargin:'400px 0px'});
      pending.set(root, observer); observer.observe(root);
    });
  }
  window.CommunityReviews = {mount, load};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan(), {once:true}); else scan();
  document.addEventListener('shopify:section:load', event => scan(event.target));
  document.addEventListener('shopify:section:unload', event => event.target.querySelectorAll('[data-community-reviews]').forEach(root => {
    pending.get(root)?.disconnect(); pending.delete(root); loading.get(root)?.abort(); instances.get(root)?.destroy();
  }));
})();
