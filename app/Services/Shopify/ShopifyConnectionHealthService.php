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
     *     shop: array{id: string, name: string, myshopify_domain: string, iana_timezone: string, currency_code: string}|null
     * }
     */
    public function check(ShopifyConnection $connection, ?User $actor = null): array
    {
        if ($connection->trashed() || $connection->uninstalled_at) {
            return $this->recordFailure($connection, 'disconnected', 'Shopify 连接已断开。', $actor);
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
            'Shopify 管理 API 验证成功。',
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
     * Refresh shop metadata after OAuth without turning a successful install
     * into a warning when Shopify is temporarily unavailable.
     */
    public function refreshMetadata(ShopifyConnection $connection, ?User $actor = null): bool
    {
        try {
            $result = $this->client->checkConnection($connection);
        } catch (Throwable) {
            return false;
        }

        if (! $result['success'] || $result['shop'] === null) {
            return false;
        }

        $this->lifecycle->markConnected(
            $connection,
            $actor,
            'Shopify 店铺时区与币种元数据已同步。',
            apiChecked: true,
            shop: $result['shop'],
        );

        return true;
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
