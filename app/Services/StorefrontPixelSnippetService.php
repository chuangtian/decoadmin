<?php

namespace App\Services;

use App\Models\Store;

class StorefrontPixelSnippetService
{
    public function endpoint(Store $store): string
    {
        return rtrim((string) config('shopify.app_url'), '/')
            .'/shopify/pixels/'.$store->analytics_ingest_key;
    }

    public function snippet(Store $store): string
    {
        $endpoint = json_encode($this->endpoint($store), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $events = json_encode(StorefrontEventIngestionService::EVENTS, JSON_UNESCAPED_SLASHES);

        $template = <<<'JAVASCRIPT'
// DecoAdmin Customer Events collector
const decoEndpoint = __ENDPOINT__;
const decoEvents = new Set(__EVENTS__);
let decoSessionId = await browser.sessionStorage.getItem('decoadmin_session_id');

if (!decoSessionId) {
  decoSessionId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  await browser.sessionStorage.setItem('decoadmin_session_id', decoSessionId);
}

analytics.subscribe('all_standard_events', (event) => {
  if (!decoEvents.has(event.name)) return;

  const location = event.context?.document?.location;
  const referrer = event.context?.document?.referrer;
  const address = event.data?.checkout?.shippingAddress;
  const searchQuery = event.name === 'search_submitted'
    ? event.data?.searchResult?.query ?? null
    : null;

  fetch(decoEndpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
    keepalive: true,
    body: JSON.stringify({
      event_id: event.id,
      event_name: event.name,
      client_id: event.clientId,
      session_id: decoSessionId,
      occurred_at: event.timestamp,
      path: location?.pathname ?? null,
      referrer_host: (() => { try { return referrer ? new URL(referrer).hostname : null; } catch (_) { return null; } })(),
      country_code: address?.countryCode ?? null,
      region_code: address?.provinceCode ?? null,
      city: address?.city ?? null,
      search_query: searchQuery,
    }),
  });
});
JAVASCRIPT;

        return str_replace(['__ENDPOINT__', '__EVENTS__'], [$endpoint, $events], $template);
    }
}
