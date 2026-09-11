<?php

namespace App\Services;

use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\SeoGoalWorkRecord;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SeoGoalDashboardService
{
    private const META = [
        'seo_gmv' => ['name' => 'SEO GMV', 'category' => 'outcome', 'unit' => '$', 'source' => 'SEO 日度表'],
        'industry_clicks' => ['name' => '行业词点击', 'category' => 'traffic', 'unit' => '', 'source' => 'SEO 日度表'],
        'blog_clicks' => ['name' => '博客页面点击', 'category' => 'traffic', 'unit' => '', 'source' => 'SEO 日度表'],
        'new_blog' => ['name' => '新博客上线数量', 'category' => 'work', 'unit' => '篇', 'source' => '新博客推进表'],
        'old_blog' => ['name' => '旧博客/内链/404/重定向优化量', 'category' => 'work', 'unit' => '篇', 'source' => '旧博客推进表'],
        'backlinks' => ['name' => '外链合作数（含 PR）', 'category' => 'work', 'unit' => '个', 'source' => '外链推进表'],
        'ai_automation' => ['name' => 'AI 自动化有效流程', 'category' => 'work', 'unit' => '个', 'source' => 'AI 自动化应用推进表'],
    ];

    private const DEFAULT_TARGETS = [
        'seo_gmv' => 268483.61,
        'industry_clicks' => 4318,
        'blog_clicks' => 19379,
        'new_blog' => 15,
        'old_blog' => 50,
        'backlinks' => 10,
        'ai_automation' => 2,
    ];

    private const MONTHLY_TARGETS = [
        '2026-05' => self::DEFAULT_TARGETS,
        '2026-06' => [
            'seo_gmv' => 290000,
            'industry_clicks' => 4800,
            'blog_clicks' => 21000,
            'new_blog' => 15,
            'old_blog' => 50,
            'backlinks' => 10,
            'ai_automation' => 2,
        ],
    ];

    public function __construct(private StoreFeishuDataLinkService $dataLinks) {}

    /** @return array<string, mixed> */
    public function forStore(Store $store, ?string $requestedMonth = null): array
    {
        $now = CarbonImmutable::now('Asia/Shanghai');
        $month = $this->validMonth($requestedMonth) ?? $now->format('Y-m');
        $table = $this->dailyTable($store);
        [$actuals, $dateRange, $availableMonths] = $this->dailyActuals($table, $month);
        $workActuals = $this->workActuals($store, $month);
        $actuals = [...$actuals, ...$workActuals];
        $hasMonthData = $dateRange['days'] > 0 || array_sum($workActuals) > 0;
        $targets = self::MONTHLY_TARGETS[$month] ?? self::DEFAULT_TARGETS;
        $daysInMonth = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'Asia/Shanghai')->daysInMonth;
        $monthPosition = $month <=> $now->format('Y-m');
        $naturalDay = $monthPosition < 0 ? $daysInMonth : ($monthPosition > 0 ? 0 : $now->day);
        $dataDay = $monthPosition < 0
            ? $daysInMonth
            : ($monthPosition > 0 ? 0 : (int) substr((string) ($dateRange['through'] ?? ''), 8, 2));
        $naturalPace = $daysInMonth > 0 ? $naturalDay / $daysInMonth : 0;
        $dataPace = $daysInMonth > 0 ? $dataDay / $daysInMonth : 0;
        $metrics = [];

        foreach (self::META as $id => $meta) {
            $current = (float) ($actuals[$id] ?? 0);
            $target = (float) $targets[$id];
            $pace = in_array($id, ['seo_gmv', 'industry_clicks', 'blog_clicks'], true) ? $dataPace : $naturalPace;
            $completion = $target > 0 ? $current / $target : 0;
            $forecast = $pace > 0 ? $current / $pace : 0;
            $forecastRate = $target > 0 ? $forecast / $target : 0;

            $metrics[] = [
                'id' => $id,
                ...$meta,
                'current' => round($current, 2),
                'target' => round($target, 2),
                'completion' => round($completion, 6),
                'expected' => round($target * $pace, 2),
                'forecast' => round($forecast, 2),
                'forecast_rate' => round($forecastRate, 6),
                'gap' => round(max($target - $current, 0), 2),
                'pace' => round($pace, 6),
                'pace_kind' => in_array($id, ['seo_gmv', 'industry_clicks', 'blog_clicks'], true) ? 'data' : 'calendar',
                'status' => $hasMonthData ? $this->status($completion, $pace, $forecastRate) : 'no_data',
            ];
        }

        $expectedCount = collect($metrics)->filter(fn (array $metric): bool => $metric['forecast_rate'] >= 1)->count();
        $catchUpCount = collect($metrics)->filter(fn (array $metric): bool => $metric['status'] !== 'no_data' && $metric['completion'] < $metric['pace'] && $metric['forecast_rate'] < 1)->count();
        $overall = ! $hasMonthData ? 'no_data' : ($catchUpCount >= 3 ? 'catch_up' : ($catchUpCount > 0 ? 'manageable' : 'normal'));
        $lastSyncedAt = collect([$table?->synced_at, SeoGoalWorkRecord::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->max('synced_at')])
            ->filter()
            ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value))
            ->sortDesc()
            ->first();

        $months = collect([...$availableMonths, $month, $now->format('Y-m')])
            ->filter(fn (string $value): bool => $this->validMonth($value) !== null)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return [
            'schema' => 'seo-goal-dashboard-v1',
            'month' => $month,
            'months' => $months,
            'target_origin' => isset(self::MONTHLY_TARGETS[$month]) ? 'monthly' : 'default',
            'generated_at' => $now->toIso8601String(),
            'last_synced_at' => $lastSyncedAt?->toIso8601String(),
            'data_through' => $dateRange['through'],
            'data_range' => $dateRange,
            'progress' => ['data' => round($dataPace, 6), 'calendar' => round($naturalPace, 6)],
            'summary' => [
                'overall' => $overall,
                'expected_count' => $expectedCount,
                'total_count' => count($metrics),
                'catch_up_count' => $catchUpCount,
            ],
            'metrics' => $metrics,
            'data_ready' => $table !== null,
        ];
    }

    private function dailyTable(Store $store): ?FeishuBitableTable
    {
        $configured = $this->dataLinks->valuesForSync($store, 'seo_geo');
        $tableId = trim((string) ($configured['seo_daily_table_id'] ?? ''));

        return FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->when($tableId !== '', fn (Builder $query) => $query->where('source_table_id', $tableId), fn (Builder $query) => $query->where('name', '日数据'))
            ->orderByDesc('synced_at')
            ->first();
    }

    /** @return array{array<string, float>, array{from: string|null, through: string|null, days: int}, list<string>} */
    private function dailyActuals(?FeishuBitableTable $table, string $month): array
    {
        $actuals = ['seo_gmv' => 0.0, 'industry_clicks' => 0.0, 'blog_clicks' => 0.0];
        $dates = [];
        $months = [];

        if ($table === null) {
            return [$actuals, ['from' => null, 'through' => null, 'days' => 0], []];
        }

        FeishuBitableRecord::query()
            ->where('organization_id', $table->organization_id)
            ->where('store_id', $table->store_id)
            ->where('feishu_bitable_table_id', $table->id)
            ->orderBy('id')
            ->each(function (FeishuBitableRecord $record) use (&$actuals, &$dates, &$months, $month): void {
                $fields = $record->fields_encrypted;
                if (! is_array($fields)) {
                    return;
                }
                $date = $this->fieldDate($fields['日期'] ?? null);
                if ($date === null) {
                    return;
                }
                $recordMonth = substr($date, 0, 7);
                $months[] = $recordMonth;
                if ($recordMonth !== $month) {
                    return;
                }
                $dates[] = $date;
                $actuals['seo_gmv'] += $this->number($fields['SEOGMV'] ?? null);
                $actuals['industry_clicks'] += $this->number($fields['点击-行业词'] ?? null);
                $actuals['blog_clicks'] += $this->number($fields['点击-博客'] ?? null);
            });

        sort($dates);

        return [$actuals, [
            'from' => $dates[0] ?? null,
            'through' => $dates !== [] ? $dates[array_key_last($dates)] : null,
            'days' => count(array_unique($dates)),
        ], array_values(array_unique($months))];
    }

    /** @return array<string, int> */
    private function workActuals(Store $store, string $month): array
    {
        $base = SeoGoalWorkRecord::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id);
        $from = $month.'-01';
        $through = CarbonImmutable::createFromFormat('Y-m-d', $from)->endOfMonth()->toDateString();
        $monthNumber = (int) substr($month, 5, 2);

        return [
            'new_blog' => (clone $base)->where('source_key', 'new_blog')->whereBetween('actual_date', [$from, $through])->where('url_present', true)->count(),
            'old_blog' => (clone $base)->where('source_key', 'old_blog')->whereBetween('actual_date', [$from, $through])->where('url_present', true)->count(),
            'backlinks' => (clone $base)->where('source_key', 'backlinks')->where(function (Builder $query) use ($month, $monthNumber): void {
                $query->where('cooperation_month', $month)
                    ->orWhere(function (Builder $query) use ($monthNumber): void {
                        $query->whereNull('cooperation_month')->where('cooperation_month_number', $monthNumber);
                    });
            })->count(),
            'ai_automation' => (clone $base)->where('source_key', 'ai_automation')->where('included', true)->whereNotNull('name')->distinct()->count('name'),
        ];
    }

    /** @return 'leading'|'achievable'|'catch_up'|'high_risk' */
    private function status(float $completion, float $pace, float $forecastRate): string
    {
        if ($forecastRate >= 1.08 && $completion >= $pace) {
            return 'leading';
        }
        if ($forecastRate >= 1 && $completion >= $pace * .92) {
            return 'achievable';
        }

        return $forecastRate >= .88 ? 'catch_up' : 'high_risk';
    }

    private function fieldDate(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->first(fn (mixed $item): bool => is_scalar($item)) ?? data_get($value, '0.text');
        }
        if (is_numeric($value)) {
            $number = (float) $value;
            if ($number > 100000000000) {
                return CarbonImmutable::createFromTimestampMs((int) $number, 'UTC')->setTimezone('Asia/Shanghai')->toDateString();
            }
            if ($number > 1000000000) {
                return CarbonImmutable::createFromTimestamp((int) $number, 'UTC')->setTimezone('Asia/Shanghai')->toDateString();
            }
        }
        if (is_string($value) && preg_match('/(\d{4})[-\/]?(\d{1,2})[-\/]?(\d{1,2})/', trim($value), $matches) === 1) {
            try {
                return CarbonImmutable::createSafe((int) $matches[1], (int) $matches[2], (int) $matches[3])->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function number(mixed $value): float
    {
        if (is_array($value)) {
            $value = data_get($value, '0.text', data_get($value, '0'));
        }

        return is_numeric($value) ? (float) $value : 0;
    }

    private function validMonth(?string $month): ?string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : null;
    }
}
