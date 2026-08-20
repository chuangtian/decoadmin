<?php

namespace App\Jobs;

use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RefreshShopifyAnalyticsSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $uniqueFor;

    public int $tries = 3;

    public function __construct(public readonly int $snapshotId)
    {
        $this->onQueue('shopify-analytics');
        $this->timeout = max(45, (int) config('shopify.analytics_snapshot_refresh_lock_seconds', 90));
        $this->uniqueFor = $this->timeout + 60;
    }

    public function handle(ShopifyAnalyticsReportService $reports): void
    {
        $reports->refreshSnapshot($this->snapshotId);
    }

    public function uniqueId(): string
    {
        return (string) $this->snapshotId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("shopify-analytics-snapshot:{$this->snapshotId}"))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 30)
                ->shared(),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }
}
