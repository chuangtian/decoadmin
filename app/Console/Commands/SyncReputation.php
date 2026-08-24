<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Reputation\ReputationSyncManager;
use App\Services\StoreFeishuDataLinkService;
use Illuminate\Console\Command;

class SyncReputation extends Command
{
    protected $signature = 'reputation:sync {--store= : 仅同步指定店铺 ID}';

    protected $description = '将当前项目配置的舆情电子表格排队同步到本地数据库';

    public function handle(ReputationSyncManager $manager, StoreFeishuDataLinkService $dataLinks): int
    {
        $query = Store::query()->where('status', 'active')->orderBy('id');
        if (filled($this->option('store'))) {
            $query->whereKey((int) $this->option('store'));
        }

        $queued = 0;
        $query->each(function (Store $store) use ($manager, $dataLinks, &$queued): void {
            if (! $dataLinks->sectionStatusForFrontend($store, 'reputation')['has_configuration']) {
                return;
            }

            $run = $manager->queue($store, 'scheduled');
            $queued++;
            $this->line("店铺 {$store->id} 已进入同步队列：{$run->uuid}");
        });

        $this->info("共提交 {$queued} 个舆情同步任务。");

        return self::SUCCESS;
    }
}
