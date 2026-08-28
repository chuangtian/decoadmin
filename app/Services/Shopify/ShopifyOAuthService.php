<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyOAuthException;
use App\Models\App;
use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopifyOAuthService
{
    public const STATE_COOKIE = 'shopify_oauth_state';

    public function __construct(
        private HttpFactory $http,
        private ShopifyOAuthHmacValidator $hmacValidator,
        private ShopifyConnectionService $connectionService,
    ) {}

    /**
     * @return array{authorization_url: string, state: string, state_record: OAuthState, store: Store}
     */
    public function begin(Organization $organization, User $user, Store $store): array
    {
        $this->ensureConfigured();
        $app = $this->configuredApp();

        return $this->beginForApp($organization, $user, $store, $app);
    }

    /**
     * @return array{authorization_url: string, state: string, state_record: OAuthState, store: Store}
     */
    public function beginForApp(Organization $organization, User $user, Store $store, App $app): array
    {
        $this->ensureAppConfigured($app);
        if ($store->organization_id !== $organization->getKey()) {
            throw ValidationException::withMessages(['shop_domain' => '该店铺不属于当前组织。']);
        }

        $domain = $this->normalizeShopDomain($store->shopify_domain);
        $redirectUri = $this->redirectUriForApp($app);
        $scopes = array_values(is_array($app->scopes) ? $app->scopes : []);
        $plainState = Str::random(64);

        $stateRecord = DB::transaction(function () use ($organization, $user, $domain, $store, $app, $redirectUri, $scopes, $plainState): OAuthState {
            $lockedStore = Store::query()->lockForUpdate()->findOrFail($store->getKey());

            if ($lockedStore->organization_id !== $organization->getKey()
                || ! hash_equals($domain, $this->normalizeShopDomain($lockedStore->shopify_domain))) {
                throw ValidationException::withMessages(['shop_domain' => '店铺身份校验失败，请刷新后重试。']);
            }

            $stateRecord = OAuthState::query()->create([
                'state_hash' => hash('sha256', $plainState),
                'organization_id' => $organization->getKey(),
                'store_id' => $store->getKey(),
                'app_id' => $app->getKey(),
                'user_id' => $user->getKey(),
                'shop_domain' => $domain,
                'redirect_uri' => $redirectUri,
                'intended_url' => route('stores.show', $store),
                'scopes' => $scopes,
                'expires_at' => now()->addMinutes((int) config('shopify.state_ttl_minutes', 10)),
            ]);

            return $stateRecord;
        });

        $query = http_build_query([
            'client_id' => $app->client_id,
            'scope' => implode(',', $scopes),
            'redirect_uri' => $redirectUri,
            'state' => $plainState,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => "https://{$domain}/admin/oauth/authorize?{$query}",
            'state' => $plainState,
            'state_record' => $stateRecord,
            'store' => $store,
        ];
    }

    public function syncConfiguredApp(): ?App
    {
        if (! filled(config('shopify.client_id')) || ! filled(config('shopify.client_secret'))) {
            return null;
        }

        return $this->configuredApp();
    }

    /** @param array<string, mixed> $query */
    public function complete(array $query, ?string $stateCookie, ?string $expectedAppHandle = null): Store
    {
        $plainState = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';

        if ($plainState === '' || $code === '' || ! is_string($stateCookie) || ! hash_equals($plainState, $stateCookie)) {
            throw new ShopifyOAuthException('Shopify OAuth 状态令牌验证失败。');
        }

        $state = OAuthState::query()
            ->with('app')
            ->where('state_hash', hash('sha256', $plainState))
            ->first();

        if (! $state || ! $state->app || ! is_string($state->app->client_secret_encrypted)) {
            throw new ShopifyOAuthException('Shopify OAuth 状态令牌无效。');
        }

        if ($expectedAppHandle !== null && ! hash_equals($expectedAppHandle, (string) $state->app->handle)) {
            throw new ShopifyOAuthException('Shopify OAuth 应用身份不匹配。');
        }

        $domain = $this->normalizeShopDomain((string) ($query['shop'] ?? ''));

        if (! hash_equals($state->shop_domain, $domain)
            || ! $this->hmacValidator->validate($query, $state->app->client_secret_encrypted)) {
            throw new ShopifyOAuthException('Shopify OAuth 回调验证失败。');
        }

        $state = DB::transaction(function () use ($state): OAuthState {
            $lockedState = OAuthState::query()->lockForUpdate()->find($state->getKey());

            if (! $lockedState || $lockedState->consumed_at || $lockedState->expires_at->isPast()) {
                throw new ShopifyOAuthException('Shopify OAuth 状态令牌已过期或已使用。');
            }

            $lockedState->forceFill(['consumed_at' => now()])->save();

            return $lockedState;
        });

        $token = $this->exchangeCode($state, $code);
        $this->assertGrantedScopes($state, (string) ($token['scope'] ?? ''));

        return $this->connectionService->connect($state, $token);
    }

    public function normalizeShopDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim(explode('/', $domain, 2)[0], '.');

        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain)) {
            throw ValidationException::withMessages([
                'shop_domain' => '请输入有效的 myshopify.com 店铺域名，例如 example.myshopify.com。',
            ]);
        }

        return $domain;
    }

    private function configuredApp(): App
    {
        $app = App::withTrashed()->firstOrNew(['handle' => (string) config('shopify.app_handle')]);
        $app->fill([
            'organization_id' => null,
            'name' => (string) config('shopify.app_name'),
            'client_id' => (string) config('shopify.client_id'),
            'client_secret_encrypted' => (string) config('shopify.client_secret'),
            'distribution' => 'custom',
            'status' => 'active',
            'scopes' => config('shopify.requested_scopes', []),
            'redirect_uris' => [$this->redirectUri()],
            'webhook_api_version' => (string) config('shopify.api_version'),
        ]);
        $app->deleted_at = null;
        $app->save();

        return $app;
    }

    private function redirectUri(): string
    {
        $redirectUri = (string) (config('shopify.redirect_uri') ?: rtrim((string) config('shopify.app_url'), '/').'/shopify/oauth/callback');

        return $this->validatedRedirectUri($redirectUri);
    }

    private function redirectUriForApp(App $app): string
    {
        $redirectUris = is_array($app->redirect_uris) ? $app->redirect_uris : [];
        $redirectUri = collect($redirectUris)
            ->first(fn (mixed $uri): bool => is_string($uri) && trim($uri) !== '');

        if (! is_string($redirectUri)) {
            throw new ShopifyOAuthException('Shopify 应用的 OAuth 回调地址尚未配置。');
        }

        return $this->validatedRedirectUri($redirectUri);
    }

    private function validatedRedirectUri(string $redirectUri): string
    {
        $parts = parse_url($redirectUri);
        $isLocal = in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true);

        if (! filter_var($redirectUri, FILTER_VALIDATE_URL)
            || ! in_array($parts['scheme'] ?? null, $isLocal ? ['http', 'https'] : ['https'], true)) {
            throw new ShopifyOAuthException('Shopify OAuth 回调地址配置无效；远程地址必须使用 HTTPS。');
        }

        return $redirectUri;
    }

    private function ensureConfigured(): void
    {
        if (! is_string(config('shopify.client_id')) || config('shopify.client_id') === ''
            || ! is_string(config('shopify.client_secret')) || config('shopify.client_secret') === '') {
            throw new ShopifyOAuthException('Shopify 应用的 Client ID 或 Client Secret 尚未配置。');
        }
    }

    private function ensureAppConfigured(App $app): void
    {
        if (! is_string($app->client_id) || trim($app->client_id) === ''
            || ! is_string($app->client_secret_encrypted) || $app->client_secret_encrypted === '') {
            throw new ShopifyOAuthException('Shopify 应用的 Client ID 或 Client Secret 尚未配置。');
        }
    }

    private function assertGrantedScopes(OAuthState $state, string $scopeList): void
    {
        $granted = array_values(array_filter(array_map('trim', explode(',', $scopeList))));
        $required = is_array($state->scopes) ? $state->scopes : [];
        $missing = collect($required)
            ->filter(fn (mixed $scope): bool => is_string($scope) && ! $this->scopeIsGranted($scope, $granted))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new ShopifyOAuthException('Shopify 应用尚未授予完整权限，请重新安装并确认授权。');
        }
    }

    /** @param list<string> $granted */
    private function scopeIsGranted(string $required, array $granted): bool
    {
        if (in_array($required, $granted, true)) {
            return true;
        }

        return str_starts_with($required, 'read_')
            && in_array('write_'.substr($required, 5), $granted, true);
    }

    /**
     * @return array{access_token: string, scope?: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}
     */
    private function exchangeCode(OAuthState $state, string $code): array
    {
        $app = $state->app()->first();
        if (! $app) {
            throw new ShopifyOAuthException('Shopify OAuth 应用身份无效。');
        }
        $this->ensureAppConfigured($app);

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post("https://{$state->shop_domain}/admin/oauth/access_token", [
                'client_id' => $app->client_id,
                'client_secret' => $app->client_secret_encrypted,
                'code' => $code,
            ]);

        $payload = $response->json();

        if ($response->failed() || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
            throw new ShopifyOAuthException('Shopify 访问令牌交换失败。');
        }

        return $payload;
    }
}
