<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use Illuminate\Http\Client\Factory as HttpFactory;

class ShopifyGraphQLClient
{
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
}
