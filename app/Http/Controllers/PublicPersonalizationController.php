<?php

namespace App\Http\Controllers;

use App\Exceptions\PersonalizationException;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\Store;
use App\Services\Personalization\PersonalizationRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicPersonalizationController extends Controller
{
    public function __construct(private PersonalizationRecommendationService $recommendations) {}

    public function recommendations(
        Request $request,
        PersonalizationRecommendationComponent $component,
    ): JsonResponse {
        $store = $request->attributes->get('personalization_store');
        if (! $store instanceof Store) {
            return response()->json(['error' => [
                'code' => 'STORE_NOT_AVAILABLE',
                'message' => '当前店铺未启用个性化推荐。',
            ]], 404);
        }

        $context = [
            'seed_product_id' => $request->query('seed_product_id'),
            'cart_product_ids' => $this->ids($request->query('cart_product_ids', [])),
            'recently_viewed_product_ids' => $this->ids($request->query('recently_viewed_product_ids', [])),
        ];
        try {
            $result = $this->recommendations->forComponent($store, $component, $context);
        } catch (PersonalizationException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        } catch (\Throwable) {
            return response()->json(['error' => [
                'code' => 'PERSONALIZATION_UNAVAILABLE',
                'message' => '个性化推荐暂时不可用，请稍后重试。',
            ]], 503);
        }

        return response()->json(['data' => $result], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    private function ids(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value)
            ? array_values(array_filter(array_map(
                fn (mixed $id): string => is_scalar($id) ? trim((string) $id) : '',
                $value,
            )))
            : [];
    }
}
