<?php

namespace App\Services\Codex;

use App\Exceptions\CodexOAuthException;
use App\Models\AuditLog;
use App\Models\CodexApiToken;
use App\Models\CodexOAuthAuthorizationCode;
use App\Models\CodexOAuthClient;
use App\Models\CodexOAuthRefreshToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CodexOAuthService
{
    public function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function resource(): string
    {
        return $this->issuer().'/'.ltrim((string) config('codex.mcp_path', '/mcp/decoadmin'), '/');
    }

    /** @return list<string> */
    public function supportedScopes(): array
    {
        return CodexApiToken::SUPPORTED_ABILITIES;
    }

    /** @return array<string, mixed> */
    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => $this->supportedScopes(),
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => $this->issuer().'/codex-tokens',
        ];
    }

    /** @return array<string, mixed> */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->issuer().'/oauth/authorize',
            'token_endpoint' => $this->issuer().'/oauth/token',
            'registration_endpoint' => $this->issuer().'/oauth/register',
            'revocation_endpoint' => $this->issuer().'/oauth/revoke',
            'authorization_response_iss_parameter_supported' => true,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => $this->supportedScopes(),
        ];
    }

    /** @param list<string> $redirectUris
     * @return array{client: CodexOAuthClient, created: bool}
     */
    public function registerClient(string $clientName, array $redirectUris): array
    {
        $redirectUris = array_values(array_unique($redirectUris));
        sort($redirectUris);
        foreach ($redirectUris as $redirectUri) {
            $this->assertAllowedRedirectUri($redirectUri);
        }

        $fingerprint = hash('sha256', json_encode([
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $existing = CodexOAuthClient::query()->where('registration_fingerprint', $fingerprint)->first();
        if ($existing) {
            return ['client' => $existing, 'created' => false];
        }

        $client = CodexOAuthClient::query()->create([
            'client_id' => 'dco_'.Str::random(48),
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'registration_fingerprint' => $fingerprint,
        ]);

        return ['client' => $client, 'created' => true];
    }

    /** @param array<string, mixed> $input
     * @return array{client: CodexOAuthClient, abilities: list<string>, organizations: Collection<int, Organization>, request: array<string, string>}
     */
    public function authorizationContext(User $user, array $input): array
    {
        $request = $this->validateAuthorizationRequest($input);
        $organizations = $this->organizationsFor($user);
        if ($organizations->isEmpty()) {
            throw new CodexOAuthException('access_denied', '当前账号没有可授权的启用组织。', 403);
        }

        return [
            'client' => $request['client'],
            'abilities' => $request['abilities'],
            'organizations' => $organizations,
            'request' => $request['request'],
        ];
    }

    /** @param array<string, mixed> $input
     * @return array{redirect_uri: string, parameters: array<string, string>}
     */
    public function denyAuthorization(array $input): array
    {
        $request = $this->validateAuthorizationRequest($input);

        return [
            'redirect_uri' => $request['request']['redirect_uri'],
            'parameters' => [
                'error' => 'access_denied',
                'error_description' => '用户取消了 DecoAdmin 插件授权。',
                'state' => $request['request']['state'],
                'iss' => $this->issuer(),
            ],
        ];
    }

    /** @param array<string, mixed> $input
     * @return array{redirect_uri: string, parameters: array<string, string>}
     */
    public function approveAuthorization(User $user, int $organizationId, array $input): array
    {
        $request = $this->validateAuthorizationRequest($input);
        $organization = $this->organizationsFor($user)->firstWhere('id', $organizationId);
        if (! $organization instanceof Organization) {
            throw new CodexOAuthException('access_denied', '当前账号无权为所选组织授权。', 403);
        }

        $plainCode = 'dcoac_'.Str::random(72);
        CodexOAuthAuthorizationCode::query()->create([
            'codex_oauth_client_id' => $request['client']->getKey(),
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'code_hash' => hash('sha256', $plainCode),
            'redirect_uri' => $request['request']['redirect_uri'],
            'abilities' => $request['abilities'],
            'code_challenge' => $request['request']['code_challenge'],
            'resource' => $request['request']['resource'],
            'expires_at' => now()->addMinutes((int) config('codex.authorization_code_ttl_minutes', 5)),
        ]);
        AuditLog::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'action' => 'codex_oauth_authorized',
            'subject_type' => CodexOAuthClient::class,
            'subject_id' => $request['client']->getKey(),
            'metadata' => [
                'client_id' => $request['client']->client_id,
                'client_name' => $request['client']->client_name,
                'abilities' => $request['abilities'],
                'resource' => $request['request']['resource'],
            ],
        ]);

        return [
            'redirect_uri' => $request['request']['redirect_uri'],
            'parameters' => [
                'code' => $plainCode,
                'state' => $request['request']['state'],
                'iss' => $this->issuer(),
            ],
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(array $input): array
    {
        $client = $this->client((string) ($input['client_id'] ?? ''));
        $code = (string) ($input['code'] ?? '');
        $redirectUri = (string) ($input['redirect_uri'] ?? '');
        $resource = (string) ($input['resource'] ?? '');
        $verifier = (string) ($input['code_verifier'] ?? '');
        if ($code === '' || $redirectUri === '' || $resource !== $this->resource() || ! $client->acceptsRedirectUri($redirectUri)) {
            throw new CodexOAuthException('invalid_grant', '授权码、回调地址或资源无效。');
        }
        if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            throw new CodexOAuthException('invalid_grant', 'PKCE 校验参数无效。');
        }

        return DB::transaction(function () use ($client, $code, $redirectUri, $resource, $verifier): array {
            $authorizationCode = CodexOAuthAuthorizationCode::query()
                ->with(['user', 'organization'])
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();
            if (! $authorizationCode
                || ! $authorizationCode->isUsable()
                || (int) $authorizationCode->codex_oauth_client_id !== (int) $client->getKey()
                || ! hash_equals($authorizationCode->redirect_uri, $redirectUri)
                || ! hash_equals($authorizationCode->resource, $resource)
                || ! hash_equals($authorizationCode->code_challenge, $this->pkceChallenge($verifier))) {
                throw new CodexOAuthException('invalid_grant', '授权码已失效或 PKCE 校验失败。');
            }

            $this->assertPrincipalActive($authorizationCode->user, $authorizationCode->organization);
            $authorizationCode->forceFill(['used_at' => now()])->save();
            $refresh = CodexOAuthRefreshToken::issue(
                $client,
                $authorizationCode->user,
                $authorizationCode->organization,
                $authorizationCode->abilities,
                $resource,
            );

            return $this->issueAccessToken($refresh['token'], $refresh['plain_text_token'], 'codex_oauth_access_issued');
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function refreshAccessToken(array $input): array
    {
        $client = $this->client((string) ($input['client_id'] ?? ''));
        $resource = (string) ($input['resource'] ?? '');
        $plainRefreshToken = (string) ($input['refresh_token'] ?? '');
        if ($resource !== $this->resource()) {
            throw new CodexOAuthException('invalid_target', '资源参数无效。');
        }
        $resolved = CodexOAuthRefreshToken::fromPlainText($plainRefreshToken);
        if (! $resolved) {
            throw new CodexOAuthException('invalid_grant', '刷新令牌无效。');
        }

        return DB::transaction(function () use ($resolved, $client, $plainRefreshToken, $resource): array {
            $refreshToken = CodexOAuthRefreshToken::query()
                ->with(['user', 'organization'])
                ->whereKey($resolved->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if (! $refreshToken->isUsable()
                || (int) $refreshToken->codex_oauth_client_id !== (int) $client->getKey()
                || ! hash_equals($refreshToken->resource, $resource)) {
                throw new CodexOAuthException('invalid_grant', '刷新令牌已失效。');
            }

            $this->assertPrincipalActive($refreshToken->user, $refreshToken->organization);
            $refreshToken->forceFill(['last_used_at' => now()])->save();

            return $this->issueAccessToken($refreshToken, $plainRefreshToken, 'codex_oauth_access_refreshed');
        });
    }

    public function revoke(string $plainToken, ?string $clientId): void
    {
        $client = $clientId ? $this->client($clientId) : null;
        $refreshToken = CodexOAuthRefreshToken::fromPlainText($plainToken);
        $accessToken = $refreshToken ? null : CodexApiToken::fromPlainText($plainToken);
        if (! $refreshToken && $accessToken?->oauth_refresh_token_id) {
            $refreshToken = CodexOAuthRefreshToken::query()->find($accessToken->oauth_refresh_token_id);
        }
        if (! $refreshToken || ($client && (int) $refreshToken->codex_oauth_client_id !== (int) $client->getKey())) {
            return;
        }

        DB::transaction(function () use ($refreshToken): void {
            $locked = CodexOAuthRefreshToken::query()->whereKey($refreshToken->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->revoked_at !== null) {
                return;
            }
            $locked->forceFill(['revoked_at' => now()])->save();
            $locked->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            AuditLog::query()->create([
                'organization_id' => $locked->organization_id,
                'user_id' => $locked->user_id,
                'action' => 'codex_oauth_connection_revoked',
                'subject_type' => CodexOAuthRefreshToken::class,
                'subject_id' => $locked->getKey(),
                'metadata' => ['client_id' => $locked->client?->client_id],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function issueAccessToken(
        CodexOAuthRefreshToken $refreshToken,
        string $plainRefreshToken,
        string $auditAction,
    ): array {
        $refreshToken->loadMissing(['client', 'user', 'organization']);
        $ttl = (int) config('codex.access_token_ttl_seconds', 3600);
        $issued = CodexApiToken::issueOrRotateOAuth($refreshToken, now()->addSeconds($ttl));
        AuditLog::query()->create([
            'organization_id' => $refreshToken->organization_id,
            'user_id' => $refreshToken->user_id,
            'action' => $auditAction,
            'subject_type' => CodexApiToken::class,
            'subject_id' => $issued['token']->getKey(),
            'metadata' => [
                'client_id' => $refreshToken->client->client_id,
                'abilities' => $refreshToken->abilities,
                'access_expires_at' => $issued['token']->expires_at->toIso8601String(),
            ],
        ]);

        return [
            'access_token' => $issued['plain_text_token'],
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'refresh_token' => $plainRefreshToken,
            'scope' => implode(' ', $refreshToken->abilities),
        ];
    }

    /** @param array<string, mixed> $input
     * @return array{client: CodexOAuthClient, abilities: list<string>, request: array<string, string>}
     */
    private function validateAuthorizationRequest(array $input): array
    {
        $client = $this->client((string) ($input['client_id'] ?? ''));
        $request = [
            'client_id' => $client->client_id,
            'redirect_uri' => (string) ($input['redirect_uri'] ?? ''),
            'response_type' => (string) ($input['response_type'] ?? ''),
            'scope' => trim((string) ($input['scope'] ?? '')),
            'state' => (string) ($input['state'] ?? ''),
            'code_challenge' => (string) ($input['code_challenge'] ?? ''),
            'code_challenge_method' => (string) ($input['code_challenge_method'] ?? ''),
            'resource' => (string) ($input['resource'] ?? ''),
        ];
        if ($request['response_type'] !== 'code') {
            throw new CodexOAuthException('unsupported_response_type', '仅支持授权码流程。');
        }
        if (! $client->acceptsRedirectUri($request['redirect_uri'])) {
            throw new CodexOAuthException('invalid_request', '回调地址未注册。');
        }
        if ($request['state'] === '' || strlen($request['state']) > 2048) {
            throw new CodexOAuthException('invalid_request', '缺少或无效的 state。');
        }
        if ($request['code_challenge_method'] !== 'S256'
            || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $request['code_challenge'])) {
            throw new CodexOAuthException('invalid_request', '必须使用 S256 PKCE。');
        }
        if ($request['resource'] !== $this->resource()) {
            throw new CodexOAuthException('invalid_target', '请求的 MCP 资源无效。');
        }

        return ['client' => $client, 'abilities' => $this->abilities($request['scope']), 'request' => $request];
    }

    /** @return list<string> */
    private function abilities(string $scope): array
    {
        $requested = $scope === '' ? CodexApiToken::DEFAULT_ABILITIES : (preg_split('/\s+/', $scope) ?: []);
        $unknown = array_diff($requested, $this->supportedScopes());
        if ($unknown !== []) {
            throw new CodexOAuthException('invalid_scope', '请求包含不支持的插件能力。');
        }

        return array_values(array_filter(
            $this->supportedScopes(),
            fn (string $ability): bool => in_array($ability, $requested, true),
        ));
    }

    private function client(string $clientId): CodexOAuthClient
    {
        $client = $clientId === '' ? null : CodexOAuthClient::query()->where('client_id', $clientId)->first();
        if (! $client) {
            throw new CodexOAuthException('invalid_client', 'OAuth 客户端无效。', 401);
        }

        return $client;
    }

    /** @return Collection<int, Organization> */
    private function organizationsFor(User $user): Collection
    {
        $query = $user->isSuperAdmin() ? Organization::query() : $user->organizations();

        return $query->where('organizations.status', 'active')->orderBy('organizations.name')->get();
    }

    private function assertAllowedRedirectUri(string $redirectUri): void
    {
        $parts = parse_url($redirectUri);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $allowedHosts = (array) config('codex.allowed_redirect_hosts', ['chatgpt.com']);
        $allowedHost = collect($allowedHosts)->contains(fn (string $allowed): bool => $host === $allowed || str_ends_with($host, '.'.$allowed));
        $allowedPath = $path === '/connector_platform_oauth_redirect' || str_starts_with($path, '/connector/oauth/');
        if (($parts['scheme'] ?? null) !== 'https' || ! $allowedHost || ! $allowedPath || isset($parts['fragment'])) {
            throw new CodexOAuthException('invalid_redirect_uri', '仅允许 ChatGPT/Codex 的 HTTPS OAuth 回调地址。');
        }
    }

    private function assertPrincipalActive(?User $user, ?Organization $organization): void
    {
        if (! $user
            || ! $organization
            || $user->trashed()
            || $organization->trashed()
            || $user->status !== 'active'
            || ! $user->hasVerifiedEmail()
            || $organization->status !== 'active'
            || (! $user->isSuperAdmin() && ! $user->organizations()->whereKey($organization->getKey())->exists())) {
            throw new CodexOAuthException('invalid_grant', '用户或组织授权已失效。');
        }
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
