<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Models\WebhookEvent;

class OrdersCreatedHandler implements WebhookHandlerInterface
{
    public function topic(): string
    {
        return 'orders/create';
    }

    public function handle(WebhookEvent $event): void
    {
        // Handler scaffold only. Order synchronization is intentionally out of scope.
    }
}
