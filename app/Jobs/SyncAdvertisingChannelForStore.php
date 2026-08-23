<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Advertising\AdvertisingChannelSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncAdvertisingChannelForStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public int $uniqueFor = 2100;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly string $channel,
        public readonly string $mode = 'incremental',
        public readonly ?string $credentialVersion = null,
    ) {
        $this->onQueue('advertising-sync');
    }

    public function handle(AdvertisingChannelSyncService $sync): void
    {
        $store = Store::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->storeId)
            ->where('status', 'active')
            ->first();
        if ($store) {
            $sync->sync($store, $this->channel, $this->mode, $this->credentialVersion);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}:{$this->channel}:{$this->mode}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("advertising-sync:{$this->organizationId}:{$this->storeId}:{$this->channel}"))
            ->releaseAfter(60)->expireAfter($this->timeout + 120)->shared()];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 600];
    }
}
