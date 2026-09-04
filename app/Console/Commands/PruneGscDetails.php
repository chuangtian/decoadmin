<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\SeoAnalytics\GscDetailPruneService;
use App\Services\SeoAnalytics\GscDetailRetentionPolicy;
use Illuminate\Console\Command;

class PruneGscDetails extends Command
{
    protected $signature = 'seo-analytics:prune-gsc-details
        {--store= : 只处理指定店铺}
        {--min-impressions=5 : 无点击明细的最低曝光保留值}
        {--chunk=50000 : 分批删除时使用的 ID 区间大小}
        {--dry-run : 只统计，不删除}';

    protected $description = '删除无点击且低曝光的 GSC Page / Query 明细';

    public function handle(GscDetailPruneService $pruner): int
    {
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        if ($storeId !== null && ! Store::query()->whereKey($storeId)->exists()) {
            $this->components->error('指定的店铺不存在。');

            return self::INVALID;
        }

        $minimumImpressions = max(1, (int) ($this->option('min-impressions') ?: GscDetailRetentionPolicy::MIN_IMPRESSIONS));
        $result = $this->option('dry-run')
            ? $pruner->estimate($storeId, $minimumImpressions)
            : $pruner->prune(
                $storeId,
                $minimumImpressions,
                (int) $this->option('chunk'),
                function (string $type, int $processed, int $total): void {
                    $this->line("{$type}: {$processed}/{$total}");
                },
            );

        $rows = collect(['pages', 'queries'])->map(function (string $type) use ($result): array {
            $row = $result[$type];

            return [
                $type,
                $row['fact_rows'],
                $row['matched_rows'],
                $row['deleted_rows'] ?? 0,
                $row['retained_rows'],
                $row['orphan_dimensions_deleted'] ?? 0,
            ];
        })->all();
        $this->table(['维度', '原明细', '低价值', '已删除', '保留', '孤立字典已删除'], $rows);
        if (isset($result['redundant'])) {
            $this->line('重复 Web 汇总已删除：'.$result['redundant']['web_search_type_rows_deleted']);
            $this->line('空高级维度已删除：'.$result['redundant']['empty_breakdown_rows_deleted']);
        }

        return self::SUCCESS;
    }
}
