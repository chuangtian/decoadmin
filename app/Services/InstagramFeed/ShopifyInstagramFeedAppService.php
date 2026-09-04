<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramFeedInstallation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;

/**
 * Shopify Admin 里打开 instagram-feed App 时的入口服务。
 *
 * App Home 扩展带着 App Bridge 的 id_token 调 bootstrap，这里用 token exchange 换一份
 * offline access token 存库。之后 DecoAdmin 后台就能随时用本 App 的身份发布前台数据，
 * 不必再回到 Shopify Admin。
 */
class ShopifyInstagramFeedAppService
{
    private const INSTALLATION_QUERY = <<<'GRAPHQL'
        query CurrentInstagramFeedInstallation {
          currentAppInstallation {
            id
            accessScopes { handle }
          }
        }
        GRAPHQL;

    public function __construct(
        private HttpFactory $http,
        private InstagramFeedAppRegistry $registry,
        private InstagramFeedShopifyClient $client,
    ) {}

    /** Shopify Admin 跳转到 DecoAdmin 后台前的鉴权：店铺存在、用户有权访问且有查看权限。 */
    public function managementStore(User $user, string $shop): Store
    {
        $shop = strtolower(trim($shop));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1) {
            throw new InstagramFeedException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        $store = $this->activeStore($shop);
        if (! $user->canAccessStore($store)
            || ! $user->hasPermission('instagram_feed.view', $store->organization, $store)) {
            throw new InstagramFeedException('STORE_ACCESS_DENIED', '无权访问该店铺的 Instagram Feed 后台。', 403);
        }

        return $store;
    }

    public function connectedStore(string $shop): ?Store
    {
        $shop = strtolower(trim($shop));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1) {
            return null;
        }

        return Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->with('organization')
            ->first();
    }

    /**
     * 用 id_token 换 offline token，读安装信息并落库。
     *
     * @return array{app_installation_id: string, granted_scopes: list<string>}
     */
    public function bootstrap(Store $store, string $idToken): array
    {
        $shop = $store->shopify_domain;
        $token = $this->exchangeOfflineToken($shop, $idToken);

        $payload = $this->client->graphqlWithToken($shop, $token['access_token'], self::INSTALLATION_QUERY);
        $installationId = data_get($payload, 'data.currentAppInstallation.id');
        if (! is_string($installationId) || $installationId === '') {
            throw new InstagramFeedException(
                'SHOPIFY_APP_NOT_INSTALLED',
                '未找到当前 Instagram Feed App 的安装记录。',
                409,
            );
        }

        $scopes = collect(data_get($payload, 'data.currentAppInstallation.accessScopes', []))
            ->pluck('handle')
            ->filter(fn (mixed $scope): bool => is_string($scope) && $scope !== '')
            ->values()
            ->all();
        $this->registry->assertRequiredScopes($scopes);

        DB::transaction(function () use ($store, $installationId, $scopes, $token): void {
            $installation = InstagramFeedInstallation::query()->firstOrNew(['store_id' => $store->id]);
            $installation->fill([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'environment' => $this->registry->environment(),
                'app_installation_id' => $installationId,
                'access_token_encrypted' => $token['access_token'],
                'granted_scopes' => $scopes,
                'installed_at' => $installation->installed_at ?? now(),
                'last_verified_at' => now(),
            ]);
            $installation->save();

            // 应用中心的列表、侧边栏条目和配置卡片都以 app_installations 为准，
            // 这里必须一起写，否则店铺装了 App 也不会出现在应用中心。
            $this->registry->synchronizeInstallation(
                $store,
                'active',
                $scopes,
                'instagram_feed_bootstrap',
                $installationId,
            );

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'instagram_feed_shopify_app_bootstrapped',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => $this->registry->environment(),
                    'app_installation_id' => $installationId,
                    'granted_scopes' => $scopes,
                ],
            ]);
        });

        return ['app_installation_id' => $installationId, 'granted_scopes' => $scopes];
    }

    /**
     * @return array{access_token: string, granted_scopes: list<string>}
     */
    private function exchangeOfflineToken(string $shop, string $idToken): array
    {
        $credentials = $this->registry->credentials();

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => $credentials['client_id'],
                    'client_secret' => $credentials['client_secret'],
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                    'subject_token' => $idToken,
                    'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                    // offline token 不随会话过期，后台后续发布要靠它。
                    'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                ]);
        } catch (ConnectionException) {
            throw new InstagramFeedException(
                'SHOPIFY_TOKEN_EXCHANGE_TIMEOUT',
                'Shopify 身份交换超时，请稍后重试。',
                502,
            );
        }

        if ($response->status() === 400) {
            throw new InstagramFeedException(
                'INVALID_SHOPIFY_ID_TOKEN',
                'Shopify 身份令牌已失效，请刷新后重试。',
                401,
            );
        }
        $accessToken = $response->json('access_token');
        if ($response->failed() || ! is_string($accessToken) || $accessToken === '') {
            throw new InstagramFeedException(
                'SHOPIFY_TOKEN_EXCHANGE_FAILED',
                '无法建立 Shopify Admin API 会话。',
                502,
            );
        }

        $grantedScopes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->json('scope', '')),
        )));
        $this->registry->assertRequiredScopes($grantedScopes);

        return ['access_token' => $accessToken, 'granted_scopes' => $grantedScopes];
    }

    private function activeStore(string $shop): Store
    {
        $store = $this->connectedStore($shop);
        if (! $store || ! $store->organization) {
            throw new InstagramFeedException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        return $store;
    }
}
