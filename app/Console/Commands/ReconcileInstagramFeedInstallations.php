<?php

namespace App\Console\Commands;

use App\Exceptions\InstagramFeedException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\InstagramFeedInstallation;
use App\Services\InstagramFeed\InstagramFeedAppRegistry;
use Illuminate\Console\Command;

/**
 * 把已经 bootstrap 过、但没有 app_installations 记录的店铺补进应用中心。
 *
 * 早期 bootstrap 只写了 instagram_feed_installations，应用中心的可见性判断读的是
 * app_installations，所以这些店铺装了 App 也不会出现在应用中心。改动上线后跑一次即可，
 * 之后 bootstrap 与 webhook 都会自动维护。重复执行是安全的。
 */
class ReconcileInstagramFeedInstallations extends Command
{
    protected $signature = 'instagram-feed:reconcile-installations
        {--shop= : 只处理指定的 myshopify 域名}
        {--dry-run : 只报告将要处理的店铺，不写入}';

    protected $description = 'Backfill App Center installation records for stores that already bootstrapped the Instagram Feed app';

    public function handle(InstagramFeedAppRegistry $registry): int
    {
        $shop = strtolower(trim((string) $this->option('shop')));
        $dryRun = (bool) $this->option('dry-run');
        $environment = $registry->environment();

        try {
            $app = $registry->configuredApp();
        } catch (InstagramFeedException $exception) {
            $this->error("当前环境（{$environment}）的 Instagram Feed App 配置不完整：{$exception->getMessage()}");

            return self::FAILURE;
        }

        $feedInstallations = InstagramFeedInstallation::query()
            ->where('environment', $environment)
            ->when($shop !== '', fn ($query) => $query
                ->whereHas('store', fn ($query) => $query->where('shopify_domain', $shop)))
            ->with('store')
            ->get();

        if ($feedInstallations->isEmpty()) {
            $this->warn("环境 {$environment} 下没有找到需要同步的 Instagram Feed 安装记录。");

            return self::SUCCESS;
        }

        $synchronized = 0;
        $skipped = 0;

        foreach ($feedInstallations as $feedInstallation) {
            $store = $feedInstallation->store;
            if (! $store) {
                $this->warn("跳过安装记录 {$feedInstallation->uuid}：关联店铺不存在。");
                $skipped++;

                continue;
            }

            $existing = AppInstallation::query()
                ->where('app_id', $app->id)
                ->where('store_id', $store->id)
                ->where('status', 'active')
                ->exists();
            if ($existing) {
                $this->line("已是最新，跳过：{$store->shopify_domain}");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("将同步：{$store->shopify_domain}");
                $synchronized++;

                continue;
            }

            $installation = $registry->synchronizeInstallation(
                $store,
                'active',
                is_array($feedInstallation->granted_scopes) ? $feedInstallation->granted_scopes : [],
                'instagram_feed_reconciliation',
                $feedInstallation->app_installation_id,
            );

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'instagram_feed_shopify_app_reconciled',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => $environment,
                    'source_installation_uuid' => $feedInstallation->uuid,
                ],
            ]);

            $this->info("已同步：{$store->shopify_domain}");
            $synchronized++;
        }

        $verb = $dryRun ? '待同步' : '已同步';
        $this->info("{$verb} {$synchronized} 个店铺，跳过 {$skipped} 个。");

        return self::SUCCESS;
    }
}
