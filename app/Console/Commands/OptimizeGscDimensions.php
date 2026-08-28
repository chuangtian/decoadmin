<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\SeoAnalytics\GscDimensionBackfillService;
use Illuminate\Console\Command;

class OptimizeGscDimensions extends Command
{
    protected $signature = 'seo-analytics:optimize-gsc-dimensions {--store=} {--chunk=50000} {--verify}';

    protected $description = '将 GSC Page 与 Query 明细关联到去重维度表';

    public function handle(GscDimensionBackfillService $backfill): int
    {
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        if ($storeId !== null && ! Store::query()->whereKey($storeId)->exists()) {
            $this->components->error('指定的店铺不存在。');

            return self::INVALID;
        }

        $status = $this->option('verify')
            ? $backfill->status($storeId)
            : $backfill->backfill(
                $storeId,
                (int) $this->option('chunk'),
                function (string $type, int $processed, int $total): void {
                    $this->line("{$type}: {$processed}/{$total}");
                },
            );

        $this->table(['维度', '明细行', '已关联', '未关联', '字典行'], collect($status)->map(
            fn (array $row, string $type): array => [$type, $row['fact_rows'], $row['linked_rows'], $row['missing_rows'], $row['dimension_rows']],
        )->values()->all());

        return collect($status)->sum('missing_rows') === 0 ? self::SUCCESS : self::FAILURE;
    }
}
