<?php

namespace App\Http\Controllers;

use App\Services\Codex\CodexOAuthService;
use Illuminate\Http\JsonResponse;

class CodexOAuthMetadataController extends Controller
{
    public function protectedResource(CodexOAuthService $oauth): JsonResponse
    {
        return response()->json($oauth->protectedResourceMetadata());
    }

    public function authorizationServer(CodexOAuthService $oauth): JsonResponse
    {
        return response()->json($oauth->authorizationServerMetadata());
    }
}
