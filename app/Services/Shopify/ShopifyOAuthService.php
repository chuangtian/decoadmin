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
    public function begin(Organization $organization, User $user, string $name, string $shopDomain, ?Store $store = null): array
    {
        $this->ensureConfigured();
        $domain = $this->normalizeShopDomain($shopDomain);
        $app = $this->configuredApp();
        $redirectUri = $this->redirectUri();
        $scopes = config('shopify.requested_scopes', []);
        $plainState = Str::random(64);

        [$store, $stateRecord] = DB::transaction(function () use ($organization, $user, $name, $domain, $store, $app, $redirectUri, $scopes, $plainState): array {
            if ($store) {
                if ($store->organization_id !== $organization->getKey()) {
                    throw ValidationException::withMessages(['shop_domain' => '该店铺不属于当前组织。']);
                }

                $store->forceFill(['name' => $name, 'shopify_domain' => $domain, 'status' => 'pending'])->save();
            } else {
                $existing = Store::withTrashed()->where('shopify_domain', $domain)->first();

                if ($existing) {
                    throw ValidationException::withMessages(['shop_domain' => '该 Shopify 店铺已存在，请从店铺详情页重新连接。']);
                }

                $store = $organization->stores()->create([
                    'name' => $name,
                    'shopify_domain' => $domain,
                    'status' => 'pending',
                    'created_by' => $user->getKey(),
                ]);
                $store->members()->attach($user, [
                    'status' => 'active',
                    'invited_by' => $user->getKey(),
                    'joined_at' => now(),
                ]);
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

            return [$store, $stateRecord];
        });

        $query = http_build_query([
            'client_id' => config('shopify.client_id'),
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

    /** @param array<string, mixed> $query */
    public function complete(array $query, ?string $stateCookie): Store
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

    /**
     * @return array{access_token: string, scope?: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}
     */
    private function exchangeCode(OAuthState $state, string $code): array
    {
        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post("https://{$state->shop_domain}/admin/oauth/access_token", [
                'client_id' => config('shopify.client_id'),
                'client_secret' => config('shopify.client_secret'),
                'code' => $code,
            ]);

        $payload = $response->json();

        if ($response->failed() || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
            throw new ShopifyOAuthException('Shopify 访问令牌交换失败。');
        }

        return $payload;
    }
}
