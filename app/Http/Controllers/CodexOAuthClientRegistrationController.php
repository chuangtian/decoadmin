<?php

namespace App\Http\Controllers;

use App\Exceptions\CodexOAuthException;
use App\Services\Codex\CodexOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodexOAuthClientRegistrationController extends Controller
{
    public function __invoke(Request $request, CodexOAuthService $oauth): JsonResponse
    {
        $values = $request->validate([
            'client_name' => ['required', 'string', 'max:160'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:5'],
            'redirect_uris.*' => ['required', 'string', 'max:1024', 'distinct'],
            'grant_types' => ['nullable', 'array'],
            'grant_types.*' => ['string', 'in:authorization_code,refresh_token'],
            'response_types' => ['nullable', 'array'],
            'response_types.*' => ['string', 'in:code'],
            'token_endpoint_auth_method' => ['nullable', 'in:none'],
        ]);

        try {
            $registered = $oauth->registerClient($values['client_name'], $values['redirect_uris']);
        } catch (CodexOAuthException $exception) {
            return response()->json([
                'error' => $exception->oauthError,
                'error_description' => $exception->getMessage(),
            ], $exception->status);
        }
        $client = $registered['client'];

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => $client->response_types,
            'token_endpoint_auth_method' => $client->token_endpoint_auth_method,
        ], $registered['created'] ? 201 : 200, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
