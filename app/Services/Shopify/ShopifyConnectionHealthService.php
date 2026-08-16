<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;
use Throwable;

class ShopifyConnectionHealthService
{
    public function __construct(private ShopifyGraphQLClient $client) {}

    /**
     * @return array{
     *     success: bool,
     *     status: 'connected'|'warning'|'invalid'|'disconnected',
     *     message: string,
     *     shop: array{id: string, name: string, myshopify_domain: string}|null
     * }
     */
    public function check(ShopifyConnection $connection): array
    {
        if ($connection->trashed() || $connection->uninstalled_at) {
            return $this->recordFailure($connection, 'disconnected', 'Shopify Connection 已断开。');
        }

        try {
            $result = $this->client->checkConnection($connection);
        } catch (Throwable) {
            return $this->recordFailure($connection, 'warning', 'Shopify API 暂时不可用，请稍后重试。');
        }

        if (! $result['success']) {
            $status = match ($result['status_code']) {
                401, 403 => 'invalid',
                410 => 'disconnected',
                default => 'warning',
            };

            return $this->recordFailure($connection, $status, $result['message']);
        }

        $shop = $result['shop'];
        $metadata = $connection->metadata ?? [];
        $metadata['health_shop'] = $shop;

        $connection->forceFill([
            'shopify_shop_id' => $this->numericShopId($shop['id'] ?? null) ?? $connection->shopify_shop_id,
            'status' => 'connected',
            'last_verified_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
            'metadata' => $metadata,
        ])->save();

        return [
            'success' => true,
            'status' => 'connected',
            'message' => $result['message'],
            'shop' => $shop,
        ];
    }

    /**
     * @param  'warning'|'invalid'|'disconnected'  $status
     * @return array{success: false, status: 'warning'|'invalid'|'disconnected', message: string, shop: null}
     */
    private function recordFailure(ShopifyConnection $connection, string $status, string $message): array
    {
        $connection->forceFill([
            'status' => $status,
            'last_error' => $message,
            'last_error_at' => now(),
        ])->save();

        return [
            'success' => false,
            'status' => $status,
            'message' => $message,
            'shop' => null,
        ];
    }

    private function numericShopId(?string $shopId): ?int
    {
        if (! $shopId || ! preg_match('/\/(\d+)$/', $shopId, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
