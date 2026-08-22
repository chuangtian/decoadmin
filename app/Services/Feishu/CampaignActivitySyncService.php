<?php

namespace App\Services\Feishu;

use App\Models\CampaignActivity;
use App\Models\Store;
use App\Services\StoreFeishuDataLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CampaignActivitySyncService
{
    private const TIMEZONE = 'Asia/Shanghai';

    /** @var array<string, string> */
    private const TEXT_COLUMNS = [
        '活动ID' => 'campaign_id',
        '活动名称' => 'campaign_name',
        '主标题' => 'main_title',
        '副标题' => 'subtitle',
        '核心优惠' => 'core_offer',
        '活动总结' => 'campaign_summary',
        '问题诊断' => 'problem_diagnosis',
        '优化分析' => 'optimization_analysis',
        '单选' => 'single_select',
    ];

    /** @var array<string, string> */
    private const DATE_COLUMNS = [
        '活动开始日期' => 'starts_on',
        '活动结束日期' => 'ends_on',
    ];

    /** @var array<string, string> */
    private const NUMBER_COLUMNS = [
        '销售额' => 'sales_amount',
        '广告花费' => 'ad_spend',
        'ROI' => 'roi',
        '转化率' => 'conversion_rate',
        '日均销售额' => 'daily_average_sales',
        '日均店铺访问' => 'daily_average_store_visits',
        '日均订单数' => 'daily_average_order_count',
        '日均广告花费' => 'daily_average_ad_spend',
    ];

    /** @var array<string, string> */
    private const INTEGER_COLUMNS = [
        '订单数' => 'order_count',
        '店铺访问' => 'store_visits',
    ];

    /** @var array<string, string> */
    private const ATTACHMENT_COLUMNS = [
        '活动图片' => 'campaign_images',
        '邮件内容' => 'email_content',
    ];

    public function __construct(
        private FeishuBitableClient $client,
        private StoreFeishuDataLinkService $dataLinks,
        private CampaignImageStorageService $imageStorage,
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
                        Log::warning('Feishu campaign activity sync failed.', [
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
        $credentials = $this->dataLinks->valuesForSync($store, 'campaign');
        $appToken = trim((string) ($credentials['campaign_app_token'] ?? ''));
        $tableId = trim((string) ($credentials['campaign_table_id'] ?? ''));
        $viewId = trim((string) ($credentials['campaign_view_id'] ?? ''));

        if ($appToken === '' || $tableId === '') {
            throw new RuntimeException('当前店铺未完整配置活动主题飞书多维表格。');
        }

        $fields = $this->client->fields($appToken, $tableId);
        $headers = collect($fields)
            ->map(fn (array $field): mixed => $field['field_name'] ?? null)
            ->filter(fn (mixed $header): bool => is_string($header) && trim($header) !== '')
            ->map(fn (string $header): string => trim($header))
            ->values()
            ->all();
        $records = $this->client->records($appToken, $tableId, $viewId ?: null, textFieldAsArray: true);
        $syncedAt = now();
        $rowsByRecordId = [];
        $skipped = 0;

        foreach ($records as $record) {
            $row = $this->mapRecord($store, $record, $headers, $syncedAt);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $rowsByRecordId[$row['source_record_id']] = $row;
        }

        $rows = array_values($rowsByRecordId);
        $existingRecordIds = [];

        foreach (array_chunk(array_keys($rowsByRecordId), 500) as $recordIdChunk) {
            $recordIds = CampaignActivity::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->id)
                ->whereIn('source_record_id', $recordIdChunk)
                ->pluck('source_record_id')
                ->map(fn (mixed $recordId): string => (string) $recordId)
                ->all();
            $existingRecordIds = [...$existingRecordIds, ...$recordIds];
        }

        $existingLookup = array_fill_keys($existingRecordIds, true);
        $inserted = count(array_filter(
            $rows,
            fn (array $row): bool => ! isset($existingLookup[$row['source_record_id']]),
        ));
        $updated = count($rows) - $inserted;

        foreach (array_chunk($rows, 500) as $chunk) {
            CampaignActivity::query()->upsert(
                $chunk,
                ['organization_id', 'store_id', 'source_record_id'],
                array_values(array_diff(
                    array_keys($chunk[0]),
                    ['organization_id', 'store_id', 'source_record_id', 'created_at'],
                )),
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
                ->where('credential_key', 'campaign_app_token'))
            ->whereHas('businessCredentials', fn (Builder $credentials): Builder => $credentials
                ->where('provider', 'feishu_data_links')
                ->where('credential_key', 'campaign_table_id'));

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

        $recordId = trim((string) ($record['record_id'] ?? $record['id'] ?? ''));

        if ($recordId === '') {
            return null;
        }

        $normalizedFields = [];
        $originalHeaders = [];

        foreach ($sourceFields as $header => $value) {
            if (! is_string($header)) {
                continue;
            }

            $normalized = $this->normalizeHeader($header);
            $normalizedFields[$normalized] = $value;
            $originalHeaders[$normalized] = $header;
        }

        $mappedHeaders = array_map(
            fn (string $header): string => $this->normalizeHeader($header),
            [
                ...array_keys(self::TEXT_COLUMNS),
                '策划书',
                ...array_keys(self::DATE_COLUMNS),
                ...array_keys(self::NUMBER_COLUMNS),
                ...array_keys(self::INTEGER_COLUMNS),
                ...array_keys(self::ATTACHMENT_COLUMNS),
                '父记录',
            ],
        );
        $unmappedFields = [];

        foreach ($normalizedFields as $normalizedHeader => $value) {
            if (! in_array($normalizedHeader, $mappedHeaders, true)) {
                $unmappedFields[$originalHeaders[$normalizedHeader]] = $value;
            }
        }

        foreach ($headers as $header) {
            $normalizedHeader = $this->normalizeHeader($header);

            if (! in_array($normalizedHeader, $mappedHeaders, true) && ! array_key_exists($header, $unmappedFields)) {
                $unmappedFields[$header] = null;
            }
        }

        $row = [
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->id,
            'source_record_id' => $recordId,
            'planning_document' => $this->linkValue(
                $normalizedFields[$this->normalizeHeader('策划书')] ?? null,
            ),
            'parent_records' => $this->jsonValue(
                $normalizedFields[$this->normalizeHeader('父记录')] ?? null,
            ),
            'unmapped_fields' => $this->jsonValue($unmappedFields),
            'source_created_at' => $this->timestampValue($record['created_time'] ?? null),
            'source_updated_at' => $this->timestampValue($record['last_modified_time'] ?? null),
            'synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];

        foreach (self::TEXT_COLUMNS as $header => $column) {
            $row[$column] = $this->textValue($normalizedFields[$this->normalizeHeader($header)] ?? null);
        }

        foreach (self::DATE_COLUMNS as $header => $column) {
            $row[$column] = $this->dateValue($normalizedFields[$this->normalizeHeader($header)] ?? null);
        }

        foreach (self::NUMBER_COLUMNS as $header => $column) {
            $row[$column] = $this->numberValue($normalizedFields[$this->normalizeHeader($header)] ?? null);
        }

        foreach (self::INTEGER_COLUMNS as $header => $column) {
            $number = $this->numberValue($normalizedFields[$this->normalizeHeader($header)] ?? null);
            $row[$column] = $number === null ? null : max(0, (int) round($number));
        }

        foreach (self::ATTACHMENT_COLUMNS as $header => $column) {
            $row[$column] = $this->jsonValue(
                $this->imageStorage->storeAttachments(
                    $store,
                    $recordId,
                    $column,
                    $normalizedFields[$this->normalizeHeader($header)] ?? null,
                ),
            );
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

    private function textValue(mixed $value): ?string
    {
        $value = $this->scalarValue($value);

        if ($value === null || is_bool($value) || ! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function linkValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['link', 'url'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $link = trim($value[$key]);

                if (filter_var($link, FILTER_VALIDATE_URL)) {
                    return $link;
                }
            }
        }

        foreach ($value as $item) {
            $link = $this->linkValue($item);

            if ($link !== null) {
                return $link;
            }
        }

        return null;
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

            return count($values) === 1 ? $values[0] : implode("\n", array_map('strval', $values));
        }

        return null;
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

        return mb_substr($message ?: '飞书活动主题数据同步失败。', 0, 300);
    }
}
