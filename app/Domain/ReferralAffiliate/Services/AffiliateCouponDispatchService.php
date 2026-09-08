<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Jobs\SyncAffiliateCoupon;
use App\Models\Store;

class AffiliateCouponDispatchService
{
    public function dispatch(Store $store, ?int $programId = null, ?int $membershipId = null): void
    {
        app(AffiliateShopGuard::class)->store($store);
        AffiliateCoupon::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->when($membershipId, fn ($query) => $query->where('membership_id', $membershipId))
            ->when($programId, fn ($query) => $query->whereHas('membership', fn ($member) => $member->where('program_id', $programId)))
            ->chunkById(100, function ($coupons): void {
                foreach ($coupons as $coupon) {
                    $coupon->forceFill(['status' => 'sync_pending', 'last_error' => null])->save();
                    SyncAffiliateCoupon::dispatch((int) $coupon->organization_id, (int) $coupon->store_id, (int) $coupon->id)->afterCommit();
                }
            });
    }
}
