<?php

namespace App\Services\NaturalTraffic;

use App\Models\FeishuBitableTable;
use App\Models\Store;
use App\Services\Feishu\FeishuBitableArchiveSyncService;
use App\Services\Feishu\FeishuSpreadsheetArchiveSyncService;
use App\Services\StoreFeishuDataLinkService;
use RuntimeException;
use Throwable;

class NaturalTrafficDataSyncService
{
    public const SECTIONS = [
        'brand-media' => ['natural-traffic:social'],
        'influencer-operations' => ['natural-traffic:kol'],
        'edm-email' => ['natural-traffic:sequence', 'natural-traffic:edm'],
        'affiliate-marketing' => ['natural-traffic:affiliate'],
    ];

    public function __construct(
        private StoreFeishuDataLinkService $dataLinks,
        private FeishuBitableArchiveSyncService $bitable,
        private FeishuSpreadsheetArchiveSyncService $spreadsheet,
    ) {}

    /** @return array{channel: string, tables: int, fields: int, records: int, synced_at: string} */
    public function sync(Store $store, string $channel): array
    {
        if (! isset(self::SECTIONS[$channel])) {
            throw new RuntimeException('不支持的自然流量数据来源。');
        }

        $parts = match ($channel) {
            'brand-media' => [$this->syncWikiSection($store, 'social', 'social_wiki_node', 'natural-traffic:social')],
            'influencer-operations' => $this->syncKol($store),
            'edm-email' => [
                $this->syncWikiSection($store, 'sequence', 'sequence_wiki_node', 'natural-traffic:sequence'),
                $this->syncWikiSection($store, 'edm', 'edm_wiki_node', 'natural-traffic:edm'),
            ],
            'affiliate-marketing' => [$this->syncBitableSection(
                $store,
                'affiliate',
                'affiliate_app_token',
                'affiliate_table_id',
                'affiliate_view_id',
                'natural-traffic:affiliate',
            )],
        };

        return [
            'channel' => $channel,
            'tables' => array_sum(array_column($parts, 'tables')),
            'fields' => array_sum(array_column($parts, 'fields')),
            'records' => array_sum(array_column($parts, 'records')),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{stores: int, sources: int, tables: int, fields: int, records: int, failed: int, failures: list<array{organization_id: int, store_id: int, channel: string, error: string}>}
     */
    public function syncConfiguredSources(?int $storeId = null, ?string $channel = null): array
    {
        if ($channel !== null && ! isset(self::SECTIONS[$channel])) {
            throw new RuntimeException('不支持的自然流量数据来源。');
        }

        $channels = $channel === null ? array_keys(self::SECTIONS) : [$channel];
        $summary = ['stores' => 0, 'sources' => 0, 'tables' => 0, 'fields' => 0, 'records' => 0, 'failed' => 0, 'failures' => []];

        Store::query()
            ->where('status', 'active')
            ->when($storeId !== null, fn ($query) => $query->whereKey($storeId))
            ->select(['id', 'organization_id', 'status'])
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($channels, &$summary): void {
                foreach ($stores as $store) {
                    $storeCounted = false;
                    foreach ($channels as $channel) {
                        if (! $this->hasConfiguration($store, $channel)) {
                            continue;
                        }

                        try {
                            $result = $this->sync($store, $channel);
                            $summary['sources']++;
                            $summary['tables'] += $result['tables'];
                            $summary['fields'] += $result['fields'];
                            $summary['records'] += $result['records'];
                            if (! $storeCounted) {
                                $summary['stores']++;
                                $storeCounted = true;
                            }
                        } catch (Throwable $exception) {
                            report($exception);
                            $summary['failed']++;
                            $summary['failures'][] = [
                                'organization_id' => (int) $store->organization_id,
                                'store_id' => (int) $store->id,
                                'channel' => $channel,
                                'error' => $this->safeError($exception),
                            ];
                        }
                    }
                }
            });

        return $summary;
    }

    public function hasConfiguration(Store $store, string $channel): bool
    {
        return match ($channel) {
            'brand-media' => filled($this->values($store, 'social')['social_wiki_node'] ?? null),
            'influencer-operations' => filled($this->values($store, 'kol')['kol_app_token'] ?? null)
                && filled($this->values($store, 'kol')['kol_table_id'] ?? null),
            'edm-email' => filled($this->values($store, 'sequence')['sequence_wiki_node'] ?? null)
                && filled($this->values($store, 'edm')['edm_wiki_node'] ?? null),
            'affiliate-marketing' => filled($this->values($store, 'affiliate')['affiliate_app_token'] ?? null)
                && filled($this->values($store, 'affiliate')['affiliate_table_id'] ?? null),
            default => false,
        };
    }

    /** @return list<array{tables: int, fields: int, records: int}> */
    private function syncKol(Store $store): array
    {
        $values = $this->values($store, 'kol');
        $appToken = trim((string) ($values['kol_app_token'] ?? ''));
        $definitions = [
            ['table' => 'kol_table_id', 'view' => 'kol_view_id'],
            ['table' => 'kol_yearly_table_id', 'view' => 'kol_yearly_view_id'],
            ['table' => 'kol_viral_table_id', 'view' => 'kol_viral_view_id'],
        ];

        if ($appToken === '') {
            throw new RuntimeException('当前店铺尚未配置红人运营 App Token。');
        }

        $results = [];
        $incoming = [];
        foreach ($definitions as $definition) {
            $tableId = trim((string) ($values[$definition['table']] ?? ''));
            if ($tableId === '') {
                continue;
            }
            $incoming[] = $tableId;
            $result = $this->bitable->syncTableById(
                $store,
                'natural-traffic:kol',
                $appToken,
                $tableId,
                trim((string) ($values[$definition['view']] ?? '')) ?: null,
            );
            $results[] = ['tables' => 1, ...$result];
        }

        if ($incoming === []) {
            throw new RuntimeException('当前店铺尚未配置红人运营数据表。');
        }

        FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_section', 'natural-traffic:kol')
            ->whereNotIn('source_table_id', $incoming)
            ->delete();

        return $results;
    }

    /** @return array{tables: int, fields: int, records: int} */
    private function syncWikiSection(Store $store, string $linkSection, string $key, string $archiveSection): array
    {
        $token = trim((string) ($this->values($store, $linkSection)[$key] ?? ''));
        if ($token === '') {
            throw new RuntimeException('当前店铺尚未完整配置'.$linkSection.'数据来源。');
        }

        return $this->spreadsheet->syncWiki($store, $archiveSection, $token);
    }

    /** @return array{tables: int, fields: int, records: int} */
    private function syncBitableSection(
        Store $store,
        string $linkSection,
        string $appKey,
        string $tableKey,
        string $viewKey,
        string $archiveSection,
    ): array {
        $values = $this->values($store, $linkSection);
        $appToken = trim((string) ($values[$appKey] ?? ''));
        $tableId = trim((string) ($values[$tableKey] ?? ''));

        if ($appToken === '' || $tableId === '') {
            throw new RuntimeException('当前店铺尚未完整配置'.$linkSection.'数据来源。');
        }

        $result = $this->bitable->syncTableById(
            $store,
            $archiveSection,
            $appToken,
            $tableId,
            trim((string) ($values[$viewKey] ?? '')) ?: null,
        );

        FeishuBitableTable::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->where('source_section', $archiveSection)
            ->where('source_table_id', '!=', $tableId)
            ->delete();

        return ['tables' => 1, ...$result];
    }

    /** @return array<string, string> */
    private function values(Store $store, string $section): array
    {
        return $this->dataLinks->valuesForSync($store, $section);
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr(preg_replace('/[A-Za-z0-9_-]{24,}/', '[已隐藏]', $exception->getMessage()) ?: '同步失败。', 0, 500);
    }
}
