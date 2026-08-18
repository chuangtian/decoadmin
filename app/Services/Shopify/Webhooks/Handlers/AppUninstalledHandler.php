<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyConnectionLifecycleService;

class AppUninstalledHandler implements WebhookHandlerInterface
{
    public function __construct(private ShopifyConnectionLifecycleService $lifecycle) {}

    public function topic(): string
    {
        return 'app/uninstalled';
    }

    public function handle(WebhookEvent $event): void
    {
        $event->loadMissing('shopifyConnection.appInstallations');
        $connection = $event->shopifyConnection;

        if (! $connection) {
            return;
        }

        $this->lifecycle->markDisconnected($connection, 'Shopify 应用已从店铺卸载。');
        $connection->appInstallations()->update([
            'status' => 'uninstalled',
            'uninstalled_at' => now(),
        ]);
    }
}
