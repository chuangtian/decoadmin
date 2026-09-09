<?php

namespace App\Jobs;

use App\Domain\ReferralAffiliate\Services\AffiliateAccountingService;
use App\Domain\ReferralAffiliate\Services\AffiliateOrderReader;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAffiliateOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 100;

    public int $uniqueFor = 300;

    public function __construct(public int $organizationId, public int $storeId, public string $orderId)
    {
        $this->onQueue('affiliate');
    }

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->storeId.':'.$this->orderId;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AffiliateOrderReader $reader, AffiliateAccountingService $accounting): void
    {
        $store = Store::query()->where('organization_id', $this->organizationId)->findOrFail($this->storeId);
        $accounting->reconcile($store, $reader->read($store, $this->orderId));
    }
}
