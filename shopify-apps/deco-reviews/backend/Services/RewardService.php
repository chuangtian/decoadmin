<?php

namespace DecoReviews\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\Media;
use DecoReviews\Models\Review;
use DecoReviews\Models\Reward;
use DecoReviews\Models\RewardDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardService
{
    public function schedule(Store $store, Review $review): ?Reward
    {
        abort_unless((int) $review->organization_id === (int) $store->organization_id && (int) $review->store_id === (int) $store->id, 404);
        $settings = app(ReviewService::class)->settings($store);
        $mediaKind = $this->eligibleMediaKind($store, $review, $settings);
        if (! $mediaKind) {
            return null;
        }

        $reward = Reward::firstOrCreate([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'review_id' => $review->id,
        ], [
            'uuid' => (string) Str::uuid(),
            'media_kind' => $mediaKind,
            'discount_kind' => $settings['reward_discount_kind'],
            'value' => $settings['reward_discount_kind'] === 'free_shipping' ? null : $settings['reward_value'],
            'currency' => $settings['reward_discount_kind'] === 'fixed' ? strtoupper((string) $store->currency) : null,
            'expiration_days' => $settings['reward_expiration_days'],
            'status' => 'scheduled',
            'due_at' => now(),
        ]);
        if ($reward->wasRecentlyCreated) {
            app(ReviewService::class)->audit($store, null, 'reward.scheduled', $review->id,
                ['reward' => $reward->uuid, 'media_kind' => $reward->media_kind]);
        }

        return $reward;
    }

    public function cancelScheduled(Store $store, Review $review): void
    {
        Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('review_id', $review->id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'due_at' => null]);
    }

    public function process(int $organizationId, int $storeId, string $uuid): void
    {
        $store = Store::where('organization_id', $organizationId)->whereKey($storeId)->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->first();
        if (! $store || ! config('deco_reviews.reward_writes_enabled')
            || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return;
        }
        $lock = Cache::lock('deco-reviews:reward:'.$organizationId.':'.$storeId.':'.$uuid, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $reward = DB::transaction(function () use ($store, $organizationId, $storeId, $uuid) {
                $activeStore = Store::where('organization_id', $organizationId)->whereKey($storeId)->where('status', 'active')
                    ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->lockForUpdate()->first();
                $candidate = Reward::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->where('uuid', $uuid)->first(['id', 'review_id']);
                if (! $activeStore || ! $candidate) {
                    return null;
                }
                $review = Review::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->whereKey($candidate->review_id)->lockForUpdate()->first();
                $fresh = Reward::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->whereKey($candidate->id)->where('uuid', $uuid)->lockForUpdate()->first();
                if (! $fresh || $fresh->status !== 'scheduled' || ($fresh->due_at && $fresh->due_at->isFuture())) {
                    return null;
                }
                $settings = app(ReviewService::class)->settings($store);
                if ($this->eligibleMediaKind($store, $review, $settings) !== $fresh->media_kind) {
                    $fresh->update(['status' => 'cancelled', 'due_at' => null]);

                    return null;
                }
                $code = 'DECO-'.Str::upper(Str::random(16));
                $fresh->update([
                    'status' => 'issuing',
                    'attempts' => $fresh->attempts + 1,
                    'code' => $code,
                    'code_hash' => hash('sha256', $code),
                    'expires_at' => now()->addDays($fresh->expiration_days),
                    'error_code' => null,
                ]);

                return $fresh;
            });
            if (! $reward) {
                return;
            }
            try {
                $discountId = app(ShopifyClient::class)->createReviewReward(
                    $store,
                    $reward->discount_kind,
                    $reward->code,
                    'Review reward '.$reward->uuid,
                    (float) ($reward->value ?? 0),
                    $reward->currency ?? strtoupper((string) $store->currency),
                    $reward->expires_at,
                );
            } catch (\Throwable) {
                $discountId = null;
            }
            DB::transaction(function () use ($store, $organizationId, $storeId, $reward, $discountId) {
                $fresh = Reward::where('organization_id', $organizationId)->where('store_id', $storeId)->whereKey($reward->id)
                    ->where('status', 'issuing')->lockForUpdate()->first();
                if (! $fresh) {
                    return;
                }
                if (! $discountId) {
                    $fresh->update(['status' => 'held', 'due_at' => null, 'error_code' => 'SHOPIFY_RESULT_REQUIRES_REVIEW']);

                    return;
                }
                $fresh->update(['status' => 'issued', 'shopify_discount_id' => $discountId, 'issued_at' => now(), 'due_at' => null, 'error_code' => null]);
                Review::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->whereKey($fresh->review_id)->update(['incentivized' => true]);
                app(RewardEmailService::class)->scheduleIssued($store, $fresh);
            });
            app(ReviewService::class)->audit($store, null, $discountId ? 'reward.issued' : 'reward.held', $reward->review_id,
                ['reward' => $reward->uuid, 'media_kind' => $reward->media_kind]);
        } finally {
            $lock->release();
        }
    }

    public function reconcileHeld(Store $store, User $user, string $uuid, string $conclusion): void
    {
        app(ReviewService::class)->authorize($user, $store, true);
        if (! in_array($conclusion, ['created', 'not_created'], true)) {
            throw ValidationException::withMessages(['conclusion' => '必须明确选择已创建或未创建。']);
        }
        DB::transaction(function () use ($store, $user, $uuid, $conclusion) {
            Store::where('organization_id', $store->organization_id)->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $reward = Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($reward, 404);
            if ($reward->status !== 'held') {
                throw ValidationException::withMessages(['reward' => '只有待复核奖励可以记录人工结论。']);
            }
            if ($conclusion === 'created') {
                if (! $reward->code || ! $reward->expires_at) {
                    throw ValidationException::withMessages(['reward' => '奖励记录缺少创建时快照，不能确认已创建。']);
                }
                $reward->update(['status' => 'issued', 'issued_at' => now(), 'due_at' => null, 'error_code' => 'MANUALLY_CONFIRMED_CREATED']);
                Review::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->whereKey($reward->review_id)->update(['incentivized' => true]);
                app(RewardEmailService::class)->scheduleIssued($store, $reward);
            } else {
                $reward->update(['status' => 'failed', 'code' => null, 'code_hash' => null, 'shopify_discount_id' => null,
                    'due_at' => null, 'error_code' => 'MANUALLY_CONFIRMED_NOT_CREATED']);
                RewardDelivery::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->where('reward_id', $reward->id)->where('status', 'scheduled')
                    ->update(['status' => 'cancelled', 'due_at' => null, 'error_code' => 'REWARD_NOT_CREATED']);
            }
            app(ReviewService::class)->audit($store, $user, 'reward.reconciled', $reward->review_id,
                ['reward' => $reward->uuid, 'conclusion' => $conclusion]);
        });
    }

    public function history(Store $store): array
    {
        app(ReviewService::class)->active($store);

        return Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->select(['id', 'uuid', 'review_id', 'media_kind', 'discount_kind', 'value', 'currency', 'status', 'due_at',
                'issued_at', 'expires_at', 'created_at', 'error_code'])
            ->with(['deliveries' => fn ($query) => $query->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->select(['id', 'reward_id', 'type', 'status', 'due_at', 'sent_at', 'error_code']),
                'review' => fn ($query) => $query->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->select(['id', 'product_id', 'title'])->with(['product' => fn ($product) => $product
                    ->where('organization_id', $store->organization_id)->where('store_id', $store->id)->select(['id', 'title'])])])
            ->latest('id')->limit(30)->get()->map(function (Reward $reward) {
                $initial = $reward->deliveries->firstWhere('type', 'reward_issued');
                $reminder = $reward->deliveries->firstWhere('type', 'reward_reminder');

                return [
                    'uuid' => $reward->uuid,
                    'media_kind' => $reward->media_kind,
                    'discount_kind' => $reward->discount_kind,
                    'value' => $reward->value,
                    'currency' => $reward->currency,
                    'status' => $reward->status,
                    'review_title' => $reward->review?->title,
                    'product_title' => $reward->review?->product?->title,
                    'due_at' => $reward->due_at?->toIso8601String(),
                    'issued_at' => $reward->issued_at?->toIso8601String(),
                    'expires_at' => $reward->expires_at?->toIso8601String(),
                    'created_at' => $reward->created_at->toIso8601String(),
                    'error_code' => $reward->error_code,
                    'email_status' => $initial?->status,
                    'email_due_at' => $initial?->due_at?->toIso8601String(),
                    'email_sent_at' => $initial?->sent_at?->toIso8601String(),
                    'email_error_code' => $initial?->error_code,
                    'reminder_status' => $reminder?->status,
                    'reminder_due_at' => $reminder?->due_at?->toIso8601String(),
                    'reminder_sent_at' => $reminder?->sent_at?->toIso8601String(),
                    'reminder_error_code' => $reminder?->error_code,
                ];
            })->all();
    }

    private function eligibleMediaKind(Store $store, ?Review $review, array $settings): ?string
    {
        if (! $review || (int) $review->organization_id !== (int) $store->organization_id || (int) $review->store_id !== (int) $store->id
            || ! $settings['rewards_enabled'] || $review->status !== 'published' || $review->source !== 'email'
            || $review->verified_source !== 'order' || ! $review->author_email || ! $review->order_id || ! $review->product_id) {
            return null;
        }
        $purchased = Order::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereKey($review->order_id)->whereHas('items', fn ($query) => $query->where('product_id', $review->product_id))->exists();
        if (! $purchased) {
            return null;
        }
        $types = Media::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('review_id', $review->id)->pluck('type');
        $mediaKind = $types->contains('video') ? 'video' : ($types->contains('image') ? 'photo' : null);

        return $mediaKind && $settings[$mediaKind.'_reward_enabled'] ? $mediaKind : null;
    }
}
