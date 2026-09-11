<?php

namespace DecoMarketing\Controllers;

use App\Models\Store;
use DecoMarketing\Models\Event;
use DecoMarketing\Models\Settings;
use DecoMarketing\Services\Events;
use DecoMarketing\Services\Guard;
use DecoMarketing\Services\Shopify;
use DecoMarketing\Services\Tokens;
use Illuminate\Http\Request;

class AppController
{
    public function home(Request $request)
    {
        abort_unless(app()->environment(['local', 'testing', 'test', 'staging']) && config('marketing.environment') !== 'production' && in_array($request->query('shop'), app(Guard::class)->domains(), true), 403);

        return response()->view('marketing::app', ['clientId' => config('marketing.active.client_id'), 'shop' => $request->query('shop')])
            ->header('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://'.$request->query('shop').';')->header('Cache-Control', 'no-store');
    }

    public function bootstrap(Request $request)
    {
        abort_unless(in_array($request->attributes->get('shopify_shop'), app(Guard::class)->domains(), true), 403);
        $store = Store::where('shopify_domain', $request->attributes->get('shopify_shop'))->sole();
        app(Tokens::class)->bootstrap($store, $request->attributes->get('shopify_id_token'));

        return response()->json(['management_url' => route('marketing.index', [$store->organization_id, $store->id])]);
    }

    public function webhook(Request $request)
    {
        $secret = config('marketing.active.client_secret');
        abort_unless($secret && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), (string) $request->header('X-Shopify-Hmac-Sha256')), 401);
        abort_unless(in_array($request->header('X-Shopify-Shop-Domain'), app(Guard::class)->domains(), true), 403);
        $store = Store::where('shopify_domain', $request->header('X-Shopify-Shop-Domain'))->sole();
        app(Guard::class)->store($store);
        $installation = app(Shopify::class)->installation($store);
        if (! $installation) {
            return response()->json(['ignored' => true]);
        }
        $topic = $request->header('X-Shopify-Topic');
        $id = $request->header('X-Shopify-Webhook-Id');
        abort_unless(is_string($id) && strlen($id) <= 120 && $id !== '', 422);
        $body = $request->json()->all();
        if ($topic === 'app/uninstalled') {
            $installation->update(['status' => 'uninstalled', 'access_token_encrypted' => null, 'refresh_token_encrypted' => null, 'uninstalled_at' => now()]);
            Settings::forStore($store)->update(['enabled' => false]);

            return response()->json(['ok' => true]);
        }
        if (! in_array($topic, ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'refunds/create', 'customers/create', 'customers/update', 'customers/delete', 'inventory_levels/update', 'fulfillments/create', 'fulfillments/update'])) {
            return response()->json(['ignored' => true]);
        }
        $payload = ['resource_id' => $body['admin_graphql_api_id'] ?? null, 'numeric_id' => isset($body['id']) ? (string) $body['id'] : null, 'order_id' => isset($body['order_id']) ? (string) $body['order_id'] : null];
        $event = Event::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'provider' => 'shopify', 'event_key' => $id], ['type' => $topic, 'payload_encrypted' => $payload]);

        return response()->json(['received' => true, 'duplicate' => ! $event->wasRecentlyCreated]);
    }

    public function resend(Request $request)
    {
        $secret = (string) config('marketing.resend_webhook_secret');
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        abort_unless($key !== false && $key !== '' && ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300 && strlen($id) <= 120 && $id !== '', 401);
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));
        $valid = false;
        foreach (explode(' ', (string) $request->header('svix-signature')) as $signature) {
            if (str_starts_with($signature, 'v1,') && hash_equals($expected, substr($signature, 3))) {
                $valid = true;
            }
        }
        abort_unless($valid, 401);
        $store = Store::where('shopify_domain', Guard::SHOP)->firstOrFail();
        app(Guard::class)->store($store);
        $body = $request->json()->all();
        $payload = ['provider_id' => data_get($body, 'data.email_id'), 'occurred_at' => $body['created_at'] ?? null, 'delivery' => data_get($body, 'data.tags.delivery')];
        $event = Event::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'provider' => 'resend', 'event_key' => $id], ['type' => mb_substr((string) ($body['type'] ?? ''), 0, 80), 'payload_encrypted' => $payload]);
        app(Events::class)->resend($store, $event);

        return response()->json(['received' => true]);
    }
}
