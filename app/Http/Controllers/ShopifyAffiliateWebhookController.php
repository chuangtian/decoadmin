<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Services\AffiliateWebhookService;
use App\Exceptions\AffiliateException;
use Illuminate\Http\Request;

class ShopifyAffiliateWebhookController extends Controller
{
    public function __invoke(Request $request, AffiliateWebhookService $service)
    {
        try {
            $event = $service->receive($request->getContent(), ['hmac' => $request->header('X-Shopify-Hmac-Sha256'),
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'), 'topic' => $request->header('X-Shopify-Topic'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain')]);

            return response()->json(['accepted' => true, 'duplicate' => ! $event->wasRecentlyCreated], 200);
        } catch (AffiliateException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->statusCode);
        }
    }
}
