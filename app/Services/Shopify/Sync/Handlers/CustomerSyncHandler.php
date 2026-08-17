<?php

namespace App\Services\Shopify\Sync\Handlers;

class CustomerSyncHandler extends PlaceholderSyncHandler
{
    public function type(): string
    {
        return 'customers';
    }

    protected function label(): string
    {
        return '客户';
    }
}
