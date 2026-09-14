<?php

namespace DecoReviews\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoReviews\Models\Reward;
use Illuminate\Support\Facades\DB;

class RewardLifecycleService
{
    public const TOPICS = ['orders/paid', 'orders/cancelled', 'refunds/create'];

    public function process(Store $store, string $topic, array $payload, ?string $webhookId, string $rawPayload): void
    {
        if (! in_array($topic, self::TOPICS, true) || $store->status !== 'active' || $store->organization?->status !== 'active') {
            return;
        }

        $payloadHash = hash('sha256', $rawPayload);
        $receiptId = $this->receiptId($webhookId, $topic, $payloadHash);
        DB::transaction(function () use ($store, $topic, $payload, $payloadHash, $receiptId) {
            $inserted = DB::table('deco_review_webhook_receipts')->insertOrIgnore([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'webhook_id' => $receiptId,
                'topic' => $topic,
                'payload_hash' => $payloadHash,
                'processed_at' => now(),
            ]);
            if ($inserted !== 1) {
                return;
            }

            match ($topic) {
                'orders/paid' => $this->redeemed($store, $payload),
                'orders/cancelled' => $this->cancelled($store, $payload),
                'refunds/create' => $this->refunded($store, $payload),
            };
        });
    }

    private function redeemed(Store $store, array $payload): void
    {
        $orderId = $this->externalId($payload['id'] ?? null);
        if (! $orderId) {
            return;
        }
        $hashes = collect($payload['discount_codes'] ?? [])->take(100)
            ->filter(fn ($entry) => is_array($entry) && is_string($entry['code'] ?? null))
            ->map(fn ($entry) => strtoupper(trim($entry['code'])))
            ->filter(fn ($code) => $code !== '' && strlen($code) <= 255)
            ->unique()
            ->map(fn ($code) => hash('sha256', $code))
            ->values();
        if ($hashes->isEmpty()) {
            return;
        }
        $at = $this->timestamp($payload['processed_at'] ?? $payload['updated_at'] ?? null);
        Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('status', 'issued')->whereIn('code_hash', $hashes)->lockForUpdate()->get()
            ->each(function (Reward $reward) use ($store, $orderId, $at) {
                $reward->update(['status' => 'redeemed', 'redeemed_order_id' => $orderId, 'redeemed_at' => $at]);
                app(ReviewService::class)->audit($store, null, 'reward.redeemed', $reward->review_id, ['reward' => $reward->uuid]);
            });
    }

    private function refunded(Store $store, array $payload): void
    {
        $orderId = $this->externalId($payload['order_id'] ?? null);
        if (! $orderId) {
            return;
        }
        $at = $this->timestamp($payload['processed_at'] ?? $payload['created_at'] ?? null);
        Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('status', 'redeemed')->where('redeemed_order_id', $orderId)->whereNull('refunded_at')
            ->lockForUpdate()->get()->each(function (Reward $reward) use ($store, $at) {
                $reward->update(['refunded_at' => $at]);
                app(ReviewService::class)->audit($store, null, 'reward.order_refunded', $reward->review_id, ['reward' => $reward->uuid]);
            });
    }

    private function cancelled(Store $store, array $payload): void
    {
        $orderId = $this->externalId($payload['id'] ?? null);
        if (! $orderId) {
            return;
        }
        $at = $this->timestamp($payload['cancelled_at'] ?? $payload['updated_at'] ?? null);
        Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('status', 'redeemed')->where('redeemed_order_id', $orderId)->whereNull('cancelled_order_at')
            ->lockForUpdate()->get()->each(function (Reward $reward) use ($store, $at) {
                $reward->update(['cancelled_order_at' => $at]);
                app(ReviewService::class)->audit($store, null, 'reward.order_cancelled', $reward->review_id, ['reward' => $reward->uuid]);
            });
    }

    private function externalId(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' && strlen($value) <= 128 ? $value : null;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : CarbonImmutable::now();
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function receiptId(?string $webhookId, string $topic, string $payloadHash): string
    {
        $webhookId = trim((string) $webhookId);
        if ($webhookId !== '' && strlen($webhookId) <= 80 && preg_match('/^[A-Za-z0-9._:-]+$/', $webhookId)) {
            return $topic.':'.$webhookId;
        }

        return 'payload:'.hash('sha256', $topic.':'.$payloadHash);
    }
}
