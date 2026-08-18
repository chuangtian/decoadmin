<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\ShopifyIncrementalDataService;

class ShopifyDataWebhookHandler implements WebhookHandlerInterface
{
    public function __construct(
        private string $webhookTopic,
        private ShopifyIncrementalDataService $data,
    ) {}

    public function topic(): string
    {
        return $this->webhookTopic;
    }

    public function handle(WebhookEvent $event): void
    {
        $this->data->handle($event);
    }
}
