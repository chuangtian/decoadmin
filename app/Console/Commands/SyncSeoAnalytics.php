<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\SeoAnalytics\SeoAnalyticsConfigurationService;
use App\Services\SeoAnalytics\SeoAnalyticsSyncManager;
use Illuminate\Console\Command;

class SyncSeoAnalytics extends Command
{
    protected $signature = 'seo-analytics:sync {--mode=incremental} {--store=}';

    protected $description = '将 GA4 与 Google Search Console 数据异步同步到本地数据库';

    public function handle(SeoAnalyticsSyncManager $manager, SeoAnalyticsConfigurationService $configuration): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['incremental', 'backfill'], true)) {
            return self::INVALID;
        }
        $stores = Store::query()->where('status', 'active')->when($this->option('store'), fn ($query) => $query->whereKey((int) $this->option('store')))
            ->get()->filter(fn (Store $store): bool => $configuration->status($store)['configured']);
        foreach ($stores as $store) {
            $manager->queue($store, 'scheduled', null, $mode);
        }
        $this->components->info("已提交 {$stores->count()} 个 SEO 分析同步任务。");

        return self::SUCCESS;
    }
}
