<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
use App\Domain\ReferralAffiliate\Services\ShopifyAffiliateAppService;
use App\Exceptions\AffiliateException;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyAffiliateAppController extends Controller
{
    public function home(Request $request)
    {
        abort_unless(app()->environment(['local', 'testing', 'test', 'staging']), 403);
        $shop = (string) $request->query('shop');
        abort_unless($shop === AffiliateShopGuard::TEST_SHOP, 403);

        // This public shell contains no store data. Bootstrap verifies the identity token.
        return response(view()->file(base_path('shopify-apps/deco-referral/resources/home.blade.php'), [
            'clientId' => (string) config('referral.active.client_id'),
            'shop' => $shop,
        ]))->header('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://{$shop};")
            ->header('Cache-Control', 'no-store');
    }

    public function management(Request $request, ShopifyAffiliateAppService $service)
    {
        try {
            $store = $service->managementStore($request->user(), (string) $request->query('shop'));
        } catch (AffiliateException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()->route('affiliate.index', [$store->organization_id, $store->id]);
    }

    public function bootstrap(Request $request, ShopifyAffiliateAppService $service): JsonResponse
    {
        $shop = (string) $request->attributes->get('shopify_shop');
        abort_unless($shop === AffiliateShopGuard::TEST_SHOP, 403);
        $store = Store::query()->where('shopify_domain', $shop)->firstOrFail();
        try {
            return response()->json(['data' => $service->bootstrap($store, (string) $request->attributes->get('shopify_id_token'))]);
        } catch (AffiliateException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->statusCode);
        }
    }
}
