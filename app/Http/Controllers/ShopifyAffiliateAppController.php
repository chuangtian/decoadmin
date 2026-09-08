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
