<?php

namespace App\Contracts\Shopify;

use App\Models\WebhookEvent;

interface WebhookHandlerInterface
{
    public function topic(): string;

    public function handle(WebhookEvent $event): void;
}
