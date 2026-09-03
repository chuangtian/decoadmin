<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Models\ShopifyConnection;
use App\Models\Store;

class DiscountShopifyConnectionService
{
    public function forStore(Store $store, bool $write = false): ShopifyConnection
    {
        $domain = strtolower(trim((string) $store->shopify_domain));
        $connection = $store->shopifyConnection()->first();
        if ($store->status !== 'active' || ! $connection
            || ! in_array($connection->status, ['connected', 'warning'], true)
            || preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) !== 1
            || ! hash_equals($domain, strtolower(trim($connection->shop_domain)))
            || ! filled($connection->access_token_encrypted)
            || $connection->access_token_expires_at?->isPast()) {
            throw new DiscountManagerException('SHOPIFY_CONNECTION_REQUIRED', '当前店铺的 Shopify 连接不可用，请由管理员检查现有店铺连接。', 409);
        }
        $scopes = (array) $connection->scopes;
        $required = $write ? ['write_discounts', 'read_products'] : ['read_discounts', 'read_products'];
        $missing = array_values(array_filter($required, fn (string $scope): bool => ! in_array($scope, $scopes, true)
            && ! (str_starts_with($scope, 'read_') && in_array('write_'.substr($scope, 5), $scopes, true))));
        if ($missing !== []) {
            throw new DiscountManagerException('SHOPIFY_DISCOUNT_SCOPE_REQUIRED', '现有 Shopify 连接尚未授予 '.implode('、', $missing).'。发布 App 版本后，还需要通过“授权折扣权限”补充授权；无需新建 App。', 409);
        }

        return $connection;
    }
}
