<?php

namespace App\Http\Controllers;

use App\Exceptions\CodexOAuthException;
use App\Services\Codex\CodexOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodexOAuthTokenController extends Controller
{
    public function token(Request $request, CodexOAuthService $oauth): JsonResponse
    {
        try {
            $payload = match ($request->string('grant_type')->toString()) {
                'authorization_code' => $oauth->exchangeAuthorizationCode($request->all()),
                'refresh_token' => $oauth->refreshAccessToken($request->all()),
                default => throw new CodexOAuthException('unsupported_grant_type', '不支持的授权类型。'),
            };

            return response()->json($payload, 200, $this->noStoreHeaders());
        } catch (CodexOAuthException $exception) {
            return response()->json([
                'error' => $exception->oauthError,
                'error_description' => $exception->getMessage(),
            ], $exception->status, $this->noStoreHeaders());
        }
    }

    public function revoke(Request $request, CodexOAuthService $oauth): JsonResponse
    {
        $values = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'client_id' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $oauth->revoke($values['token'], $values['client_id'] ?? null);
        } catch (CodexOAuthException) {
            // RFC 7009 requires a successful response even when the token is already invalid.
        }

        return response()->json([], 200, $this->noStoreHeaders());
    }

    /** @return array<string, string> */
    private function noStoreHeaders(): array
    {
        return ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];
    }
}
