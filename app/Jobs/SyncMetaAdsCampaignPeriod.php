<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Services\MetaAds\MetaAdsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMetaAdsCampaignPeriod implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public int $tries = 3;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly string $account,
        public readonly string $since,
        public readonly string $until,
        public readonly string $credentialVersion,
    ) {
        $this->onQueue('meta-ads');
    }

    public function handle(MetaAdsSyncService $sync): void
    {
        $store = Store::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->storeId)
            ->where('status', 'active')
            ->first();
        if (! $store) {
            return;
        }

        $credential = StoreBusinessCredential::query()
            ->where('organization_id', $this->organizationId)
            ->where('store_id', $this->storeId)
            ->where('provider', 'meta_ads')
            ->where('credential_key', 'access_token')
            ->first();
        $currentVersion = $credential?->updated_at?->utc()->format('Y-m-d H:i:s') ?? '';
        if (! $credential || ! hash_equals($this->credentialVersion, $currentVersion)) {
            return;
        }

        $sync->syncCampaignPeriodSnapshot(
            $store,
            $this->account,
            $this->since,
            $this->until,
            $currentVersion,
        );
    }

    public function uniqueId(): string
    {
        return implode(':', [
            $this->organizationId,
            $this->storeId,
            $this->account,
            $this->since,
            $this->until,
        ]);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }
}
