<?php

namespace App\Services\Shopify\Webhooks;

class WebhookProcessingResult
{
    private function __construct(
        public readonly string $result,
        public readonly ?string $handler,
        public readonly ?string $reason,
    ) {}

    public static function handled(string $handler): self
    {
        return new self('handled', $handler, null);
    }

    public static function unsupported(string $reason): self
    {
        return new self('unsupported', null, $reason);
    }
}
