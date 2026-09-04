<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

class ShopifyGraphQLClient
{
    private const CONNECTION_HEALTH_QUERY = <<<'GRAPHQL'
        query ConnectionHealth {
          shop {
            id
            name
            myshopifyDomain
            ianaTimezone
            currencyCode
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
    public function query(
        ShopifyConnection $connection,
        string $query,
        array $variables = [],
        int $timeoutSeconds = 20,
    ): array {
        return $this->queryWithAccessToken(
            $connection->shop_domain,
            $connection->access_token_encrypted,
            $query,
            $variables,
            $timeoutSeconds,
            $connection->api_version,
        );
    }

    /**
     * Execute a request with an app-owned token without storing it on the shared connection.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function queryWithAccessToken(
        string $shopDomain,
        string $accessToken,
        string $query,
        array $variables = [],
        int $timeoutSeconds = 20,
        ?string $apiVersion = null,
    ): array {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = $this->http
                ->acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Shopify-Access-Token' => $accessToken,
                ])
                ->timeout($timeoutSeconds)
                ->post($this->endpoint($shopDomain, $apiVersion), $payload);
        } catch (ConnectionException $exception) {
            throw new ShopifyApiException('Shopify API 请求超时或网络连接失败。', [
                'error_type' => 'timeout',
                'retryable' => true,
                'shop_domain' => $shopDomain,
            ]);
        }

        if ($response->failed()) {
            $status = $response->status();

            throw new ShopifyApiException('Shopify API 请求失败。', [
                'status' => $status,
                'error_type' => match ($status) {
                    429 => 'rate_limit',
                    408, 504 => 'timeout',
                    default => 'api_error',
                },
                'retryable' => $status === 429 || $status === 408 || $status >= 500,
                'retry_after' => $response->header('Retry-After'),
                'shop_domain' => $shopDomain,
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload) || ! empty($payload['errors'])) {
            $errors = is_array($payload) ? ($payload['errors'] ?? []) : [];
            $throttled = collect(is_array($errors) ? $errors : [])->contains(
                fn ($error) => data_get($error, 'extensions.code') === 'THROTTLED',
            );

            throw new ShopifyApiException('Shopify GraphQL 返回错误。', [
                'shop_domain' => $shopDomain,
                'status' => $throttled ? 429 : null,
                'error_type' => $throttled ? 'rate_limit' : 'graphql_error',
                'retryable' => $throttled,
                'errors' => $errors,
            ]);
        }

        return $payload;
    }

    /**
     * Execute a GraphQL request for synchronization handlers without exposing the HTTP client.
     *
     * @param  array<string, mixed>  $variables
     * @return array{data: array<string, mixed>, extensions: array<string, mixed>, throttle_status: array<string, mixed>|null}
     */
    public function executeSyncQuery(
        ShopifyConnection $connection,
        string $query,
        array $variables = [],
        int $timeoutSeconds = 30,
    ): array {
        $payload = $this->query($connection, $query, $variables, $timeoutSeconds);
        $extensions = is_array($payload['extensions'] ?? null) ? $payload['extensions'] : [];

        return [
            'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
            'extensions' => $extensions,
            'throttle_status' => is_array(data_get($extensions, 'cost.throttleStatus'))
                ? data_get($extensions, 'cost.throttleStatus')
                : null,
        ];
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     shop: array{id: string, name: string, myshopify_domain: string, iana_timezone: string, currency_code: string}|null,
     *     status_code: int|null
     * }
     */
    public function checkConnection(ShopifyConnection $connection): array
    {
        try {
            $payload = $this->query($connection, self::CONNECTION_HEALTH_QUERY);
            $shop = data_get($payload, 'data.shop');

            if (! is_array($shop) || ! isset($shop['id'], $shop['name'], $shop['myshopifyDomain'], $shop['ianaTimezone'], $shop['currencyCode'])) {
                throw new ShopifyApiException('Shopify API 未返回有效店铺信息。');
            }

            return [
                'success' => true,
                'message' => 'Shopify 连接验证成功。',
                'shop' => [
                    'id' => (string) $shop['id'],
                    'name' => (string) $shop['name'],
                    'myshopify_domain' => (string) $shop['myshopifyDomain'],
                    'iana_timezone' => (string) $shop['ianaTimezone'],
                    'currency_code' => strtoupper((string) $shop['currencyCode']),
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
                    401, 403 => 'Shopify 访问令牌无效或已被撤销。',
                    429 => 'Shopify API 请求过于频繁，请稍后重试。',
                    default => 'Shopify API 暂时无法完成连接验证。',
                },
                'shop' => null,
                'status_code' => $statusCode,
            ];
        }
    }
}
