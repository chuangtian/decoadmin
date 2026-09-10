<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramFeedInstallation;
use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                '尚未建立 Instagram Feed App 的 Shopify 会话，请先在 Shopify 后台打开一次该应用。',
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
                ->post($this->endpoint($shopDomain), [
                    'query' => $query,
                    // GraphQL 的 variables 必须是 JSON 对象。PHP 的空数组会被编成 `[]`，
                    // Shopify 直接以 HTTP 200 + errors: "Invalid variables parameter." 拒掉，
                    // 于是所有不带变量的查询（currentAppInstallation、webhook 订阅列表）全部失败。
                    // 与 student-discount、personalization 两个 App 的客户端保持同一写法。
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException) {
            throw new InstagramFeedException('SHOPIFY_ADMIN_API_TIMEOUT', 'Shopify Admin API 请求超时。', 502);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new InstagramFeedException(
                'SHOPIFY_APP_SESSION_INVALID',
                'Instagram Feed App 的 Shopify 授权已失效，请在 Shopify 后台重新打开该应用。',
                401,
            );
        }

        $payload = $response->json();
        if ($response->failed() || ! is_array($payload) || ! empty($payload['errors'])) {
            // 这里以前只抛一句通用文案，Shopify 的真实原因（HTTP 状态或 GraphQL errors）
            // 全部丢失，线上排查只能靠猜。响应体不含我们的令牌，仍做一次兜底脱敏。
            Log::warning('Instagram Feed Shopify Admin API 调用失败。', [
                'shop' => $shopDomain,
                'status' => $response->status(),
                'api_version' => (string) config('shopify.api_version'),
                'response' => $this->safeSnippet((string) $response->body()),
            ]);

            throw new InstagramFeedException(
                'SHOPIFY_ADMIN_API_FAILED',
                'Shopify Admin API 请求失败（HTTP '.$response->status().'）：'.$this->firstErrorMessage($payload, $response->body()),
                502,
            );
        }

        return $payload;
    }

    public function endpoint(string $shopDomain): string
    {
        return 'https://'.$shopDomain.'/admin/api/'.(string) config('shopify.api_version').'/graphql.json';
    }

    /** 取第一条可展示的错误说明：GraphQL errors 优先，退回响应体片段。 */
    private function firstErrorMessage(mixed $payload, string $body): string
    {
        $errors = is_array($payload) ? ($payload['errors'] ?? null) : null;

        if (is_string($errors) && trim($errors) !== '') {
            return $this->safeSnippet($errors, 200);
        }

        if (is_array($errors)) {
            $message = collect($errors)
                ->map(fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? '') : (string) $error)
                ->filter()
                ->implode('；');

            if ($message !== '') {
                return $this->safeSnippet($message, 200);
            }
        }

        return $this->safeSnippet($body, 200);
    }

    private function safeSnippet(string $value, int $limit = 500): string
    {
        $redacted = preg_replace('/\b(shp(at|ca|pa|ss)_[A-Za-z0-9]+)\b/', '[redacted]', $value) ?? $value;

        return Str::limit(trim($redacted), $limit, '…');
    }
}
