<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyOAuthHmacValidator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscountManagerOAuthService
{
    public const STATE_COOKIE = 'discount_manager_oauth_state';

    public function __construct(
        private HttpFactory $http,
        private ShopifyOAuthHmacValidator $hmac,
        private DiscountManagerAppRegistryService $registry,
        private DiscountManagerShopGuard $shopGuard,
    ) {}

    /** @return array{authorization_url: string, state: string, state_record: OAuthState} */
    public function begin(Organization $organization, User $user, Store $store): array
    {
        if ((int) $store->organization_id !== (int) $organization->id || ! $user->canAccessStore($store)) {
            throw new DiscountManagerException('STORE_ACCESS_DENIED', '无权为该店铺连接折扣管理 App。', 403);
        }
        $app = $this->registry->configuredApp();
        $shop = $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $redirectUri = $this->redirectUri();
        $scopes = array_values((array) config('discount_manager.required_scopes', []));
        $plainState = Str::random(64);
        $state = OAuthState::query()->create([
            'state_hash' => hash('sha256', $plainState),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'app_id' => $app->id,
            'user_id' => $user->id,
            'shop_domain' => $shop,
            'redirect_uri' => $redirectUri,
            'intended_url' => route('discounts.index'),
            'scopes' => $scopes,
            'expires_at' => now()->addMinutes(10),
        ]);
        $query = http_build_query([
            'client_id' => config('discount_manager.active.client_id'),
            'scope' => implode(',', $scopes),
            'redirect_uri' => $redirectUri,
            'state' => $plainState,
        ], '', '&', PHP_QUERY_RFC3986);

        return ['authorization_url' => "https://{$shop}/admin/oauth/authorize?{$query}", 'state' => $plainState, 'state_record' => $state];
    }

    public function complete(array $query, ?string $stateCookie): Store
    {
        $plainState = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($plainState === '' || $code === '' || ! is_string($stateCookie) || ! hash_equals($plainState, $stateCookie)) {
            throw new DiscountManagerException('INVALID_OAUTH_STATE', '折扣管理 Shopify OAuth 状态校验失败。', 403);
        }
        $state = OAuthState::query()->with(['app', 'store.shopifyConnection'])
            ->where('state_hash', hash('sha256', $plainState))->first();
        if (! $state || ! $state->app || ! $state->store || $state->consumed_at || $state->expires_at->isPast()) {
            throw new DiscountManagerException('INVALID_OAUTH_STATE', '折扣管理 Shopify OAuth 状态已失效。', 403);
        }
        $shop = $this->shopGuard->assertAllowed((string) ($query['shop'] ?? ''));
        if (! hash_equals($state->shop_domain, $shop)
            || ! is_string($state->app->client_secret_encrypted)
            || ! $this->hmac->validate($query, $state->app->client_secret_encrypted)) {
            throw new DiscountManagerException('INVALID_OAUTH_CALLBACK', '折扣管理 Shopify OAuth 回调校验失败。', 403);
        }
        $state = DB::transaction(function () use ($state): OAuthState {
            $locked = OAuthState::query()->lockForUpdate()->find($state->id);
            if (! $locked || $locked->consumed_at || $locked->expires_at->isPast()) {
                throw new DiscountManagerException('INVALID_OAUTH_STATE', '折扣管理 Shopify OAuth 状态已失效。', 403);
            }
            $locked->forceFill(['consumed_at' => now()])->save();

            return $locked;
        });
        $response = $this->http->asForm()->acceptJson()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $state->app->client_id,
            'client_secret' => $state->app->client_secret_encrypted,
            'code' => $code,
        ]);
        $token = $response->json('access_token');
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $response->json('scope', '')))));
        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new DiscountManagerException('SHOPIFY_TOKEN_EXCHANGE_FAILED', '无法建立折扣管理 Shopify 会话。', 502);
        }
        $missing = collect((array) config('discount_manager.required_scopes', []))
            ->filter(fn (mixed $scope): bool => is_string($scope) && ! in_array($scope, $scopes, true))->all();
        if ($missing !== []) {
            throw new DiscountManagerException('SHOPIFY_REQUIRED_SCOPES_MISSING', '折扣管理 App 尚未授予完整权限。', 403);
        }
        $connection = $state->store->shopifyConnection;
        if (! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            throw new DiscountManagerException('STORE_NOT_CONNECTED', '该店铺尚未连接 DecoAdmin Commerce Hub。', 409);
        }
        $installation = $this->registry->synchronizeInstallation($state->store, $connection, 'active', $scopes, 'discount_manager_oauth');
        $installation->forceFill([
            'installed_by' => $state->user_id,
            'access_token_encrypted' => $token,
            'refresh_token_encrypted' => null,
            'token_type' => 'offline',
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ])->save();
        AuditLog::query()->create([
            'organization_id' => $state->organization_id,
            'store_id' => $state->store_id,
            'user_id' => $state->user_id,
            'action' => 'discount_manager_shopify_app_connected',
            'subject_type' => AppInstallation::class,
            'subject_id' => $installation->id,
            'new_values' => ['status' => 'active'],
            'metadata' => ['environment' => config('discount_manager.environment'), 'granted_scopes' => $scopes],
        ]);

        return $state->store;
    }

    private function redirectUri(): string
    {
        $origin = rtrim((string) config('discount_manager.active.app_url'), '/');
        $uri = $origin.route('discount-manager.oauth.callback', [], false);
        $parts = parse_url($uri);
        if (($parts['scheme'] ?? null) !== 'https') {
            throw new DiscountManagerException('DISCOUNT_MANAGER_REDIRECT_INVALID', '折扣管理回调地址必须使用 HTTPS。', 503);
        }

        return $uri;
    }
}
