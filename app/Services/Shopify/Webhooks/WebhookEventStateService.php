<?php

namespace App\Services\Shopify\Webhooks;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

class WebhookEventStateService
{
    public function markQueued(WebhookEvent $event): WebhookEvent
    {
        $event->forceFill(['status' => 'queued'])->save();

        return $event;
    }

    public function markProcessing(int $eventId): ?WebhookEvent
    {
        return DB::transaction(function () use ($eventId): ?WebhookEvent {
            $event = WebhookEvent::query()->lockForUpdate()->find($eventId);

            if (! $event || $event->status === 'processed') {
                return null;
            }

            $event->forceFill([
                'status' => 'processing',
                'processing_result' => null,
                'handler' => null,
                'unsupported_reason' => null,
                'processing_started_at' => now(),
                'processing_duration_ms' => null,
                'processed_at' => null,
                'attempts' => $event->attempts + 1,
                'next_retry_at' => null,
                'last_error' => null,
            ])->save();

            return $event;
        });
    }

    public function markProcessed(WebhookEvent $event, WebhookProcessingResult $result): WebhookEvent
    {
        $processedAt = now();
        $event->forceFill([
            'status' => 'processed',
            'processing_result' => $result->result,
            'handler' => $result->handler,
            'unsupported_reason' => $result->reason,
            'processed_at' => $processedAt,
            'processing_duration_ms' => $this->durationMs($event, $processedAt),
            'last_error' => null,
            'next_retry_at' => null,
        ])->save();

        return $event;
    }

    public function markFailed(WebhookEvent $event, string $error): WebhookEvent
    {
        $failedAt = now();
        $event->forceFill([
            'status' => 'failed',
            'processing_result' => 'failed',
            'processing_duration_ms' => $this->durationMs($event, $failedAt),
            'last_error' => $this->safeError($event, $error),
            'next_retry_at' => $failedAt->copy()->addMinutes(5),
        ])->save();

        return $event;
    }

    public function markRetrying(WebhookEvent $event): WebhookEvent
    {
        $event->forceFill([
            'status' => 'retrying',
            'processing_result' => null,
            'handler' => null,
            'unsupported_reason' => null,
            'processing_started_at' => null,
            'processing_duration_ms' => null,
            'processed_at' => null,
            'next_retry_at' => null,
        ])->save();

        return $event;
    }

    private function durationMs(WebhookEvent $event, \DateTimeInterface $finishedAt): ?int
    {
        if (! $event->processing_started_at) {
            return null;
        }

        return max(0, (int) round(
            ((float) $finishedAt->format('U.u') - (float) $event->processing_started_at->format('U.u')) * 1000,
        ));
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
