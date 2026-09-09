(() => {
  if (window.__decoReferralTracking) return;
  window.__decoReferralTracking = true;
  const key = 'deco_referral_v1';
  const url = new URL(window.location.href);
  let incoming = url.searchParams.get('deco_aff');
  let queue = Promise.resolve();
  let lastWritten = null;
  const valid = token => {
    try {
      if (typeof token !== 'string' || token.length > 2048 || !/^[\w-]+\.[\w-]+$/.test(token)) return false;
      const payload = JSON.parse(atob(token.split('.')[0].replace(/-/g, '+').replace(/_/g, '/')));
      return payload.v === 1 && Number.isInteger(payload.exp) && payload.exp * 1000 > Date.now();
    } catch { return false; }
  };
  const allowed = () => window.Shopify?.customerPrivacy?.marketingAllowed() === true;
  const read = () => {
    try { const value = JSON.parse(localStorage.getItem(key) || '{}'); return {first: valid(value.first) ? value.first : '', last: valid(value.last) ? value.last : ''}; }
    catch { return {first: '', last: ''}; }
  };
  const writeCart = attributes => {
    const body = JSON.stringify({attributes});
    queue = queue.catch(() => {}).then(async () => {
      if (body === lastWritten) return;
      const response = await fetch(`${window.Shopify?.routes?.root || '/'}cart/update.js`, {
        method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body,
        signal: AbortSignal.timeout(5000)
      });
      if (!response.ok) throw new Error('Cart tracking update failed');
      lastWritten = body;
    }).catch(() => {});
  };
  const synchronize = () => {
    if (!window.Shopify?.customerPrivacy) return;
    if (!allowed()) {
      try { localStorage.removeItem(key); } catch {}
      writeCart({deco_aff__: '', deco_aff_first__: '', deco_aff_last__: ''});
      return;
    }
    const saved = read();
    if (valid(incoming)) {
      saved.first ||= incoming;
      saved.last = incoming;
      incoming = null;
      try { localStorage.setItem(key, JSON.stringify(saved)); } catch {}
      url.searchParams.delete('deco_aff');
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
    if (saved.first || saved.last) writeCart({deco_aff__: saved.last, deco_aff_first__: saved.first, deco_aff_last__: saved.last});
  };
  document.addEventListener('visitorConsentCollected', synchronize);
  window.addEventListener('pageshow', synchronize);
  document.addEventListener('cart:updated', () => { lastWritten = null; synchronize(); });
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !allowed()) return;
    const action = new URL(form.action, location.href);
    if (action.origin !== location.origin || !/\/cart\/?$/.test(action.pathname)) return;
    const saved = read();
    for (const [name,value] of Object.entries({deco_aff__:saved.last,deco_aff_first__:saved.first,deco_aff_last__:saved.last})) {
      let field = form.querySelector(`input[name="attributes[${name}]"]`);
      if (!field) { field=document.createElement('input'); field.type='hidden'; field.name=`attributes[${name}]`; form.appendChild(field); }
      field.value=value;
    }
  }, true);
  const initialize = () => {
    if (window.Shopify?.customerPrivacy) synchronize();
    else window.Shopify?.loadFeatures?.([{name:'consent-tracking-api',version:'0.1'}], error => { if (!error) synchronize(); });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once:true});
  else initialize();
})();
