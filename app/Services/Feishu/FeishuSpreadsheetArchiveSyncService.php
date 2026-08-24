<?php

namespace App\Services\Feishu;

use App\Models\FeishuBitableField;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FeishuSpreadsheetArchiveSyncService
{
    public function __construct(private FeishuBitableClient $client) {}

    /** @return array{tables: int, fields: int, records: int} */
    public function syncWiki(Store $store, string $sourceSection, string $wikiNodeToken): array
    {
        $node = $this->client->wikiNode(trim($wikiNodeToken));
        $spreadsheetToken = trim((string) ($node['obj_token'] ?? ''));

        if ($spreadsheetToken === '') {
            throw new RuntimeException('飞书知识库节点未关联可读取的电子表格。');
        }

        return $this->syncSpreadsheet($store, $sourceSection, $spreadsheetToken);
    }

    /** @return array{tables: int, fields: int, records: int} */
    public function syncSpreadsheet(Store $store, string $sourceSection, string $spreadsheetToken): array
    {
        $sourceSection = trim($sourceSection);
        $spreadsheetToken = trim($spreadsheetToken);

        if ($sourceSection === '' || $spreadsheetToken === '') {
            throw new RuntimeException('飞书电子表格归档缺少数据来源或 Spreadsheet Token。');
        }

        $sheets = $this->client->spreadsheetSheets($spreadsheetToken);
        $maxTables = max(1, (int) config('services.feishu_table.archive_max_tables', 100));

        if (count($sheets) > $maxTables) {
            throw new RuntimeException("飞书电子表格工作表数量超过安全上限（{$maxTables} 张）。");
        }

        $summary = ['tables' => 0, 'fields' => 0, 'records' => 0];
        $incomingIds = [];

        foreach ($sheets as $sheet) {
            $sheetId = trim((string) ($sheet['sheet_id'] ?? ''));

            if ($sheetId === '') {
                continue;
            }

            $sourceTableId = 'sheet:'.$sheetId;
            $incomingIds[] = $sourceTableId;
            $rowCount = min(
                max(1, (int) config('services.feishu_table.spreadsheet_archive_max_rows', 10000)),
                max(1, (int) data_get($sheet, 'grid_properties.row_count', 2000)),
            );
            $columnCount = min(
                max(1, (int) config('services.feishu_table.spreadsheet_archive_max_columns', 200)),
                max(1, (int) data_get($sheet, 'grid_properties.column_count', 52)),
            );
            $values = $this->client->spreadsheetValues($spreadsheetToken, $sheetId, $rowCount, $columnCount);
            $result = $this->storeSheet($store, $sourceSection, $sourceTableId, $sheet, $values);
            $summary['tables']++;
            $summary['fields'] += $result['fields'];
            $summary['records'] += $result['records'];
        }

        $stale = FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_section', $sourceSection);
        $incomingIds === [] ? $stale->delete() : $stale->whereNotIn('source_table_id', $incomingIds)->delete();

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @param  list<array<int, mixed>>  $values
     * @return array{fields: int, records: int}
     */
    private function storeSheet(Store $store, string $sourceSection, string $sourceTableId, array $sheet, array $values): array
    {
        [$headerIndex, $headers] = $this->headers($values);
        $syncedAt = now();

        return DB::transaction(function () use ($store, $sourceSection, $sourceTableId, $sheet, $values, $headerIndex, $headers, $syncedAt): array {
            $table = FeishuBitableTable::query()->updateOrCreate(
                [
                    'store_id' => (int) $store->id,
                    'source_section' => $sourceSection,
                    'source_table_id' => $sourceTableId,
                ],
                [
                    'organization_id' => (int) $store->organization_id,
                    'name' => $this->text($sheet['title'] ?? $sheet['name'] ?? null, 255),
                    'metadata_encrypted' => $sheet,
                    'synced_at' => $syncedAt,
                ],
            );

            $fieldIds = [];
            foreach ($headers as $column => $name) {
                $sourceFieldId = 'column:'.$column;
                $fieldIds[] = $sourceFieldId;
                FeishuBitableField::query()->updateOrCreate(
                    ['feishu_bitable_table_id' => $table->id, 'source_field_id' => $sourceFieldId],
                    [
                        'organization_id' => (int) $store->organization_id,
                        'store_id' => (int) $store->id,
                        'name' => $name,
                        'type' => null,
                        'field_order' => (int) $column,
                        'is_primary' => $column === array_key_first($headers),
                        'metadata_encrypted' => ['column' => $column, 'header_row' => $headerIndex + 1],
                        'synced_at' => $syncedAt,
                    ],
                );
            }

            $recordIds = [];
            foreach (array_slice($values, $headerIndex + 1, null, true) as $rowIndex => $row) {
                $fields = [];
                foreach ($headers as $column => $name) {
                    $fields[$name] = $this->cell($row[$column] ?? null);
                }

                if (collect($fields)->every(fn (mixed $value): bool => $value === null || $value === '')) {
                    continue;
                }

                $sourceRecordId = 'row:'.($rowIndex + 1);
                $recordIds[] = $sourceRecordId;
                FeishuBitableRecord::query()->updateOrCreate(
                    ['feishu_bitable_table_id' => $table->id, 'source_record_id' => $sourceRecordId],
                    [
                        'organization_id' => (int) $store->organization_id,
                        'store_id' => (int) $store->id,
                        'fields_encrypted' => $fields,
                        'source_created_at' => null,
                        'source_updated_at' => null,
                        'synced_at' => $syncedAt,
                    ],
                );
            }

            $fields = $table->fields();
            $fieldIds === [] ? $fields->delete() : $fields->whereNotIn('source_field_id', $fieldIds)->delete();
            $records = $table->records();
            $recordIds === [] ? $records->delete() : $records->whereNotIn('source_record_id', $recordIds)->delete();

            return ['fields' => count($fieldIds), 'records' => count($recordIds)];
        });
    }

    /** @param list<array<int, mixed>> $values @return array{int, array<int, string>} */
    private function headers(array $values): array
    {
        if ($values === []) {
            return [-1, []];
        }

        $bestIndex = 0;
        foreach (array_slice($values, 0, 20, true) as $index => $row) {
            $nonEmpty = collect($row)->filter(fn (mixed $value): bool => trim((string) $this->cell($value)) !== '')->count();
            if ($nonEmpty >= 2) {
                $bestIndex = (int) $index;
                break;
            }
        }

        $counts = [];
        $headers = [];
        foreach ($values[$bestIndex] ?? [] as $column => $value) {
            $name = trim((string) $this->cell($value));
            if ($name === '') {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
            $headers[(int) $column] = $counts[$name] === 1 ? $name : $name.'_'.$counts[$name];
        }

        return [$bestIndex, $headers];
    }

    private function cell(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)->map(function (mixed $item): mixed {
                return is_array($item) ? ($item['text'] ?? $item['name'] ?? $item['value'] ?? null) : $item;
            })->filter(fn (mixed $item): bool => $item !== null && $item !== '')->values()->all();
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
