<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Exceptions\AffiliateException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;

class ShopifyAffiliateAppService
{
    private const INSTALLATION_QUERY = <<<'GRAPHQL'
        query ReferralInstallation {
          currentAppInstallation {
            id
            accessScopes { handle }
          }
          shop { myshopifyDomain }
        }
        GRAPHQL;

    public function __construct(
        private HttpFactory $http,
        private AffiliateAppRegistryService $registry,
    ) {}

    public function managementStore(User $user, string $shop): Store
    {
        $shop = strtolower(trim($shop));
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) {
            throw new AffiliateException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        $store = Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->with('organization')
            ->first();
        if (! $store || ! $store->organization) {
            throw new AffiliateException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }
        if (! $user->canAccessStore($store)
            || ! $user->hasPermission('affiliate.dashboard.view', $store->organization, $store)) {
            throw new AffiliateException('STORE_ACCESS_DENIED', '无权访问该店铺的推荐与联盟后台。', 403);
        }

        app(AffiliateShopGuard::class)->store($store);

        return $store;
    }

    /** @return array{app_installation_id: string} */
    public function bootstrap(Store $store, string $idToken): array
    {
        app(AffiliateShopGuard::class)->store($store);
        $connection = $store->shopifyConnection()->whereIn('status', ['connected', 'warning'])->first();
        if (! $connection || ! filled(config('referral.active.client_id')) || ! filled(config('referral.active.client_secret'))) {
            throw new AffiliateException('AFFILIATE_APP_NOT_CONFIGURED', '请先配置测试 App 并连接测试店铺。', 409);
        }
        $shop = $store->shopify_domain;
        $token = $this->exchangeOfflineToken($shop, $idToken);
        $installationPayload = $this->graphql($shop, $token['access_token'], self::INSTALLATION_QUERY);
        $installationId = data_get($installationPayload, 'data.currentAppInstallation.id');
        if (! is_string($installationId) || $installationId === '') {
            throw new AffiliateException('SHOPIFY_APP_NOT_INSTALLED', '未找到当前推荐与联盟 App 的安装记录。', 409);
        }
        $installationScopes = collect(data_get($installationPayload, 'data.currentAppInstallation.accessScopes', []))
            ->pluck('handle')
            ->filter(fn (mixed $scope): bool => is_string($scope) && $scope !== '')
            ->values()
            ->all();
        $this->assertRequiredScopes($installationScopes);

        if (data_get($installationPayload, 'data.shop.myshopifyDomain') !== $shop) {
            throw new AffiliateException('AFFILIATE_SHOP_MISMATCH', 'Shopify 安装所属店铺不匹配。', 403);
        }

        DB::transaction(function () use ($store, $connection, $installationId, $installationScopes, $token): void {
            $installation = $this->registry->synchronizeInstallation(
                $store,
                $connection,
                'active',
                $installationScopes,
                'referral_bootstrap',
                $installationId,
            );
            $this->storeOfflineToken($installation, $token);

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'referral_shopify_app_bootstrapped',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => (string) config('referral.environment'),
                    'app_installation_id' => $installationId,
                    'granted_scopes' => $installationScopes,
                ],
            ]);
        });

        return ['app_installation_id' => $installationId];
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int,
     *     granted_scopes: list<string>
     * }
     */
    private function exchangeOfflineToken(string $shop, string $idToken): array
    {
        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => (string) config('referral.active.client_id'),
                    'client_secret' => (string) config('referral.active.client_secret'),
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $idToken,
                    'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                    'expiring' => 1,
                ]);
        } catch (ConnectionException) {
            throw new AffiliateException('SHOPIFY_TOKEN_EXCHANGE_TIMEOUT', 'Shopify 身份交换超时，请稍后重试。', 502);
        }

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');
        $refreshTokenExpiresIn = $response->json('refresh_token_expires_in');
        if ($response->status() === 400) {
            throw new AffiliateException('INVALID_SHOPIFY_ID_TOKEN', 'Shopify 身份令牌已失效，请刷新后重试。', 401);
        }
        if ($response->failed()
            || ! is_string($accessToken) || $accessToken === ''
            || ! is_string($refreshToken) || $refreshToken === ''
            || ! is_numeric($expiresIn) || (int) $expiresIn <= 0
            || ! is_numeric($refreshTokenExpiresIn) || (int) $refreshTokenExpiresIn <= 0) {
            throw new AffiliateException('SHOPIFY_TOKEN_EXCHANGE_FAILED', '无法建立 Shopify Admin API 会话。', 502);
        }

        $grantedScopes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->json('scope', '')),
        )));
        $this->assertRequiredScopes($grantedScopes);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => (int) $expiresIn,
            'refresh_token_expires_in' => (int) $refreshTokenExpiresIn,
            'granted_scopes' => $grantedScopes,
        ];
    }

    /**
     * @param  array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int,
     *     granted_scopes: list<string>
     * }  $token
     */
    private function storeOfflineToken(AppInstallation $installation, array $token): void
    {
        $issuedAt = now();
        $installation->forceFill([
            'access_token_encrypted' => $token['access_token'],
            'refresh_token_encrypted' => $token['refresh_token'],
            'token_type' => 'offline',
            'access_token_expires_at' => $issuedAt->copy()->addSeconds($token['expires_in']),
            'refresh_token_expires_at' => $issuedAt->copy()->addSeconds($token['refresh_token_expires_in']),
        ])->save();
    }

    /** @param list<string> $grantedScopes */
    private function assertRequiredScopes(array $grantedScopes): void
    {
        $missing = collect(config('referral.required_scopes', []))
            ->filter(fn (mixed $required): bool => is_string($required) && ! $this->scopeIsGranted($required, $grantedScopes))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new AffiliateException(
                'SHOPIFY_REQUIRED_SCOPES_MISSING',
                '推荐与联盟 App 尚未授予完整权限，请更新安装授权后重试。',
                403,
            );
        }
    }

    /** @param list<string> $grantedScopes */
    private function scopeIsGranted(string $required, array $grantedScopes): bool
    {
        if (in_array($required, $grantedScopes, true)) {
            return true;
        }

        return str_starts_with($required, 'read_')
            && in_array('write_'.substr($required, 5), $grantedScopes, true);
    }

    /** @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function graphql(string $shop, string $accessToken, string $query, array $variables = []): array
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/api/".(string) config('shopify.api_version').'/graphql.json', [
                    'query' => $query,
                    // GraphQL requires variables to be a JSON object. PHP's empty array
                    // would otherwise be encoded as `[]` and rejected by Shopify.
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException) {
            throw new AffiliateException('SHOPIFY_ADMIN_API_TIMEOUT', 'Shopify Admin API 请求超时。', 502);
        }

        $payload = $response->json();
        if ($response->failed() || ! is_array($payload) || ! empty($payload['errors'])) {
            throw new AffiliateException('SHOPIFY_ADMIN_API_FAILED', 'Shopify Admin API 请求失败。', 502);
        }

        return $payload;
    }
}
