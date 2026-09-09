<?php

namespace App\Services\Shopify\Webhooks;

use App\Domain\ReferralAffiliate\Services\AffiliateWebhookService;
use App\Models\WebhookEvent;

class WebhookEventProcessor
{
    public function __construct(private WebhookHandlerRegistry $handlers) {}

    public function process(WebhookEvent $event): WebhookProcessingResult
    {
        if ($event->app?->handle === config('referral.active.handle') && $event->app?->client_id === config('referral.active.client_id')) {
            app(AffiliateWebhookService::class)->process($event);

            return WebhookProcessingResult::handled(AffiliateWebhookService::class);
        }
        $handler = $this->handlers->forTopic($event->topic);

        if (! $handler) {
            return WebhookProcessingResult::unsupported(
                "主题 [{$event->topic}] 暂未注册事件处理器。",
            );
        }

        $handler->handle($event);

        return WebhookProcessingResult::handled($handler::class);
    }
}
