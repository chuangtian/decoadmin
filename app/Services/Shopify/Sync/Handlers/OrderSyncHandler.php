<?php

namespace App\Services\Shopify\Sync\Handlers;

class OrderSyncHandler extends PlaceholderSyncHandler
{
    public function type(): string
    {
        return 'orders';
    }

    protected function label(): string
    {
        return '订单';
    }
}
