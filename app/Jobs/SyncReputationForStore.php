<?php

namespace App\Jobs;

use App\Models\ReputationSyncRun;
use App\Models\Store;
use App\Services\Reputation\ReputationSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncReputationForStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public int $uniqueFor = 1900;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly int $syncRunId,
    ) {}

    public function handle(ReputationSyncService $sync): void
    {
        $store = Store::query()->where('organization_id', $this->organizationId)->whereKey($this->storeId)->where('status', 'active')->first();
        $run = ReputationSyncRun::query()->forOrganization($this->organizationId)->forStore($this->storeId)->find($this->syncRunId);
        if (! $store || ! $run || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }

        $run->forceFill(['status' => 'running', 'progress_percent' => 5, 'started_at' => now(), 'last_error' => null])->save();

        try {
            $result = $sync->sync($store, $run);
            $run->forceFill([
                'status' => 'completed',
                'progress_percent' => 100,
                'processed_rows' => $result['raw_rows'],
                'result' => $result,
                'completed_at' => now(),
                'failed_at' => null,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
            $run->forceFill([
                'status' => 'failed',
                'last_error' => '舆情数据同步失败，请检查当前项目的数据源配置。',
                'failed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter($this->timeout + 60)];
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}";
    }
}
