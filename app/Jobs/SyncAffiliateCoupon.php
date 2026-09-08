<?php

namespace App\Jobs;

use App\Domain\ReferralAffiliate\Services\AffiliateCouponSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAffiliateCoupon implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 100;

    public function __construct(public int $organizationId, public int $storeId, public int $couponId)
    {
        $this->onQueue('affiliate');
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(AffiliateCouponSyncService $service): void
    {
        $service->sync($this->organizationId, $this->storeId, $this->couponId);
    }
}
