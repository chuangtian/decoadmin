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

class PaidAdvertisingGoogleAdsMetricsService
{
    private const DATE_FIELD = '日期';

    private const DAILY_SALES_FIELD = '今日销售额($)';

    private const MONTHLY_SALES_FIELD = '本月已完成销售额($)';

    private const MONTHLY_TARGET_FIELD = '本月销售额目标($)';

    private const DAILY_NEEDED_FIELD = '日均还需完成($)';

    private const DAILY_ACHIEVEMENT_RATE_FIELD = '日均达成率';

    private const COMPLETION_RATE_FIELD = '月目标当前完成率';

    private const TIME_VARIANCE_FIELD = '月时间对比完成度';

    private const TIME_PROGRESS_FIELD = '月时间进度';

    private const SALES_SHEET_TITLE = '销售目标';

    private const DEFAULT_TARGET_ROAS = 3.7;

    private const MAX_RECORDS = 5000;

    private const REQUIRED_FIELDS = [
        self::DATE_FIELD,
        self::DAILY_SALES_FIELD,
        self::MONTHLY_SALES_FIELD,
        self::MONTHLY_TARGET_FIELD,
        self::DAILY_NEEDED_FIELD,
        self::DAILY_ACHIEVEMENT_RATE_FIELD,
        self::COMPLETION_RATE_FIELD,
        self::TIME_VARIANCE_FIELD,
        self::TIME_PROGRESS_FIELD,
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

        $source = $this->salesSource($organization, $store, $board);
        if ($source === null) {
            return $this->result(
                $store,
                null,
                null,
                self::REQUIRED_FIELDS,
                '尚未在当前目标页签的同步数据中找到“销售目标”工作表。',
                period: $period,
            );
        }

        $missingFields = array_values(array_diff(self::REQUIRED_FIELDS, $source['fields']));
        $rows = $this->datedRows($organization, $store, $source['source_key'])
            ->filter(fn (array $row): bool => $row['date'] >= $period['date_from']
                && $row['date'] <= $period['date_to'])
            ->sortByDesc('date')
            ->values();
        $row = $rows->first(fn (array $row): bool => $this->hasGoalValue($row['fields']));
        $details = $this->details($rows, $source['columns'], $period);

        if (! is_array($row)) {
            return $this->result(
                $store,
                $source['sheet_title'],
                null,
                $missingFields,
                '所选时间范围内暂无可用的 Google 广告目标同步数据。',
                period: $period,
                efficiency: $this->efficiency(null, $source['fields']),
                details: $details,
            );
        }

        $dailySales = $this->roundedNumeric($row['fields'][self::DAILY_SALES_FIELD] ?? null);
        $monthlySales = $this->roundedNumeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null);
        $monthlyTarget = $this->roundedNumeric($row['fields'][self::MONTHLY_TARGET_FIELD] ?? null);
        $completionRate = $this->percentage($row['fields'][self::COMPLETION_RATE_FIELD] ?? null)
            ?? $this->ratio($monthlySales, $monthlyTarget);
        $timeProgress = $this->percentage($row['fields'][self::TIME_PROGRESS_FIELD] ?? null)
            ?? $this->monthProgress($row['date']);
        $timeVariance = $this->percentage($row['fields'][self::TIME_VARIANCE_FIELD] ?? null)
            ?? ($completionRate !== null && $timeProgress !== null
                ? round($completionRate - $timeProgress, 2)
                : null);
        $dailyNeeded = $this->roundedNumeric($row['fields'][self::DAILY_NEEDED_FIELD] ?? null)
            ?? $this->dailyNeeded($monthlySales, $monthlyTarget, $row['date']);
        $dailyAchievementRate = $this->percentage($row['fields'][self::DAILY_ACHIEVEMENT_RATE_FIELD] ?? null)
            ?? $this->ratio($dailySales, $dailyNeeded);
        $message = $missingFields === []
            ? null
            : '飞书“销售目标”工作表缺少字段：'.implode('、', $missingFields).'。缺失指标已尽量按相同口径计算。';

