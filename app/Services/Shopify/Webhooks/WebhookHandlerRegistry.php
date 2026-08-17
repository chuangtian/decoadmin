<?php

namespace App\Services\Shopify\Webhooks;

use App\Contracts\Shopify\WebhookHandlerInterface;
use InvalidArgumentException;

class WebhookHandlerRegistry
{
    /** @var array<string, WebhookHandlerInterface> */
    private array $handlers = [];

    /** @param iterable<WebhookHandlerInterface> $handlers */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            if (isset($this->handlers[$handler->topic()])) {
                throw new InvalidArgumentException("Webhook topic [{$handler->topic()}] 已注册多个 Handler。");
            }

            $this->handlers[$handler->topic()] = $handler;
        }
    }

    public function forTopic(string $topic): ?WebhookHandlerInterface
    {
        return $this->handlers[$topic] ?? null;
    }
}
