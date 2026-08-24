<?php

namespace App\Services\Reputation;

use App\Jobs\SyncReputationForStore;
use App\Models\ReputationSyncRun;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreFeishuDataLinkService;

class ReputationSyncManager
{
    public function __construct(private StoreFeishuDataLinkService $dataLinks) {}

    public function queue(Store $store, string $source = 'manual', ?User $actor = null): ReputationSyncRun
    {
        $active = ReputationSyncRun::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();
        if ($active) {
            return $active;
        }

        $status = $this->dataLinks->sectionStatusForFrontend($store, 'reputation');
        abort_unless($status['has_configuration'], 422, '当前店铺尚未配置舆情数据源。');

        $run = ReputationSyncRun::query()->create([
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->id,
            'requested_by' => $actor?->id,
            'source' => $source,
            'status' => 'queued',
            'progress_percent' => 0,
        ]);

        SyncReputationForStore::dispatch((int) $store->organization_id, (int) $store->id, (int) $run->id);

        return $run;
    }
}
