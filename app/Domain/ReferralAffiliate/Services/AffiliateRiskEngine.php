<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Models\Store;
use Carbon\CarbonImmutable;

class AffiliateRiskEngine
{
    public function evaluate(Store $store, AffiliateProgramMembership $member, array $order, ?AffiliateClick $click): array
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless((int) $member->store_id === (int) $store->id && (int) $member->organization_id === (int) $store->organization_id, 403);
        $at = CarbonImmutable::parse($order['ordered_at']);
        $risks = [];
        $orders = AffiliateConversion::query()->forOrganization($store->organization_id)->forStore($store)->where('membership_id', $member->id)->where('shopify_order_id', '!=', $order['id'])->whereNotIn('status', ['unattributed', 'rejected', 'cancelled']);
        $limit = max(2, (int) config('referral.risk.order_velocity_limit', 10));
        $recent = (clone $orders)->whereBetween('ordered_at', [$at->subMinutes(10), $at])->count();
        if ($recent + 1 >= $limit) {
            $risks['order_velocity'] = ['observed_orders' => $recent + 1, 'limit' => $limit, 'window_minutes' => 10];
        }
        if ($click && $click->ip_hash && $click->ua_hash) {
            abort_unless((int) $click->store_id === (int) $store->id && (int) $click->organization_id === (int) $store->organization_id, 403);
            $same = AffiliateClick::query()->forOrganization($store->organization_id)->forStore($store)->where('ip_hash', $click->ip_hash)->where('ua_hash', $click->ua_hash)->whereBetween('occurred_at', [$at->subDay(), $at]);
            $sourceLimit = max(2, (int) config('referral.risk.same_source_order_limit', 3));
            $sourceOrders = (clone $orders)->whereBetween('ordered_at', [$at->subDay(), $at])->whereIn('attribution_snapshot->source_click_id', (clone $same)->select('id'))->count();
            if ($sourceOrders + 1 >= $sourceLimit) {
                $risks['same_source_orders'] = ['observed_orders' => $sourceOrders + 1, 'limit' => $sourceLimit, 'window_hours' => 24];
            }
            $clickLimit = max(10, (int) config('referral.risk.click_burst_limit', 60));
            $burst = (clone $same)->where('occurred_at', '>=', $at->subMinute())->count();
            if ($burst >= $clickLimit) {
                $risks['click_burst'] = ['observed_clicks' => $burst, 'limit' => $clickLimit, 'window_seconds' => 60];
            }
        }

        return $risks;
    }
}
