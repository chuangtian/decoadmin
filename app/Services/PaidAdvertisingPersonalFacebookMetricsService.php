<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class PaidAdvertisingPersonalFacebookMetricsService
{
    private const DATE_FIELD = '日期';

    private const DAILY_SALES_FIELD = '今日销售额';

    private const MONTHLY_SALES_FIELD = '月销售额之和';

    private const MONTHLY_TARGET_FIELD = '月销售额目标';

    private const MAX_RECORDS = 5000;

    private const FACEBOOK_ROAS_TARGET = 5.0;

    private const META_WEEKLY_TABLE_COLUMNS = [
        ['key' => '年度第几周', 'label' => '年度第几周', 'format' => 'text'],
        ['key' => '展示次数', 'label' => '展示次数', 'format' => 'integer'],
        ['key' => '链接点击率', 'label' => '链接点击率', 'format' => 'percentage'],
        ['key' => '花费金额', 'label' => '花费金额', 'format' => 'currency'],
        ['key' => '转化次数', 'label' => '转化次数', 'format' => 'integer'],
        ['key' => '每次转化费用', 'label' => '每次转化费用', 'format' => 'currency'],
        ['key' => '转化价值', 'label' => '转化价值', 'format' => 'currency'],
        ['key' => 'ROI', 'label' => 'ROI', 'format' => 'roi'],
        ['key' => '加购数', 'label' => '加购数', 'format' => 'integer'],
        ['key' => '结账数', 'label' => '结账数', 'format' => 'integer'],
    ];

    /**
     * @param  array{date_from: string, date_to: string, timezone: string, label?: string}  $period
     * @return array<string, mixed>
     */
    public function summary(
        Organization $organization,
        Store $store,
        PaidAdvertisingGoalBoard $board,
        array $period,
    ): array {
        $this->assertScope($organization, $store, $board);

        $sourceKey = 'board:'.$board->id;
        $fieldDefinitions = PaidAdvertisingGoalField::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', $sourceKey)
            ->orderBy('field_order')
            ->get(['name', 'type', 'field_order', 'description'])
            ->unique('name')
            ->values();
        $personalFields = $fieldDefinitions->pluck('name')->values();
        $requiredFields = [
            self::DATE_FIELD,
            self::DAILY_SALES_FIELD,
            self::MONTHLY_SALES_FIELD,
            self::MONTHLY_TARGET_FIELD,
        ];
        $missingPersonalFields = array_values(array_diff($requiredFields, $personalFields->all()));
        $spendFields = $this->spendFields($board, $personalFields);
        $availableOverallFields = PaidAdvertisingGoalField::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', 'overall')
            ->whereIn('name', $spendFields)
            ->pluck('name')
            ->unique()
            ->all();
        $missingSpendFields = array_values(array_diff($spendFields, $availableOverallFields));

        $yesterday = CarbonImmutable::now($period['timezone'])->subDay()->toDateString();
        $cutoffDate = min($period['date_to'], $yesterday);
        $overallFieldNames = PaidAdvertisingGoalField::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', 'overall')
            ->orderBy('field_order')
            ->pluck('name')
            ->unique()
            ->values();
        $allOverallRows = $this->allDatedRows($organization, $store, 'overall');
        $overallRows = $allOverallRows
            ->filter(fn (array $row): bool => $row['date'] >= $period['date_from']
                && $row['date'] <= $cutoffDate)
            ->values();
        $metaWeeklySourceKey = 'board:'.$board->id.':meta-weekly';
        $metaWeeklyFieldNames = PaidAdvertisingGoalField::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', $metaWeeklySourceKey)
            ->orderBy('field_order')
            ->pluck('name')
            ->unique()
            ->values();
        $metaWeeklyRows = $this->allDatedRows(
            $organization,
            $store,
            $metaWeeklySourceKey,
            '记录日期',
        );
        $rows = $this->datedRows($organization, $store, $sourceKey, $period)
            ->filter(fn (array $row): bool => $row['date'] <= $cutoffDate)
            ->sortByDesc('date')
            ->values();
        $details = $this->details($rows, $fieldDefinitions, $period);
        $row = $rows->first(function (array $row): bool {
            $dailySales = $this->numeric($row['fields'][self::DAILY_SALES_FIELD] ?? null);
            $monthlySales = $this->numeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null);

            return ($dailySales !== null && $dailySales > 0)
                || ($monthlySales !== null && $monthlySales > 0);
        }) ?? $rows->first();

        if (! is_array($row)) {
            return $this->result(
                $store,
                null,
                $yesterday,
                $requiredFields,
                $spendFields,
                '所选时间范围内暂无可用的个人目标同步数据。',
                details: $details,
            );
        }

        $dailySales = $this->roundedNumeric($row['fields'][self::DAILY_SALES_FIELD] ?? null);
        $monthlySales = $this->roundedNumeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null);
        $monthlyTarget = $this->roundedNumeric($row['fields'][self::MONTHLY_TARGET_FIELD] ?? null);
        $completionRate = $monthlySales !== null && $monthlyTarget !== null && $monthlyTarget > 0
            ? round(($monthlySales / $monthlyTarget) * 100, 1)
            : null;
        $monthlySpend = $missingSpendFields === []
            ? $this->sumFields(
                $overallRows->filter(fn (array $overallRow): bool => $overallRow['date'] <= $row['date']),
                $spendFields,
            )
            : null;
        $roas = $monthlySales !== null && $monthlySpend !== null && $monthlySpend > 0
            ? round($monthlySales / $monthlySpend, 2)
            : null;
        $facebook = $this->facebookMetrics($rows, $row, $personalFields, $period);
        $criteo = $this->criteoMetrics($rows, $row, $personalFields, $period);
        $facebookEfficiency = $this->facebookEfficiency($overallRows, $overallFieldNames, $period);
        $metaWeekly = $this->metaWeekly(
            $metaWeeklyRows,
            $metaWeeklyFieldNames,
            $board,
            $period,
            $cutoffDate,
        );
        $messages = [];
        if ($missingPersonalFields !== []) {
            $messages[] = '个人目标同步记录缺少字段：'.implode('、', $missingPersonalFields).'。';
        }
        if ($missingSpendFields !== []) {
            $messages[] = '总目标同步记录缺少花费字段：'.implode('、', $missingSpendFields).'。';
        }

        return $this->result(
            $store,
            $row['date'],
            $yesterday,
            array_values(array_unique([...$missingPersonalFields, ...$missingSpendFields])),
            $spendFields,
            $messages === [] ? null : implode('', $messages),
            [
                'daily_sales' => $dailySales,
                'monthly_sales' => $monthlySales,
                'monthly_target' => $monthlyTarget,
                'completion_rate' => $completionRate,
                'monthly_spend' => $monthlySpend,
                'roas' => $roas,
            ],
            $row['synced_at'],
            $facebook,
            $criteo,
            $details,
            $facebookEfficiency,
            $metaWeekly,
        );
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  Collection<int, string>  $fieldNames
     * @param  array{date_from: string, date_to: string, timezone: string, label?: string}  $period
     * @return array<string, mixed>
     */
    private function facebookEfficiency(Collection $rows, Collection $fieldNames, array $period): array
    {
        $roasField = $fieldNames->first(fn (string $field): bool => $field === 'FB的ROI')
            ?? $fieldNames->first(fn (string $field): bool => preg_match('/^(FB|Facebook|Meta).*ROAS|^(FB|Facebook|Meta).*ROI/i', $field) === 1);
        $spendField = $fieldNames->first(fn (string $field): bool => $field === 'FB花费')
            ?? $fieldNames->first(fn (string $field): bool => preg_match('/^(FB|Facebook|Meta).*花费/i', $field) === 1
                && ! str_contains($field, '-'));
        $missingFields = [];
        if (! is_string($roasField)) {
            $missingFields[] = 'FB的ROI';
        }
        if (! is_string($spendField)) {
            $missingFields[] = 'FB花费';
        }

        if ($missingFields !== []) {
            return $this->emptyFacebookEfficiency('总目标同步记录缺少字段：'.implode('、', $missingFields).'。');
        }

        $latest = $rows
            ->filter(fn (array $row): bool => $this->numeric($row['fields'][$roasField] ?? null) !== null)
            ->sortByDesc('date')
            ->first();
        $periodSpend = $this->sumFields($rows, [$spendField]);
        if (! is_array($latest) || $periodSpend === null) {
            return $this->emptyFacebookEfficiency('所选时间范围内暂无可用的 Facebook 效率数据。');
        }

        $currentRoasRaw = $this->numeric($latest['fields'][$roasField] ?? null);
        $currentRoas = $currentRoasRaw === null ? null : round($currentRoasRaw, 2);
        $achievementRate = $currentRoasRaw === null
            ? null
            : round(($currentRoasRaw / self::FACEBOOK_ROAS_TARGET) * 100, 2);

        return [
            'schema' => 'paid-advertising-facebook-efficiency-v1',
            'available' => $currentRoas !== null,
            'message' => null,
            'as_of_date' => $latest['date'],
            'period_label' => $period['label'] ?? $period['date_from'].' 至 '.$period['date_to'],
            'values' => [
                'current_roas' => $currentRoas,
                'target_roas' => self::FACEBOOK_ROAS_TARGET,
                'achievement_rate' => $achievementRate,
                'period_spend' => $periodSpend,
            ],
            'source_fields' => [
                'current_roas' => $roasField,
                'target_roas' => '系统目标值',
                'achievement_rate' => [$roasField, '系统目标值'],
                'period_spend' => $spendField,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyFacebookEfficiency(?string $message): array
    {
        return [
            'schema' => 'paid-advertising-facebook-efficiency-v1',
            'available' => false,
            'message' => $message,
            'as_of_date' => null,
            'period_label' => '',
            'values' => [
                'current_roas' => null,
                'target_roas' => self::FACEBOOK_ROAS_TARGET,
                'achievement_rate' => null,
                'period_spend' => null,
            ],
            'source_fields' => [
                'current_roas' => '',
                'target_roas' => '系统目标值',
                'achievement_rate' => [],
                'period_spend' => '',
            ],
        ];
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  Collection<int, string>  $fieldNames
     * @param  array{date_from: string, date_to: string, timezone: string, label?: string}  $period
     * @return array<string, mixed>
     */
    private function metaWeekly(
        Collection $rows,
        Collection $fieldNames,
        PaidAdvertisingGoalBoard $board,
        array $period,
        string $cutoffDate,
    ): array {
        $weekField = $fieldNames->first(fn (string $field): bool => $field === '年度第几周')
            ?? $fieldNames->first(fn (string $field): bool => $field === '周')
            ?? $fieldNames->first(fn (string $field): bool => str_contains($field, '周'));
        $salesField = 'FB销售额-'.$board->name;
        $spendField = 'FB花费-'.$board->name;
        $roiField = 'FB的ROI-'.$board->name;
        $missingFields = collect([
            $weekField ? null : '周',
            $fieldNames->contains($salesField) ? null : $salesField,
            $fieldNames->contains($spendField) ? null : $spendField,
        ])->filter()->values()->all();

        if (! is_string($weekField) || $missingFields !== []) {
            return $this->emptyMetaWeekly('Meta 周数据同步记录缺少字段：'.implode('、', $missingFields).'。');
        }

        $weeks = [];
        foreach ($rows as $row) {
            if ($row['date'] > $cutoffDate) {
                continue;
            }

            $labelValue = $this->flattenValue($row['fields'][$weekField] ?? null);
            $label = is_scalar($labelValue) ? trim((string) $labelValue) : '';
            if ($label === '') {
                continue;
            }

            $date = CarbonImmutable::parse($row['date'], $period['timezone']);
            $weekStart = $date->startOfWeek()->toDateString();
            $weekEnd = $date->endOfWeek()->toDateString();
            $key = $weekStart.'|'.$label;
            $weeks[$key] ??= [
                'label' => $label,
                'date_from' => $weekStart,
                'date_to' => $weekEnd,
                'sales' => 0.0,
                'spend' => 0.0,
                'has_sales' => false,
                'has_spend' => false,
                'roi' => null,
                'fields' => $row['fields'],
                'synced_at' => $row['synced_at'],
            ];
            $sales = $this->numeric($row['fields'][$salesField] ?? null);
            $spend = $this->numeric($row['fields'][$spendField] ?? null);
            $roi = $this->numeric($row['fields'][$roiField] ?? null);
            if ($sales !== null) {
                $weeks[$key]['sales'] += $sales;
                $weeks[$key]['has_sales'] = true;
            }
            if ($spend !== null) {
                $weeks[$key]['spend'] += $spend;
                $weeks[$key]['has_spend'] = true;
            }
            if ($roi !== null) {
                $weeks[$key]['roi'] = $roi;
            }
        }

        $completedWeeks = collect($weeks)
            ->filter(fn (array $week): bool => $week['date_to'] <= $cutoffDate
                && $week['has_sales']
                && $week['has_spend'])
            ->sortBy('date_to')
            ->values();
        $latest = $completedWeeks
            ->filter(fn (array $week): bool => $week['date_to'] >= $period['date_from']
                && $week['date_to'] <= $period['date_to'])
            ->last();
        if (! is_array($latest)) {
            return $this->emptyMetaWeekly('所选时间范围内暂无已结束的 Meta 周数据。');
        }

        $previous = $completedWeeks
            ->filter(fn (array $week): bool => $week['date_to'] < $latest['date_to'])
            ->last();
        $latestSales = round((float) $latest['sales'], 2);
        $latestSpend = round((float) $latest['spend'], 2);
        $latestRoiRaw = $latest['roi'] ?? ($latestSpend > 0 ? $latestSales / $latestSpend : null);
        $latestRoi = is_numeric($latestRoiRaw) ? round((float) $latestRoiRaw, 2) : null;
        $previousSales = is_array($previous) ? round((float) $previous['sales'], 2) : null;
        $previousSpend = is_array($previous) ? round((float) $previous['spend'], 2) : null;
        $previousRoiRaw = is_array($previous)
            ? ($previous['roi'] ?? ($previousSpend > 0 ? $previousSales / $previousSpend : null))
            : null;
        $previousRoi = is_numeric($previousRoiRaw) ? round((float) $previousRoiRaw, 2) : null;
        $timeline = $completedWeeks
            ->filter(fn (array $week): bool => $week['date_to'] <= $latest['date_to'])
            ->take(-8)
            ->values();
        $trend = $timeline->map(function (array $week): array {
            $sales = round((float) $week['sales'], 2);
            $spend = round((float) $week['spend'], 2);
            $roi = $week['roi'] ?? ($spend > 0 ? $sales / $spend : null);

            return [
                'week' => $week['label'],
                'date_from' => $week['date_from'],
                'date_to' => $week['date_to'],
                'sales' => $sales,
                'spend' => $spend,
                'roi' => is_numeric($roi) ? round((float) $roi, 2) : null,
            ];
        })->all();
        $tableColumns = collect(self::META_WEEKLY_TABLE_COLUMNS)
            ->filter(fn (array $column): bool => $fieldNames->contains($column['key']))
            ->values()
            ->all();
        $tableRows = $timeline->map(function (array $week) use ($tableColumns): array {
            $values = [];
            foreach ($tableColumns as $column) {
                $value = $week['fields'][$column['key']] ?? null;
                $values[$column['key']] = $column['format'] === 'text'
                    ? $this->flattenValue($value)
                    : $this->metaWeeklyTableValue($value, $column['format']);
            }

            return [
                'key' => $week['date_from'].'-'.$week['label'],
                'date_from' => $week['date_from'],
                'date_to' => $week['date_to'],
                'values' => $values,
            ];
        })->all();

        return [
            'schema' => 'paid-advertising-meta-weekly-v1',
            'available' => true,
            'message' => null,
            'latest_week' => [
                'label' => $latest['label'],
                'date_from' => $latest['date_from'],
                'date_to' => $latest['date_to'],
            ],
            'previous_week' => is_array($previous) ? [
                'label' => $previous['label'],
                'date_from' => $previous['date_from'],
                'date_to' => $previous['date_to'],
            ] : null,
            'values' => [
                'sales' => $latestSales,
                'spend' => $latestSpend,
                'roi' => $latestRoi,
            ],
            'changes' => [
                'sales' => $this->percentageChange($latestSales, $previousSales),
                'spend' => $this->percentageChange($latestSpend, $previousSpend),
                'roi' => $this->percentageChange($latestRoi, $previousRoi),
            ],
            'trend' => [
                'schema' => 'paid-advertising-meta-weekly-trend-v1',
                'available' => $trend !== [],
                'points' => $trend,
            ],
            'table' => [
                'schema' => 'paid-advertising-meta-weekly-table-v1',
                'available' => $tableColumns !== [] && $tableRows !== [],
                'period_label' => $period['label'] ?? $period['date_from'].' 至 '.$period['date_to'],
                'columns' => $tableColumns,
                'rows' => $tableRows,
                'total' => count($tableRows),
            ],
            'source_fields' => [
                'week' => $weekField,
                'sales' => $salesField,
                'spend' => $spendField,
                'roi' => $fieldNames->contains($roiField) ? [$roiField] : [$salesField, $spendField],
            ],
            'synced_at' => $latest['synced_at'],
        ];
    }

    private function metaWeeklyTableValue(mixed $value, string $format): ?float
    {
        $number = $this->numeric($value);
        if ($number === null) {
            return null;
        }

        return match ($format) {
            'integer' => round($number),
            'percentage' => round($number, 6),
            default => round($number, 2),
        };
    }

    /** @return array<string, mixed> */
    private function emptyMetaWeekly(?string $message): array
    {
        return [
            'schema' => 'paid-advertising-meta-weekly-v1',
            'available' => false,
            'message' => $message,
            'latest_week' => null,
            'previous_week' => null,
            'values' => ['sales' => null, 'spend' => null, 'roi' => null],
            'changes' => ['sales' => null, 'spend' => null, 'roi' => null],
            'trend' => [
                'schema' => 'paid-advertising-meta-weekly-trend-v1',
                'available' => false,
                'points' => [],
            ],
            'table' => [
                'schema' => 'paid-advertising-meta-weekly-table-v1',
                'available' => false,
                'period_label' => '',
                'columns' => [],
                'rows' => [],
                'total' => 0,
            ],
            'source_fields' => ['week' => '', 'sales' => '', 'spend' => '', 'roi' => []],
            'synced_at' => null,
        ];
    }

    private function percentageChange(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  array{date: string, fields: array<string, mixed>, synced_at: string|null}  $row
     * @param  Collection<int, string>  $personalFields
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @return array<string, mixed>
     */
    private function facebookMetrics(
        Collection $rows,
        array $row,
        Collection $personalFields,
        array $period,
    ): array {
        return $this->channelMetrics(
            $rows,
            $row,
            $personalFields,
            $period,
            'facebook',
            'Facebook',
            'paid-advertising-personal-facebook-channel-goal-v1',
        );
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  array{date: string, fields: array<string, mixed>, synced_at: string|null}  $row
     * @param  Collection<int, string>  $personalFields
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @return array<string, mixed>
     */
    private function criteoMetrics(
        Collection $rows,
        array $row,
        Collection $personalFields,
        array $period,
    ): array {
        return $this->channelMetrics(
            $rows,
            $row,
            $personalFields,
            $period,
            'criteo',
            'Criteo',
            'paid-advertising-personal-criteo-channel-goal-v1',
        );
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  array{date: string, fields: array<string, mixed>, synced_at: string|null}  $row
     * @param  Collection<int, string>  $personalFields
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @return array<string, mixed>
     */
    private function channelMetrics(
        Collection $rows,
        array $row,
        Collection $personalFields,
        array $period,
        string $channel,
        string $label,
        string $schema,
    ): array {
        $dailySalesField = $personalFields->first(fn (string $field): bool => $this->isChannelField($field, $channel)
            && str_contains($field, '销售额')
            && ! str_contains($field, '目标'));
        $targetField = $personalFields->first(fn (string $field): bool => $this->isChannelField($field, $channel)
            && (str_contains($field, '销售目标') || str_contains($field, '销售额目标')));
        $missingFields = [];
        if (! is_string($dailySalesField)) {
            $missingFields[] = $label.' 销售额字段';
        }
        if (! is_string($targetField)) {
            $missingFields[] = $label.' 月销售目标字段';
        }

        if ($missingFields !== []) {
            return $this->emptyChannelMetrics(
                $schema,
                '个人目标同步记录缺少字段：'.implode('、', $missingFields).'。',
            );
        }

        $calculationRows = $rows
            ->filter(fn (array $item): bool => $item['date'] <= $row['date'])
            ->sortBy('date')
            ->values();
        $periodSales = 0.0;
        $hasSales = false;
        foreach ($calculationRows as $calculationRow) {
            $value = $this->numeric($calculationRow['fields'][$dailySalesField] ?? null);
            if ($value !== null) {
                $periodSales += $value;
                $hasSales = true;
            }
        }

        $dailySales = $this->roundedNumeric($row['fields'][$dailySalesField] ?? null);
        $target = $this->roundedNumeric($row['fields'][$targetField] ?? null);
        if (! $hasSales || $target === null) {
            return $this->emptyChannelMetrics($schema, '所选时间范围内暂无可用的 '.$label.' 目标数据。');
        }

        $periodSales = round($periodSales, 2);
        $completion = $target > 0 ? ($periodSales / $target) * 100 : null;
        $periodStart = CarbonImmutable::parse($period['date_from'], $period['timezone'])->startOfDay();
        $periodEnd = CarbonImmutable::parse($period['date_to'], $period['timezone'])->startOfDay();
        $asOf = CarbonImmutable::parse($row['date'], $period['timezone'])->startOfDay();
        $totalDays = max((int) $periodStart->diffInDays($periodEnd) + 1, 1);
        $elapsedDays = min(max((int) $periodStart->diffInDays($asOf) + 1, 1), $totalDays);
        $remainingDays = max($totalDays - $elapsedDays, 0);
        $timeProgress = ($elapsedDays / $totalDays) * 100;
        $timeVariance = $completion === null ? null : $completion - $timeProgress;
        $remainingSales = $target > $periodSales ? $target - $periodSales : 0;
        $dailyNeeded = $remainingSales / max($remainingDays, 1);
        $dailyAchievementRate = $completion !== null && $timeProgress > 0
            ? ($completion / $timeProgress) * 100
            : null;

        return [
            'schema' => $schema,
            'available' => true,
            'message' => null,
            'as_of_date' => $row['date'],
            'values' => [
                'daily_sales' => $dailySales,
                'period_sales' => $periodSales,
                'target' => $target,
                'completion_rate' => $completion === null ? null : round($completion, 2),
                'time_progress' => round($timeProgress, 2),
                'time_variance' => $timeVariance === null ? null : round($timeVariance, 2),
                'daily_needed' => round($dailyNeeded, 2),
                'daily_achievement_rate' => $dailyAchievementRate === null ? null : round($dailyAchievementRate, 2),
                'elapsed_days' => $elapsedDays,
                'remaining_days' => $remainingDays,
            ],
            'source_fields' => [
                'daily_sales' => $dailySalesField,
                'period_sales' => $dailySalesField,
                'target' => $targetField,
                'completion_rate' => [$dailySalesField, $targetField],
                'time_progress' => self::DATE_FIELD,
                'time_variance' => [$dailySalesField, $targetField, self::DATE_FIELD],
                'daily_needed' => [$dailySalesField, $targetField, self::DATE_FIELD],
                'daily_achievement_rate' => [$dailySalesField, $targetField, self::DATE_FIELD],
            ],
        ];
    }

    private function isChannelField(string $field, string $channel): bool
    {
        return match ($channel) {
            'facebook' => preg_match('/(^|[^a-z])fb([^a-z]|$)|facebook|meta/i', $field) === 1,
            'criteo' => str_contains(strtolower($field), 'criteo'),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function emptyChannelMetrics(string $schema, ?string $message): array
    {
        return [
            'schema' => $schema,
            'available' => false,
            'message' => $message,
            'as_of_date' => null,
            'values' => [
                'daily_sales' => null,
                'period_sales' => null,
                'target' => null,
                'completion_rate' => null,
                'time_progress' => null,
                'time_variance' => null,
                'daily_needed' => null,
                'daily_achievement_rate' => null,
                'elapsed_days' => 0,
                'remaining_days' => 0,
            ],
            'source_fields' => [
                'daily_sales' => '',
                'period_sales' => '',
                'target' => '',
                'completion_rate' => [],
                'time_progress' => self::DATE_FIELD,
                'time_variance' => [],
                'daily_needed' => [],
                'daily_achievement_rate' => [],
            ],
        ];
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  Collection<int, PaidAdvertisingGoalField>  $fieldDefinitions
     * @param  array{date_from: string, date_to: string, timezone: string, label?: string}  $period
     * @return array<string, mixed>
     */
    private function details(Collection $rows, Collection $fieldDefinitions, array $period): array
    {
        $columns = $fieldDefinitions
            ->sortBy(fn (PaidAdvertisingGoalField $field): int => (
                $field->name === '月份' ? 0 : ($field->name === self::DATE_FIELD ? 1 : 2)
            ) * 10000 + (int) $field->field_order)
            ->values()
            ->map(fn (PaidAdvertisingGoalField $field): array => [
                'key' => $field->name,
                'label' => $field->name,
                'kind' => $this->detailKind($field->name),
                'description' => $field->description,
            ])
            ->all();
        $detailRows = $rows
            ->sortByDesc('date')
            ->values()
            ->map(function (array $row, int $index) use ($columns): array {
                $values = [];
                foreach ($columns as $column) {
                    $values[$column['key']] = $this->detailValue(
                        $row['fields'][$column['key']] ?? null,
                        $column['kind'],
                    );
                }

                return [
                    'key' => $row['date'].'-'.$index,
                    'date' => $row['date'],
                    'values' => $values,
                ];
            })
            ->all();

        return [
            'schema' => 'paid-advertising-personal-goal-details-v1',
            'available' => $columns !== [] && $detailRows !== [],
            'message' => $detailRows === [] ? '所选时间范围内暂无目标明细记录。' : null,
            'period_label' => $period['label'] ?? $period['date_from'].' 至 '.$period['date_to'],
            'columns' => $columns,
            'rows' => $detailRows,
            'total' => count($detailRows),
        ];
    }

    private function detailKind(string $field): string
    {
        if ($field === self::DATE_FIELD || preg_match('/日期|date/i', $field) === 1) {
            return 'date';
        }
        if (preg_match('/^月份$|^月$/', $field) === 1) {
            return 'month';
        }
        if (preg_match('/进度|完成率|达成|对比|百分比|占比/', $field) === 1) {
            return 'percentage';
        }
        if (preg_match('/销售额|目标|花费|退款|金额|数量|销量|订单|日均|ROAS|ROI/i', $field) === 1) {
            return 'number';
        }

        return 'text';
    }

    private function detailValue(mixed $value, string $kind): string|float|null
    {
        if ($kind === 'date') {
            return $this->date($value);
        }

        $flattened = $this->flattenValue($value);
        if ($flattened === null || $flattened === '') {
            return null;
        }
        if ($kind === 'month') {
            return (string) $flattened;
        }
        if ($kind === 'percentage') {
            $number = $this->numeric($flattened);
            if ($number === null) {
                return (string) $flattened;
            }

            return round(abs($number) <= 10 ? $number * 100 : $number, 2);
        }
        if ($kind === 'number') {
            $number = $this->numeric($flattened);

            return $number === null ? (string) $flattened : round($number, 2);
        }

        return is_scalar($flattened) ? (string) $flattened : null;
    }

    private function flattenValue(mixed $value): string|float|int|null
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (! is_array($value)) {
            return null;
        }

        foreach (['text', 'value', 'number'] as $key) {
            if (array_key_exists($key, $value)) {
                return $this->flattenValue($value[$key]);
            }
        }

        $items = collect($value)
            ->map(fn (mixed $item): string|float|int|null => $this->flattenValue($item))
            ->filter(fn (string|float|int|null $item): bool => $item !== null && $item !== '')
            ->values();
        if ($items->count() === 1) {
            return $items->first();
        }

        return $items->isEmpty() ? null : $items->map(fn ($item): string => (string) $item)->implode('、');
    }

    /** @param Collection<int, string> $personalFields @return list<string> */
    private function spendFields(PaidAdvertisingGoalBoard $board, Collection $personalFields): array
    {
        $fields = ['FB花费-'.$board->name];

        if ($personalFields->contains(fn (string $field): bool => str_contains(strtolower($field), 'criteo'))) {
            $fields[] = 'Criteo花费';
        }

        if ($personalFields->contains(fn (string $field): bool => preg_match('/tiktok|tik tok|抖音/i', $field) === 1)) {
            $fields[] = 'Tiktok花费';
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @param  list<string>  $spendFields
     */
    private function sumFields(Collection $rows, array $fields): ?float
    {
        if ($fields === []) {
            return null;
        }

        $sum = 0.0;
        $hasValue = false;
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $value = $this->numeric($row['fields'][$field] ?? null);
                if ($value !== null) {
                    $sum += $value;
                    $hasValue = true;
                }
            }
        }

        return $hasValue ? round($sum, 2) : null;
    }

    /**
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @return Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>
     */
    private function datedRows(
        Organization $organization,
        Store $store,
        string $sourceKey,
        array $period,
    ): Collection {
        return $this->allDatedRows($organization, $store, $sourceKey)
            ->filter(fn (array $row): bool => $row['date'] >= $period['date_from']
                && $row['date'] <= $period['date_to'])
            ->values();
    }

    /**
     * @return Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>
     */
    private function allDatedRows(
        Organization $organization,
        Store $store,
        string $sourceKey,
        string $dateField = self::DATE_FIELD,
    ): Collection {
        return PaidAdvertisingGoalRecord::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', $sourceKey)
            ->select(['id', 'fields_encrypted', 'synced_at'])
            ->orderByDesc('id')
            ->limit(self::MAX_RECORDS)
            ->get()
            ->map(function (PaidAdvertisingGoalRecord $record) use ($dateField): ?array {
                $fields = $record->fields_encrypted;
                if (! is_array($fields)) {
                    return null;
                }

                $date = $this->date($fields[$dateField] ?? null);
                if ($date === null) {
                    return null;
                }

                return [
                    'date' => $date,
                    'fields' => $fields,
                    'synced_at' => $record->synced_at?->toIso8601String(),
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null)
            ->values();
    }

    private function date(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['value', 'text', 'date'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->date($value[$key]);
                }
            }

            if (count($value) === 1) {
                return $this->date(reset($value));
            }

            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (float) $value;
            $seconds = abs($timestamp) >= 100000000000 ? $timestamp / 1000 : $timestamp;
            $feishuTimezone = (string) config(
                'services.feishu_table.paid_advertising_goal_sync_timezone',
                'Asia/Shanghai',
            );

            try {
                return CarbonImmutable::createFromTimestampUTC($seconds)
                    ->setTimezone($feishuTimezone)
                    ->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/D', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

            return $date->format('Y-m-d') === $value ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function roundedNumeric(mixed $value): ?float
    {
        $number = $this->numeric($value);

        return $number === null ? null : round($number, 2);
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (is_array($value)) {
            foreach (['value', 'text', 'number'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->numeric($value[$key]);
                }
            }

            if (count($value) === 1) {
                return $this->numeric(reset($value));
            }

            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace([',', '$', '¥', '￥', ' '], '', $value));
        $negative = str_starts_with($normalized, '(') && str_ends_with($normalized, ')');
        if ($negative) {
            $normalized = trim($normalized, '()');
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return is_finite($number) ? ($negative ? -abs($number) : $number) : null;
    }

    /**
     * @param  list<string>  $missingFields
     * @param  list<string>  $spendFields
     * @param  array{daily_sales: float|null, monthly_sales: float|null, monthly_target: float|null, completion_rate: float|null, monthly_spend: float|null, roas: float|null}|null  $values
     * @param  array<string, mixed>|null  $facebook
     * @param  array<string, mixed>|null  $criteo
     * @param  array<string, mixed>|null  $details
     * @param  array<string, mixed>|null  $facebookEfficiency
     * @param  array<string, mixed>|null  $metaWeekly
     * @return array<string, mixed>
     */
    private function result(
        Store $store,
        ?string $asOfDate,
        string $yesterday,
        array $missingFields,
        array $spendFields,
        ?string $message,
        ?array $values = null,
        ?string $syncedAt = null,
        ?array $facebook = null,
        ?array $criteo = null,
        ?array $details = null,
        ?array $facebookEfficiency = null,
        ?array $metaWeekly = null,
    ): array {
        $values ??= [
            'daily_sales' => null,
            'monthly_sales' => null,
            'monthly_target' => null,
            'completion_rate' => null,
            'monthly_spend' => null,
            'roas' => null,
        ];
        $dayLabel = $asOfDate === null
            ? '最新有效日'
            : ($asOfDate === $yesterday
                ? '昨日'
                : CarbonImmutable::parse($asOfDate, 'UTC')->format('n月j日'));

        return [
            'schema' => 'paid-advertising-personal-facebook-summary-v1',
            'available' => $asOfDate !== null
                && $values['monthly_sales'] !== null
                && $values['monthly_target'] !== null,
            'source' => 'database_sync',
            'currency' => $store->currency ?: 'USD',
            'as_of_date' => $asOfDate,
            'day_label' => $dayLabel,
            'synced_at' => $syncedAt,
            'missing_fields' => $missingFields,
            'message' => $message,
            'values' => $values,
            'facebook' => $facebook ?? $this->emptyChannelMetrics('paid-advertising-personal-facebook-channel-goal-v1', null),
            'criteo' => $criteo ?? $this->emptyChannelMetrics('paid-advertising-personal-criteo-channel-goal-v1', null),
            'details' => $details ?? [
                'schema' => 'paid-advertising-personal-goal-details-v1',
                'available' => false,
                'message' => null,
                'period_label' => '',
                'columns' => [],
                'rows' => [],
                'total' => 0,
            ],
            'facebook_efficiency' => $facebookEfficiency ?? $this->emptyFacebookEfficiency(null),
            'meta_weekly' => $metaWeekly ?? $this->emptyMetaWeekly(null),
            'source_fields' => [
                'daily_sales' => self::DAILY_SALES_FIELD,
                'monthly_sales' => self::MONTHLY_SALES_FIELD,
                'monthly_target' => self::MONTHLY_TARGET_FIELD,
                'completion_rate' => [self::MONTHLY_SALES_FIELD, self::MONTHLY_TARGET_FIELD],
                'monthly_spend' => $spendFields,
                'roas' => [self::MONTHLY_SALES_FIELD, ...$spendFields],
            ],
        ];
    }

    private function assertScope(
        Organization $organization,
        Store $store,
        PaidAdvertisingGoalBoard $board,
    ): void {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless((int) $board->organization_id === (int) $organization->id, 404);
        abort_unless((int) $board->store_id === (int) $store->id, 404);
    }
}
