<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;
use App\Models\User;
use Throwable;

class ShopifyConnectionHealthService
{
    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyConnectionLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     status: 'connected'|'warning'|'invalid'|'disconnected',
     *     message: string,
     *     shop: array{id: string, name: string, myshopify_domain: string}|null
     * }
     */
    public function check(ShopifyConnection $connection, ?User $actor = null): array
    {
        if ($connection->trashed() || $connection->uninstalled_at) {
            return $this->recordFailure($connection, 'disconnected', 'Shopify Connection 已断开。', $actor);
        }

        try {
            $result = $this->client->checkConnection($connection);
        } catch (Throwable) {
            return $this->recordFailure($connection, 'warning', 'Shopify API 暂时不可用，请稍后重试。', $actor);
        }

        if (! $result['success']) {
            $status = match ($result['status_code']) {
                401, 403 => 'invalid',
                410 => 'disconnected',
                default => 'warning',
            };

            return $this->recordFailure($connection, $status, $result['message'], $actor);
        }

        $shop = $result['shop'];
        $this->lifecycle->markConnected(
            $connection,
            $actor,
            'Shopify Admin API 验证成功。',
            apiChecked: true,
            shop: $shop,
        );

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
    private function recordFailure(ShopifyConnection $connection, string $status, string $message, ?User $actor): array
    {
        match ($status) {
            'invalid' => $this->lifecycle->markInvalid($connection, $message, $actor, apiChecked: true),
            'disconnected' => $this->lifecycle->markDisconnected($connection, $message, $actor, apiChecked: true),
            default => $this->lifecycle->markWarning($connection, $message, $actor, apiChecked: true),
        };

        return [
            'success' => false,
            'status' => $status,
            'message' => $message,
            'shop' => null,
        ];
    }
}
