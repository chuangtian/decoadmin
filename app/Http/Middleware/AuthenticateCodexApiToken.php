<?php

namespace App\Http\Middleware;

use App\Models\CodexApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCodexApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = CodexApiToken::fromPlainText($request->bearerToken());
        if (! $token) {
            return $this->unauthorized('缺少或无效的 DecoAdmin Codex 访问令牌。');
        }

        $token->loadMissing(['user', 'organization']);

        if (! $token->isUsable()
            || ! $token->user
            || ! $token->organization
            || $token->user->trashed()
            || ! $token->user->hasVerifiedEmail()
            || $token->organization->trashed()
            || $token->organization->status !== 'active'
            || (! $token->user->isSuperAdmin()
                && ! $token->user->organizations()->whereKey($token->organization_id)->exists())) {
            return $this->unauthorized('DecoAdmin Codex 访问令牌已失效或无权访问该组织。');
        }

        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('codex_api_token', $token);
        $request->attributes->set('codex_organization', $token->organization);

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        $response = response()->json([
            'error' => ['code' => 'codex_unauthenticated', 'message' => $message],
        ], 401);

        if (request()->is(ltrim((string) config('codex.mcp_path'), '/'))) {
            $resourceMetadata = rtrim((string) config('app.url'), '/').'/.well-known/oauth-protected-resource';
            $scopes = implode(' ', CodexApiToken::SUPPORTED_ABILITIES);
            $response->headers->set(
                'WWW-Authenticate',
                'Bearer resource_metadata="'.$resourceMetadata.'", scope="'.$scopes.'"',
            );
        }

        return $response;
    }
}
