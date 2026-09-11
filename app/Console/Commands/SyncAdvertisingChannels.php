<?php

namespace App\Console\Commands;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\Store;
use App\Services\Advertising\AdvertisingChannelSyncService;
use Illuminate\Console\Command;

class SyncAdvertisingChannels extends Command
{
    protected $signature = 'advertising-channels:sync {channel} {--mode=incremental} {--store=}';

    protected $description = '按店铺同步广告渠道账户及日指标数据';

    public function handle(AdvertisingChannelSyncService $sync): int
    {
        $channel = trim((string) $this->argument('channel'));
        $mode = trim((string) $this->option('mode'));
        if (! isset(AdvertisingChannelSyncService::CHANNELS[$channel])
            || ! in_array($mode, ['priority', 'backfill', 'incremental', 'reconcile'], true)
            || ($mode === 'reconcile' && $channel !== 'google')) {
            $this->components->error('广告渠道或同步模式无效。');

            return self::INVALID;
        }

        $storeId = $this->option('store');
        $stores = Store::query()
            ->where('status', 'active')
            ->when(filled($storeId), fn ($query) => $query->whereKey((int) $storeId))
            ->get()
            ->filter(fn (Store $store): bool => $sync->configured($store, $channel));
        $submitted = 0;
        foreach ($stores as $store) {
            if ($sync->cooldownSeconds($store, $channel, $sync->credentialVersion($store, $channel)) > 0) {
                continue;
            }
            SyncAdvertisingChannelForStore::dispatch(
                (int) $store->organization_id,
                (int) $store->getKey(),
                $channel,
                $sync->resumeMode($store, $channel, $mode, $sync->credentialVersion($store, $channel)),
                $sync->credentialVersion($store, $channel),
            );
            $submitted++;
        }
        $this->components->info("已提交 {$submitted} 个店铺的 {$channel} 同步任务（冷却中的店铺已跳过）。");

        return self::SUCCESS;
    }
}
