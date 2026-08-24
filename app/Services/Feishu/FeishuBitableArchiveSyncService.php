<?php

namespace App\Services\Feishu;

use App\Models\FeishuBitableField;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FeishuBitableArchiveSyncService
{
    public function __construct(private FeishuBitableClient $client) {}

    /** @return array{tables: int, fields: int, records: int} */
    public function sync(Store $store, string $sourceSection, string $appToken): array
    {
        $sourceSection = trim($sourceSection);
        $appToken = trim($appToken);

        if ($sourceSection === '' || $appToken === '') {
            throw new RuntimeException('飞书全表归档缺少数据来源或 App Token。');
        }

        $tables = $this->client->tables($appToken);
        $maxTables = max(1, (int) config('services.feishu_table.archive_max_tables', 100));

        if (count($tables) > $maxTables) {
            throw new RuntimeException("飞书数据表数量超过安全上限（{$maxTables} 张）。");
        }

        $summary = ['tables' => 0, 'fields' => 0, 'records' => 0];
        $incomingTableIds = [];

        foreach ($tables as $tableDefinition) {
            $sourceTableId = trim((string) ($tableDefinition['table_id'] ?? $tableDefinition['id'] ?? ''));

            if ($sourceTableId === '') {
                continue;
            }

            $incomingTableIds[] = $sourceTableId;
            $result = $this->syncTable(
                $store,
                $sourceSection,
                $appToken,
                $sourceTableId,
                $tableDefinition,
            );
            $summary['tables']++;
            $summary['fields'] += $result['fields'];
            $summary['records'] += $result['records'];
        }

        $staleTables = FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_section', $sourceSection);

        if ($incomingTableIds === []) {
            $staleTables->delete();
        } else {
            $staleTables->whereNotIn('source_table_id', $incomingTableIds)->delete();
        }

        return $summary;
    }

    /** @return array{fields: int, records: int} */
    public function syncTableById(
        Store $store,
        string $sourceSection,
        string $appToken,
        string $sourceTableId,
        ?string $viewId = null,
    ): array {
        $sourceSection = trim($sourceSection);
        $appToken = trim($appToken);
        $sourceTableId = trim($sourceTableId);

        if ($sourceSection === '' || $appToken === '' || $sourceTableId === '') {
            throw new RuntimeException('飞书数据表归档缺少数据来源、App Token 或 Table ID。');
        }

        $definition = collect($this->client->tables($appToken))
            ->first(fn (array $table): bool => trim((string) ($table['table_id'] ?? $table['id'] ?? '')) === $sourceTableId);

        if (! is_array($definition)) {
            throw new RuntimeException("未在飞书多维表格中找到数据表 {$sourceTableId}。");
        }

        return $this->syncTable($store, $sourceSection, $appToken, $sourceTableId, $definition, $viewId);
    }

    /** @return array{fields: int, records: int} */
    private function syncTable(
        Store $store,
        string $sourceSection,
        string $appToken,
        string $sourceTableId,
        array $tableDefinition,
        ?string $viewId = null,
    ): array {
        $fields = $this->client->fields($appToken, $sourceTableId);
        $records = $this->client->records($appToken, $sourceTableId, $viewId, textFieldAsArray: true);
        $maxFields = max(1, (int) config('services.feishu_table.archive_max_fields_per_table', 1000));
        $maxRecords = max(1, (int) config('services.feishu_table.archive_max_records_per_table', 50000));

        if (count($fields) > $maxFields) {
            throw new RuntimeException("飞书数据表 {$sourceTableId} 字段数超过安全上限（{$maxFields} 个）。");
        }

        if (count($records) > $maxRecords) {
            throw new RuntimeException("飞书数据表 {$sourceTableId} 记录数超过安全上限（{$maxRecords} 条）。");
        }

        $syncedAt = now();

        return DB::transaction(function () use (
            $store,
            $sourceSection,
            $sourceTableId,
            $tableDefinition,
            $fields,
            $records,
            $syncedAt,
        ): array {
            $table = FeishuBitableTable::query()->updateOrCreate(
                [
                    'store_id' => (int) $store->id,
                    'source_section' => $sourceSection,
                    'source_table_id' => $sourceTableId,
                ],
                [
                    'organization_id' => (int) $store->organization_id,
                    'name' => $this->optionalString($tableDefinition['name'] ?? null),
                    'metadata_encrypted' => $tableDefinition,
                    'synced_at' => $syncedAt,
                ],
            );

            $fieldRows = $this->fieldRows($store, $table, $fields, $syncedAt);
            $recordRows = $this->recordRows($store, $table, $records, $syncedAt);

            foreach (array_chunk(array_values($fieldRows), 500) as $chunk) {
                FeishuBitableField::query()->upsert(
                    $chunk,
                    ['feishu_bitable_table_id', 'source_field_id'],
                    [
                        'organization_id', 'store_id', 'name', 'type', 'field_order',
                        'is_primary', 'metadata_encrypted', 'synced_at', 'updated_at',
                    ],
                );
            }

            foreach (array_chunk(array_values($recordRows), 500) as $chunk) {
                FeishuBitableRecord::query()->upsert(
                    $chunk,
                    ['feishu_bitable_table_id', 'source_record_id'],
                    [
                        'organization_id', 'store_id', 'fields_encrypted', 'source_created_at',
                        'source_updated_at', 'synced_at', 'updated_at',
                    ],
                );
            }

            $this->deleteMissingFields($table, array_keys($fieldRows));
            $this->deleteMissingRecords($table, array_keys($recordRows));

            return ['fields' => count($fieldRows), 'records' => count($recordRows)];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function fieldRows(Store $store, FeishuBitableTable $table, array $fields, mixed $syncedAt): array
    {
        $rows = [];

        foreach ($fields as $position => $field) {
            $sourceFieldId = trim((string) ($field['field_id'] ?? $field['id'] ?? ''));

            if ($sourceFieldId === '') {
                continue;
            }

            $type = $field['type'] ?? null;
            $rows[$sourceFieldId] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'feishu_bitable_table_id' => (int) $table->id,
                'source_field_id' => mb_substr($sourceFieldId, 0, 120),
                'name' => $this->optionalString($field['field_name'] ?? $field['name'] ?? null),
                'type' => is_numeric($type) ? max(0, min(65535, (int) $type)) : null,
                'field_order' => (int) $position,
                'is_primary' => (bool) ($field['is_primary'] ?? false),
                'metadata_encrypted' => $this->encryptArray($field),
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function recordRows(Store $store, FeishuBitableTable $table, array $records, mixed $syncedAt): array
    {
        $rows = [];

        foreach ($records as $record) {
            $sourceRecordId = trim((string) ($record['record_id'] ?? $record['id'] ?? ''));
            $sourceFields = $record['fields'] ?? null;

            if ($sourceRecordId === '' || ! is_array($sourceFields)) {
                continue;
            }

            $rows[$sourceRecordId] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'feishu_bitable_table_id' => (int) $table->id,
                'source_record_id' => mb_substr($sourceRecordId, 0, 120),
                'fields_encrypted' => $this->encryptArray($sourceFields),
                'source_created_at' => $this->sourceTimestamp($record['created_time'] ?? null),
                'source_updated_at' => $this->sourceTimestamp($record['last_modified_time'] ?? null),
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    /** @param list<string> $incomingIds */
    private function deleteMissingFields(FeishuBitableTable $table, array $incomingIds): void
    {
        $query = $table->fields();
        $incomingIds === [] ? $query->delete() : $query->whereNotIn('source_field_id', $incomingIds)->delete();
    }

    /** @param list<string> $incomingIds */
    private function deleteMissingRecords(FeishuBitableTable $table, array $incomingIds): void
    {
        $query = $table->records();
        $incomingIds === [] ? $query->delete() : $query->whereNotIn('source_record_id', $incomingIds)->delete();
    }

    /** @param array<string, mixed> $value */
    private function encryptArray(array $value): string
    {
        return Crypt::encryptString(json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function sourceTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 100000000000
            ? CarbonImmutable::createFromTimestampMs($timestamp)
            : CarbonImmutable::createFromTimestamp($timestamp);
    }
}
