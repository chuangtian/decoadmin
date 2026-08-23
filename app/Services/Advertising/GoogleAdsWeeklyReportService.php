<?php

namespace App\Services\Advertising;

use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Throwable;

class GoogleAdsWeeklyReportService
{
    private const SOURCE_TABLE_NAMES = ['Google周数据', 'Google 周数据'];

    private const METRIC_FIELDS = [
        'spend' => '费用',
        'revenue' => '转化价值',
        'roi' => 'ROI',
        'add_to_cart' => '加购数',
        'checkout' => '结账数',
        'add_to_cart_cost' => '单次加购成本',
        'checkout_cost' => '单次结账成本',
        'conversions' => '成交数',
        'cpa' => '单次转化成本',
    ];

    /** @param array{week?: string} $filters */
    public function forStore(Store $store, array $filters = []): array
    {
        $table = FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->whereIn('name', self::SOURCE_TABLE_NAMES)
            ->latest('synced_at')
            ->first();

        if (! $table instanceof FeishuBitableTable) {
            return $this->emptyPayload('未找到已同步的「Google周数据」表。');
        }

        $timezone = (string) config('services.feishu_table.paid_advertising_goal_sync_timezone', 'Asia/Shanghai');
        $weeks = FeishuBitableRecord::query()
            ->where('organization_id', (int) $store->organization_id)
            ->where('store_id', (int) $store->getKey())
            ->where('feishu_bitable_table_id', (int) $table->getKey())
            ->latest('id')
            ->limit(600)
            ->get(['id', 'source_record_id', 'fields_encrypted'])
            ->map(fn (FeishuBitableRecord $record): ?array => $this->weekRow($record, $timezone))
            ->filter()
            ->unique('key')
            ->sortByDesc('date_from')
            ->values();

        if ($weeks->isEmpty()) {
            return $this->emptyPayload(
                '「Google周数据」暂无字段完整的周记录。',
                $table,
            );
        }

        $requestedWeek = trim((string) ($filters['week'] ?? ''));
        $selectedIndex = $requestedWeek === ''
            ? 0
            : $weeks->search(fn (array $week): bool => $week['key'] === $requestedWeek);
        $selectedIndex = $selectedIndex === false ? 0 : (int) $selectedIndex;
        $selected = $weeks->get($selectedIndex);
        $previous = $weeks->get($selectedIndex + 1);

        return [
            'schema' => 'google-ads-weekly-report-v1',
            'available' => true,
            'source_table' => (string) $table->name,
            'source_synced_at' => $table->synced_at?->toIso8601String(),
            'weeks' => $weeks->map(fn (array $week): array => $this->weekOption($week))->all(),
            'selected_week' => $this->weekOption($selected),
            'previous_week' => is_array($previous) ? $this->weekOption($previous) : null,
            'values' => $this->roundedMetrics($selected['metrics']),
            'previous_values' => is_array($previous) ? $this->roundedMetrics($previous['metrics']) : null,
            'changes' => collect(self::METRIC_FIELDS)->keys()->mapWithKeys(
                fn (string $key): array => [
                    $key => is_array($previous)
                        ? $this->delta($selected['metrics'][$key], $previous['metrics'][$key])
                        : null,
                ],
            )->all(),
            'history' => $weeks
                ->slice($selectedIndex)
                ->reverse()
                ->values()
                ->map(fn (array $week): array => [
                    ...$this->weekOption($week),
                    'values' => $this->roundedMetrics($week['metrics']),
                ])
                ->all(),
            'message' => null,
        ];
    }

    private function weekRow(FeishuBitableRecord $record, string $timezone): ?array
    {
        $fields = $record->fields_encrypted;

        if (! is_array($fields)) {
            return null;
        }

        $label = $this->stringValue($fields['周'] ?? null);
        $start = $this->dateValue($fields['记录日期'] ?? null, $timezone);

        if ($label === null || ! $start instanceof CarbonImmutable) {
            return null;
        }

        $metrics = [];

        foreach (self::METRIC_FIELDS as $key => $field) {
            $value = $this->numberValue($fields[$field] ?? null);

            if ($value === null) {
                return null;
            }

            $metrics[$key] = $value;
        }

        return [
            'key' => $start->toDateString(),
            'label' => $label,
            'date_from' => $start->toDateString(),
            'date_to' => $start->addDays(6)->toDateString(),
            'metrics' => $metrics,
        ];
    }

    private function dateValue(mixed $value, string $timezone): ?CarbonImmutable
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (float) $value;
                $timestamp = $timestamp > 10_000_000_000 ? $timestamp / 1000 : $timestamp;

                return CarbonImmutable::createFromTimestampUTC((int) floor($timestamp))
                    ->setTimezone($timezone)
                    ->startOfDay();
            }

            return CarbonImmutable::parse($value, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $string = trim((string) $value);

            return $string === '' ? null : $string;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['text', 'value'] as $key) {
            if (array_key_exists($key, $value)) {
                $string = $this->stringValue($value[$key]);

                if ($string !== null) {
                    return $string;
                }
            }
        }

        foreach ($value as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function numberValue(mixed $value): ?float
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        $normalized = str_replace([',', '$', '%', '¥', ' '], '', $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /** @param array<string, float> $metrics */
    private function roundedMetrics(array $metrics): array
    {
        return collect($metrics)->map(fn (float $value): float => round($value, 2))->all();
    }

    private function delta(float $current, float $previous): ?float
    {
        return abs($previous) < 0.000001
            ? null
            : round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private function weekOption(array $week): array
    {
        return [
            'key' => $week['key'],
            'label' => $week['label'],
            'date_from' => $week['date_from'],
            'date_to' => $week['date_to'],
        ];
    }

    private function emptyPayload(string $message, ?FeishuBitableTable $table = null): array
    {
        return [
            'schema' => 'google-ads-weekly-report-v1',
            'available' => false,
            'source_table' => $table?->name,
            'source_synced_at' => $table?->synced_at?->toIso8601String(),
            'weeks' => [],
            'selected_week' => null,
            'previous_week' => null,
            'values' => null,
            'previous_values' => null,
            'changes' => null,
            'history' => [],
            'message' => $message,
        ];
    }
}
