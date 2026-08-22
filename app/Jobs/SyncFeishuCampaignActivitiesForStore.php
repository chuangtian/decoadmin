<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\CampaignThemeRefreshService;
use App\Services\Feishu\CampaignActivitySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncFeishuCampaignActivitiesForStore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $storeId,
        public readonly ?int $actorId,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        CampaignActivitySyncService $sync,
        CampaignThemeRefreshService $refresh,
    ): void {
        $store = Store::query()->findOrFail($this->storeId);
        $refresh->markRunning($store);
        $result = $sync->syncStore($store);
        $refresh->markCompleted($store, $this->actorId, $result);
    }

    public function tries(): int
    {
        return 2;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("feishu-campaign-sync:{$this->storeId}"))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120)
                ->shared(),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $store = Store::query()->find($this->storeId);

        if ($store) {
            app(CampaignThemeRefreshService::class)->markFailed($store, $this->actorId);
        }
    }
}
