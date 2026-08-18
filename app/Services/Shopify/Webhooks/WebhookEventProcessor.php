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
                "主题 [{$event->topic}] 暂未注册事件处理器。",
            );
        }

        $handler->handle($event);

        return WebhookProcessingResult::handled($handler::class);
    }
}
