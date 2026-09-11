<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Exceptions\StudentDiscountException;
use App\Models\AppInstallation;
use App\Services\StudentDiscount\StudentDiscountAppTokenService;
use App\Models\ShopifyConnection;
use App\Models\Store;

class DiscountShopifyConnectionService
{
    public function __construct(private StudentDiscountAppTokenService $studentTokens) {}

    public function forStore(Store $store, bool $write = false): ShopifyConnection
    {
        $domain = strtolower(trim((string) $store->shopify_domain));
        if ($store->status !== 'active' || $store->organization?->status !== 'active'
            || preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) !== 1) {
            throw new DiscountManagerException('SHOPIFY_CONNECTION_REQUIRED', '当前店铺不可用。', 409);
        }

        $installation = AppInstallation::query()->where('store_id', $store->id)->where('status', 'active')
            ->whereHas('app', fn ($query) => $query->whereNull('organization_id')
                ->where('handle', config('student_discount.active.handle'))
                ->where('client_id', config('student_discount.active.client_id'))->where('status', 'active'))->first();
        if ($installation) {
            try {
                // Keep the app-owned token in memory; never overwrite the Commerce Hub connection.
                return new ShopifyConnection([
                    'store_id' => $store->id, 'shop_domain' => $domain,
                    'access_token_encrypted' => $this->studentTokens->accessTokenFor($store),
                    'api_version' => config('shopify.api_version'), 'scopes' => $installation->granted_scopes,
                ]);
            } catch (StudentDiscountException $exception) {
                throw new DiscountManagerException('SHOPIFY_DISCOUNT_SCOPE_REQUIRED', $exception->getMessage(), $exception->statusCode);
            }
        }

        // Existing legacy grants remain usable; new authorization always belongs to the discount app.
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
            throw new DiscountManagerException('SHOPIFY_DISCOUNT_SCOPE_REQUIRED', '当前店铺尚无可用的折扣应用授权，缺少 '.implode('、', $missing).'。请打开独立的学生优惠 App 完成授权；不会扩展 Commerce Hub 权限。', 409);
        }

        return $connection;
    }
}
