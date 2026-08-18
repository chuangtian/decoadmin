<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyWebhookException;
use App\Models\App;
use App\Services\Shopify\ShopifyWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, App $app, ShopifyWebhookService $webhooks): JsonResponse
    {
        try {
            $result = $webhooks->receive($app, $request->getContent(), [
                'hmac' => $request->header('X-Shopify-Hmac-Sha256'),
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'topic' => $request->header('X-Shopify-Topic'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
                'api_version' => $request->header('X-Shopify-API-Version'),
                'triggered_at' => $request->header('X-Shopify-Triggered-At'),
            ]);
        } catch (ShopifyWebhookException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => ! $result['created'],
            'webhook_id' => $result['event']->webhook_id,
        ], $result['created'] ? 202 : 200);
    }
}
