<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateManualAttributionService
{
    public function assign(Organization $org, Store $store, User $actor, string $conversionId, string $membershipId, string $reason, string $requestId): void
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.conversions.override');
        abort_unless(Str::isUuid($requestId) && mb_strlen(trim($reason)) >= 3, 422);
        $conversion = AffiliateConversion::query()->forOrganization($org)->forStore($store)->where('public_id', $conversionId)->firstOrFail();
        $order = app(AffiliateOrderReader::class)->read($store, $conversion->shopify_order_id);
        app(AffiliateAccountingService::class)->reconcile($store, $order);
        DB::transaction(function () use ($org, $store, $actor, $conversionId, $membershipId, $reason, $requestId, $order) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $conversion = AffiliateConversion::query()->forOrganization($org)->forStore($store)->where('public_id', $conversionId)->lockForUpdate()->firstOrFail();
            abort_if($conversion->shopify_updated_at->gt(CarbonImmutable::parse($order['updated_at'])), 409, '订单刚有变化，请刷新后重试。');
            $member = AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)->where('public_id', $membershipId)->with('program', 'promoter')->firstOrFail();
            if (data_get($conversion->attribution_snapshot, 'manual_request_id') === $requestId) {
                abort_unless($conversion->membership_id === $member->id && $conversion->reason === $reason, 409);

                return;
            }
            abort_unless($order['paid'] && ! $order['cancelled'] && ! $conversion->membership_id && ! $conversion->entries()->exists(), 409, '只能补归因尚未归属推广者的已付款订单。');
            abort_unless($member->status->value === 'approved' && $member->program->currency === $order['currency'] && $member->promoter->status === 'active', 422);
            abort_if($member->shopify_customer_id && $member->shopify_customer_id === ($order['customer_id'] ?? null), 422, '相同顾客自购不能补归因。');
            $engine = app(AffiliateCommissionEngine::class);
            $calculation = $engine->calculate($member, $order['lines'], CarbonImmutable::parse($order['ordered_at']));
            $cumulative = [];
            foreach ($order['refunds'] as $refund) {
                foreach ($refund['lines'] as $line) {
                    $id = $line['line_id'];
                    $cumulative[$id] ??= ['base_minor' => 0, 'quantity' => 0];
                    $cumulative[$id]['base_minor'] += $line['base_minor'];
                    $cumulative[$id]['quantity'] += $line['quantity'];
                }
            }
            $refund = $engine->refundTotals($calculation['lines'], $calculation['snapshot'], $calculation['base_minor'], $cumulative);
            $review = ($order['customer_email_hash'] ?? null) && hash_equals($member->promoter->email_hash, $order['customer_email_hash']);
            $old = $conversion->only(['membership_id', 'source', 'reason', 'attribution_snapshot']);
            $conversion->update(['membership_id' => $member->id, 'source' => 'manual', 'reason' => $reason, 'status' => $review ? 'review' : 'pending',
                'base_minor' => $calculation['base_minor'], 'refunded_base_minor' => $refund['base_minor'], 'commission_minor' => $calculation['commission_minor'],
                'reversed_minor' => min($calculation['commission_minor'], $refund['commission_minor']), 'rule_snapshot' => $calculation['snapshot'],
                'available_at' => CarbonImmutable::parse($order['ordered_at'])->addDays($calculation['snapshot']['hold_days']),
                'attribution_snapshot' => ['manual_request_id' => $requestId, 'actor_id' => $actor->id, 'previous' => $old['attribution_snapshot']]]);
            foreach ($calculation['lines'] as $line) {
                $conversion->lines()->create($line + ['organization_id' => $org->id, 'store_id' => $store->id]);
            }
            $conversion->risks()->where('rule', 'multiple_coupon_conflict')->where('status', 'open')->update(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
            if ($review) {
                $conversion->risks()->firstOrCreate(['rule' => 'same_email_hash'], ['organization_id' => $org->id, 'store_id' => $store->id, 'status' => 'open', 'evidence' => ['source' => 'manual']]);
            }
            foreach (['manual_accrual' => $conversion->commission_minor, 'manual_refund' => -$conversion->reversed_minor] as $type => $amount) {
                if ($amount === 0) {
                    continue;
                }
                AffiliateLedgerEntry::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'conversion_id' => $conversion->id,
                    'idempotency_key' => $type.':'.$requestId, 'type' => $type, 'status' => 'pending', 'currency' => $order['currency'], 'amount_minor' => $amount, 'available_at' => $conversion->available_at, 'created_by' => $actor->id, 'reason' => $reason]);
            }
            AuditLog::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'user_id' => $actor->id, 'action' => 'affiliate_manual_attribution', 'subject_type' => $conversion::class, 'subject_id' => $conversion->id, 'old_values' => $old, 'new_values' => ['membership_id' => $member->id, 'request_id' => $requestId], 'metadata' => ['reason' => $reason]]);
        });
    }
}
