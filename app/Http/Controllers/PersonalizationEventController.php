<?php

namespace App\Http\Controllers;

use App\Exceptions\PersonalizationException;
use App\Models\PersonalizationEventSource;
use App\Services\Personalization\PersonalizationEventIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalizationEventController extends Controller
{
    public function __invoke(
        Request $request,
        PersonalizationEventSource $source,
        PersonalizationEventIngestionService $events,
    ): JsonResponse {
        try {
            $result = $events->ingest($source, $request->getContent());
        } catch (PersonalizationException $exception) {
            return $this->response(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        } catch (\Throwable) {
            return $this->response(['error' => [
                'code' => 'PERSONALIZATION_EVENT_UNAVAILABLE',
                'message' => '推荐事件暂时无法接收。',
            ]], 503);
        }

        return $this->response([
            'data' => [
                'accepted' => true,
                'duplicate' => ! $result['created'],
            ],
        ], $result['created'] ? 202 : 200);
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status): JsonResponse
    {
        return response()->json($payload, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
