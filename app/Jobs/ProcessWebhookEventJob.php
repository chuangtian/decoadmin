<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\WebhookEventProcessor;
use App\Services\Shopify\Webhooks\WebhookEventStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('shopify-webhook');
    }

    public function handle(
        WebhookEventProcessor $processor,
        WebhookEventStateService $states,
    ): void {
        $event = $states->markProcessing($this->webhookEventId);

        if (! $event) {
            return;
        }

        try {
            $states->markProcessed($event, $processor->process($event));
        } catch (Throwable $exception) {
            $states->markFailed($event, $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event && $event->status !== 'processed') {
            app(WebhookEventStateService::class)->markFailed($event, $exception->getMessage());
        }
    }
}
