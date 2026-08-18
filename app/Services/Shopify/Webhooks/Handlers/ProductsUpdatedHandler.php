<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Models\WebhookEvent;

class ProductsUpdatedHandler implements WebhookHandlerInterface
{
    public function topic(): string
    {
        return 'products/update';
    }

    public function handle(WebhookEvent $event): void
    {
        // Handler scaffold only. Product synchronization is intentionally out of scope.
    }
}
