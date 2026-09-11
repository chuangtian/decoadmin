# Deco Marketing pilot

- Only macfox-test-app.myshopify.com in local/testing/test/staging may be mutated or tested.
- Macfox Bike, MACFOX CRM, crm.macfoxbike.com and production are strictly read-only reference systems.
- Own app identity, tokens, queue and delivery configuration. Never borrow referral, CRM or commerce credentials.
- Keep durable per-message idempotency. Unknown provider outcomes must never be blindly retried after the provider deduplication window.
- Default to preview, all flows disabled, scheduler disabled. Preview is not delivery.
- Real test mail is restricted to the explicitly authorized recipient allowlist.
