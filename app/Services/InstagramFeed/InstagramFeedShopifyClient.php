<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramFeedInstallation;
use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * instagram-feed App 自己的 Shopify Admin API 会话。
 *
 * 不能复用 DecoAdmin 主 App 的 ShopifyConnection：app-data metafield 归属于写入
 * 它的那个 App 的 AppInstallation，而 Theme App Extension 通过 app.metafields 只能
 * 读到自己所属 App 的值。所以这里用 instagram_feed_installations 里保存的
 * offline token。
 */
class InstagramFeedShopifyClient
{
    public function __construct(private HttpFactory $http) {}

    public function installationFor(Store $store): InstagramFeedInstallation
    {
        $installation = InstagramFeedInstallation::query()->where('store_id', $store->id)->first();
        if (! $installation || ! $installation->isUsable()) {
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_APP_SESSION_MISSING',
                '尚未建立 Instagram 内容 App 的 Shopify 会话，请先在 Shopify 后台打开一次该应用。',
                409,
            );
        }

        return $installation;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(Store $store, string $query, array $variables = []): array
    {
        return $this->graphqlWithToken(
            $store->shopify_domain,
            (string) $this->installationFor($store)->access_token_encrypted,
            $query,
            $variables,
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphqlWithToken(string $shopDomain, string $accessToken, string $query, array $variables = []): array
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->connectTimeout(5)
                ->timeout(30)
                ->post($this->endpoint($shopDomain), ['query' => $query, 'variables' => $variables]);
        } catch (ConnectionException) {
            throw new InstagramFeedException('SHOPIFY_ADMIN_API_TIMEOUT', 'Shopify Admin API 请求超时。', 502);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new InstagramFeedException(
                'SHOPIFY_APP_SESSION_INVALID',
                'Instagram 内容 App 的 Shopify 授权已失效，请在 Shopify 后台重新打开该应用。',
                401,
            );
        }

        $payload = $response->json();
        if ($response->failed() || ! is_array($payload) || ! empty($payload['errors'])) {
            throw new InstagramFeedException('SHOPIFY_ADMIN_API_FAILED', 'Shopify Admin API 请求失败。', 502);
        }

        return $payload;
    }

    public function endpoint(string $shopDomain): string
    {
        return 'https://'.$shopDomain.'/admin/api/'.(string) config('shopify.api_version').'/graphql.json';
    }
}
