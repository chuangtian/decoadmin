<?php

namespace DecoReviews\Services;

use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\Review;
use DecoReviews\Models\Reward;
use DecoReviews\Models\RewardDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardEmailService
{
    public function scheduleIssued(Store $store, Reward $reward): ?RewardDelivery
    {
        return $this->schedule($store, $reward, 'reward_issued', now());
    }

    public function scheduleReminder(Store $store, Reward $reward): ?RewardDelivery
    {
        $settings = app(ReviewService::class)->settings($store);
        $initial = RewardDelivery::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('reward_id', $reward->id)->where('type', 'reward_issued')->where('status', 'sent')->first();
        if (! $settings['reward_reminder_enabled'] || ! $initial?->sent_at) {
            return null;
        }
        $dueAt = $initial->sent_at->copy()->addDays($settings['reward_reminder_days']);
        if (! $reward->expires_at || $dueAt->gte($reward->expires_at)) {
            return null;
        }

        return $this->schedule($store, $reward, 'reward_reminder', $dueAt);
    }

    public function process(int $organizationId, int $storeId, string $uuid): void
    {
        $store = Store::where('organization_id', $organizationId)->whereKey($storeId)->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->first();
        if (! $store || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return;
        }
        $lock = Cache::lock('deco-reviews:reward-email:'.$organizationId.':'.$storeId.':'.$uuid, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $delivery = DB::transaction(function () use ($store, $organizationId, $storeId, $uuid) {
                Store::where('organization_id', $organizationId)->whereKey($storeId)->lockForUpdate()->firstOrFail();
                $candidate = RewardDelivery::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->where('uuid', $uuid)->first(['id', 'reward_id']);
                if (! $candidate) {
                    return null;
                }
                $reward = Reward::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->whereKey($candidate->reward_id)->lockForUpdate()->first();
                $fresh = RewardDelivery::where('organization_id', $organizationId)->where('store_id', $storeId)
                    ->whereKey($candidate->id)->where('uuid', $uuid)->lockForUpdate()->first();
                if (! $fresh || ! $reward || $fresh->status !== 'scheduled' || ($fresh->due_at && $fresh->due_at->isFuture())) {
                    return null;
                }
                $fresh->setRelation('reward', $reward);
                if ($code = app(RewardEmailDelivery::class)->cancellationCode($store, $fresh)) {
                    $fresh->update(['status' => 'cancelled', 'due_at' => null, 'error_code' => $code]);

                    return null;
                }
                if (! app(RewardEmailDelivery::class)->transportAllowed($store, $fresh)) {
                    return null;
                }
                $fresh->update(['status' => 'sending', 'attempts' => $fresh->attempts + 1, 'error_code' => null]);

                return $fresh;
            });
            if (! $delivery) {
                return;
            }
            try {
                $sent = app(RewardEmailDelivery::class)->send($store, $delivery);
            } catch (\Throwable) {
                $sent = false;
            }
            $sentAt = now();
            RewardDelivery::where('organization_id', $organizationId)->where('store_id', $storeId)->whereKey($delivery->id)
                ->where('status', 'sending')->update($sent
                    ? ['status' => 'sent', 'sent_at' => $sentAt, 'due_at' => null, 'error_code' => null]
                    : ['status' => 'held', 'due_at' => null, 'error_code' => 'DELIVERY_RESULT_REQUIRES_REVIEW']);
            if ($sent && $delivery->type === 'reward_issued') {
                $reward = Reward::where('organization_id', $organizationId)->where('store_id', $storeId)->whereKey($delivery->reward_id)->first();
                if ($reward) {
                    $this->scheduleReminder($store, $reward);
                }
            }
            app(ReviewService::class)->audit($store, null, $sent ? 'reward.email.'.$delivery->type.'.sent' : 'reward.email.'.$delivery->type.'.held', null,
                ['reward' => $delivery->reward?->uuid, 'delivery' => $delivery->uuid]);
        } finally {
            $lock->release();
        }
    }

    public function reconcileHeld(Store $store, User $user, string $uuid, string $conclusion): void
    {
        app(ReviewService::class)->authorize($user, $store, true);
        if (! in_array($conclusion, ['sent', 'not_sent'], true)) {
            throw ValidationException::withMessages(['conclusion' => '必须明确选择已发送或未发送。']);
        }

        $reward = null;
        $delivery = DB::transaction(function () use ($store, $user, $uuid, $conclusion, &$reward) {
            Store::where('organization_id', $store->organization_id)->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $delivery = RewardDelivery::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($delivery, 404);
            if ($delivery->status !== 'held') {
                throw ValidationException::withMessages(['delivery' => '只有发送结果待复核的奖励邮件可以记录人工结论。']);
            }

            $reward = Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereKey($delivery->reward_id)->lockForUpdate()->first();
            abort_unless($reward, 404);
            $delivery->update($conclusion === 'sent'
                ? ['status' => 'sent', 'sent_at' => now(), 'due_at' => null, 'error_code' => 'MANUALLY_CONFIRMED_SENT']
                : ['status' => 'failed', 'sent_at' => null, 'due_at' => null, 'error_code' => 'MANUALLY_CONFIRMED_NOT_SENT']);
            app(ReviewService::class)->audit($store, $user, 'reward.email.reconciled', $reward->review_id, [
                'reward' => $reward->uuid,
                'delivery' => $delivery->uuid,
                'type' => $delivery->type,
                'conclusion' => $conclusion,
            ]);

            return $delivery;
        });

        if ($conclusion === 'sent' && $delivery->type === 'reward_issued' && $reward) {
            $this->scheduleReminder($store, $reward);
        }
    }

    private function schedule(Store $store, Reward $reward, string $type, \DateTimeInterface $dueAt): ?RewardDelivery
    {
        abort_unless((int) $reward->organization_id === (int) $store->organization_id && (int) $reward->store_id === (int) $store->id, 404);
        abort_unless(in_array($type, RewardEmail::TYPES, true), 422);
        $review = Review::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereKey($reward->review_id)->first();
        $reward->setRelation('review', $review);
        $settings = app(ReviewService::class)->settings($store);
        if ($reward->status !== 'issued' || ! $reward->code || ! $reward->expires_at || $reward->expires_at->isPast()
            || ! $settings['rewards_enabled'] || ! $review?->author_email) {
            return null;
        }
        $dedupe = hash('sha256', implode(':', [$store->organization_id, $store->id, $reward->uuid, $type]));

        return RewardDelivery::firstOrCreate(['dedupe_key' => $dedupe], [
            'uuid' => (string) Str::uuid(),
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'reward_id' => $reward->id,
            'type' => $type,
            'recipient' => $review->author_email,
            'recipient_hash' => app(ReviewService::class)->emailHash($store, $review->author_email),
            'status' => 'scheduled',
            'due_at' => $dueAt,
        ]);
    }
}
