<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateRiskFlag;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateLedgerService
{
    public function release(Store $store): int
    {
        app(AffiliateShopGuard::class)->store($store);

        return DB::transaction(function () use ($store) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $eligible = AffiliateConversion::query()->forOrganization($store->organization_id)->forStore($store)
                ->whereNotIn('status', ['rejected', 'review', 'unattributed'])->where('available_at', '<=', now())
                ->whereDoesntHave('risks', fn ($q) => $q->whereIn('status', ['open', 'reviewing', 'rejected']))
                ->whereHas('entries', fn ($q) => $q->where('status', 'pending'))->orderBy('id')->limit(1000)->pluck('id');
            $count = AffiliateLedgerEntry::query()->forOrganization($store->organization_id)->forStore($store)
                ->whereIn('conversion_id', $eligible)->where('status', 'pending')->where('available_at', '<=', now())->update(['status' => 'available']);
            AffiliateConversion::query()->whereIn('id', $eligible)->where('status', 'pending')->update(['status' => 'approved']);
            foreach (AffiliateConversion::query()->whereIn('id', $eligible)->with('membership.promoter', 'membership.program', 'membership.store')->get() as $conversion) {
                if ($conversion->membership) {
                    app(AffiliateNotificationService::class)->intent($conversion->membership, 'commission.available', 'available:'.$conversion->public_id);
                }
            }

            return $count;
        });
    }

    public function adjust(Organization $org, Store $store, User $actor, string $membershipId, int $amount, string $reason, string $requestId): AffiliateLedgerEntry
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.commissions.adjust');
        abort_unless($amount !== 0 && abs($amount) <= 1000000000 && mb_strlen(trim($reason)) >= 3 && Str::isUuid($requestId), 422);

        return DB::transaction(function () use ($org, $store, $actor, $membershipId, $amount, $reason, $requestId) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $member = AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)->where('public_id', $membershipId)->firstOrFail();
            $entry = AffiliateLedgerEntry::query()->firstOrCreate(['store_id' => $store->id, 'idempotency_key' => 'manual:'.$requestId], [
                'organization_id' => $org->id, 'membership_id' => $member->id, 'amount_minor' => $amount, 'currency' => $store->currency,
                'status' => 'available', 'type' => 'manual_adjustment', 'reason' => $reason, 'created_by' => $actor->id, 'available_at' => now(),
            ]);
            abort_unless((int) $entry->membership_id === (int) $member->id && $entry->amount_minor === $amount && $entry->reason === $reason, 409);
            if ($entry->wasRecentlyCreated) {
                app(AffiliateNotificationService::class)->intent($member, 'commission.adjusted', 'adjustment:'.$requestId);
                $this->audit($org, $store, $actor, 'affiliate_manual_adjustment', $entry, ['amount_minor' => $amount, 'reason' => $reason]);
            }

            return $entry;
        });
    }

    public function review(Organization $org, Store $store, User $actor, string $flagId, string $action, string $reason): void
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.fraud.review');
        abort_unless(in_array($action, ['approved', 'rejected', 'dismissed'], true) && mb_strlen(trim($reason)) >= 3, 422);
        DB::transaction(function () use ($org, $store, $actor, $flagId, $action, $reason) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $flag = AffiliateRiskFlag::query()->forOrganization($org)->forStore($store)->where('public_id', $flagId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($flag->status, ['open', 'reviewing'], true), 409);
            abort_if($flag->rule === 'self_customer_id' && $action !== 'rejected', 422, '相同顾客自购不能通过审核。');
            $flag->update(['status' => $action, 'review_reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $conversion = AffiliateConversion::query()->forOrganization($org)->forStore($store)->findOrFail($flag->conversion_id);
            if ($action === 'rejected') {
                $remaining = $conversion->commission_minor - $conversion->reversed_minor;
                if ($remaining > 0 && $conversion->membership_id) {
                    AffiliateLedgerEntry::query()->firstOrCreate(['store_id' => $store->id, 'idempotency_key' => 'risk-reject:'.$conversion->public_id], [
                        'organization_id' => $org->id, 'membership_id' => $conversion->membership_id, 'conversion_id' => $conversion->id,
                        'currency' => $conversion->currency, 'type' => 'manual_adjustment', 'status' => 'pending', 'amount_minor' => -$remaining,
                        'created_by' => $actor->id, 'reason' => $reason, 'available_at' => $conversion->available_at,
                    ]);
                    $conversion->reversed_minor += $remaining;
                }
                $conversion->status = 'rejected';
            } elseif (! $conversion->risks()->whereIn('status', ['open', 'reviewing', 'rejected'])->exists() && $conversion->membership_id) {
                $conversion->status = data_get($conversion->order_snapshot, 'cancelled', false) ? 'cancelled'
                    : ($conversion->refunded_base_minor > 0 ? ($conversion->refunded_base_minor >= $conversion->base_minor ? 'refunded' : 'partially_refunded') : 'pending');
            }
            $conversion->save();
            app(AffiliateRewardService::class)->record($conversion);
            $this->audit($org, $store, $actor, 'affiliate_risk_reviewed', $flag, ['action' => $action, 'reason' => $reason]);
        });
    }

    private function audit(Organization $org, Store $store, User $actor, string $action, object $subject, array $metadata): void
    {
        AuditLog::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'user_id' => $actor->id,
            'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->id, 'metadata' => $metadata]);
    }
}
