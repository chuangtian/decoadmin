<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

class AffiliateReportService
{
    public const EXCLUDED = ['unattributed', 'rejected', 'cancelled'];

    public function metrics(Organization $org, Store $store, User $actor): array
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.dashboard.view');
        $query = AffiliateConversion::query()->forOrganization($org)->forStore($store)->whereNotNull('membership_id')->whereNotIn('status', self::EXCLUDED);
        $totals = (clone $query)->selectRaw('COUNT(*) AS orders, COALESCE(SUM(base_minor - refunded_base_minor),0) AS sales, COALESCE(SUM(commission_minor),0) AS commission, COALESCE(SUM(reversed_minor),0) AS reversed')->first();
        $clicks = AffiliateClick::query()->forOrganization($org)->forStore($store)->count();
        $balances = AffiliateLedgerEntry::query()->forOrganization($org)->forStore($store)->selectRaw('status,SUM(amount_minor) AS total')->groupBy('status')->get()->mapWithKeys(fn ($row) => [$row->status => Money::decimal((int) $row->total, $store->currency)])->all();

        return ['orders' => (int) $totals->orders, 'clicks' => $clicks, 'conversion_rate' => $clicks ? round(100 * (int) $totals->orders / $clicks, 2) : 0,
            'net_sales' => Money::decimal((int) $totals->sales, $store->currency), 'commission' => Money::decimal((int) $totals->commission, $store->currency),
            'reversed' => Money::decimal((int) $totals->reversed, $store->currency), 'balances' => $balances];
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
