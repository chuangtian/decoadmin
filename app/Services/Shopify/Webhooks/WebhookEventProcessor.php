<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;

class WebhookEventProcessor
{
    public function __construct(private WebhookHandlerRegistry $handlers) {}

    public function process(WebhookEvent $event): WebhookProcessingResult
    {
        $handler = $this->handlers->forTopic($event->topic);

        if (! $handler) {
            return WebhookProcessingResult::unsupported(
                "Topic [{$event->topic}] 暂未注册处理 Handler。",
            );
        }

        $handler->handle($event);

        return WebhookProcessingResult::handled($handler::class);
    }
}
