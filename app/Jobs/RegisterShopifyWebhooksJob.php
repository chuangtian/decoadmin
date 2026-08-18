<?php

namespace App\Jobs;

use App\Models\AppInstallation;
use App\Services\Shopify\Webhooks\ShopifyWebhookSubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RegisterShopifyWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $appInstallationId)
    {
        $this->onQueue('default');
    }

    public function handle(ShopifyWebhookSubscriptionService $subscriptions): void
    {
        $installation = AppInstallation::query()->find($this->appInstallationId);

        if ($installation && $installation->status === 'active') {
            $subscriptions->reconcile($installation);
        }
    }
}
