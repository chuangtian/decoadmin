<?php

namespace DecoReviews\Controllers;

use App\Models\Store;
use DecoReviews\Models\Installation;
use DecoReviews\Models\Invitation;
use DecoReviews\Models\Reward;
use DecoReviews\Models\RewardDelivery;
use DecoReviews\Models\Settings;
use DecoReviews\Services\ShopifyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppController
{
    public function home(Request $request)
    {
        $shop = strtolower((string) $request->query('shop'));
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop), 422);

        return response()->view('deco-reviews::app', ['shop' => $shop, 'clientId' => config('deco_reviews.active.client_id')])
            ->header('Content-Security-Policy', "frame-ancestors https://{$shop} https://admin.shopify.com;")->header('Cache-Control', 'no-store');
    }

    public function bootstrap(Request $request, ShopifyClient $client)
    {
        $store = Store::where('shopify_domain', $request->attributes->get('shopify_shop'))->where('status', 'active')->whereHas('organization', fn ($q) => $q->where('status', 'active'))->firstOrFail();
        $client->connect($store, $request->attributes->get('shopify_id_token'));

        return response()->json(['data' => ['management_url' => route('deco-reviews.index', [$store->organization_id, $store->id])]]);
    }

    public function webhook(Request $request)
    {
        $secret = (string) config('deco_reviews.active.client_secret');
        abort_unless($secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), (string) $request->header('X-Shopify-Hmac-Sha256')), 401);
        $store = Store::where('shopify_domain', strtolower((string) $request->header('X-Shopify-Shop-Domain')))->first();
        if (! $store) {
            return response('', 200);
        }
        if ($request->header('X-Shopify-Topic') === 'app/uninstalled') {
            DB::transaction(function () use ($store) {
                Installation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('environment', config('deco_reviews.environment'))->update(['access_token' => null]);
                Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'cancelled', 'due_at' => null]);
                Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('status', 'scheduled')
                    ->update(['status' => 'cancelled', 'due_at' => null]);
                RewardDelivery::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('status', 'scheduled')
                    ->update(['status' => 'cancelled', 'due_at' => null, 'error_code' => 'APP_UNINSTALLED']);
                $settings = Settings::where('organization_id', $store->organization_id)->where('store_id', $store->id)->first();
                if ($settings) {
                    $settings->update(['values' => array_replace($settings->values, ['enabled' => false, 'invites_enabled' => false, 'rewards_enabled' => false])]);
                }
            });
        }

        return response('', 200);
    }
}
