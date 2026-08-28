<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\MetaAds\MetaAdsInsightOptimizationService;
use Illuminate\Console\Command;

class OptimizeMetaAdsInsights extends Command
{
    protected $signature = 'meta-ads:optimize-insights
        {--store= : 只处理指定店铺}
        {--chunk=10000 : 分批处理使用的 ID 区间大小}
        {--dry-run : 只统计，不删除或更新}';

    protected $description = '删除未使用的 Meta 小时级洞察并清空重复 JSON';

    public function handle(MetaAdsInsightOptimizationService $optimizer): int
    {
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        if ($storeId !== null && ! Store::query()->whereKey($storeId)->exists()) {
            $this->components->error('指定的店铺不存在。');

            return self::INVALID;
        }

        $result = $this->option('dry-run')
            ? $optimizer->estimate($storeId)
            : $optimizer->optimize(
                $storeId,
                (int) $this->option('chunk'),
                function (string $type, int $processed, int $total): void {
                    $this->line("{$type}: {$processed}/{$total}");
                },
            );

        $this->table(
            ['原记录', '小时级', '重复 JSON', '保留', '已删小时级', '已清 JSON'],
            [[
                $result['fact_rows'],
                $result['hourly_rows'],
                $result['redundant_json_rows'],
                $result['retained_rows'],
                $result['hourly_deleted'] ?? 0,
                $result['json_sanitized'] ?? 0,
            ]],
        );

        return self::SUCCESS;
    }
}
