import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

class Node {
  children = []; dataset = {}; attrs = {}; events = {}; listeners = {}; style = { setProperty() {} };
  textContent = '';
  append(...nodes) { nodes.forEach(node => { if (node && typeof node === 'object') node.parentNode = this; }); this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  setAttribute(key, value) { this.attrs[key] = value; }
  getAttribute(key) { return this.attrs[key]; }
  removeAttribute(key) { delete this.attrs[key]; }
  addEventListener(key, handler) { (this.listeners[key] ||= []).push(handler); this.events[key] = (...args) => { const results = this.listeners[key].slice().map(listener => listener(...args)); return results.length === 1 ? results[0] : Promise.all(results); }; }
  removeEventListener(key, handler) { this.listeners[key] = (this.listeners[key] || []).filter(listener => listener !== handler); }
  dispatchEvent(event) { event.target ||= this; this.events[event.type]?.(event); }
  remove() { this.parentNode && (this.parentNode.children = this.parentNode.children.filter(node => node !== this)); this.removed = true; }
  scrollIntoView(options) { this.scrolled = options; }
  showModal() { this.open = true; }
  close() { this.open = false; this.events.close?.(); }
  contains(node) { return node === this || Boolean(node && this.children.some(child => child?.contains?.(node))); }
  get childElementCount() { return this.children.length; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  focus() { this.focused = true; }
}
class CustomEvent { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } }
const content = node => [node.textContent, ...node.children.map(content)].join(' ');
const source = fs.readFileSync(new URL('../frontend/storefront.js', import.meta.url), 'utf8');
const widgetSource = source.split('/* DECO_REVIEWS_WIDGETS */')[1];
const organicSource = fs.readFileSync(new URL('../frontend/organic.js', import.meta.url), 'utf8');

test('organic form is shown only for an enabled same-origin product form', () => {
  const form = new Node(); form.hidden = true; form.action = '';
  const root = new Node();
  root.dataset = { showForm: 'true', mode: 'reviews', productId: '123', formAction: '/apps/deco-reviews/reviews' };
  root.querySelector = key => key === '[data-dr-organic-form]' ? form : null;
  const window = {};
  const document = { createElement: () => new Node(), createTextNode: value => ({ textContent: value }), querySelectorAll: () => [] };
  vm.runInNewContext(organicSource, { document, window, URL, location: { origin: 'https://shop.example' } });
  window.DecoReviewsOrganic.render(root, { settings: { organic_collection_enabled: true }, form: {
    version: 'v1', allow_photos: false, allow_video: false, questions: [],
  } });
  assert.equal(form.hidden, false);
  assert.equal(form.action, 'https://shop.example/apps/deco-reviews/reviews');
  assert.equal(root.dataset.allowPhotos, 'false');
  assert.equal(root.dataset.allowVideo, 'false');

  root.dataset.formAction = 'https://attacker.example/reviews';
  window.DecoReviewsOrganic.render(root, { settings: { organic_collection_enabled: true }, form: { questions: [] } });
  assert.equal(form.hidden, true);
});

for (const verification of ['none', 'import', 'manual', 'order', null]) {
  test(`purchase disclosure is truthful for ${verification}`, async () => {
    const root = new Node(); const list = new Node(); const status = new Node();
    root.dataset.feedUrl = '/feed';
    root.querySelector = key => ({ '[data-dr-list]': list, '[data-dr-status]': status })[key] ?? null;
    const document = { readyState: 'complete', documentElement: { lang: 'en' },
      createElement: () => new Node(), querySelectorAll: () => [root], addEventListener() {} };
    vm.runInNewContext(source, { document, CustomEvent, URL, AbortController, Intl, location: { origin: 'https://example.test' },
      fetch: async () => ({ ok: true, json: async () => ({ data: [{ uuid: 'demo', rating: 4,
        body: 'Synthetic review', source: verification === 'import' ? 'import' : 'organic', verified_source: verification, media: [] }] }) }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(list.children.length, 1, status.textContent);
    assert.equal(content(list).includes('Verified purchase'), verification === 'order');
    assert.equal(content(list).includes('Verified by store'), verification === 'manual');
    assert.equal(content(list).includes('Imported review'), verification === 'import');
    assert.equal(content(list).includes('Source not verified'), verification === 'none' || verification === null);
  });
}

test('saved-form preview cannot send a review request', async () => {
  const root = new Node(); const form = new Node(); const status = new Node(); const submit = new Node();
  root.dataset.formPreview = 'true';
  root.querySelector = key => key === '[data-dr-form]' ? form : null;
  form.querySelector = key => key === '[data-dr-form-status]' ? status : submit;
  let requests = 0;
  const document = { readyState: 'complete', querySelectorAll: () => [root], addEventListener() {} };
  vm.runInNewContext(source, { document, CustomEvent, URL, AbortController, Intl, location: { origin: 'https://example.test' },
    fetch: async () => { requests++; throw Error('Preview must never fetch'); } });
  await form.events.submit({ preventDefault() {} });
  assert.equal(requests, 0);
  assert.match(status.textContent, /No review was submitted or saved/);
});

for (const succeeds of [true, false]) {
  test(`buyer form prevents parallel submits and ${succeeds ? 'locks after success' : 'permits retry after failure'}`, async () => {
    const root = new Node(); const form = new Node(); const status = new Node(); const submit = new Node();
    root.querySelector = key => key === '[data-dr-form]' ? form : null;
    form.querySelector = key => key === '[data-dr-form-status]' ? status : submit;
    form.elements = { namedItem: () => ({ files: [] }) };
    form.reset = () => { form.wasReset = true; };
    let requests = 0; let resolveRequest;
    const document = { readyState: 'complete', querySelectorAll: () => [root], addEventListener() {} };
    vm.runInNewContext(source, { document, CustomEvent, URL, AbortController, Intl, FormData: class {}, location: { origin: 'https://example.test' },
      fetch: () => { requests++; return new Promise(resolve => { resolveRequest = resolve; }); } });
    const event = { preventDefault() {} };
    const first = form.events.submit(event);
    await form.events.submit(event);
    assert.equal(requests, 1);
    resolveRequest({ ok: succeeds, json: async () => ({ message: 'Validation failed' }) });
    await first;
    assert.equal(submit.disabled, succeeds);
    assert.equal(Boolean(status.focused), succeeds);
    assert.equal(Boolean(form.wasReset), succeeds);
    const retry = form.events.submit(event);
    assert.equal(requests, succeeds ? 1 : 2);
    if (!succeeds) resolveRequest({ ok: false, json: async () => ({}) });
    await retry;
  });
}

const runWidgets = async (configs) => {
  const widgets = configs.map(({ mode = 'reviews', data = [], reject = false, reduced = true, autoplay = false, openState = false, blockId = crypto.randomUUID() }) => {
    const root = new Node(); const list = new Node(); const status = new Node(); const pagination = new Node();
    const controls = new Node(); const previous = new Node(); const next = new Node(); const position = new Node();
    const open = new Node(); const close = new Node(); const panel = new Node(); const galleryStatus = new Node();
    root.dataset = { feedUrl: `/feed/${blockId}`, mode, blockId, open: String(openState), autoplay: String(autoplay), autoplaySeconds: '3', itemPosition: 'Item {current} of {total}' };
    const nodes = { '[data-dr-list]': list, '[data-dr-status]': status, '[data-dr-pagination]': pagination,
      '[data-dr-carousel-controls]': controls, '[data-dr-carousel-status]': position, '[data-dr-gallery-status]': galleryStatus,
      '[data-dr-carousel-previous]': previous, '[data-dr-carousel-next]': next,
      '[data-dr-open]': open, '[data-dr-close]': close, '[data-dr-panel]': panel };
    root.querySelector = key => nodes[key] ?? null;
    return { root, list, status, pagination, controls, previous, next, position, open, close, panel, galleryStatus, data, reject, reduced };
  });
  const documentEvents = {};
  const document = { readyState: 'complete', documentElement: { lang: 'en' }, createElement: tag => {
    const node = new Node(); node.tag = tag; return node;
  }, hidden: false, querySelectorAll: () => widgets.map(widget => widget.root), addEventListener: (key, handler) => { const previous = documentEvents[key]; documentEvents[key] = event => { previous?.(event); handler(event); }; } };
  const timers = [];
  vm.runInNewContext(source, { document, CustomEvent, URL, AbortController, Intl, location: { origin: 'https://example.test' },
    matchMedia: () => ({ matches: widgets[0]?.reduced ?? true }),
    setInterval: (callback, delay) => { const timer = { callback, delay, active: true }; timers.push(timer); return timer; },
    clearInterval: timer => { if (timer) timer.active = false; },
    fetch: async url => { const widget = widgets.find(item => String(url).includes(item.root.dataset.blockId)); if (widget.reject) throw new Error('offline'); return { ok: true, json: async () => ({ data: widget.data }) }; } });
  await new Promise(resolve => setImmediate(resolve));
  widgets.forEach(widget => { widget.documentEvents = documentEvents; widget.document = document; widget.timers = timers; });
  return widgets;
};
const runWidget = async (config = {}) => (await runWidgets([config]))[0];

test('carousel controls and arrow keys move focus without reduced-motion animation', async () => {
  const widget = await runWidget({ mode: 'carousel', data: [
    { uuid: 'one', rating: 5, body: 'First', media: [] }, { uuid: 'two', rating: 4, body: 'Second', media: [] },
  ] });
  assert.equal(widget.controls.hidden, false);
  assert.equal(widget.previous.disabled, true);
  widget.next.events.click();
  assert.equal(widget.list.children[1].focused, true);
  assert.equal(widget.list.children[1].scrolled.behavior, 'auto');
  assert.equal(widget.next.disabled, true);
  let prevented = false;
  widget.list.events.keydown({ key: 'ArrowLeft', preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.match(widget.position.textContent, /1.*2/);
});

test('sidebar launcher manages focus and Escape restores it', async () => {
  const widget = await runWidget({ mode: 'sidebar' });
  assert.equal(widget.panel.hidden, true);
  assert.equal(widget.open.hidden, false);
  widget.open.events.click();
  assert.equal(widget.panel.hidden, false);
  assert.equal(widget.close.focused, true);
  widget.root.events.keydown({ key: 'Escape' });
  assert.equal(widget.panel.hidden, true);
  assert.equal(widget.open.focused, true);
});

test('gallery reports a truthful empty state when reviews have no media', async () => {
  const widget = await runWidget({ mode: 'gallery', data: [{ uuid: 'text', rating: 5, body: 'Text only', media: [] }] });
  assert.equal(widget.list.children.length, 0);
  assert.equal(widget.root.dataset.state, 'empty');
  assert.match(content(widget.status), /No reviews yet/);
});

test('feed failure clears stale UI and section teardown removes generated lightboxes', async () => {
  const failed = await runWidget({ reject: true });
  assert.equal(failed.root.dataset.state, 'error');
  assert.match(content(failed.status), /unavailable/);
  const loaded = await runWidget({ data: [{ uuid: 'media', rating: 5, body: 'Photo', media: [{ type: 'image', url: '/photo.jpg' }] }] });
  const dialog = loaded.root.children.find(node => node.tag === 'dialog');
  assert.ok(dialog);
  loaded.list.children[0].children[0].children[0].events.click();
  dialog.children[2].children[0].events.error();
  assert.match(content(dialog), /media is unavailable/);
  loaded.documentEvents['shopify:section:unload']({ target: { querySelectorAll: () => [loaded.root] } });
  assert.equal(dialog.removed, true);
});

test('carousel autoplay pauses for interaction, focus, visibility and reduced motion, then stops after manual use', async () => {
  const reviews = [1, 2, 3].map(number => ({ uuid: String(number), rating: 5, body: `Review ${number}`, media: [] }));
  const widget = await runWidget({ mode: 'carousel', data: reviews, autoplay: true, reduced: false });
  const timer = widget.timers[0];
  assert.equal(timer.delay, 3000);
  timer.callback();
  assert.match(widget.position.textContent, /2.*3/);
  widget.root.events.mouseenter(); timer.callback();
  assert.match(widget.position.textContent, /2.*3/);
  widget.root.events.mouseleave(); widget.root.events.focusin(); timer.callback();
  assert.match(widget.position.textContent, /2.*3/);
  widget.root.events.focusout({ relatedTarget: null }); timer.callback();
  assert.match(widget.position.textContent, /3.*3/);
  widget.document.hidden = true; timer.callback();
  assert.match(widget.position.textContent, /3.*3/);
  widget.document.hidden = false; timer.callback();
  assert.match(widget.position.textContent, /1.*3/);
  widget.next.events.click();
  assert.equal(timer.active, false);
  timer.callback();
  assert.match(widget.position.textContent, /2.*3/);
  const reduced = await runWidget({ mode: 'carousel', data: reviews, autoplay: true, reduced: true });
  assert.equal(reduced.timers.length, 0);
});

test('gallery thumbnails expose current state and support arrow, Home and End focus navigation', async () => {
  const widget = await runWidget({ mode: 'gallery', data: [{ uuid: 'gallery', rating: 5, body: 'Gallery', media: [
    { type: 'image', url: '/one.jpg' }, { type: 'image', url: '/two.jpg' }, { type: 'image', url: '/three.jpg' },
  ] }] });
  const thumbnails = widget.list.children;
  assert.equal(widget.list.attrs.role, 'listbox');
  assert.equal(thumbnails[0].attrs['aria-current'], 'true');
  assert.equal(thumbnails[0].attrs['aria-selected'], 'true');
  assert.equal(thumbnails[0].attrs.role, 'option');
  let prevented = false;
  widget.list.events.keydown({ key: 'ArrowRight', preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(thumbnails[1].focused, true);
  assert.equal(thumbnails[1].attrs['aria-selected'], 'true');
  assert.equal(thumbnails[0].attrs['aria-current'], undefined);
  assert.match(widget.galleryStatus.textContent, /2.*3/);
  widget.list.events.keydown({ key: 'End', preventDefault() {} });
  assert.equal(thumbnails[2].focused, true);
  widget.list.events.keydown({ key: 'Home', preventDefault() {} });
  assert.equal(thumbnails[0].focused, true);
  const dialog = widget.root.children.find(node => node.tag === 'dialog');
  thumbnails[1].events.click();
  assert.notEqual(dialog.open, true);
  widget.list.events.click({ target: thumbnails[1] });
  thumbnails[1].events.click();
  assert.equal(dialog.open, true);
});

test('video slider excludes photo-only reviews and exposes bounded controls and keyboard navigation', async () => {
  const widget = await runWidget({ mode: 'video', data: [
    { uuid: 'photo', rating: 5, body: 'Photo only', media: [{ type: 'image', url: '/photo.jpg' }] },
    { uuid: 'video-one', rating: 5, body: 'First video', media: [{ type: 'video', url: '/one.mp4' }] },
    { uuid: 'video-two', rating: 4, body: 'Second video', media: [{ type: 'video', url: '/two.mp4' }] },
  ] });
  assert.equal(widget.list.children.length, 2);
  assert.equal(widget.list.attrs.role, 'region');
  assert.equal(widget.list.attrs['aria-roledescription'], 'carousel');
  assert.equal(widget.controls.hidden, false);
  assert.equal(widget.previous.disabled, true);
  widget.list.events.keydown({ key: 'ArrowRight', preventDefault() {} });
  assert.equal(widget.list.children[1].focused, true);
  assert.equal(widget.next.disabled, true);
  assert.equal(widget.timers.length, 0);
});

test('video slider reports an empty state when no playable video review exists', async () => {
  const widget = await runWidget({ mode: 'video', data: [
    { uuid: 'photo', rating: 5, body: 'Photo only', media: [{ type: 'image', url: '/photo.jpg' }] },
  ] });
  assert.equal(widget.list.children.length, 0);
  assert.equal(widget.root.dataset.state, 'empty');
  assert.equal(widget.controls.hidden, true);
  assert.match(content(widget.status), /No reviews yet/);
});

test('multiple blocks keep independent controls and section reload does not duplicate listeners', async () => {
  const reviews = [1, 2].map(number => ({ uuid: String(number), rating: 5, body: `Review ${number}`, media: [] }));
  const [first, second] = await runWidgets([
    { mode: 'carousel', data: reviews, autoplay: true, reduced: false, blockId: 'first' },
    { mode: 'carousel', data: reviews, autoplay: true, reduced: false, blockId: 'second' },
  ]);
  first.next.events.click();
  assert.match(first.position.textContent, /2.*2/);
  assert.match(second.position.textContent, /1.*2/);
  first.documentEvents['shopify:section:unload']({ target: { querySelectorAll: () => [first.root] } });
  assert.equal(first.list.listeners.keydown.length, 0);
  first.documentEvents['shopify:section:load']({ target: { querySelectorAll: () => [first.root] } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(first.list.listeners.keydown.length, 1);
  assert.equal(second.list.listeners.keydown.length, 1);
});

test('repeated widget asset execution is guarded against duplicate listeners', () => {
  const root = new Node(); const list = new Node(); const controls = new Node(); const previous = new Node(); const next = new Node(); const position = new Node();
  list.append(new Node());
  root.dataset = { mode: 'carousel', autoplay: 'false', itemPosition: 'Item {current} of {total}' };
  root.querySelector = key => ({ '[data-dr-list]': list, '[data-dr-carousel-controls]': controls, '[data-dr-carousel-previous]': previous,
    '[data-dr-carousel-next]': next, '[data-dr-carousel-status]': position })[key] ?? null;
  const document = { readyState: 'complete', hidden: false, querySelectorAll: () => [root], addEventListener() {} };
  const context = vm.createContext({ document, matchMedia: () => ({ matches: false }) });
  vm.runInContext(widgetSource, context);
  vm.runInContext(widgetSource, context);
  assert.equal(list.listeners.keydown.length, 1);
  assert.equal(previous.listeners.click.length, 1);
});

test('launcher dismissal persists only in the current page runtime and remains block-scoped', async () => {
  const [first, second] = await runWidgets([
    { mode: 'floating', blockId: 'dismissed', openState: true },
    { mode: 'floating', blockId: 'other', openState: true },
  ]);
  assert.equal(first.panel.hidden, false);
  assert.equal(second.panel.hidden, false);
  first.close.events.click();
  first.documentEvents['shopify:section:unload']({ target: { querySelectorAll: () => [first.root] } });
  first.root.dataset.open = 'true';
  first.documentEvents['shopify:section:load']({ target: { querySelectorAll: () => [first.root] } });
  assert.equal(first.panel.hidden, true);
  assert.equal(second.panel.hidden, false);
  const newPage = await runWidget({ mode: 'floating', blockId: 'dismissed', openState: true });
  assert.equal(newPage.panel.hidden, false);
});
