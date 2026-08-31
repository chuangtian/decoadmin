<?php

namespace App\Services;

use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class PaidAdvertisingGoalMetricsService
{
    private const OVERALL_SOURCE_TABLE = 'MF数据表';

    private const DATE_FIELD = '日期';

    private const DAILY_SALES_FIELD = '总销售额';

    private const REFUNDS_FIELD = '退款';

    private const MONTHLY_SALES_FIELD = '月销售额总和';

    private const MONTHLY_TARGET_FIELD = '月度目标销售额';

    private const MONTHLY_REFUNDS_FIELD = '月总退款总和';

    private const PROJECT_SALES_FIELDS = [
        ['key' => 'x7', 'label' => 'X7', 'field' => 'X7销量'],
        ['key' => 'x2', 'label' => 'X2', 'field' => 'X2销量'],
        ['key' => 'x1s', 'label' => 'X1S', 'field' => 'X1S销量'],
        ['key' => 'm16', 'label' => 'M16', 'field' => 'M16销量'],
        ['key' => 'x1s_bszay', 'label' => 'X1S × Bs.zay', 'field' => 'X1S x Bs.zay销量'],
    ];

    private const SITE_ACTIVITY_FIELDS = [
        ['key' => 'orders', 'label' => '订单数', 'field' => '订单数'],
        ['key' => 'add_to_cart', 'label' => '整站加购', 'field' => '整站总加购'],
        ['key' => 'checkout', 'label' => '整站结账', 'field' => '整站总结账'],
    ];

    private const CHANNEL_FIELDS = [
        ['key' => 'facebook', 'label' => 'Facebook', 'spend' => 'FB花费', 'sales' => 'FB销售额', 'roi' => 'FB的ROI'],
        ['key' => 'google', 'label' => 'Google', 'spend' => 'GG花费', 'sales' => 'GG销售额', 'roi' => 'GG的ROI'],
        ['key' => 'tiktok', 'label' => 'TikTok', 'spend' => 'Tiktok花费', 'sales' => 'Tiktok销售额', 'roi' => 'Tiktok的ROI'],
        ['key' => 'bing', 'label' => 'Bing', 'spend' => 'Bing花费', 'sales' => 'Bing销售额', 'roi' => 'Bing的ROI'],
        ['key' => 'criteo', 'label' => 'Criteo', 'spend' => 'Criteo花费', 'sales' => 'Criteo销售', 'roi' => 'Criteo的ROI'],
    ];

    private const CHANNEL_SUMMARY_FIELDS = [
        ['key' => 'total_spend', 'label' => '总花费', 'field' => '总花费', 'format' => 'currency', 'tone' => 'rose'],
        ['key' => 'total_roi', 'label' => '总ROI', 'field' => '总ROI', 'format' => 'roi', 'tone' => 'blue'],
        ['key' => 'adjusted_roi', 'label' => 'ROI（预5%退款）', 'field' => 'ROI（预5%退款）', 'format' => 'roi', 'tone' => 'orange'],
        ['key' => 'monthly_spend', 'label' => '月累计花费', 'field' => '月总花费总和', 'format' => 'currency', 'tone' => 'violet'],
    ];

    private const REQUIRED_FIELDS = [
        self::DATE_FIELD,
        self::DAILY_SALES_FIELD,
        self::REFUNDS_FIELD,
        self::MONTHLY_SALES_FIELD,
        self::MONTHLY_TARGET_FIELD,
    ];

    private const MAX_RECORDS = 5000;

    /**
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @return array<string, mixed>
     */
    public function summary(
        Organization $organization,
        Store $store,
        ?int $boardId,
        array $period,
    ): array {
        $this->assertScope($organization, $store, $boardId);
        $sourceKey = $boardId === null ? 'overall' : 'board:'.$boardId;
        $overallSourceTable = $boardId === null
            ? $this->overallSourceTable($organization, $store)
            : null;
        $availableFields = $overallSourceTable instanceof FeishuBitableTable
            ? $overallSourceTable->fields()
                ->whereIn('name', self::REQUIRED_FIELDS)
                ->pluck('name')
                ->unique()
                ->all()
            : PaidAdvertisingGoalField::query()
                ->forOrganization($organization)
                ->forStore($store)
                ->where('source_key', $sourceKey)
                ->whereIn('name', self::REQUIRED_FIELDS)
                ->pluck('name')
                ->unique()
                ->all();
        $missingFields = array_values(array_diff(self::REQUIRED_FIELDS, $availableFields));
        $rows = $overallSourceTable instanceof FeishuBitableTable
            ? $this->datedArchiveRows($overallSourceTable, $period)
            : $this->datedRows($organization, $store, $sourceKey, $period);
        $yesterday = CarbonImmutable::now($period['timezone'])->subDay()->toDateString();
        $cutoffDate = min($period['date_to'], $yesterday);
        $eligibleRows = $rows
            ->filter(fn (array $row): bool => $row['date'] <= $cutoffDate)
            ->sortByDesc('date')
            ->values();
        $latestRow = $eligibleRows->first();
        $row = $eligibleRows->first(function (array $row): bool {
            $dailySales = $this->numeric($row['fields'][self::DAILY_SALES_FIELD] ?? null);
            $monthlySales = $this->numeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null);

            return ($dailySales !== null && $dailySales > 0)
                || ($monthlySales !== null && $monthlySales > 0);
        }) ?? $latestRow;

        if (! is_array($row)) {
            return $this->result(
                $store,
                null,
                $yesterday,
                $missingFields,
                '所选时间范围内暂无可用的飞书数据。',
            );
        }

        $fields = $row['fields'];
        $dailySales = $this->numeric($fields[self::DAILY_SALES_FIELD] ?? null);
        $refunds = $this->numeric($fields[self::REFUNDS_FIELD] ?? null);
        $monthlySales = $this->numeric($fields[self::MONTHLY_SALES_FIELD] ?? null);
        $monthlyTarget = $this->numeric($fields[self::MONTHLY_TARGET_FIELD] ?? null);
        $completionRate = $monthlySales !== null && $monthlyTarget !== null && $monthlyTarget > 0
            ? round(($monthlySales / $monthlyTarget) * 100, 1)
            : null;
        $values = [
            'daily_sales' => $dailySales !== null ? round($dailySales, 2) : null,
            'refunds' => $refunds !== null && abs($refunds) > 0 ? round(-abs($refunds), 2) : null,
            'monthly_sales' => $monthlySales !== null ? round($monthlySales, 2) : null,
            'completion_rate' => $completionRate,
        ];
        $message = $missingFields === []
            ? null
            : '飞书表缺少字段：'.implode('、', $missingFields).'。';

        return $this->result(
            $store,
            $row['date'],
            $yesterday,
            $missingFields,
            $message,
            $values,
            $row['synced_at'],
            $this->projectSales($row['date'], $yesterday, $fields),
            $this->channelPerformance($row['date'], $yesterday, $fields),
            $this->progress(
                $eligibleRows->sortBy('date')->values(),
                $row,
                $latestRow,
            ),
        );
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
                    'date' => $date,
                    'fields' => $fields,
                    'synced_at' => $record->synced_at?->toIso8601String(),
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null
                && $row['date'] >= $period['date_from']
                && $row['date'] <= $period['date_to'])
            ->values();
    }

    /**
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
     * @return Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>
     */
    private function datedArchiveRows(FeishuBitableTable $table, array $period): Collection
    {
        return $table->records()
            ->select(['id', 'feishu_bitable_table_id', 'fields_encrypted', 'synced_at'])
            ->orderByDesc('id')
            ->limit(self::MAX_RECORDS)
            ->get()
            ->map(function (FeishuBitableRecord $record): ?array {
                $fields = $record->fields_encrypted;
                if (! is_array($fields)) {
                    return null;
                }

                $date = $this->date($fields[self::DATE_FIELD] ?? null);
                if ($date === null) {
                    return null;
                }

                return [
                    'date' => $date,
                    'fields' => $fields,
                    'synced_at' => $record->synced_at?->toIso8601String(),
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null
                && $row['date'] >= $period['date_from']
                && $row['date'] <= $period['date_to'])
            ->values();
    }

    private function overallSourceTable(Organization $organization, Store $store): ?FeishuBitableTable
    {
        return FeishuBitableTable::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('name', self::OVERALL_SOURCE_TABLE)
            ->whereHas('fields', fn ($query) => $query->where('name', self::DATE_FIELD))
            ->whereHas('records')
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->first();
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
     * @param  array{daily_sales: float|null, refunds: float|null, monthly_sales: float|null, completion_rate: float|null}|null  $values
     * @param  array<string, mixed>|null  $projectSales
     * @param  array<string, mixed>|null  $channelPerformance
     * @param  array<string, mixed>|null  $progress
     * @return array<string, mixed>
     */
    private function result(
        Store $store,
        ?string $asOfDate,
        string $yesterday,
        array $missingFields,
        ?string $message,
        ?array $values = null,
        ?string $syncedAt = null,
        ?array $projectSales = null,
        ?array $channelPerformance = null,
        ?array $progress = null,
    ): array {
        $values ??= [
            'daily_sales' => null,
            'refunds' => null,
            'monthly_sales' => null,
            'completion_rate' => null,
        ];
        $dayLabel = $asOfDate === null
            ? '所选日期'
            : ($asOfDate === $yesterday
                ? '昨日'
                : CarbonImmutable::parse($asOfDate, 'UTC')->format('n月j日'));

        return [
            'schema' => 'paid-advertising-goal-metrics-v1',
            'available' => $asOfDate !== null && ! in_array(null, [
                $values['daily_sales'],
                $values['monthly_sales'],
                $values['completion_rate'],
            ], true),
            'source' => 'feishu',
            'currency' => $store->currency ?: 'USD',
            'as_of_date' => $asOfDate,
            'synced_at' => $syncedAt,
            'missing_fields' => $missingFields,
            'message' => $message,
            'project_sales' => $projectSales ?? $this->projectSales(null, $yesterday, []),
            'channel_performance' => $channelPerformance ?? $this->channelPerformance(null, $yesterday, []),
            'progress' => $progress ?? $this->progress(collect(), null, null),
            'cards' => [
                [
                    'key' => 'daily_sales',
                    'label' => $dayLabel.'完成',
                    'value' => $values['daily_sales'],
                    'format' => 'currency',
                    'tone' => 'blue',
                    'source_fields' => [self::DAILY_SALES_FIELD],
                ],
                [
                    'key' => 'refunds',
                    'label' => $dayLabel.'退款',
                    'value' => $values['refunds'],
                    'format' => 'currency',
                    'tone' => 'rose',
                    'source_fields' => [self::REFUNDS_FIELD],
                ],
                [
                    'key' => 'monthly_sales',
                    'label' => '截止'.$dayLabel.'累计完成',
                    'value' => $values['monthly_sales'],
                    'format' => 'currency',
                    'tone' => 'emerald',
                    'source_fields' => [self::MONTHLY_SALES_FIELD],
                ],
                [
                    'key' => 'completion_rate',
                    'label' => '目标完成率',
                    'value' => $values['completion_rate'],
                    'format' => 'percent',
                    'tone' => 'amber',
                    'source_fields' => [self::MONTHLY_SALES_FIELD, self::MONTHLY_TARGET_FIELD],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function projectSales(?string $asOfDate, string $yesterday, array $fields): array
    {
        $dayLabel = $asOfDate === null
            ? null
            : ($asOfDate === $yesterday
                ? '昨日'
                : CarbonImmutable::parse($asOfDate, 'UTC')->format('n月j日'));
        $dateLabel = $asOfDate === null
            ? null
            : CarbonImmutable::parse($asOfDate, 'UTC')->format('m-d');

        return [
            'schema' => 'paid-advertising-project-sales-v1',
            'available' => $asOfDate !== null,
            'as_of_date' => $asOfDate,
            'title' => $asOfDate === null
                ? '项目销量明细'
                : $dayLabel.'项目销量明细（'.$dateLabel.'）',
            'message' => $asOfDate === null ? '所选时间范围内暂无项目销量数据。' : null,
            'products' => array_map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'value' => (int) round($this->numeric($fields[$definition['field']] ?? null) ?? 0),
                'unit' => '台',
                'source_field' => $definition['field'],
            ], self::PROJECT_SALES_FIELDS),
            'site_metrics' => array_map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'value' => (int) round($this->numeric($fields[$definition['field']] ?? null) ?? 0),
                'source_field' => $definition['field'],
            ], self::SITE_ACTIVITY_FIELDS),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function channelPerformance(?string $asOfDate, string $yesterday, array $fields): array
    {
        $dayLabel = $asOfDate === null
            ? null
            : ($asOfDate === $yesterday
                ? '昨日'
                : CarbonImmutable::parse($asOfDate, 'UTC')->format('n月j日'));
        $dateLabel = $asOfDate === null
            ? null
            : CarbonImmutable::parse($asOfDate, 'UTC')->format('m-d');

        return [
            'schema' => 'paid-advertising-channel-performance-v1',
            'available' => $asOfDate !== null,
            'as_of_date' => $asOfDate,
            'title' => $asOfDate === null
                ? '各渠道投放数据'
                : $dayLabel.'各渠道投放数据（'.$dateLabel.'）',
            'message' => $asOfDate === null ? '所选时间范围内暂无渠道投放数据。' : null,
            'channels' => array_map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'spend' => $this->roundedNumeric($fields[$definition['spend']] ?? null),
                'sales' => $this->roundedNumeric($fields[$definition['sales']] ?? null),
                'roi' => $this->roundedNumeric($fields[$definition['roi']] ?? null),
                'source_fields' => [
                    'spend' => $definition['spend'],
                    'sales' => $definition['sales'],
                    'roi' => $definition['roi'],
                ],
            ], self::CHANNEL_FIELDS),
            'summary' => array_map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'value' => $this->roundedNumeric($fields[$definition['field']] ?? null),
                'format' => $definition['format'],
                'tone' => $definition['tone'],
                'source_field' => $definition['field'],
            ], self::CHANNEL_SUMMARY_FIELDS),
        ];
    }

    private function roundedNumeric(mixed $value): ?float
    {
        $number = $this->numeric($value);

        return $number === null ? null : round($number, 2);
    }

    /**
     * @param  Collection<int, array{date: string, fields: array<string, mixed>, synced_at: string|null}>  $eligibleRows
     * @param  array{date: string, fields: array<string, mixed>, synced_at: string|null}|null  $summaryRow
     * @param  array{date: string, fields: array<string, mixed>, synced_at: string|null}|null  $latestRow
     * @return array<string, mixed>
     */
    private function progress(Collection $eligibleRows, ?array $summaryRow, ?array $latestRow): array
    {
        $lastValidIndex = -1;
        foreach ($eligibleRows as $index => $row) {
            $dailySales = $this->numeric($row['fields'][self::DAILY_SALES_FIELD] ?? null);
            $monthlySales = $this->numeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null);
            if (($dailySales !== null && $dailySales > 0) || ($monthlySales !== null && $monthlySales > 0)) {
                $lastValidIndex = $index;
            }
        }

        $chartRows = $lastValidIndex >= 0
            ? $eligibleRows->take($lastValidIndex + 1)
            : $eligibleRows;
        $target = $this->roundedNumeric($latestRow['fields'][self::MONTHLY_TARGET_FIELD] ?? null);
        $cumulativeSales = $this->roundedNumeric($summaryRow['fields'][self::MONTHLY_SALES_FIELD] ?? null);
        $monthlyRefunds = $this->roundedNumeric($summaryRow['fields'][self::MONTHLY_REFUNDS_FIELD] ?? null);
        $completionRate = $target !== null && $target > 0 && $cumulativeSales !== null
            ? round(($cumulativeSales / $target) * 100, 1)
            : null;
        $remaining = $target !== null && $cumulativeSales !== null
            ? round(max($target - $cumulativeSales, 0), 2)
            : null;

        return [
            'schema' => 'paid-advertising-goal-progress-v1',
            'available' => $chartRows->isNotEmpty(),
            'message' => $chartRows->isEmpty() ? '所选时间范围内暂无每日进度数据。' : null,
            'points' => $chartRows->map(fn (array $row): array => [
                'date' => $row['date'],
                'daily_sales' => $this->roundedNumeric($row['fields'][self::DAILY_SALES_FIELD] ?? null) ?? 0.0,
                'daily_refunds' => round(abs($this->numeric($row['fields'][self::REFUNDS_FIELD] ?? null) ?? 0), 2),
                'cumulative_sales' => $this->roundedNumeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null) ?? 0.0,
            ])->values()->all(),
            'monthly_target' => $target,
            'completion' => [
                'cumulative_sales' => $cumulativeSales,
                'monthly_refunds' => $monthlyRefunds === null ? null : round(-abs($monthlyRefunds), 2),
                'target' => $target,
                'rate' => $completionRate,
                'remaining' => $remaining,
            ],
            'source_fields' => [
                'daily_sales' => self::DAILY_SALES_FIELD,
                'daily_refunds' => self::REFUNDS_FIELD,
                'cumulative_sales' => self::MONTHLY_SALES_FIELD,
                'monthly_target' => self::MONTHLY_TARGET_FIELD,
                'monthly_refunds' => self::MONTHLY_REFUNDS_FIELD,
            ],
        ];
    }

    private function assertScope(Organization $organization, Store $store, ?int $boardId): void
    {
        if ((int) $store->organization_id !== (int) $organization->id) {
            throw new InvalidArgumentException('The store does not belong to the supplied organization.');
        }

        if ($boardId !== null && ! PaidAdvertisingGoalBoard::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->whereKey($boardId)
            ->exists()) {
            throw new InvalidArgumentException('The goal board does not belong to the supplied store.');
        }
    }
}
