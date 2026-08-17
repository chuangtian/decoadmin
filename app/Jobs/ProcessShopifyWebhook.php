<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessShopifyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(): void
    {
        $event = DB::transaction(function (): ?WebhookEvent {
            $event = WebhookEvent::query()->lockForUpdate()->find($this->webhookEventId);

            if (! $event || $event->status === 'processed') {
                return null;
            }

            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
                'next_retry_at' => null,
                'last_error' => null,
            ])->save();

            return $event;
        });

        if (! $event) {
            return;
        }

        try {
            // Topic-specific handlers are intentionally added in later business modules.
            $event->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'last_error' => $this->safeError($event, $exception->getMessage()),
                'next_retry_at' => now()->addMinutes(5),
            ])->save();

            throw $exception;
        }
    }

    private function safeError(WebhookEvent $event, string $message): string
    {
        $event->loadMissing(['app', 'shopifyConnection']);
        $secrets = array_filter([
            $event->app?->client_secret_encrypted,
            $event->shopifyConnection?->access_token_encrypted,
            $event->shopifyConnection?->refresh_token_encrypted,
        ], fn ($secret) => is_string($secret) && $secret !== '');

        return mb_substr(str_replace($secrets, '[redacted]', $message), 0, 2000);
    }
}
