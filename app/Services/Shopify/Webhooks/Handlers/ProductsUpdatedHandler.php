<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\ShopifyIncrementalDataService;

class ProductsUpdatedHandler implements WebhookHandlerInterface
{
    public function __construct(private ShopifyIncrementalDataService $data) {}

    public function topic(): string
    {
        return 'products/update';
    }

    public function handle(WebhookEvent $event): void
    {
        $this->data->handle($event);
    }
}
