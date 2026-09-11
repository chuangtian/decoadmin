<?php

namespace CommunityReviews\Controllers;

use App\Models\Store;
use CommunityReviews\Models\Installation;
use CommunityReviews\Models\Settings;
use CommunityReviews\Services\ShopifyClient;
use Illuminate\Http\Request;

class AppController
{
    public function home(Request $request)
    {
        $shop = strtolower((string) $request->query('shop'));
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop), 422);

        return response()->view('community-reviews::app', ['shop' => $shop, 'clientId' => config('community_reviews.active.client_id')])
            ->header('Content-Security-Policy', "frame-ancestors https://{$shop} https://admin.shopify.com;");
    }

    public function bootstrap(Request $request, ShopifyClient $shopify)
    {
        $store = Store::query()->where('shopify_domain', $request->attributes->get('shopify_shop'))->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->firstOrFail();
        $shopify->bootstrap($store, $request->attributes->get('shopify_id_token'));

        return response()->json(['data' => ['management_url' => route('community-reviews.index', [$store->organization_id, $store->id])]]);
    }

    public function webhook(Request $request)
    {
        $secret = (string) config('community_reviews.active.client_secret');
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256');
        abort_unless($secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $hmac), 401);
        $shop = (string) $request->header('X-Shopify-Shop-Domain');
        $store = Store::query()->where('shopify_domain', $shop)->first();
        if ($store && in_array($request->header('X-Shopify-Topic'), ['products/update', 'products/delete', 'app/uninstalled'], true)) {
            app(\CommunityReviews\Services\FeedCache::class)->invalidate((int) $store->organization_id, (int) $store->id);
        }
        if ($store && $request->header('X-Shopify-Topic') === 'app/uninstalled') {
            Installation::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('environment', config('community_reviews.environment'))
                ->update(['access_token_encrypted' => null]);
            Settings::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->update(['enabled' => false]);
        }

        return response('', 200);
    }
}
