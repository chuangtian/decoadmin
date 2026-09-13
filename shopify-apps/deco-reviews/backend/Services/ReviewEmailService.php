<?php

namespace DecoReviews\Services;

use App\Models\Store;
use DecoReviews\Models\EmailDelivery;
use DecoReviews\Models\Review;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewEmailService
{
    public function scheduleCreated(Store $store, Review $review): void
    {
        if (! in_array($review->source, ['email', 'organic'], true) || ! $review->author_email) {
            return;
        }
        $type = $review->kind === 'store' ? 'store_thank_you' : 'product_thank_you';
        $this->schedule($store, $review, $type);
    }

    public function scheduleReply(Store $store, Review $review): void
    {
        if ($review->status !== 'published' || ! $review->author_email || ! filled($review->reply)) {
            return;
        }
        $this->schedule($store, $review, 'reply_notification');
    }

    public function schedule(Store $store, Review $review, string $type): ?EmailDelivery
    {
        abort_unless((int) $review->organization_id === (int) $store->organization_id && (int) $review->store_id === (int) $store->id, 404);
        abort_unless(in_array($type, ReviewEmail::TYPES, true), 422);
        $settings = app(ReviewService::class)->settings($store);
        if (! ($settings[$type.'_enabled'] ?? false) || ! $review->author_email) {
            return null;
        }
        $dedupe = hash('sha256', implode(':', [$store->organization_id, $store->id, $review->uuid, $type]));

        return EmailDelivery::query()->firstOrCreate(['dedupe_key' => $dedupe], [
            'uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'review_id' => $review->id, 'type' => $type, 'recipient' => $review->author_email,
            'recipient_hash' => app(ReviewService::class)->emailHash($store, $review->author_email),
            'status' => 'scheduled', 'due_at' => now(),
        ]);
    }

    public function process(int $organizationId, int $storeId, string $uuid): void
    {
        $store = Store::where('organization_id', $organizationId)->whereKey($storeId)->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->first();
        if (! $store || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return;
        }
        $lock = Cache::lock('deco-reviews:email:'.$organizationId.':'.$storeId.':'.$uuid, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $delivery = EmailDelivery::where('organization_id', $organizationId)->where('store_id', $storeId)
                ->where('uuid', $uuid)->with('review.product')->first();
            if (! $delivery || $delivery->status !== 'scheduled' || ($delivery->due_at && $delivery->due_at->isFuture())) {
                return;
            }
            DB::transaction(function () use ($store, $delivery) {
                $fresh = EmailDelivery::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status !== 'scheduled' || ! app(ReviewEmailDelivery::class)->allowed($store, $fresh)) {
                    return;
                }
                $fresh->update(['status' => 'sending', 'attempts' => $fresh->attempts + 1, 'error_code' => null]);
            });
            $delivery->refresh();
            if ($delivery->status !== 'sending') {
                return;
            }
            try {
                $sent = app(ReviewEmailDelivery::class)->send($store, $delivery);
            } catch (\Throwable) {
                $sent = false;
            }
            EmailDelivery::whereKey($delivery->id)->where('status', 'sending')->update($sent
                ? ['status' => 'sent', 'sent_at' => now(), 'due_at' => null, 'error_code' => null]
                : ['status' => 'held', 'due_at' => null, 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);
            app(ReviewService::class)->audit($store, null, $sent ? 'email.'.$delivery->type.'.sent' : 'email.'.$delivery->type.'.held', $delivery->review_id,
                ['delivery' => $delivery->uuid]);
        } finally {
            $lock->release();
        }
    }
}
