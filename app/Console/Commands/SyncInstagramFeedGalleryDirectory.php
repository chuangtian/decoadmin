<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\InstagramFeed\InstagramGalleryDirectory;
use Illuminate\Console\Command;
use Throwable;

/**
 * 把展示组同步成主题编辑器里可选的 metaobject 条目。
 *
 * 平时不需要手动跑：增删改展示组时控制器会自动同步。这个命令用于两种场景：
 * 1. 商家刚为 write_metaobjects 重新授权，需要把已有的历史展示组一次性补齐；
 * 2. definition 或条目被人在 Shopify 后台改坏了，需要重建。
 *
 * 顺序提醒：带 metaobject 设置的主题扩展必须在 definition 建好之后才能发布，否则
 * 主题编辑器会把那个设置直接显示成错误。跑通这个命令就是发布扩展的前置条件。
 */
class SyncInstagramFeedGalleryDirectory extends Command
{
    protected $signature = 'instagram-feed:sync-gallery-directory
        {--store= : 只处理指定店铺 ID}';

    protected $description = 'Sync Instagram galleries into the app-owned metaobject entries used by the theme editor picker';

    public function handle(InstagramGalleryDirectory $directory): int
    {
        $storeFilter = $this->option('store');

        $stores = Store::query()
            ->when(is_numeric($storeFilter), fn ($query) => $query->where('id', (int) $storeFilter))
            ->whereHas('instagramGalleries')
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            $this->info('没有配置过展示组的店铺，无需同步。');

            return self::SUCCESS;
        }

        $this->line('metaobject 类型：'.$directory->definitionType());

        $failed = 0;
        foreach ($stores as $store) {
            try {
                $result = $directory->sync($store, null);
                $this->info(sprintf(
                    '店铺 %d %s：%d 个展示组已同步（写入 %d 条，清理 %d 条残留）。',
                    $store->id,
                    $store->shopify_domain,
                    $result['galleries'],
                    $result['upserted'],
                    $result['deleted'],
                ));
            } catch (Throwable $exception) {
                // 一个店铺失败不该挡住其余店铺，通常原因是这家还没重新授权。
                $failed++;
                $this->error(sprintf(
                    '店铺 %d %s：同步失败 —— %s',
                    $store->id,
                    $store->shopify_domain,
                    $exception->getMessage(),
                ));
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->warn($failed.' 个店铺同步失败。若提示权限不足，需要商家在 Shopify 后台重新授权本应用（write_metaobjects）。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
