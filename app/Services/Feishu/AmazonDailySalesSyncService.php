<?php

namespace App\Services\Feishu;

use App\Models\AmazonDailySale;
use App\Models\Store;
use App\Services\StoreFeishuDataLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AmazonDailySalesSyncService
{
    private const TIMEZONE = 'Asia/Shanghai';

    /** @var array<string, string> */
    private const FIELD_COLUMNS = [
        'FBA单量' => 'fba_order_count',
        'FBA销售额' => 'fba_sales',
        'FBM单量' => 'fbm_order_count',
        'FBM销售额' => 'fbm_sales',
        '总销售额' => 'total_sales',
        '配件退款' => 'accessory_refunds',
        '退款' => 'refunds',
        '总退款' => 'total_refunds',
        '净销售' => 'net_sales',
        '退款占比' => 'refund_rate',
        '广告花费' => 'ad_spend',
        '广告占比' => 'ad_spend_rate',
        'ROI' => 'roi',
        'SP曝光' => 'sp_impressions',
        'SP点击' => 'sp_clicks',
        'SP点击率' => 'sp_click_through_rate',
        'SP花费' => 'sp_spend',
        'SP广告销售额' => 'sp_ad_sales',
        'SP单量' => 'sp_order_count',
        'SPACOS' => 'sp_acos',
        'SP转化率' => 'sp_conversion_rate',
        'SB曝光' => 'sb_impressions',
        'SB点击' => 'sb_clicks',
        'SB点击率' => 'sb_click_through_rate',
        'SB花费' => 'sb_spend',
        'SB广告销售额' => 'sb_ad_sales',
        'SB单量' => 'sb_order_count',
        'SBACOS' => 'sb_acos',
        'SB转化率' => 'sb_conversion_rate',
        'SD曝光' => 'sd_impressions',
        'SD点击' => 'sd_clicks',
        'SD点击率' => 'sd_click_through_rate',
        'SD花费' => 'sd_spend',
        'SD广告销售额' => 'sd_ad_sales',
        'SD广告单量' => 'sd_order_count',
        'SDACOS' => 'sd_acos',
        'SD转化率' => 'sd_conversion_rate',
    ];

    /** @var list<string> */
    private const INTEGER_COLUMNS = [
        'fba_order_count', 'fbm_order_count',
        'sp_impressions', 'sp_clicks', 'sp_order_count',
        'sb_impressions', 'sb_clicks', 'sb_order_count',
        'sd_impressions', 'sd_clicks', 'sd_order_count',
    ];

    public function __construct(
        private FeishuBitableClient $client,
        private StoreFeishuDataLinkService $dataLinks,
    ) {}

    /**
     * @return array{
     *     stores: int,
     *     inserted: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     failures: list<array{store_id: int, organization_id: int, error: string}>
     * }
     */
    public function syncConfiguredStores(?int $storeId = null): array
    {
        $summary = [
            'stores' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        $this->configuredStoreQuery($storeId)
            ->select(['id', 'organization_id', 'status'])
            ->orderBy('id')
            ->chunkById(50, function ($stores) use (&$summary): void {
                foreach ($stores as $store) {
                    $summary['stores']++;

                    try {
                        $result = $this->syncStore($store);
                        $summary['inserted'] += $result['inserted'];
                        $summary['updated'] += $result['updated'];
                        $summary['skipped'] += $result['skipped'];
                    } catch (Throwable $exception) {
                        $summary['failed']++;
                        $error = $this->safeError($exception);
                        $summary['failures'][] = [
                            'store_id' => (int) $store->id,
                            'organization_id' => (int) $store->organization_id,
                            'error' => $error,
                        ];
                        Log::warning('Feishu Amazon daily sales sync failed.', [
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->id,
                            'exception' => $exception::class,
                            'error' => $error,
                        ]);
                    }
                }
            });

        return $summary;
    }

    /** @return array{inserted: int, updated: int, skipped: int, records: int} */
    public function syncStore(Store $store): array
    {
        $credentials = $this->dataLinks->valuesForSync($store, 'amazon');
        $appToken = trim((string) ($credentials['amazon_app_token'] ?? ''));
        $tableId = trim((string) ($credentials['amazon_table_id'] ?? ''));
        $viewId = trim((string) ($credentials['amazon_view_id'] ?? ''));

        if ($appToken === '' || $tableId === '') {
            throw new RuntimeException('当前店铺未完整配置亚马逊飞书多维表格。');
        }

        $fields = $this->client->fields($appToken, $tableId);
        $headers = collect($fields)
            ->map(fn (array $field): mixed => $field['field_name'] ?? null)
            ->filter(fn (mixed $header): bool => is_string($header) && trim($header) !== '')
            ->map(fn (string $header): string => trim($header))
            ->values()
            ->all();
        $records = $this->client->records($appToken, $tableId, $viewId ?: null);
        $syncedAt = now();
        $rowsByDate = [];
        $skipped = 0;

        foreach ($records as $record) {
            $row = $this->mapRecord($store, $record, $headers, $syncedAt);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $rowsByDate[$row['sale_date']] = $row;
        }

        $rows = array_values($rowsByDate);
        $existingDates = [];

        foreach (array_chunk(array_keys($rowsByDate), 500) as $dateChunk) {
            $dates = AmazonDailySale::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->id)
                ->whereIn('sale_date', $dateChunk)
                ->pluck('sale_date')
                ->map(fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
                ->all();
            $existingDates = [...$existingDates, ...$dates];
        }

        $existingLookup = array_fill_keys($existingDates, true);
        $inserted = count(array_filter($rows, fn (array $row): bool => ! isset($existingLookup[$row['sale_date']])));
        $updated = count($rows) - $inserted;

        foreach (array_chunk($rows, 500) as $chunk) {
            AmazonDailySale::query()->upsert(
                $chunk,
                ['organization_id', 'store_id', 'sale_date'],
                array_values(array_diff(array_keys($chunk[0]), ['organization_id', 'store_id', 'sale_date', 'created_at'])),
            );
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'records' => count($records),
        ];
    }

    private function configuredStoreQuery(?int $storeId): Builder
    {
        $query = Store::query()
            ->where('status', 'active')
            ->whereHas('businessCredentials', fn (Builder $credentials): Builder => $credentials
                ->where('provider', 'feishu_data_links')
                ->where('credential_key', 'amazon_app_token'))
            ->whereHas('businessCredentials', fn (Builder $credentials): Builder => $credentials
                ->where('provider', 'feishu_data_links')
                ->where('credential_key', 'amazon_table_id'));

        if ($storeId !== null) {
            $query->whereKey($storeId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $headers
     * @return array<string, mixed>|null
     */
    private function mapRecord(Store $store, array $record, array $headers, mixed $syncedAt): ?array
    {
        $sourceFields = $record['fields'] ?? [];

        if (! is_array($sourceFields)) {
            return null;
        }

        $normalizedFields = [];

        foreach ($sourceFields as $header => $value) {
            if (is_string($header)) {
                $normalizedFields[$this->normalizeHeader($header)] = $value;
            }
        }

        $saleDate = $this->dateValue($normalizedFields['日期'] ?? null);

        if ($saleDate === null) {
            return null;
        }

        $recordId = trim((string) ($record['record_id'] ?? ''));

        if ($recordId === '') {
            return null;
        }

        $mappedHeaders = ['日期', '星期', ...array_keys(self::FIELD_COLUMNS)];
        $unmappedHeaders = array_values(array_filter(
            $headers,
            fn (string $header): bool => ! in_array($this->normalizeHeader($header), $mappedHeaders, true),
        ));
        $row = [
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->id,
            'source_record_id' => $recordId,
            'sale_date' => $saleDate,
            'weekday' => $this->stringValue($normalizedFields['星期'] ?? null),
            'remarks' => json_encode([
                'source' => 'feishu_bitable',
                'headers' => $headers,
                'unmapped_headers' => $unmappedHeaders,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_created_at' => $this->timestampValue($record['created_time'] ?? null),
            'source_updated_at' => $this->timestampValue($record['last_modified_time'] ?? null),
            'synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];

        foreach (self::FIELD_COLUMNS as $header => $column) {
            $number = $this->numberValue($normalizedFields[$header] ?? null);
            $row[$column] = in_array($column, self::INTEGER_COLUMNS, true) && $number !== null
                ? max(0, (int) round($number))
                : $number;
        }

        return $row;
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtoupper(preg_replace('/[\s\-_]+/u', '', trim($header)) ?? trim($header));
    }

    private function dateValue(mixed $value): ?string
    {
        $value = $this->scalarValue($value);

        if (is_numeric($value)) {
            $timestamp = (float) $value;
            $seconds = abs($timestamp) >= 100000000000 ? $timestamp / 1000 : $timestamp;

            return CarbonImmutable::createFromTimestampUTC($seconds)
                ->setTimezone(self::TIMEZONE)
                ->toDateString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['!Y/m/d', '!Y-m-d', '!Y.m.d', '!Y年m月d日'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value, self::TIMEZONE);

                if ($date !== false) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Continue with the next supported format.
            }
        }

        return null;
    }

    private function numberValue(mixed $value): ?float
    {
        $value = $this->scalarValue($value);

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || in_array(mb_strtolower($value), ['-', '--', 'n/a', 'null'], true)) {
            return null;
        }

        $percent = str_ends_with($value, '%');
        $normalized = preg_replace('/[^0-9eE+\-.]/', '', str_replace(',', '', $value));

        if (! is_string($normalized) || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return $percent ? $number / 100 : $number;
    }

    private function stringValue(mixed $value): ?string
    {
        $value = $this->scalarValue($value);

        if ($value === null || is_bool($value) || (! is_scalar($value))) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 16);
    }

    private function scalarValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach (['value', 'text', 'name'] as $key) {
            if (array_key_exists($key, $value)) {
                return $this->scalarValue($value[$key]);
            }
        }

        if (array_is_list($value)) {
            $values = array_values(array_filter(
                array_map(fn (mixed $item): mixed => $this->scalarValue($item), $value),
                fn (mixed $item): bool => $item !== null && $item !== '',
            ));

            return count($values) === 1 ? $values[0] : implode(', ', array_map('strval', $values));
        }

        return null;
    }

    private function timestampValue(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $timestamp = (float) $value;
        $seconds = abs($timestamp) >= 100000000000 ? $timestamp / 1000 : $timestamp;

        return CarbonImmutable::createFromTimestampUTC($seconds)->toDateTimeString();
    }

    private function safeError(Throwable $exception): string
    {
        $message = preg_replace('/https?:\/\/[^\s]+|(?:app|tbl|vew)[A-Za-z0-9_-]{6,}/i', '[redacted]', $exception->getMessage());

        return mb_substr($message ?: '飞书亚马逊数据同步失败。', 0, 300);
    }
}
