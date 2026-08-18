<?php

namespace App\Http\Controllers;

use App\Services\DeploymentHealthService;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends Controller
{
    public function __invoke(DeploymentHealthService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json([
            ...$result,
            'timestamp' => now()->toIso8601String(),
        ], $result['status'] === 'ok' ? 200 : 503);
    }
}
