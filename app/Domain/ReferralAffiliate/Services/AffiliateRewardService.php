<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Models\AffiliateRewardLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateRiskFlag;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Jobs\SyncAffiliateReward;
use App\Models\AuditLog;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AffiliateRewardService
{
    /** Record usage from the verified paid order, without waiting for Shopify's asynchronous usage counter. */
    public function recordRedemptions(Store $store, array $order): void
    {
        app(AffiliateShopGuard::class)->store($store);
        if (! ($order['paid'] ?? false) || empty($order['customer_id'])) {
            return;
        }
        $codes = array_map(fn ($code) => strtoupper(trim($code)), $order['discount_codes'] ?? []);
        if (! $codes) {
            return;
        }
        AffiliateReward::query()->forOrganization($store->organization_id)->forStore($store)->where('customer_id', $order['customer_id'])->whereIn('code', $codes)->lockForUpdate()->get()->each(function ($reward) use ($order) {
            if ($reward->redeemed_order_id && $reward->redeemed_order_id !== $order['id']) {
                return;
            }
            $reward->update(['redeemed_order_id' => $order['id'], 'status' => 'redeemed']);
            $this->history($reward, 'redeemed', $order['ordered_at']);
            SyncAffiliateReward::dispatch($reward->organization_id, $reward->store_id, $reward->id)->afterCommit();
        });
    }

    public function record(AffiliateConversion $conversion): void
    {
        if (! $conversion->membership_id) {
            return;
        }
        $member = $conversion->membership()->with('program', 'store', 'promoter')->firstOrFail();
        if ($member->program->type->value !== 'advocate') {
            return;
        }
        app(AffiliateShopGuard::class)->store($member->store);
        $rule = data_get($conversion->rule_snapshot, 'reward');
        if ($rule && $member->shopify_customer_id) {
            $reward = $this->create($member, 'conversion:'.$conversion->public_id, $rule, $conversion->id, null, $conversion->available_at);
            SyncAffiliateReward::dispatch($member->organization_id, $member->store_id, $reward->id)->afterCommit();
        }
        $count = $this->qualifying($member)->count();
        foreach (data_get($member->program->settings, 'milestones', []) as $milestone) {
            if ($count >= $milestone['threshold'] && $member->shopify_customer_id) {
                $reward = $this->create($member, 'milestone:'.$member->public_id.':'.$milestone['threshold'], $milestone['reward'], null, $milestone['threshold'], now());
                SyncAffiliateReward::dispatch($member->organization_id, $member->store_id, $reward->id)->afterCommit();
            }
        }
        AffiliateReward::query()->forStore($member->store_id)->where('membership_id', $member->id)->whereNotNull('threshold')->whereNotIn('status', ['revoked', 'expired'])
            ->limit(30)->get()->each(fn ($r) => SyncAffiliateReward::dispatch($r->organization_id, $r->store_id, $r->id)->afterCommit());
    }

    private function create(AffiliateProgramMembership $member, string $key, array $rule, ?int $conversion, ?int $threshold, $available): AffiliateReward
    {
        $reward = AffiliateReward::query()->firstOrCreate(['store_id' => $member->store_id, 'dedupe_key' => $key], [
            'organization_id' => $member->organization_id, 'membership_id' => $member->id, 'conversion_id' => $conversion, 'threshold' => $threshold,
            'customer_id' => $member->shopify_customer_id, 'rule_snapshot' => $rule + ['currency' => $member->program->currency],
            'status' => 'pending', 'available_at' => $available ?? now()]);
        $this->history($reward, 'earned', $reward->created_at);

        return $reward;
    }

    private function history(AffiliateReward $reward, string $event, $at = null): void
    {
        AffiliateRewardLedgerEntry::query()->firstOrCreate(['reward_id' => $reward->id, 'event' => $event], [
            'organization_id' => $reward->organization_id, 'store_id' => $reward->store_id, 'membership_id' => $reward->membership_id,
            'rule_snapshot' => $reward->rule_snapshot, 'occurred_at' => $at ?? now(),
        ]);
    }

    private function qualifying(AffiliateProgramMembership $member)
    {
        return AffiliateConversion::query()->forOrganization($member->organization_id)->forStore($member->store_id)->where('membership_id', $member->id)
            ->whereIn('status', ['pending', 'approved', 'partially_refunded'])->whereColumn('base_minor', '>', 'refunded_base_minor')->where('available_at', '<=', now())
            ->whereDoesntHave('risks', fn ($q) => $q->whereIn('status', ['open', 'reviewing', 'rejected']));
    }

    private function validSource(AffiliateReward $reward, AffiliateProgramMembership $member): bool
    {
        if ($member->status->value !== 'approved' || ! $member->promoter || $member->promoter->status !== 'active') {
            return false;
        }
        if (! $reward->shopify_discount_id && AffiliateRiskFlag::query()->forStore($member->store_id)->where('rule', 'reward_used_after_refund')->whereIn('status', ['open', 'reviewing'])->whereIn('conversion_id', AffiliateConversion::query()->forStore($member->store_id)->where('membership_id', $member->id)->select('id'))->exists()) {
            return false;
        }
        if ($reward->conversion_id) {
            return $this->qualifying($member)->whereKey($reward->conversion_id)->exists();
        }

        return $this->qualifying($member)->count() >= (int) $reward->threshold;
    }

    public function sync(int $orgId, int $storeId, int $id): void
    {
        $store = Store::query()->where('organization_id', $orgId)->findOrFail($storeId);
        app(AffiliateShopGuard::class)->store($store);
        Cache::lock('affiliate-reward:'.$storeId.':'.$id, 120)->block(5, function () use ($store, $orgId, $storeId, $id) {
            $reward = AffiliateReward::query()->forOrganization($orgId)->forStore($storeId)->findOrFail($id);
            if (in_array($reward->status, ['revoked', 'expired'], true) || $reward->attempts >= 10) {
                return;
            }
            $member = AffiliateProgramMembership::query()->forOrganization($orgId)->forStore($storeId)->with('program', 'promoter')->findOrFail($reward->membership_id);
            $due = $reward->available_at->lte(now());
            $valid = $this->validSource($reward, $member);
            $invalidPermanent = $member->status->value !== 'approved' || ($reward->conversion_id && AffiliateConversion::query()->whereKey($reward->conversion_id)->where(fn ($q) => $q->whereIn('status', ['cancelled', 'refunded', 'rejected'])->orWhereColumn('base_minor', '<=', 'refunded_base_minor'))->exists());
            if (! $due && ! $invalidPermanent) {
                return;
            }
            if (! $valid && ! $reward->shopify_discount_id && ! $reward->redeemed_order_id) {
                $reward->update(['status' => $invalidPermanent ? 'revoked' : 'pending', 'last_synced_at' => now()]);
                if ($invalidPermanent) {
                    $this->history($reward, 'revoked');
                }

                return;
            }
            $enabled = $member->program->status->value === 'active' && AffiliateStoreSetting::query()->forStore($store)->where('customer_referral_enabled', true)->exists();
            if (! $enabled && ! $reward->shopify_discount_id) {
                return;
            }
            if (($reward->rule_snapshot['currency'] ?? $store->currency) !== $store->currency) {
                throw new \RuntimeException('Reward currency requires review');
            }
            $reward->expires_at ??= now()->addDays((int) ($reward->rule_snapshot['valid_days'] ?? 30));
            $reward->attempts++;
            $reward->last_synced_at = now();
            $reward->save();
            try {
                $result = app(AffiliateRewardCouponService::class)->synchronize($store, $reward, ! $valid);
                DB::transaction(function () use ($store, $reward, $result, $member) {
                    Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
                    $reward->refresh();
                    if ($reward->redeemed_order_id) {
                        $result['status'] = 'redeemed';
                    }
                    $freshMember = $member->fresh(['program', 'promoter']);
                    $stillValid = $this->validSource($reward, $freshMember);
                    $firstIssue = ! $reward->issued_at && $result['status'] === 'issued';
                    $reward->update(['shopify_discount_id' => $result['id'], 'status' => (! $stillValid && $result['status'] === 'issued') ? 'revoke_pending' : $result['status'],
                        'issued_at' => $reward->issued_at ?? ($result['status'] === 'issued' ? now() : null), 'last_error' => null, 'attempts' => 0]);
                    if (in_array($result['status'], ['issued', 'redeemed', 'expired', 'revoked'], true)) {
                        $this->history($reward, $result['status']);
                    }
                    if ($reward->status === 'revoke_pending') {
                        SyncAffiliateReward::dispatch($reward->organization_id, $reward->store_id, $reward->id)->afterCommit();
                    }
                    if ($result['status'] === 'redeemed' && ! $stillValid) {
                        $source = $reward->conversion ?? AffiliateConversion::query()->forOrganization($reward->organization_id)->forStore($reward->store_id)->where('membership_id', $member->id)->whereIn('status', ['refunded', 'cancelled', 'rejected'])->latest('id')->first();
                        $source?->risks()->firstOrCreate(['rule' => 'reward_used_after_refund'], ['organization_id' => $reward->organization_id, 'store_id' => $reward->store_id, 'status' => 'open', 'evidence' => ['reward_public_id' => $reward->public_id]]);
                    }
                    if ($firstIssue && $stillValid) {
                        app(AffiliateNotificationService::class)->intent($freshMember, 'advocate.reward_issued', 'reward:'.$reward->public_id);
                        if ($reward->conversion_id) {
                            $reward->conversion()->where('status', 'pending')->update(['status' => 'approved']);
                            $this->record($reward->conversion->fresh());
                        }
                    }
                    AuditLog::query()->create(['organization_id' => $reward->organization_id, 'store_id' => $reward->store_id, 'action' => 'affiliate_reward_synced', 'subject_type' => $reward::class, 'subject_id' => $reward->id, 'metadata' => ['status' => $reward->status]]);
                });
            } catch (\Throwable $e) {
                $reward->update(['status' => 'failed', 'last_error' => '奖励码同步失败，请检查授权、顾客及奖励规则。']);
                throw new \RuntimeException('Affiliate reward synchronization failed');
            }
        });
    }

    public function tick(Store $store): void
    {
        app(AffiliateShopGuard::class)->store($store);
        AffiliateReward::query()->forOrganization($store->organization_id)->forStore($store)->whereNotIn('status', ['revoked', 'expired'])->where('attempts', '<', 10)->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHour()))->orderBy('last_synced_at')->limit(100)->get()
            ->each(fn ($r) => SyncAffiliateReward::dispatch($r->organization_id, $r->store_id, $r->id));
    }
}
