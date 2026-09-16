<?php

namespace App\Console\Commands;

use App\Models\AppInstallation;
use App\Services\Shopify\Webhooks\ShopifyWebhookSubscriptionService;
use Illuminate\Console\Command;
use Throwable;

class RegisterShopifyWebhooks extends Command
{
    protected $signature = 'shopify:register-webhooks {--store=}';

    protected $description = '幂等注册或更新 Shopify Webhook 回调地址';

    public function handle(ShopifyWebhookSubscriptionService $subscriptions): int
    {
        if (! filled(config('shopify.client_id'))) {
            $this->error('后台主应用尚未配置，未修改 Webhook 订阅。');

            return self::FAILURE;
        }

        $query = AppInstallation::query()
            ->where('status', 'active')
            ->whereHas('app', fn ($query) => $query
                ->where('handle', config('shopify.app_handle', 'shopify-commerce-hub'))
                ->where('client_id', config('shopify.client_id')))
            ->whereHas('shopifyConnection', fn ($query) => $query->whereIn('status', ['connected', 'warning']))
            ->with(['store.organization', 'app', 'shopifyConnection']);

        if ($this->option('store')) {
            $query->where('store_id', (int) $this->option('store'));
        }

        $failed = 0;

        $query->each(function (AppInstallation $installation) use ($subscriptions, &$failed): void {
            try {
                $result = $subscriptions->reconcile($installation);
                $this->info(sprintf(
                    '%s：新增 %d，更新 %d，无变化 %d',
                    $installation->store?->name ?? "Store #{$installation->store_id}",
                    $result['created'],
                    $result['updated'],
                    $result['unchanged'],
                ));
            } catch (Throwable $exception) {
                $failed++;
                $this->error(($installation->store?->name ?? "Store #{$installation->store_id}").'：'.$exception->getMessage());
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
