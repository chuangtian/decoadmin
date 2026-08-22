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

    /**
     * @param  array{date_from: string, date_to: string, timezone: string}  $period
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
        $personalFields = PaidAdvertisingGoalField::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->where('source_key', $sourceKey)
            ->orderBy('field_order')
            ->pluck('name')
            ->unique()
            ->values();
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
        $rows = $this->datedRows($organization, $store, $sourceKey, $period)
            ->filter(fn (array $row): bool => $row['date'] <= $cutoffDate)
            ->sortByDesc('date')
            ->values();
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
            );
        }

        $dailySales = $this->roundedNumeric($row['fields'][self::DAILY_SALES_FIELD] ?? null);
        $monthlySales = $this->roundedNumeric($row['fields'][self::MONTHLY_SALES_FIELD] ?? null);
        $monthlyTarget = $this->roundedNumeric($row['fields'][self::MONTHLY_TARGET_FIELD] ?? null);
        $completionRate = $monthlySales !== null && $monthlyTarget !== null && $monthlyTarget > 0
            ? round(($monthlySales / $monthlyTarget) * 100, 1)
            : null;
        $monthlySpend = $missingSpendFields === []
            ? $this->monthlySpend(
                $organization,
                $store,
                $period,
                $row['date'],
                $spendFields,
            )
            : null;
        $roas = $monthlySales !== null && $monthlySpend !== null && $monthlySpend > 0
            ? round($monthlySales / $monthlySpend, 2)
            : null;
        $facebook = $this->facebookMetrics($rows, $row, $personalFields, $period);
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
        );
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
        $dailySalesField = $personalFields->first(fn (string $field): bool => $this->isFacebookField($field)
            && str_contains($field, '销售额')
            && ! str_contains($field, '目标'));
        $targetField = $personalFields->first(fn (string $field): bool => $this->isFacebookField($field)
            && (str_contains($field, '销售目标') || str_contains($field, '销售额目标')));
        $missingFields = [];
        if (! is_string($dailySalesField)) {
            $missingFields[] = 'Facebook 销售额字段';
        }
        if (! is_string($targetField)) {
            $missingFields[] = 'Facebook 月销售目标字段';
        }

        if ($missingFields !== []) {
            return $this->emptyFacebookMetrics(
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
            return $this->emptyFacebookMetrics('所选时间范围内暂无可用的 Facebook 目标数据。');
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
            'schema' => 'paid-advertising-personal-facebook-channel-goal-v1',
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

    private function isFacebookField(string $field): bool
    {
        return preg_match('/(^|[^a-z])fb([^a-z]|$)|facebook|meta/i', $field) === 1;
    }

    /** @return array<string, mixed> */
    private function emptyFacebookMetrics(?string $message): array
    {
        return [
            'schema' => 'paid-advertising-personal-facebook-channel-goal-v1',
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
    private function monthlySpend(
        Organization $organization,
        Store $store,
        array $period,
        string $asOfDate,
        array $spendFields,
    ): ?float {
        if ($spendFields === []) {
            return null;
        }

        $sum = 0.0;
        $hasValue = false;
        foreach ($this->datedRows($organization, $store, 'overall', $period) as $row) {
            if ($row['date'] > $asOfDate) {
                continue;
            }

            foreach ($spendFields as $field) {
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
            'facebook' => $facebook ?? $this->emptyFacebookMetrics(null),
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
