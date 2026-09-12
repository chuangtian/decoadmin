import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

class Node {
  children = []; dataset = {}; attrs = {}; events = {}; style = { setProperty() {} };
  textContent = '';
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(key, value) { this.attrs[key] = value; }
  removeAttribute(key) { delete this.attrs[key]; }
  addEventListener(key, handler) { this.events[key] = handler; }
  get childElementCount() { return this.children.length; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
}
const content = node => [node.textContent, ...node.children.map(content)].join(' ');
const source = fs.readFileSync(new URL('../frontend/storefront.js', import.meta.url), 'utf8');

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
