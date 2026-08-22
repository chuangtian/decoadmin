<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\PaidAdvertisingGoalSyncRun;
use App\Models\Store;
use App\Models\User;
use App\Services\PaidAdvertisingGoalRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncPaidAdvertisingGoalTarget implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly int $organizationId,
        public readonly int $storeId,
        public readonly int $actorId,
        public readonly string $trigger,
        public readonly string $target,
    ) {
        $this->onQueue('default');
    }

    public function handle(PaidAdvertisingGoalRefreshService $refresh): void
    {
        $run = PaidAdvertisingGoalSyncRun::query()->find($this->runId);

        if (! $run instanceof PaidAdvertisingGoalSyncRun) {
            return;
        }

        try {
            $organization = Organization::query()->findOrFail($this->organizationId);
            $store = Store::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->findOrFail($this->storeId);
            $actor = User::query()->findOrFail($this->actorId);

            $refresh->markRunning($run);
            $result = $refresh->syncNow($organization, $store, $actor, $this->trigger, $this->target);
            $refresh->markCompleted($run, $result);
        } catch (ModelNotFoundException) {
            $refresh->markFailed($run, '同步目标已删除，本次同步未执行。');
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = PaidAdvertisingGoalSyncRun::query()->find($this->runId);

        if ($run instanceof PaidAdvertisingGoalSyncRun) {
            app(PaidAdvertisingGoalRefreshService::class)->markFailed($run, $exception);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->storeId}:{$this->target}";
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
}
