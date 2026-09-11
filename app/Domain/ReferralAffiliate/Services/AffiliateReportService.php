<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

class AffiliateReportService
{
    public const EXCLUDED = ['unattributed', 'rejected', 'cancelled'];

    public function metrics(Organization $org, Store $store, User $actor, ?int $days = null): array
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.dashboard.view');
        $query = AffiliateConversion::query()->forOrganization($org)->forStore($store)->whereNotNull('membership_id')->whereNotIn('status', self::EXCLUDED);
        abort_unless($days === null || in_array($days, [7, 30, 90], true), 422);
        $from = $days ? now()->subDays($days - 1)->startOfDay() : null;
        if ($from) {
            $query->where('ordered_at', '>=', $from);
        }
        $totals = (clone $query)->selectRaw('COUNT(*) AS orders, COALESCE(SUM(base_minor - refunded_base_minor),0) AS sales, COALESCE(SUM(commission_minor),0) AS commission, COALESCE(SUM(reversed_minor),0) AS reversed, COALESCE(SUM(refunded_base_minor),0) AS refunded, SUM(CASE WHEN refunded_base_minor > 0 THEN 1 ELSE 0 END) AS refund_orders')->first();
        $clicks = app(AffiliateTrackingStatistics::class)->count($store, from: $from);
        $ranked = (clone $query)->selectRaw('membership_id,COUNT(*) AS orders,COALESCE(SUM(base_minor-refunded_base_minor),0) AS sales,COALESCE(SUM(commission_minor-reversed_minor),0) AS net_commission')->groupBy('membership_id')->orderByDesc('sales')->limit(20)->get();
        $members = AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)->with('promoter:id,display_name', 'program:id,name')->whereIn('id', $ranked->pluck('membership_id'))->get()->keyBy('id');
        $ranking = $ranked->map(fn ($r) => ['membership_id' => $members[$r->membership_id]->public_id, 'name' => $members[$r->membership_id]->promoter->display_name, 'program' => $members[$r->membership_id]->program->name, 'orders' => (int) $r->orders, 'sales' => Money::decimal((int) $r->sales, $store->currency), 'commission' => Money::decimal((int) $r->net_commission, $store->currency)])->all();
        $trend = (clone $query)->selectRaw('DATE(ordered_at) AS day,COUNT(*) AS orders,COALESCE(SUM(base_minor-refunded_base_minor),0) AS sales')->groupByRaw('DATE(ordered_at)')->orderByDesc('day')->limit(90)->get()->reverse()->values()->map(fn ($r) => ['day' => $r->day, 'orders' => (int) $r->orders, 'sales' => Money::decimal((int) $r->sales, $store->currency)])->all();
        $balances = AffiliateLedgerEntry::query()->forOrganization($org)->forStore($store)->selectRaw('status,SUM(amount_minor) AS total')->groupBy('status')->get()->mapWithKeys(fn ($row) => [$row->status => Money::decimal((int) $row->total, $store->currency)])->all();

        return ['orders' => (int) $totals->orders, 'clicks' => $clicks, 'conversion_rate' => $clicks ? round(100 * (int) $totals->orders / $clicks, 2) : 0,
            'net_sales' => Money::decimal((int) $totals->sales, $store->currency), 'commission' => Money::decimal((int) $totals->commission, $store->currency),
            'reversed' => Money::decimal((int) $totals->reversed, $store->currency), 'balances' => $balances, 'refund_orders' => (int) $totals->refund_orders, 'refund_rate' => $totals->orders ? round(100 * (int) $totals->refund_orders / (int) $totals->orders, 2) : 0, 'refunded_sales' => Money::decimal((int) $totals->refunded, $store->currency), 'ranking' => $ranking, 'trend' => $trend];
    }

    public function organization(Organization $org, User $actor, array $storeIds): array
    {
        $result = [];
        foreach (Store::query()->where('organization_id', $org->id)->whereIn('id', $storeIds)->get() as $store) {
            $result[] = ['store' => $store->only('id', 'name', 'currency'), 'metrics' => $this->metrics($org, $store, $actor)];
        }
        abort_unless(count($result) === count(array_unique($storeIds)), 404);

        return $result;
    }
}
