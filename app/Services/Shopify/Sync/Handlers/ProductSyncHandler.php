<?php

namespace App\Services\Shopify\Sync\Handlers;

class ProductSyncHandler extends PlaceholderSyncHandler
{
    public function type(): string
    {
        return 'products';
    }

    protected function label(): string
    {
        return '商品';
    }
}
