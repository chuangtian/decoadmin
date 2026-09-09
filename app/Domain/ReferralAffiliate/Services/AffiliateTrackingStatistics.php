<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AffiliateTrackingStatistics
{
    public function count(Store $store, ?int $membershipId = null, ?CarbonInterface $from = null): int
    {
        app(AffiliateShopGuard::class)->store($store);
        $raw = AffiliateClick::query()->forOrganization($store->organization_id)->forStore($store)
            ->when($membershipId, fn ($q) => $q->where('membership_id', $membershipId))->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))->count();
        $archived = DB::table('affiliate_click_totals')->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->when($membershipId, fn ($q) => $q->where('membership_id', $membershipId))->when($from, fn ($q) => $q->where('day', '>=', $from->toDateString()))->sum('clicks');

        return $raw + (int) $archived;
    }
}
