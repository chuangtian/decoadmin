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
        $plainTextToken = $request->bearerToken();
        $credentials = $this->credentials($plainTextToken);
        if ($credentials === null) {
            return $this->unauthorized('缺少或无效的 DecoAdmin Codex 访问令牌。');
        }

        [$uuid, $secret] = $credentials;
        $token = CodexApiToken::query()
            ->with(['user', 'organization'])
            ->where('uuid', $uuid)
            ->first();

        if (! $token
            || ! $token->isUsable()
            || ! hash_equals($token->token_hash, hash('sha256', $secret))
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

    /** @return array{0: string, 1: string}|null */
    private function credentials(?string $plainTextToken): ?array
    {
        if (! is_string($plainTextToken) || ! str_starts_with($plainTextToken, 'dca_')) {
            return null;
        }

        $parts = explode('.', substr($plainTextToken, 4), 2);
        if (count($parts) !== 2 || ! preg_match('/^[0-9a-f-]{36}$/i', $parts[0]) || strlen($parts[1]) < 40) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'codex_unauthenticated', 'message' => $message],
        ], 401);
    }
}
