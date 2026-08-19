<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\StorefrontEventIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class StorefrontEventController extends Controller
{
    public function __invoke(Request $request, Store $store, StorefrontEventIngestionService $events): JsonResponse
    {
        try {
            $result = $events->ingest($store, $request->getContent());
        } catch (InvalidArgumentException $exception) {
            return $this->response(['accepted' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->response([
            'accepted' => true,
            'duplicate' => ! $result['created'],
        ], $result['created'] ? 202 : 200);
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status): JsonResponse
    {
        return response()->json($payload, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }
}
