<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;

class DiscountManagerShopGuard
{
    public function assertAllowed(string $shop): string
    {
        $shop = strtolower(trim($shop));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1) {
            throw new DiscountManagerException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        return $shop;
    }
}
