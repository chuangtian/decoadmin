<?php

namespace App\Console\Commands;

use App\Models\InstagramMedia;
use App\Models\Store;
use App\Services\InstagramFeed\InstagramFeedStoreCredentials;
use App\Services\InstagramFeed\R2Client;
use Illuminate\Console\Command;
use Throwable;

/**
 * 一次性清理：转存从「视频 + 封面」改成「只要封面」之后的历史数据收尾。
 *
 * 做两件事：
 * 1. 删掉 R2 里遗留的视频对象并清空 video_key / video_url —— 它们再也不会被访问到，
 *    留着只是一直计存储费。
 * 2. 把 ready 但没有封面的条目重置为 pending —— 旧逻辑允许「视频转存成功、封面失败」，
 *    这类条目在新的前台（纯图片网格）里会是空白，必须重新转存拿到封面。
 *
 * 默认 --dry-run，确认无误再实跑。
 */
class PurgeInstagramFeedMirroredVideos extends Command
{
    protected $signature = 'instagram-feed:purge-mirrored-videos
        {--store= : 只处理指定店铺 ID}
        {--dry-run : 只报告将要做什么，不改动任何数据}
        {--chunk=100 : 每批处理条数}';

    protected $description = 'Delete legacy mirrored video objects and requeue mirrored media without a poster';

    public function handle(R2Client $r2, InstagramFeedStoreCredentials $credentials): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $storeFilter = $this->option('store');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->warn('dry-run 模式：不会删除对象、也不会改动数据库。');
        }

        $stores = Store::query()
            ->when(is_numeric($storeFilter), fn ($query) => $query->where('id', (int) $storeFilter))
            ->whereHas('instagramMedia')
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            $this->info('没有需要处理的店铺。');

            return self::SUCCESS;
        }

        $totalDeleted = 0;
        $totalFailed = 0;
        $totalRequeued = 0;

        foreach ($stores as $store) {
            // 凭证按店铺存，删对象要用这个店铺自己的桶。
            $credentials->apply($store);
            $configured = $r2->isConfigured();

            $withVideo = InstagramMedia::query()
                ->where('store_id', $store->id)
                ->whereNotNull('video_key')
                ->where('video_key', '!=', '')
                ->count();
            $missingPoster = InstagramMedia::query()
                ->where('store_id', $store->id)
                ->where('mirror_status', 'ready')
                ->where(fn ($query) => $query->whereNull('poster_url')->orWhere('poster_url', ''))
                ->count();

            $this->line(sprintf(
                '店铺 %d %s：遗留视频对象 %d 个，ready 但缺封面 %d 条%s',
                $store->id,
                $store->shopify_domain,
                $withVideo,
                $missingPoster,
                $configured ? '' : '（R2 未配置，只清字段不删对象）',
            ));

            if ($withVideo === 0 && $missingPoster === 0) {
                continue;
            }

            if ($dryRun) {
                $totalDeleted += $withVideo;
                $totalRequeued += $missingPoster;

                continue;
            }

            // 删对象分批做：一个店铺可能有上千个对象，逐条删且失败不中断其余。
            InstagramMedia::query()
                ->where('store_id', $store->id)
                ->whereNotNull('video_key')
                ->where('video_key', '!=', '')
                ->chunkById($chunk, function ($batch) use ($r2, $configured, &$totalDeleted, &$totalFailed): void {
                    $keys = $batch->pluck('video_key')->filter()->values()->all();
                    $failedKeys = [];
                    if ($configured && $keys !== []) {
                        try {
                            $failedKeys = $r2->deleteObjects($keys);
                        } catch (Throwable $exception) {
                            $failedKeys = $keys;
                            $this->warn('  删除对象失败：'.$exception->getMessage());
                        }
                    }

                    $totalDeleted += count($keys) - count($failedKeys);
                    $totalFailed += count($failedKeys);

                    // 字段一律清空：对象删不掉也不该让记录继续指向一个不再使用的地址。
                    // 删不掉的 key 已在上面告警，需要时人工去 R2 清。
                    InstagramMedia::query()
                        ->whereIn('id', $batch->modelKeys())
                        ->update(['video_key' => null, 'video_url' => null]);
                });

            $requeued = InstagramMedia::query()
                ->where('store_id', $store->id)
                ->where('mirror_status', 'ready')
                ->where(fn ($query) => $query->whereNull('poster_url')->orWhere('poster_url', ''))
                ->update(['mirror_status' => 'pending', 'mirror_error' => null, 'updated_at' => now()]);
            $totalRequeued += $requeued;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s已删除视频对象 %d 个%s，重新排队 %d 条缺封面的内容。',
            $dryRun ? '[dry-run] 将' : '',
            $totalDeleted,
            $totalFailed > 0 ? "（{$totalFailed} 个删除失败，需人工清理）" : '',
            $totalRequeued,
        ));

        return self::SUCCESS;
    }
}
