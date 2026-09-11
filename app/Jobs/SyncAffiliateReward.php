<?php

namespace App\Jobs;

use App\Domain\ReferralAffiliate\Services\AffiliateRewardService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAffiliateReward implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 100;

    public int $uniqueFor = 120;

    public function __construct(public int $organizationId, public int $storeId, public int $rewardId)
    {
        $this->onQueue('affiliate');
    }

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->storeId.':'.$this->rewardId;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AffiliateRewardService $service): void
    {
        $service->sync($this->organizationId, $this->storeId, $this->rewardId);
    }
}
