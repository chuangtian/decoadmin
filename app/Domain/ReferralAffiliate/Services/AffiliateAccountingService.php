<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateRefundRecord;
use App\Models\AuditLog;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AffiliateAccountingService
{
    public function __construct(private AffiliateAttributionEngine $attribution, private AffiliateCommissionEngine $commissions) {}

    /** Only server-fetched, normalized Shopify snapshots may enter this service. */
    public function reconcile(Store $store, array $order): ?AffiliateConversion
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless(preg_match('/^gid:\/\/shopify\/Order\/\d+$/D', $order['id']) === 1, 422);

        return DB::transaction(function () use ($store, $order) {
            // The same tenant lock is used by settlement and manual accounting operations.
            Store::query()->where('organization_id', $store->organization_id)->whereKey($store->id)->lockForUpdate()->firstOrFail();
            app(AffiliateRewardService::class)->recordRedemptions($store, $order);
            $conversion = AffiliateConversion::query()->forOrganization($store->organization_id)->forStore($store)
                ->where('shopify_order_id', $order['id'])->lockForUpdate()->first();
            if (! $conversion) {
                if (! $order['paid']) {
                    return null;
                }
                $choice = $this->attribution->resolve($store, $order);
                $member = $choice['membership'];
                $calculation = $member ? $this->commissions->calculate($member, $order['lines'], CarbonImmutable::parse($order['ordered_at']))
                    : ['lines' => [], 'base_minor' => 0, 'commission_minor' => 0, 'snapshot' => []];
                $hardBlocked = in_array('self_customer_id', $choice['risks'], true);
                $status = ! $member ? 'unattributed' : ($hardBlocked ? 'rejected' : ($choice['risks'] ? 'review' : 'pending'));
                $at = CarbonImmutable::parse($order['ordered_at']);
                $conversion = AffiliateConversion::query()->create([
                    'organization_id' => $store->organization_id, 'store_id' => $store->id,
                    'shopify_order_id' => $order['id'], 'order_name' => $order['name'],
                    'membership_id' => $member?->id, 'status' => $status, 'source' => $choice['source'], 'reason' => $choice['reason'],
                    'currency' => $order['currency'], 'is_test' => $order['is_test'],
                    'base_minor' => $calculation['base_minor'], 'commission_minor' => $hardBlocked ? 0 : $calculation['commission_minor'],
                    'reversed_minor' => 0, 'rule_snapshot' => $calculation['snapshot'],
                    'attribution_snapshot' => ['source_click_id' => $choice['source_click_id'] ?? null, 'candidates' => $choice['candidates'], 'risks' => $choice['risks'], 'version' => 1],
                    'order_snapshot' => $order, 'ordered_at' => $at,
                    'available_at' => $member ? $at->addDays($calculation['snapshot']['hold_days'] ?? $member->program->hold_days) : null,
                    'shopify_updated_at' => $order['updated_at'],
                ]);
                foreach ($calculation['lines'] as $line) {
                    $conversion->lines()->create($line + ['organization_id' => $store->organization_id, 'store_id' => $store->id]);
                }
                foreach ($choice['risks'] as $risk) {
                    $conversion->risks()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id,
                        'rule' => $risk, 'status' => 'open', 'evidence' => ($choice['risk_evidence'][$risk] ?? []) + ['engine_version' => 1]]);
                }
                if ($conversion->commission_minor > 0 && $member && ! $hardBlocked) {
                    $this->entry($conversion, 'accrual:'.$order['id'], 'commission_accrual', $conversion->commission_minor);
                }
                $this->audit($conversion, 'affiliate_conversion_created');
                if ($member && ! $hardBlocked) {
                    app(AffiliateNotificationService::class)->intent($member, 'conversion.created', 'conversion:'.$conversion->public_id);
                }
            }
            if (CarbonImmutable::parse($order['updated_at'])->lt($conversion->shopify_updated_at)) {
                return $conversion;
            }
            $this->refunds($conversion, $order['refunds']);
            if ($order['cancelled']) {
                $remaining = $conversion->commission_minor - $conversion->reversed_minor;
                if ($remaining > 0 && $conversion->membership_id) {
                    $this->entry($conversion, 'cancel:'.$order['id'], 'cancellation_adjustment', -$remaining);
                    $conversion->reversed_minor += $remaining;
                }
                $conversion->status = 'cancelled';
            }
            $conversion->order_snapshot = $order;
            $conversion->shopify_updated_at = $order['updated_at'];
            $conversion->save();
            app(AffiliateRewardService::class)->record($conversion);
            app(AffiliatePostPurchaseService::class)->enqueue($store, $order);

            return $conversion->fresh();
        }, 3);
    }

    private function refunds(AffiliateConversion $conversion, array $refunds): void
    {
        $conversion->loadMissing('lines');
        $previous = AffiliateRefundRecord::query()->where('conversion_id', $conversion->id)->get();
        $cumulative = [];
        foreach ($previous as $refund) {
            $this->accumulate($cumulative, $refund->line_snapshot);
        }
        usort($refunds, fn ($a, $b) => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
        foreach ($refunds as $refund) {
            if ($previous->contains('shopify_refund_id', $refund['id'])) {
                continue;
            }
            $this->accumulate($cumulative, $refund['lines']);
            $totals = $this->commissions->refundTotals($conversion->lines->toArray(), $conversion->rule_snapshot, $conversion->base_minor, $cumulative);
            $target = $totals['commission_minor'];
            $conversion->refunded_base_minor = $totals['base_minor'];
            if ($conversion->refunded_base_minor > 0 && ! in_array($conversion->status, ['cancelled', 'rejected', 'review'], true)) {
                $conversion->status = $conversion->refunded_base_minor >= $conversion->base_minor ? 'refunded' : 'partially_refunded';
            }
            $delta = max(0, min($conversion->commission_minor, $target) - $conversion->reversed_minor);
            AffiliateRefundRecord::query()->create(['organization_id' => $conversion->organization_id, 'store_id' => $conversion->store_id,
                'conversion_id' => $conversion->id, 'shopify_refund_id' => $refund['id'], 'line_snapshot' => $refund['lines'],
                'adjustment_minor' => $delta, 'refunded_at' => $refund['created_at']]);
            if ($delta > 0 && $conversion->membership_id) {
                $this->entry($conversion, 'refund:'.$refund['id'], 'refund_adjustment', -$delta);
                $conversion->reversed_minor += $delta;
                if (! in_array($conversion->status, ['cancelled', 'rejected', 'review'], true)) {
                    $conversion->status = $conversion->reversed_minor >= $conversion->commission_minor ? 'refunded' : 'partially_refunded';
                }
                $this->audit($conversion, 'affiliate_refund_adjusted');
                app(AffiliateNotificationService::class)->intent($conversion->membership, 'commission.adjusted', 'refund:'.$refund['id']);
            }
        }
    }

    private function accumulate(array &$total, array $lines): void
    {
        foreach ($lines as $line) {
            $key = $line['line_id'];
            $total[$key] ??= ['base_minor' => 0, 'quantity' => 0];
            $total[$key]['base_minor'] += max(0, (int) $line['base_minor']);
            $total[$key]['quantity'] += max(0, (int) $line['quantity']);
        }
    }

    private function entry(AffiliateConversion $conversion, string $key, string $type, int $amount): void
    {
        $available = $conversion->available_at?->lte(now()) && ! $conversion->risks()->whereIn('status', ['open', 'reviewing', 'rejected'])->exists();
        AffiliateLedgerEntry::query()->firstOrCreate(['store_id' => $conversion->store_id, 'idempotency_key' => $key], [
            'organization_id' => $conversion->organization_id, 'membership_id' => $conversion->membership_id,
            'conversion_id' => $conversion->id, 'currency' => $conversion->currency, 'amount_minor' => $amount,
            'type' => $type, 'status' => $available ? 'available' : 'pending', 'available_at' => $conversion->available_at,
            'metadata' => ['is_test' => $conversion->is_test, 'engine_version' => 1],
        ]);
    }

    private function audit(AffiliateConversion $conversion, string $action): void
    {
        AuditLog::query()->create(['organization_id' => $conversion->organization_id, 'store_id' => $conversion->store_id,
            'action' => $action, 'subject_type' => AffiliateConversion::class, 'subject_id' => $conversion->id,
            'metadata' => ['source' => $conversion->source, 'status' => $conversion->status, 'reversed_minor' => $conversion->reversed_minor]]);
    }
}
