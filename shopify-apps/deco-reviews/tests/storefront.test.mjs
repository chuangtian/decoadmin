import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

class Node {
  children = []; dataset = {}; attrs = {}; events = {}; style = { setProperty() {} };
  textContent = '';
  append(...nodes) { nodes.forEach(node => { if (node && typeof node === 'object') node.parentNode = this; }); this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  setAttribute(key, value) { this.attrs[key] = value; }
  removeAttribute(key) { delete this.attrs[key]; }
  addEventListener(key, handler) { this.events[key] = handler; }
  removeEventListener(key) { delete this.events[key]; }
  remove() { this.parentNode && (this.parentNode.children = this.parentNode.children.filter(node => node !== this)); this.removed = true; }
  scrollIntoView(options) { this.scrolled = options; }
  showModal() { this.open = true; }
  close() { this.open = false; this.events.close?.(); }
  get childElementCount() { return this.children.length; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  focus() { this.focused = true; }
}
const content = node => [node.textContent, ...node.children.map(content)].join(' ');
const source = fs.readFileSync(new URL('../frontend/storefront.js', import.meta.url), 'utf8');
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
    vm.runInNewContext(source, { document, URL, AbortController, Intl, location: { origin: 'https://example.test' },
      fetch: async () => ({ ok: true, json: async () => ({ data: [{ uuid: 'demo', rating: 4,
        body: 'Synthetic review', verified_source: verification, media: [] }] }) }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(list.children.length, 1, status.textContent);
    assert.equal(content(list).includes('Verified purchase'), verification === 'order');
    assert.equal(content(list).includes('Source not verified'), verification !== 'order');
  });
}

test('saved-form preview cannot send a review request', async () => {
  const root = new Node(); const form = new Node(); const status = new Node(); const submit = new Node();
  root.dataset.formPreview = 'true';
  root.querySelector = key => key === '[data-dr-form]' ? form : null;
  form.querySelector = key => key === '[data-dr-form-status]' ? status : submit;
  let requests = 0;
  const document = { readyState: 'complete', querySelectorAll: () => [root], addEventListener() {} };
  vm.runInNewContext(source, { document, URL, AbortController, Intl, location: { origin: 'https://example.test' },
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
    vm.runInNewContext(source, { document, URL, AbortController, Intl, FormData: class {}, location: { origin: 'https://example.test' },
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

const runWidget = async ({ mode = 'reviews', data = [], reject = false } = {}) => {
  const root = new Node(); const list = new Node(); const status = new Node(); const pagination = new Node();
  const controls = new Node(); const previous = new Node(); const next = new Node(); const position = new Node();
  const open = new Node(); const close = new Node(); const panel = new Node();
  root.dataset = { feedUrl: '/feed', mode, itemPosition: 'Item {current} of {total}' };
  const nodes = { '[data-dr-list]': list, '[data-dr-status]': status, '[data-dr-pagination]': pagination,
    '[data-dr-carousel-controls]': controls, '[data-dr-carousel-status]': position,
    '[data-dr-carousel-previous]': previous, '[data-dr-carousel-next]': next,
    '[data-dr-open]': open, '[data-dr-close]': close, '[data-dr-panel]': panel };
  root.querySelector = key => nodes[key] ?? null;
  const documentEvents = {};
  const document = { readyState: 'complete', documentElement: { lang: 'en' }, createElement: tag => {
    const node = new Node(); node.tag = tag; return node;
  }, querySelectorAll: () => [root], addEventListener: (key, handler) => { documentEvents[key] = handler; } };
  vm.runInNewContext(source, { document, URL, AbortController, Intl, location: { origin: 'https://example.test' },
    matchMedia: () => ({ matches: true }), fetch: async () => {
      if (reject) throw new Error('offline');
      return { ok: true, json: async () => ({ data }) };
    } });
  await new Promise(resolve => setImmediate(resolve));
  return { root, list, status, pagination, controls, previous, next, position, open, close, panel, documentEvents };
};

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
