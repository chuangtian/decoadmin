<?php

namespace App\Console\Commands;

use App\Jobs\SyncMetaAdsForStore;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncMetaAds extends Command
{
    protected $signature = 'meta-ads:sync
        {--store= : 仅同步指定店铺 ID}
        {--mode=incremental : 同步模式：priority、backfill、incremental、structure 或 full}';

    protected $description = '按店铺将 Meta Ads 广告结构或洞察数据同步到数据库';

    public function handle(): int
    {
        $mode = trim((string) $this->option('mode'));
        if (! in_array($mode, ['priority', 'backfill', 'incremental', 'structure', 'full'], true)) {
            $this->components->error('同步模式必须是 priority、backfill、incremental、structure 或 full。');

            return self::INVALID;
        }

        $storeOption = $this->option('store');
        $storeId = filled($storeOption) ? filter_var($storeOption, FILTER_VALIDATE_INT) : null;
        if (filled($storeOption) && ($storeId === false || $storeId < 1)) {
            $this->components->error('店铺 ID 必须是正整数。');

            return self::INVALID;
        }

        $stores = Store::query()
            ->select(['id', 'organization_id'])
            ->where('status', 'active')
            ->whereHas('businessCredentials', fn (Builder $credentials): Builder => $credentials
                ->whereColumn('store_business_credentials.organization_id', 'stores.organization_id')
                ->where('provider', 'meta_ads')
                ->where('credential_key', 'access_token'))
            ->when($storeId !== null, fn (Builder $query): Builder => $query->whereKey($storeId))
            ->with(['businessCredentials' => fn ($credentials) => $credentials
                ->where('provider', 'meta_ads')
                ->where('credential_key', 'access_token')])
            ->get();

        foreach ($stores as $store) {
            $credential = $store->businessCredentials->first();
            SyncMetaAdsForStore::dispatch(
                (int) $store->organization_id,
                (int) $store->getKey(),
                $mode,
                $credential?->updated_at?->utc()->format('Y-m-d H:i:s'),
            );
        }

        $this->components->info(sprintf('已提交 %d 个店铺的 Meta Ads %s 同步任务。', $stores->count(), $mode));

        return self::SUCCESS;
    }
}
