<?php

namespace App\Jobs;

use App\Domain\ReferralAffiliate\Services\AffiliatePostPurchaseService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepareAffiliateInvitation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 100;

    public int $uniqueFor = 120;

    public function __construct(public int $organizationId, public int $storeId, public string $orderId, public int $programId)
    {
        $this->onQueue('affiliate');
    }

    public function uniqueId(): string
    {
        return $this->storeId.':'.$this->orderId.':'.$this->programId;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AffiliatePostPurchaseService $service): void
    {
        $service->prepare($this->organizationId, $this->storeId, $this->orderId, $this->programId);
    }
}
