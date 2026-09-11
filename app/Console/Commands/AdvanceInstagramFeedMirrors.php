<?php

namespace App\Console\Commands;

use App\Models\InstagramAccount;
use App\Services\InstagramFeed\InstagramFeedStoreCredentials;
use App\Services\InstagramFeed\InstagramMirrorService;
use App\Services\InstagramFeed\InstagramProviderService;
use App\Services\InstagramFeed\R2Client;
use Illuminate\Console\Command;
use Throwable;

/**
 * 定时推进 Instagram 媒体的 R2 转存，并续期快到期的长效 token。
 *
 * 转存在应用页面上是「点一次搬一批」，媒体多的时候一次点不完；这里兜底把队列
 * 慢慢清空，商家不必反复点按钮。
 *
 * 凭证按店铺存，所以「是否配了 R2」也是按店铺判断的：每个店铺进入循环时先加载
 * 自己的凭证，再检查就绪状态。不能在循环外做一次全局判断就整体跳过。
 */
class AdvanceInstagramFeedMirrors extends Command
{
    protected $signature = 'instagram-feed:advance-mirrors {--store= : 只处理指定店铺 ID} {--batch= : 单个店铺单次转存条数}';

    protected $description = 'Advance pending Instagram media mirrors and refresh expiring Meta tokens';

    public function handle(
        InstagramMirrorService $mirror,
        InstagramProviderService $providers,
        R2Client $r2,
        InstagramFeedStoreCredentials $credentials,
    ): int {
        $storeFilter = $this->option('store');
        $batch = $this->option('batch');
        $batchSize = is_numeric($batch) ? max(1, (int) $batch) : null;

        $accounts = InstagramAccount::query()
            ->where('status', 'connected')
            ->when(is_numeric($storeFilter), fn ($query) => $query->where('store_id', (int) $storeFilter))
            ->with('store')
            ->get();

        $ready = 0;
        $failed = 0;
        $pending = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $store = $account->store;
            if (! $store) {
                continue;
            }

            // 必须每个店铺都重新加载：apply() 是进程内的 config 覆盖，
            // 漏掉一次就会用上一个店铺的凭证去操作这个店铺的数据。
            $credentials->apply($store);

            if (! $r2->isConfigured()) {
                $skipped++;

                continue;
            }

            // 顺带续期：token 快过期时提前换新的，避免定时任务某天突然全部失败。
            try {
                $providers->ensureFreshToken($account);
            } catch (Throwable $exception) {
                $this->warn("店铺 {$store->shopify_domain} 的 Instagram 令牌不可用：{$exception->getMessage()}");

                continue;
            }

            try {
                $result = $mirror->mirrorPending($store, $batchSize);
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("店铺 {$store->shopify_domain} 转存失败：{$exception->getMessage()}");

                continue;
            }

            $ready += $result['ready'];
            $failed += $result['failed'];
            $pending += $result['pending'];
        }

        if ($skipped > 0) {
            $this->warn("有 {$skipped} 个店铺未配置 Cloudflare R2，已跳过转存。");
        }
        $this->info("已转存 {$ready} 条，失败 {$failed} 条，仍待处理 {$pending} 条。");

        return self::SUCCESS;
    }
}
