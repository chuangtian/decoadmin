<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentDiscountException;
use App\Services\StudentDiscount\StudentDiscountWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyStudentDiscountWebhookController extends Controller
{
    public function __invoke(Request $request, StudentDiscountWebhookService $webhooks): JsonResponse
    {
        try {
            $result = $webhooks->receive($request->getContent(), [
                'hmac' => $request->header('X-Shopify-Hmac-Sha256'),
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'topic' => $request->header('X-Shopify-Topic'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
                'api_version' => $request->header('X-Shopify-API-Version'),
                'triggered_at' => $request->header('X-Shopify-Triggered-At'),
            ]);
        } catch (StudentDiscountException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => ! $result['created'],
            'webhook_id' => $result['event']->webhook_id,
        ], $result['created'] ? 202 : 200);
    }
}
