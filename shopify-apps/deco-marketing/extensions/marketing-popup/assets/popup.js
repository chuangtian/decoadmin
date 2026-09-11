(() => {
  if (customElements.get('deco-marketing-popup')) return;
  class MarketingPopup extends HTMLElement {
    async connectedCallback() {
      if (window.Shopify?.shop !== 'macfox-test-app.myshopify.com' || this.ready) return;
      this.ready = true;
      this.base = this.dataset.base;
      try {
        if (sessionStorage.getItem('deco-marketing-dismissed')) return;
        const response = await fetch(`${this.base}/popup`, {credentials:'same-origin'});
        if (!response.ok) return;
        const config = await response.json();
        if (!config.enabled || !this.isConnected) return;
        this.visitor = sessionStorage.getItem('deco-marketing-visitor') || crypto.randomUUID();
        sessionStorage.setItem('deco-marketing-visitor',this.visitor);
        this.innerHTML = '<dialog aria-labelledby="deco-marketing-heading"><button type="button" class="dm-close" aria-label="Close">×</button><h2 id="deco-marketing-heading"></h2><p class="dm-body"></p><form><label>Email address<input type="email" name="email" maxlength="254" autocomplete="email" required></label><label class="dm-consent"><input type="checkbox" name="consent" required>I agree to receive marketing emails. I can unsubscribe at any time.</label><button type="submit" class="dm-submit">Subscribe</button><p class="dm-result" role="status" aria-live="polite"></p></form></dialog>';
        this.querySelector('h2').textContent = config.heading;
        this.querySelector('.dm-body').textContent = config.body;
        this.dialog = this.querySelector('dialog');
        this.querySelector('.dm-close').addEventListener('click',()=>this.close());
        this.dialog.addEventListener('cancel',(event)=>{event.preventDefault();this.close();});
        this.querySelector('form').addEventListener('submit',(event)=>this.submit(event));
        this.dialog.showModal();
        this.event('impression');
      } catch { /* Storefront remains usable when the optional app is unavailable. */ }
    }
    event(type) {
      // Analytics events require the storefront's analytics consent.
      if (!window.Shopify?.customerPrivacy?.analyticsProcessingAllowed?.()) return;
      try {
      this.visitor = localStorage.getItem('deco-marketing-visitor') || this.visitor;
      localStorage.setItem('deco-marketing-visitor', this.visitor);
      fetch(`${this.base}/event`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({visitor:this.visitor,type})}).catch(()=>{});
      } catch { /* Optional analytics must not interrupt subscription. */ }
    }
    close(){this.event('close');sessionStorage.setItem('deco-marketing-dismissed','1');this.dialog.close();}
    async submit(event) {
      event.preventDefault();const form=event.target,button=form.querySelector('button'),result=form.querySelector('.dm-result');
      if (!form.reportValidity() || button.disabled) return;
      this.event('submit');
      button.disabled=true;result.textContent='Submitting…';
      try {
        const r=await fetch(`${this.base}/subscribe`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email:form.elements.email.value,consent:form.elements.consent.checked,visitor:this.visitor})});
        if (!r.ok) throw new Error();
        result.textContent='Thank you. Your subscription request has been received.';
        sessionStorage.setItem('deco-marketing-dismissed','1');form.querySelector('input[type=email]').disabled=true;
      } catch {result.textContent='We could not submit your request. Please try again.';button.disabled=false;}
    }
  }
  customElements.define('deco-marketing-popup',MarketingPopup);
})();
