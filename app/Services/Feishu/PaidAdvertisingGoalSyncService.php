<?php

namespace App\Services\Feishu;

use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\Store;
use App\Services\StoreFeishuDataLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaidAdvertisingGoalSyncService
{
    private const OVERALL_SOURCE_KEY = 'overall';

    private const META_WEEKLY_SOURCE_SUFFIX = ':meta-weekly';

    public function __construct(
        private FeishuBitableClient $client,
        private StoreFeishuDataLinkService $dataLinks,
    ) {}

    /**
     * @return array{
     *     sources: int,
     *     fields: int,
     *     inserted: int,
     *     updated: int,
     *     deleted: int,
     *     skipped: int,
     *     failed: int,
     *     failures: list<array{organization_id: int, store_id: int, board_id: int|null, error: string}>
     * }
     */
    public function syncConfiguredSources(?int $storeId = null): array
    {
        $summary = [
            'sources' => 0,
            'fields' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        $this->configuredOverallStores($storeId)
            ->select(['id', 'organization_id', 'status'])
            ->orderBy('id')
            ->chunkById(50, function ($stores) use (&$summary): void {
                foreach ($stores as $store) {
                    $this->runSource($summary, $store, null);
                }
            });

        $this->configuredBoards($storeId)
            ->select([
                'paid_advertising_goal_boards.id',
                'paid_advertising_goal_boards.organization_id',
                'paid_advertising_goal_boards.store_id',
                'paid_advertising_goal_boards.type',
                'paid_advertising_goal_boards.feishu_app_token',
                'paid_advertising_goal_boards.feishu_table_id',
                'paid_advertising_goal_boards.feishu_view_id',
            ])
            ->orderBy('paid_advertising_goal_boards.id')
            ->chunkById(50, function ($boards) use (&$summary): void {
                foreach ($boards as $board) {
                    $store = $board->store;

                    if (! $store instanceof Store) {
                        throw new RuntimeException('广告目标页签缺少所属店铺。');
                    }

                    $this->runSource($summary, $store, $board);
                }
            }, 'paid_advertising_goal_boards.id', 'id');

        return $summary;
    }

    /** @return array{fields: int, inserted: int, updated: int, deleted: int, skipped: int, records: int} */
    public function syncOverall(Store $store): array
    {
        $values = $this->dataLinks->valuesForSync($store, 'advertising_goals');

        return $this->syncSource(
            $store,
            null,
            self::OVERALL_SOURCE_KEY,
            (string) ($values['advertising_goals_app_token'] ?? ''),
            (string) ($values['advertising_goals_table_id'] ?? ''),
            (string) ($values['advertising_goals_view_id'] ?? ''),
        );
    }

    /** @return array{fields: int, inserted: int, updated: int, deleted: int, skipped: int, records: int} */
    public function syncBoard(PaidAdvertisingGoalBoard $board): array
    {
        $board->update(['sync_status' => 'running', 'last_error' => null]);

        try {
            $store = $board->store()->firstOrFail();
            $results = [$this->syncSource(
                $store,
                $board,
                'board:'.(int) $board->id,
                (string) $board->feishu_app_token,
                (string) $board->feishu_table_id,
                (string) $board->feishu_view_id,
            )];
            if ($board->type === 'personal_facebook') {
                $metaWeekly = $this->dataLinks->valuesForSync($store, 'advertising_meta_weekly');
                $appToken = (string) ($metaWeekly['advertising_meta_weekly_app_token'] ?? '');
                $tableId = (string) ($metaWeekly['advertising_meta_weekly_table_id'] ?? '');
                if (filled($appToken) && filled($tableId)) {
                    $results[] = $this->syncSource(
                        $store,
                        $board,
                        'board:'.(int) $board->id.self::META_WEEKLY_SOURCE_SUFFIX,
                        $appToken,
                        $tableId,
                        '',
                        false,
                    );
                }
            }
            $result = $this->mergeSyncResults($results);
            $board->update([
                'sync_status' => 'completed',
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $board->update([
                'sync_status' => 'failed',
                'last_error' => $this->safeError($exception),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function runSource(array &$summary, Store $store, ?PaidAdvertisingGoalBoard $board): void
    {
        try {
            $result = $board ? $this->syncBoard($board) : $this->syncOverall($store);
            $summary['sources'] += (int) ($result['sources'] ?? 1);

            foreach (['fields', 'inserted', 'updated', 'deleted', 'skipped'] as $metric) {
                $summary[$metric] += $result[$metric];
            }
        } catch (Throwable $exception) {
            $summary['failed']++;
            $error = $this->safeError($exception);
            $summary['failures'][] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'board_id' => $board ? (int) $board->id : null,
                'error' => $error,
            ];
            Log::warning('Feishu paid advertising goal sync failed.', [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'board_id' => $board ? (int) $board->id : null,
                'exception' => $exception::class,
                'error' => $error,
            ]);
        }
    }

    /** @return array{fields: int, inserted: int, updated: int, deleted: int, skipped: int, records: int} */
    private function syncSource(
        Store $store,
        ?PaidAdvertisingGoalBoard $board,
        string $sourceKey,
        string $appToken,
        string $tableId,
        string $viewId,
        bool $requireView = true,
    ): array {
        $appToken = trim($appToken);
        $tableId = trim($tableId);
        $viewId = trim($viewId);

        if ($appToken === '' || $tableId === '' || ($requireView && $viewId === '')) {
            throw new RuntimeException('当前目标页签未完整配置飞书多维表格。');
        }

        $fieldDefinitions = $this->client->fields($appToken, $tableId);
        $records = $this->client->records($appToken, $tableId, $viewId);
        $maxFields = max(1, (int) config('services.feishu_table.paid_advertising_goal_max_fields', 1000));
        $maxRecords = max(1, (int) config('services.feishu_table.paid_advertising_goal_max_records', 5000));

        if ($fieldDefinitions === []) {
            throw new RuntimeException('飞书广告目标表未返回字段定义。');
        }

        if (count($fieldDefinitions) > $maxFields) {
            throw new RuntimeException('飞书广告目标字段超过单次同步上限。');
        }

        if (count($records) > $maxRecords) {
            throw new RuntimeException('飞书广告目标数据超过单次同步上限，请缩小视图范围。');
        }

        $syncedAt = now();
        $fieldCount = $this->syncFields($store, $board, $sourceKey, $fieldDefinitions, $syncedAt);
        $rowsByRecordId = [];
        $skipped = 0;

        foreach ($records as $record) {
            $recordId = trim((string) ($record['record_id'] ?? $record['id'] ?? ''));
            $fields = $record['fields'] ?? null;

            if ($recordId === '' || ! is_array($fields)) {
                $skipped++;

                continue;
            }

            $rowsByRecordId[$recordId] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'goal_board_id' => $board?->id,
                'source_key' => $sourceKey,
                'source_record_id' => mb_substr($recordId, 0, 120),
                'fields_encrypted' => Crypt::encryptString(json_encode(
                    $fields,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                )),
                'source_created_at' => $this->timestampValue($record['created_time'] ?? null),
                'source_updated_at' => $this->timestampValue($record['last_modified_time'] ?? null),
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $scope = PaidAdvertisingGoalRecord::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_key', $sourceKey);
        $existingRecordIds = (clone $scope)->pluck('source_record_id')->all();
        $incomingRecordIds = array_keys($rowsByRecordId);
        $existingLookup = array_fill_keys($existingRecordIds, true);
        $inserted = count(array_filter($incomingRecordIds, fn (string $recordId): bool => ! isset($existingLookup[$recordId])));
        $updated = count($incomingRecordIds) - $inserted;
        $staleRecordIds = array_values(array_diff($existingRecordIds, $incomingRecordIds));

        foreach (array_chunk(array_values($rowsByRecordId), 500) as $chunk) {
            PaidAdvertisingGoalRecord::query()->upsert(
                $chunk,
                ['store_id', 'source_key', 'source_record_id'],
                [
                    'organization_id', 'goal_board_id', 'fields_encrypted',
                    'source_created_at', 'source_updated_at', 'synced_at', 'updated_at',
                ],
            );
        }

        foreach (array_chunk($staleRecordIds, 500) as $chunk) {
            (clone $scope)->whereIn('source_record_id', $chunk)->delete();
        }

        return [
            'fields' => $fieldCount,
            'inserted' => $inserted,
            'updated' => $updated,
            'deleted' => count($staleRecordIds),
            'skipped' => $skipped,
            'records' => count($records),
        ];
    }

    /**
     * @param  list<array{fields: int, inserted: int, updated: int, deleted: int, skipped: int, records: int}>  $results
     * @return array{sources: int, fields: int, inserted: int, updated: int, deleted: int, skipped: int, records: int}
     */
    private function mergeSyncResults(array $results): array
    {
        $merged = [
            'sources' => count($results),
            'fields' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'records' => 0,
        ];

        foreach ($results as $result) {
            foreach (['fields', 'inserted', 'updated', 'deleted', 'skipped', 'records'] as $metric) {
                $merged[$metric] += (int) ($result[$metric] ?? 0);
            }
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $fieldDefinitions
     */
    private function syncFields(
        Store $store,
        ?PaidAdvertisingGoalBoard $board,
        string $sourceKey,
        array $fieldDefinitions,
        mixed $syncedAt,
    ): int {
        $rowsByFieldId = [];

        foreach ($fieldDefinitions as $position => $field) {
            $fieldId = trim((string) ($field['field_id'] ?? $field['id'] ?? ''));
            $name = trim((string) ($field['field_name'] ?? $field['name'] ?? ''));
            $type = $field['type'] ?? null;

            if ($fieldId === '' || $name === '' || ! is_numeric($type)) {
                continue;
            }

            $description = $field['description'] ?? null;
            $property = $field['property'] ?? null;
            $rowsByFieldId[$fieldId] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'goal_board_id' => $board?->id,
                'source_key' => $sourceKey,
                'source_field_id' => mb_substr($fieldId, 0, 120),
                'name' => mb_substr($name, 0, 255),
                'type' => max(0, min(65535, (int) $type)),
                'field_order' => (int) $position,
                'is_primary' => (bool) ($field['is_primary'] ?? false),
                'description' => is_string($description) && trim($description) !== ''
                    ? mb_substr(trim($description), 0, 500)
                    : null,
                'property_encrypted' => is_array($property)
                    ? Crypt::encryptString(json_encode(
                        $property,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ))
                    : null,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        if ($rowsByFieldId === []) {
            throw new RuntimeException('飞书广告目标字段定义格式无效。');
        }

        $scope = PaidAdvertisingGoalField::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_key', $sourceKey);
        $incomingFieldIds = array_keys($rowsByFieldId);

        foreach (array_chunk(array_values($rowsByFieldId), 500) as $chunk) {
            PaidAdvertisingGoalField::query()->upsert(
                $chunk,
                ['store_id', 'source_key', 'source_field_id'],
                [
                    'organization_id', 'goal_board_id', 'name', 'type', 'field_order',
                    'is_primary', 'description', 'property_encrypted', 'synced_at', 'updated_at',
                ],
            );
        }

        (clone $scope)->whereNotIn('source_field_id', $incomingFieldIds)->delete();

        return count($rowsByFieldId);
    }

    private function configuredOverallStores(?int $storeId): Builder
    {
        $query = Store::query()
            ->where('status', 'active');

        foreach (['advertising_goals_app_token', 'advertising_goals_table_id', 'advertising_goals_view_id'] as $key) {
            $query->whereHas('businessCredentials', fn (Builder $credentials): Builder => $credentials
                ->where('provider', 'feishu_data_links')
                ->where('credential_key', $key));
        }

        return $query->when($storeId !== null, fn (Builder $query): Builder => $query->whereKey($storeId));
    }

    private function configuredBoards(?int $storeId): Builder
    {
        return PaidAdvertisingGoalBoard::query()
            ->with('store:id,organization_id,status')
            ->whereHas('store', fn (Builder $store): Builder => $store->where('status', 'active'))
            ->when($storeId !== null, fn (Builder $query): Builder => $query->where('store_id', $storeId));
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
        $message = preg_replace(
            '/https?:\/\/[^\s]+|(?:app|tbl|vew)[A-Za-z0-9_-]{6,}/i',
            '[redacted]',
            $exception->getMessage(),
        );

        return mb_substr($message ?: '飞书广告目标同步失败。', 0, 300);
    }
}
