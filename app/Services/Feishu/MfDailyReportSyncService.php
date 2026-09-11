<?php

namespace App\Services\Feishu;

use App\Jobs\DeliverMfDailyReportJob;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\MfDailyReportDelivery;
use App\Models\Store;
use App\Services\StoreFeishuDataLinkService;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

class MfDailyReportSyncService
{
    public const SOURCE_SECTION = 'paid-ad-goals:mf-daily-report';

    public const TABLE_NAME = 'MF数据表';

    public function __construct(
        private FeishuBitableClient $client,
        private FeishuBitableArchiveSyncService $archive,
        private StoreFeishuDataLinkService $dataLinks,
        private MfDailyReportFormatter $formatter,
    ) {}

    /**
     * @return array{stores: int, baseline: int, inserted: int, queued: int, failed: int, failures: list<array{store_id: int, error: string}>}
     */
    public function syncConfiguredStores(?int $storeId = null): array
    {
        $summary = ['stores' => 0, 'baseline' => 0, 'inserted' => 0, 'queued' => 0, 'failed' => 0, 'failures' => []];

        Store::query()
            ->where('status', 'active')
            ->when($storeId !== null, fn (Builder $query): Builder => $query->whereKey($storeId))
            ->where(function (Builder $query): void {
                $query->whereHas('paidAdvertisingGoalBoards')
                    ->orWhereHas('businessCredentials', fn (Builder $credentials): Builder => $credentials
                        ->where('provider', 'feishu_data_links')
                        ->whereIn('credential_key', [
                            'advertising_goals_app_token',
                            'advertising_meta_weekly_app_token',
                            'advertising_google_weekly_app_token',
                        ]));
            })
            ->orderBy('id')
            ->chunkById(50, function ($stores) use (&$summary): void {
                foreach ($stores as $store) {
                    $summary['stores']++;
                    try {
                        $result = $this->syncStore($store);
                        $summary['baseline'] += $result['baseline'] ? 1 : 0;
                        $summary['inserted'] += $result['inserted'];
                        $summary['queued'] += $result['queued'];
                    } catch (Throwable $exception) {
                        $summary['failed']++;
                        $summary['failures'][] = [
                            'store_id' => (int) $store->id,
                            'error' => $this->safeError($exception),
                        ];
                    }
                }
            });

        return $summary;
    }

    /** @return array{baseline: bool, fields: int, records: int, inserted: int, queued: int} */
    public function syncStore(Store $store): array
    {
        [$appToken, $definition] = $this->resolveSource($store);

        $sourceTableId = trim((string) ($definition['table_id'] ?? $definition['id'] ?? ''));
        if ($sourceTableId === '') {
            throw new RuntimeException('MF数据表缺少稳定的 Table ID。');
        }

        $existingTable = FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_section', self::SOURCE_SECTION)
            ->where('source_table_id', $sourceTableId)
            ->first();
        $baseline = ! $existingTable instanceof FeishuBitableTable;
        $syncStartedAt = now()->startOfSecond();

        $result = $this->archive->syncTableById(
            $store,
            self::SOURCE_SECTION,
            $appToken,
            $sourceTableId,
        );

        $table = FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_section', self::SOURCE_SECTION)
            ->where('source_table_id', $sourceTableId)
            ->firstOrFail();

        $newRecords = $table->records()
            ->where('created_at', '>=', $syncStartedAt)
            ->get();
        $inserted = $baseline ? $result['records'] : $newRecords->count();

        if ($baseline || $newRecords->isEmpty()) {
            return [
                'baseline' => $baseline,
                'fields' => $result['fields'],
                'records' => $result['records'],
                'inserted' => $inserted,
                'queued' => 0,
            ];
        }

        $candidate = $newRecords
            ->map(function (FeishuBitableRecord $record): ?array {
                $fields = $record->fields_encrypted;
                if (! is_array($fields)) {
                    return null;
                }
                $date = $this->formatter->reportDate($fields);

                return $date === null ? null : ['record' => $record, 'date' => $date];
            })
            ->filter()
            ->sortByDesc(fn (array $item): string => $item['date'].'|'.str_pad((string) $item['record']->id, 20, '0', STR_PAD_LEFT))
            ->first();

        if (! is_array($candidate)) {
            return ['baseline' => false, 'fields' => $result['fields'], 'records' => $result['records'], 'inserted' => $inserted, 'queued' => 0];
        }

        $record = $candidate['record'];
        $delivery = MfDailyReportDelivery::query()->firstOrCreate(
            [
                'store_id' => (int) $store->id,
                'source_table_id' => $sourceTableId,
                'source_record_id' => (string) $record->source_record_id,
            ],
            [
                'organization_id' => (int) $store->organization_id,
                'report_date' => $candidate['date'],
                'status' => 'pending',
            ],
        );

        if (! $delivery->wasRecentlyCreated) {
            return ['baseline' => false, 'fields' => $result['fields'], 'records' => $result['records'], 'inserted' => $inserted, 'queued' => 0];
        }

        DeliverMfDailyReportJob::dispatch((int) $delivery->id)->onQueue('notifications');

        return ['baseline' => false, 'fields' => $result['fields'], 'records' => $result['records'], 'inserted' => $inserted, 'queued' => 1];
    }

    /** @return array{string, array<string, mixed>} */
    private function resolveSource(Store $store): array
    {
        $advertisingGoals = $this->dataLinks->valuesForSync($store, 'advertising_goals');
        $metaWeekly = $this->dataLinks->valuesForSync($store, 'advertising_meta_weekly');
        $googleWeekly = $this->dataLinks->valuesForSync($store, 'advertising_google_weekly');
        $tokens = $store->paidAdvertisingGoalBoards()
            ->orderBy('id')
            ->get(['id', 'feishu_app_token'])
            ->pluck('feishu_app_token')
            ->push($advertisingGoals['advertising_goals_app_token'] ?? null)
            ->push($metaWeekly['advertising_meta_weekly_app_token'] ?? null)
            ->push($googleWeekly['advertising_google_weekly_app_token'] ?? null)
            ->map(fn (mixed $token): string => trim((string) $token))
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            throw new RuntimeException('当前店铺未配置可用的飞书广告数据源。');
        }

        foreach ($tokens as $appToken) {
            try {
                $definition = collect($this->client->tables($appToken))
                    ->first(fn (array $table): bool => trim((string) ($table['name'] ?? '')) === self::TABLE_NAME);
            } catch (Throwable) {
                continue;
            }

            if (is_array($definition)) {
                return [$appToken, $definition];
            }
        }

        throw new RuntimeException('已配置的飞书数据源均无法读取 MF数据表。');
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr(preg_replace(
            '/https?:\/\/[^\s]+|(?:app|tbl|vew|shtcn|bascn)[A-Za-z0-9_-]{6,}/i',
            '[redacted]',
            $exception->getMessage(),
        ) ?: '同步失败', 0, 500);
    }
}