        return $this->result(
            $store,
            $source['sheet_title'],
            $row['date'],
            $missingFields,
            $message,
            [
                'daily_sales' => $dailySales,
                'monthly_sales' => $monthlySales,
                'monthly_target' => $monthlyTarget,
                'daily_needed' => $dailyNeeded,
                'daily_achievement_rate' => $dailyAchievementRate,
                'completion_rate' => $completionRate,
                'time_variance' => $timeVariance,
                'time_progress' => $timeProgress,
            ],
            $row['synced_at'],
            $period,
            $this->efficiency($row, $source['fields']),
            $details,
        );
    }

    /**
     * @return array{source_key: string, sheet_title: string|null, fields: list<string>, columns: list<array{key: string, label: string, kind: string}>}|null
     */
    private function salesSource(
        Organization $organization,
        Store $store,
        PaidAdvertisingGoalBoard $board,
    ): ?array {
        $sourcePrefix = 'board:'.(int) $board->id.':google:';
        $groups = PaidAdvertisingGoalField::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('goal_board_id', $board->id)
            ->where('source_key', 'like', $sourcePrefix.'%')
            ->orderBy('field_order')
            ->get(['source_key', 'name', 'property_encrypted'])
            ->groupBy('source_key');

        if ($groups->isEmpty()) {
            return null;
        }

        $mapped = $groups->map(function (Collection $fields, string $sourceKey): array {
            $sheetTitle = $fields
                ->map(fn (PaidAdvertisingGoalField $field): mixed => data_get($field->property_encrypted, 'sheet_title'))
                ->first(fn (mixed $title): bool => is_string($title) && trim($title) !== '');

            return [
                'source_key' => $sourceKey,
                'sheet_title' => is_string($sheetTitle) ? trim($sheetTitle) : null,
                'fields' => $fields->pluck('name')->filter()->unique()->values()->all(),
                'columns' => $fields
                    ->filter(fn (PaidAdvertisingGoalField $field): bool => is_string($field->name) && trim($field->name) !== '')
                    ->unique('name')
                    ->map(fn (PaidAdvertisingGoalField $field): array => [
                        'key' => $field->name,
                        'label' => $field->name,
                        'kind' => $this->columnKind($field->name),
                    ])
                    ->values()
                    ->all(),
            ];
        })->values();

        $source = $mapped->first(fn (array $source): bool => $source['sheet_title'] === self::SALES_SHEET_TITLE)
            ?? $mapped->sortByDesc(fn (array $source): int => count(array_intersect(self::REQUIRED_FIELDS, $source['fields'])))
                ->first(fn (array $source): bool => in_array(self::DATE_FIELD, $source['fields'], true)
                    && count(array_intersect([
                        self::DAILY_SALES_FIELD,
                        self::MONTHLY_SALES_FIELD,
                        self::MONTHLY_TARGET_FIELD,
                    ], $source['fields'])) >= 2);

        return is_array($source) ? $source : null;
    }

    /**
     * @return Collection<int, array{key: string, date: string, fields: array<string, mixed>, synced_at: string|null}>
     */
    private function datedRows(Organization $organization, Store $store, string $sourceKey): Collection
    {
        return PaidAdvertisingGoalRecord::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', $sourceKey)
            ->select(['id', 'fields_encrypted', 'synced_at'])
            ->orderByDesc('id')
            ->limit(self::MAX_RECORDS)
            ->get()
            ->map(function (PaidAdvertisingGoalRecord $record): ?array {
                $fields = $record->fields_encrypted;
                if (! is_array($fields)) {
                    return null;
                }

                $date = $this->date($fields[self::DATE_FIELD] ?? null);
                if ($date === null) {
                    return null;
                }

                return [
                    'key' => 'record-'.$record->id,
                    'date' => $date,
                    'fields' => $fields,
                    'synced_at' => $record->synced_at?->toIso8601String(),
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null)
            ->values();
    }

    /**
     * @param  array{key: string, date: string, fields: array<string, mixed>, synced_at: string|null}|null  $row
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function efficiency(?array $row, array $fields): array
    {
        $currentRoasField = $this->matchingField(
            $fields,
            ['当前roas', 'roas'],
            fn (string $name): bool => str_contains($name, 'roas')
                && ! str_contains($name, '目标')
                && ! str_contains($name, '达成率'),
        );
        $targetRoasField = $this->matchingField(
            $fields,
            ['roas目标', '目标roas'],
            fn (string $name): bool => str_contains($name, 'roas') && str_contains($name, '目标'),
        );
        $monthlySpendField = $this->matchingField(
            $fields,
            ['本月花费', '花费金额', '花费', '本月消耗', '消耗'],
            fn (string $name): bool => str_contains($name, '花费') || str_contains($name, '消耗'),
        );
        $currentRoas = $currentRoasField !== null && $row !== null
            ? $this->roundedNumeric($row['fields'][$currentRoasField] ?? null)
            : null;
        $configuredTargetRoas = $targetRoasField !== null && $row !== null
            ? $this->roundedNumeric($row['fields'][$targetRoasField] ?? null)
            : null;
        $targetRoas = $configuredTargetRoas ?? self::DEFAULT_TARGET_ROAS;
        $monthlySpend = $monthlySpendField !== null && $row !== null
            ? $this->roundedNumeric($row['fields'][$monthlySpendField] ?? null)
            : null;
        $achievementRate = $currentRoas !== null && $targetRoas > 0
            ? round(($currentRoas / $targetRoas) * 100, 2)
            : null;
        $missing = [];

        if ($currentRoasField === null) {
            $missing[] = '当前 ROAS';
        }
        if ($targetRoasField === null) {
            $missing[] = '目标 ROAS（当前使用默认值 3.7）';
        }
        if ($monthlySpendField === null) {
            $missing[] = '本月花费';
        }

        return [
            'schema' => 'paid-advertising-google-efficiency-v1',
            'available' => $row !== null,
            'message' => $missing === []
                ? null
                : '“销售目标”工作表未提供'.implode('、', $missing).'；缺失项不会按 0 展示。',
            'values' => [
                'current_roas' => $currentRoas,
                'target_roas' => $targetRoas,
                'achievement_rate' => $achievementRate,
                'monthly_spend' => $monthlySpend,
            ],
            'source_fields' => [
                'current_roas' => $currentRoasField,
                'target_roas' => $targetRoasField,
                'monthly_spend' => $monthlySpendField,
            ],
        ];
    }

    /**
     * @param  Collection<int, array{key: string, date: string, fields: array<string, mixed>, synced_at: string|null}>  $rows
     * @param  list<array{key: string, label: string, kind: string}>  $columns
     * @param  array{date_from: string, date_to: string, timezone: string, label?: string}  $period
     * @return array<string, mixed>
     */
    private function details(Collection $rows, array $columns, array $period): array
    {
        return [
            'schema' => 'paid-advertising-google-target-details-v1',
            'period_label' => $period['label'] ?? null,
            'columns' => $columns,
            'rows' => $rows->map(function (array $row) use ($columns): array {
                $values = [];
                foreach ($columns as $column) {
                    $value = $column['key'] === self::DATE_FIELD
                        ? $row['date']
                        : ($row['fields'][$column['key']] ?? null);
                    $values[$column['key']] = $this->detailValue($value, $column['kind']);
                }

                return [
                    'key' => $row['key'],
                    'date' => $row['date'],
                    'values' => $values,
                ];
            })->values()->all(),
            'total' => $rows->count(),
        ];
    }

    private function columnKind(string $name): string
    {
        $normalized = $this->normalizedFieldName($name);

        if ($name === self::DATE_FIELD || str_contains($normalized, '日期')) {
            return 'date';
        }
        if (str_contains($normalized, 'roas')) {
            return 'roas';
        }
        if (str_contains($normalized, '达成率')
            || str_contains($normalized, '完成率')
            || str_contains($normalized, '时间进度')
            || str_contains($normalized, '对比完成度')) {
            return 'percentage';
        }
        if (str_contains($normalized, '销售额')
            || str_contains($normalized, '花费')
            || str_contains($normalized, '消耗')) {
            return 'currency';
        }

        return 'text';
    }

    private function detailValue(mixed $value, string $kind): string|float|null
    {
        if ($kind === 'date') {
            return $this->date($value);
        }
        if ($kind === 'percentage') {
            return $this->percentage($value);
        }
        if ($kind === 'currency' || $kind === 'roas') {
            return $this->roundedNumeric($value);
        }

        return $this->text($value);
    }

    private function text(mixed $value): string|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (is_array($value)) {
            foreach (['text', 'value', 'name'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->text($value[$key]);
                }
            }

            $parts = collect($value)
                ->map(fn (mixed $part): string|float|null => $this->text($part))
                ->filter(fn (string|float|null $part): bool => $part !== null && $part !== '')
                ->map(fn (string|float $part): string => (string) $part)
                ->values();

            return $parts->isEmpty() ? null : $parts->implode('、');
        }

        return null;
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $exactNames
     */
    private function matchingField(array $fields, array $exactNames, callable $fallback): ?string
    {
        $normalizedExactNames = array_map(fn (string $name): string => $this->normalizedFieldName($name), $exactNames);
        foreach ($fields as $field) {
            if (in_array($this->normalizedFieldName($field), $normalizedExactNames, true)) {
                return $field;
            }
        }
        foreach ($fields as $field) {
            if ($fallback($this->normalizedFieldName($field))) {
                return $field;
            }
        }

        return null;
    }

    private function normalizedFieldName(string $name): string
    {
        return mb_strtolower(preg_replace('/[\s_\-（）()$¥￥]/u', '', trim($name)) ?? trim($name));
    }

    /** @param array<string, mixed> $fields */
    private function hasGoalValue(array $fields): bool
    {
        foreach ([self::DAILY_SALES_FIELD, self::MONTHLY_SALES_FIELD, self::MONTHLY_TARGET_FIELD] as $field) {
            if ($this->numeric($fields[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
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
            $timezone = (string) config('services.feishu_table.paid_advertising_goal_sync_timezone', 'Asia/Shanghai');

            try {
                return CarbonImmutable::createFromTimestampUTC($seconds)->setTimezone($timezone)->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^(\d{4})[-\/]([01]?\d)[-\/]([0-3]?\d)$/D', $value, $matches) !== 1
            && preg_match('/^(\d{4})年([01]?\d)月([0-3]?\d)日$/D', $value, $matches) !== 1) {
            return null;
        }

        try {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');

            return $date->year === $year && $date->month === $month && $date->day === $day
                ? $date->toDateString()
                : null;
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

        $normalized = trim(str_replace([',', '$', '¥', '￥', '%', ' '], '', $value));
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

    private function percentage(mixed $value): ?float
    {
        $number = $this->numeric($value);
        if ($number === null) {
            return null;
        }

        $hasPercentSymbol = is_string($value) && str_contains($value, '%');
        $percentage = ! $hasPercentSymbol && abs($number) <= 1 ? $number * 100 : $number;

        return round($percentage, 2);
    }

    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        return $numerator !== null && $denominator !== null && $denominator > 0
            ? round(($numerator / $denominator) * 100, 2)
            : null;
    }

    private function monthProgress(string $date): ?float
    {
        try {
            $day = CarbonImmutable::parse($date, 'UTC');

            return round(($day->day / $day->daysInMonth) * 100, 2);
        } catch (Throwable) {
            return null;
        }
    }

    private function dailyNeeded(?float $monthlySales, ?float $monthlyTarget, string $date): ?float
    {
        if ($monthlySales === null || $monthlyTarget === null) {
            return null;
        }

        try {
            $day = CarbonImmutable::parse($date, 'UTC');
            $remainingDays = $day->daysInMonth - $day->day;
            $remainingAmount = max($monthlyTarget - $monthlySales, 0);

            if ($remainingDays <= 0) {
                return $remainingAmount === 0.0 ? 0.0 : null;
            }

            return round($remainingAmount / $remainingDays, 2);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $missingFields
     * @param  array<string, float|null>|null  $values
     * @return array<string, mixed>
     */
    private function result(
        Store $store,
        ?string $sheetTitle,
        ?string $asOfDate,
        array $missingFields,
        ?string $message,
        ?array $values = null,
        ?string $syncedAt = null,
        ?array $period = null,
        ?array $efficiency = null,
        ?array $details = null,
    ): array {
        $values ??= [
            'daily_sales' => null,
            'monthly_sales' => null,
            'monthly_target' => null,
            'daily_needed' => null,
            'daily_achievement_rate' => null,
            'completion_rate' => null,
            'time_variance' => null,
            'time_progress' => null,
        ];
        $completionRate = $values['completion_rate'];
        $timeProgress = $values['time_progress'];
        $paceStatus = $completionRate !== null && $timeProgress !== null && $completionRate > $timeProgress
            ? 'ahead'
            : 'behind';

        return [
            'schema' => 'paid-advertising-google-ads-summary-v1',
            'available' => $asOfDate !== null
                && $values['monthly_sales'] !== null
                && $values['monthly_target'] !== null,
            'source' => 'database_sync',
            'source_sheet' => $sheetTitle,
            'currency' => $store->currency ?: 'USD',
            'as_of_date' => $asOfDate,
            'synced_at' => $syncedAt,
            'missing_fields' => $missingFields,
            'message' => $message,
            'pace_status' => $paceStatus,
            'values' => $values,
            'source_fields' => [
                'daily_sales' => self::DAILY_SALES_FIELD,
                'monthly_sales' => self::MONTHLY_SALES_FIELD,
                'monthly_target' => self::MONTHLY_TARGET_FIELD,
                'daily_needed' => self::DAILY_NEEDED_FIELD,
                'daily_achievement_rate' => self::DAILY_ACHIEVEMENT_RATE_FIELD,
                'completion_rate' => self::COMPLETION_RATE_FIELD,
                'time_variance' => self::TIME_VARIANCE_FIELD,
                'time_progress' => self::TIME_PROGRESS_FIELD,
            ],
            'efficiency' => $efficiency ?? $this->efficiency(null, []),
            'details' => $details ?? [
                'schema' => 'paid-advertising-google-target-details-v1',
                'period_label' => $period['label'] ?? null,
                'columns' => [],
                'rows' => [],
                'total' => 0,
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
