<?php

namespace App\Services\Shopify\Sync\Handlers;

class InventorySyncHandler extends PlaceholderSyncHandler
{
    public function type(): string
    {
        return 'inventory';
    }

    protected function label(): string
    {
        return '库存';
    }
}
