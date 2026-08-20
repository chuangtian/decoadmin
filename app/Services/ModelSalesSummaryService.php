<?php

namespace App\Services;

use App\Models\Store;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Carbon\CarbonImmutable;
use Throwable;

class ModelSalesSummaryService
{
    public function __construct(private ShopifyAnalyticsReportService $reports) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forStore(Store $store, array $filters): array
    {
        $period = $this->period($store, $filters);
        $comparisonEnd = $period['local_start']->subDay()->endOfDay();
        $comparisonStart = $comparisonEnd
            ->subDays(max(0, $period['calendar_days'] - 1))
            ->startOfDay();

        $report = $this->reports->report(
            $store,
            'model-sales-summary',
            $period['local_start']->toDateString(),
            $period['local_end']->toDateString(),
        );

        $models = [];
        $this->mergeRows($models, $report['rows'] ?? [], 'current');
        $this->mergeRows($models, $report['rows'] ?? [], 'previous');

        $items = collect($models)
            ->map(fn (array $model): array => $this->finishModel($model))
            ->sortByDesc(fn (array $model): float => (float) $model['current']['total_sales'])
            ->values()
            ->all();

        return [
            'schema' => 'model-sales-summary-v1',
            'period' => [
                'days' => $period['days'],
                'calendar_days' => $period['calendar_days'],
                'from' => $period['local_start']->toDateString(),
                'to' => $period['local_end']->toDateString(),
                'timezone' => $period['timezone'],
                'include_test' => false,
                'include_cancelled' => true,
                'comparison_from' => $comparisonStart->toDateString(),
                'comparison_to' => $comparisonEnd->toDateString(),
            ],
            'summary' => [
                'models' => count($items),
                'variants' => collect($items)->sum('variant_count'),
                'net_items_sold' => collect($items)->sum('current.net_items_sold'),
                'total_sales' => round((float) collect($items)->sum('current.total_sales'), 2),
                'gross_sales' => round((float) collect($items)->sum('current.gross_sales'), 2),
            ],
            'models' => $items,
            'integration' => [
                'source' => 'shopifyql',
                'available' => (bool) ($report['available'] ?? false),
                'complete' => (bool) ($report['available'] ?? false),
                'scope_granted' => (bool) ($report['scope_granted'] ?? false),
                'error' => $report['error'] ?? null,
                'storage' => $report['storage'] ?? null,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $models
     * @param  list<array<string, mixed>>  $rows
     */
    private function mergeRows(array &$models, array $rows, string $bucket): void
    {
        foreach ($rows as $row) {
            $title = trim((string) ($row['product_title'] ?? ''));
            $productId = $this->shopifyId($row['product_id'] ?? null);
            if ($title === '' && $productId === null) {
                continue;
            }

            $modelKey = $productId !== null ? "product:{$productId}" : 'title:'.mb_strtolower($title);
            $variantId = $this->shopifyId($row['product_variant_id'] ?? null);
            $variantTitle = trim((string) ($row['product_variant_title'] ?? '')) ?: '默认款';
            if (strcasecmp($variantTitle, 'Default Title') === 0) {
                $variantTitle = '默认款';
            }
            $sku = trim((string) ($row['product_variant_sku'] ?? ''));
            $variantKey = $variantId !== null
                ? "variant:{$variantId}"
                : 'variant:'.mb_strtolower($variantTitle).'|'.mb_strtolower($sku);

            $models[$modelKey] ??= [
                'id' => $modelKey,
                'shopify_product_id' => $productId,
                'title' => $title !== '' ? $title : '未命名车型',
                'variants' => [],
            ];
            $models[$modelKey]['variants'][$variantKey] ??= [
                'id' => $variantKey,
                'shopify_variant_id' => $variantId,
                'title' => $variantTitle,
                'sku' => $sku,
                'current' => $this->emptyMetrics(),
                'previous' => $this->emptyMetrics(),
            ];

            $metrics = collect(['net_items_sold', 'total_sales', 'gross_sales'])
                ->mapWithKeys(function (string $metric) use ($row, $bucket): array {
                    $key = $bucket === 'previous'
                        ? "comparison_{$metric}__previous_period"
                        : $metric;
                    $value = $metric === 'net_items_sold'
                        ? $this->integer($row[$key] ?? 0)
                        : $this->decimal($row[$key] ?? 0);

                    return [$metric => $value];
                })->all();

            foreach ($metrics as $metric => $value) {
                $models[$modelKey]['variants'][$variantKey][$bucket][$metric] += $value;
            }
        }
    }

    /** @param array<string, mixed> $model */
    private function finishModel(array $model): array
    {
        $variants = collect($model['variants'])
            ->map(function (array $variant): array {
                $variant['current']['total_sales'] = round((float) $variant['current']['total_sales'], 2);
                $variant['current']['gross_sales'] = round((float) $variant['current']['gross_sales'], 2);
                $variant['previous']['total_sales'] = round((float) $variant['previous']['total_sales'], 2);
                $variant['previous']['gross_sales'] = round((float) $variant['previous']['gross_sales'], 2);
                $variant['trend'] = $this->trend(
                    (float) $variant['current']['total_sales'],
                    (float) $variant['previous']['total_sales'],
                );
                $variant['changes'] = $this->metricChanges(
                    $variant['current'],
                    $variant['previous'],
                );

                return $variant;
            })
            ->sortByDesc(fn (array $variant): float => (float) $variant['current']['total_sales'])
            ->values()
            ->all();

        $current = $this->sumMetrics($variants, 'current');
        $previous = $this->sumMetrics($variants, 'previous');

        return [
            'id' => $model['id'],
            'shopify_product_id' => $model['shopify_product_id'],
            'title' => $model['title'],
            'variant_count' => count($variants),
            'current' => $current,
            'previous' => $previous,
            'changes' => $this->metricChanges($current, $previous),
            'trend' => $this->trend((float) $current['total_sales'], (float) $previous['total_sales']),
            'variants' => $variants,
        ];
    }

    /**
     * @param  array{net_items_sold: int, total_sales: float, gross_sales: float}  $current
     * @param  array{net_items_sold: int, total_sales: float, gross_sales: float}  $previous
     * @return array{net_items_sold: array{key: string, label: string, direction: string, percent: float|null}, total_sales: array{key: string, label: string, direction: string, percent: float|null}}
     */
    private function metricChanges(array $current, array $previous): array
    {
        return [
            'net_items_sold' => $this->trend(
                (float) $current['net_items_sold'],
                (float) $previous['net_items_sold'],
            ),
            'total_sales' => $this->trend(
                (float) $current['total_sales'],
                (float) $previous['total_sales'],
            ),
        ];
    }

    /** @param list<array<string, mixed>> $variants */
    private function sumMetrics(array $variants, string $bucket): array
    {
        return [
            'net_items_sold' => (int) collect($variants)->sum("{$bucket}.net_items_sold"),
            'total_sales' => round((float) collect($variants)->sum("{$bucket}.total_sales"), 2),
            'gross_sales' => round((float) collect($variants)->sum("{$bucket}.gross_sales"), 2),
        ];
    }

    /** @return array{key: string, label: string, direction: string, percent: float|null} */
    private function trend(float $current, float $previous): array
    {
        if (abs($previous) < 0.00001) {
            return $current > 0
                ? ['key' => 'new', 'label' => '新品', 'direction' => 'up', 'percent' => null]
                : ['key' => 'stable', 'label' => '持平', 'direction' => 'flat', 'percent' => 0.0];
        }

        $percent = round(($current - $previous) / abs($previous) * 100, 1);

        return match (true) {
            $percent > 10 => ['key' => 'hot', 'label' => '热销', 'direction' => 'up', 'percent' => $percent],
            $percent > 0 => ['key' => 'up', 'label' => '上升', 'direction' => 'up', 'percent' => $percent],
            $percent === 0.0 => ['key' => 'stable', 'label' => '持平', 'direction' => 'flat', 'percent' => $percent],
            $percent >= -20 => ['key' => 'sliding', 'label' => '下滑', 'direction' => 'down', 'percent' => $percent],
            default => ['key' => 'down', 'label' => '下降', 'direction' => 'down', 'percent' => $percent],
        };
    }

    /** @return array{net_items_sold: int, total_sales: float, gross_sales: float} */
    private function emptyMetrics(): array
    {
        return ['net_items_sold' => 0, 'total_sales' => 0.0, 'gross_sales' => 0.0];
    }

    private function shopifyId(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $segments = explode('/', $value);

        return end($segments) ?: $value;
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function period(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $days = min(366, max(1, (int) ($filters['days'] ?? 30)));
        $localEnd = CarbonImmutable::now($timezone)->endOfDay();
        $localStart = $localEnd->subDays(min($days - 1, 365))->startOfDay();

        if (filled($filters['date_from'] ?? null) && filled($filters['date_to'] ?? null)) {
            try {
                $localStart = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_from'], $timezone)->startOfDay();
                $localEnd = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_to'], $timezone)->endOfDay();
                $days = min(366, (int) $localStart->diffInDays($localEnd) + 1);
            } catch (Throwable) {
                // The validated request normally prevents invalid dates.
            }
        }

        return [
            'days' => $days,
            'calendar_days' => (int) $localStart->diffInDays($localEnd) + 1,
            'timezone' => $timezone,
            'local_start' => $localStart,
            'local_end' => $localEnd,
        ];
    }
}
