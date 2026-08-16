<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use Illuminate\Http\Client\Factory as HttpFactory;

class ShopifyGraphQLClient
{
    private const CONNECTION_HEALTH_QUERY = <<<'GRAPHQL'
        query ConnectionHealth {
          shop {
            id
            name
            myshopifyDomain
          }
        }
        GRAPHQL;

    public function __construct(private HttpFactory $http) {}

    public function endpoint(string $shopDomain, ?string $apiVersion = null): string
    {
        $version = $apiVersion ?: (string) config('shopify.api_version');

        return "https://{$shopDomain}/admin/api/{$version}/graphql.json";
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(ShopifyConnection $connection, string $query, array $variables = []): array
    {
        $response = $this->http
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $connection->access_token_encrypted,
            ])
            ->timeout(20)
            ->post($this->endpoint($connection->shop_domain, $connection->api_version), [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            throw new ShopifyApiException('Shopify API 请求失败。', [
                'status' => $response->status(),
                'shop_domain' => $connection->shop_domain,
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload) || ! empty($payload['errors'])) {
            throw new ShopifyApiException('Shopify GraphQL 返回错误。', [
                'shop_domain' => $connection->shop_domain,
                'errors' => is_array($payload) ? ($payload['errors'] ?? []) : [],
            ]);
        }

        return $payload;
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     shop: array{id: string, name: string, myshopify_domain: string}|null,
     *     status_code: int|null
     * }
     */
    public function checkConnection(ShopifyConnection $connection): array
    {
        try {
            $payload = $this->query($connection, self::CONNECTION_HEALTH_QUERY);
            $shop = data_get($payload, 'data.shop');

            if (! is_array($shop) || ! isset($shop['id'], $shop['name'], $shop['myshopifyDomain'])) {
                throw new ShopifyApiException('Shopify API 未返回有效店铺信息。');
            }

            return [
                'success' => true,
                'message' => 'Shopify Connection 验证成功。',
                'shop' => [
                    'id' => (string) $shop['id'],
                    'name' => (string) $shop['name'],
                    'myshopify_domain' => (string) $shop['myshopifyDomain'],
                ],
                'status_code' => null,
            ];
        } catch (ShopifyApiException $exception) {
            $statusCode = is_int($exception->context['status'] ?? null)
                ? $exception->context['status']
                : null;

            return [
                'success' => false,
                'message' => match ($statusCode) {
                    401, 403 => 'Shopify Access Token 无效或已被撤销。',
                    429 => 'Shopify API 请求过于频繁，请稍后重试。',
                    default => 'Shopify API 暂时无法完成连接验证。',
                },
                'shop' => null,
                'status_code' => $statusCode,
            ];
        }
    }
}
