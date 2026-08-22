<?php

namespace App\Services\Feishu;

use App\Models\CampaignActivity;
use App\Models\CampaignPlanningDocument;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CampaignPlanningDocumentSyncService
{
    public function __construct(
        private FeishuBitableClient $client,
        private CampaignPlanningAssetStorageService $assets,
        private CampaignPlanningDocumentRenderer $renderer,
    ) {}

    /**
     * @return array{
     *     stores: int, documents: int, inserted: int, updated: int, unchanged: int, failed: int,
     *     failures: list<array{organization_id: int, store_id: int, campaign_activity_id: int, error: string}>
     * }
     */
    public function syncConfiguredStores(?int $storeId = null): array
    {
        $summary = [
            'stores' => 0,
            'documents' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        $this->configuredStoreQuery($storeId)
            ->select(['id', 'organization_id', 'status'])
            ->orderBy('id')
            ->chunkById(50, function ($stores) use (&$summary): void {
                foreach ($stores as $store) {
                    $summary['stores']++;
                    $result = $this->syncStore($store);

                    foreach (['documents', 'inserted', 'updated', 'unchanged', 'failed'] as $key) {
                        $summary[$key] += $result[$key];
                    }

                    $summary['failures'] = [...$summary['failures'], ...$result['failures']];
                }
            });

        return $summary;
    }

    /**
     * @return array{
     *     documents: int, inserted: int, updated: int, unchanged: int, failed: int,
     *     failures: list<array{organization_id: int, store_id: int, campaign_activity_id: int, error: string}>
     * }
     */
    public function syncStore(Store $store): array
    {
        $summary = [
            'documents' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        CampaignActivity::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->id)
            ->whereNotNull('planning_document')
            ->where('planning_document', '<>', '')
            ->orderBy('id')
            ->chunkById(50, function ($activities) use (&$summary, $store): void {
                foreach ($activities as $activity) {
                    $summary['documents']++;

                    try {
                        $status = $this->syncActivity($activity);
                        $summary[$status]++;
                    } catch (Throwable $exception) {
                        $summary['failed']++;
                        $error = $this->safeError($exception);
                        $this->markFailed($activity, $error);
                        $summary['failures'][] = [
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->id,
                            'campaign_activity_id' => (int) $activity->id,
                            'error' => $error,
                        ];
                        Log::warning('Feishu campaign planning document sync failed.', [
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->id,
                            'campaign_activity_id' => (int) $activity->id,
                            'exception' => $exception::class,
                            'error' => $error,
                        ]);
                    }
                }
            });

        return $summary;
    }

    /** @return 'inserted'|'updated'|'unchanged' */
    public function syncActivity(CampaignActivity $activity): string
    {
        $sourceUrl = trim((string) $activity->planning_document);
        $source = $this->parseSource($sourceUrl);
        $document = CampaignPlanningDocument::query()->firstOrCreate(
            ['campaign_activity_id' => $activity->id],
            [
                'organization_id' => $activity->organization_id,
                'store_id' => $activity->store_id,
                'source_url' => $sourceUrl,
                'source_type' => $source['type'],
                'source_node_token' => $source['node_token'],
                'source_document_token' => $source['document_token'],
                'sync_status' => 'pending',
            ],
        );

        if ($document->organization_id !== $activity->organization_id
            || $document->store_id !== $activity->store_id) {
            throw new RuntimeException('策划书快照与活动的组织或店铺范围不一致。');
        }

        if ($source['type'] === 'wiki') {
            $node = $this->client->wikiNode((string) $source['node_token']);
            $objectType = trim((string) ($node['obj_type'] ?? ''));
            $objectToken = trim((string) ($node['obj_token'] ?? ''));

            if ($objectType !== 'docx' || $objectToken === '') {
                throw new RuntimeException('飞书知识库节点不是可同步的新版文档。');
            }

            $source['document_token'] = $objectToken;
        }

        $documentToken = (string) $source['document_token'];
        $metadata = $this->client->document($documentToken);
        $blocks = $this->client->documentBlocks($documentToken);
        $title = trim((string) ($metadata['title'] ?? '')) ?: null;
        $revisionId = $metadata['revision_id'] ?? $metadata['document_revision_id'] ?? null;
        $revisionId = is_scalar($revisionId) ? trim((string) $revisionId) : null;
        $contentHash = hash('sha256', json_encode(
            ['title' => $title, 'revision_id' => $revisionId, 'blocks' => $blocks],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $definitions = $this->assets->definitions($blocks);
        $hadSnapshot = filled($document->content_hash);

        if ($document->content_hash === $contentHash && $this->assets->allStored($document, $definitions)) {
            $document->forceFill([
                'source_type' => $source['type'],
                'source_node_token' => $source['node_token'],
                'source_document_token' => $documentToken,
                'source_url' => $sourceUrl,
                'source_revision_id' => $revisionId,
                'sync_status' => 'synced',
                'synced_at' => now(),
                'last_error' => null,
            ])->save();

            return 'unchanged';
        }

        $assetUrls = $this->assets->synchronize($document, $definitions);
        $rendered = $this->renderer->render($title, $blocks, $assetUrls);

        DB::transaction(function () use (
            $document,
            $source,
            $sourceUrl,
            $documentToken,
            $title,
            $revisionId,
            $blocks,
            $rendered,
            $contentHash,
        ): void {
            $document->forceFill([
                'source_type' => $source['type'],
                'source_node_token' => $source['node_token'],
                'source_document_token' => $documentToken,
                'source_url' => $sourceUrl,
                'title' => $title,
                'source_revision_id' => $revisionId,
                'content_blocks' => $blocks,
                'rendered_html' => $rendered['html'],
                'plain_text' => $rendered['plain_text'],
                'content_hash' => $contentHash,
                'sync_status' => 'synced',
                'synced_at' => now(),
                'last_error' => null,
            ])->save();
        });

        return $hadSnapshot ? 'updated' : 'inserted';
    }

    private function configuredStoreQuery(?int $storeId): Builder
    {
        $query = Store::query()
            ->where('status', 'active')
            ->whereHas('campaignActivities', fn (Builder $activities): Builder => $activities
                ->whereNotNull('planning_document')
                ->where('planning_document', '<>', ''));

        if ($storeId !== null) {
            $query->whereKey($storeId);
        }

        return $query;
    }

    /** @return array{type: 'docx'|'wiki', node_token: string|null, document_token: string|null} */
    private function parseSource(string $sourceUrl): array
    {
        if (! filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('策划书飞书链接无效。');
        }

        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

        if (! ($host === 'feishu.cn' || str_ends_with($host, '.feishu.cn')
            || $host === 'larksuite.com' || str_ends_with($host, '.larksuite.com'))) {
            throw new RuntimeException('策划书链接不是受支持的飞书域名。');
        }

        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);

        if (! preg_match('#/(docx|wiki)/([A-Za-z0-9_-]+)(?:/|$)#', $path, $matches)) {
            throw new RuntimeException('策划书链接缺少可识别的 docx 或 wiki Token。');
        }

        $type = $matches[1];
        $token = $matches[2];

        return [
            'type' => $type,
            'node_token' => $type === 'wiki' ? $token : null,
            'document_token' => $type === 'docx' ? $token : null,
        ];
    }

    private function markFailed(CampaignActivity $activity, string $error): void
    {
        $document = CampaignPlanningDocument::query()->firstOrNew([
            'campaign_activity_id' => $activity->id,
        ]);
        $document->fill([
            'organization_id' => $activity->organization_id,
            'store_id' => $activity->store_id,
            'source_url' => (string) $activity->planning_document,
            'sync_status' => 'failed',
            'last_error' => $error,
        ])->save();
    }

    private function safeError(Throwable $exception): string
    {
        $message = preg_replace(
            '/https?:\/\/[^\s]+|(?:docx|wiki)\/[A-Za-z0-9_-]+|[A-Za-z0-9_-]{24,}/i',
            '[redacted]',
            $exception->getMessage(),
        );

        return mb_substr($message ?: '飞书策划书同步失败。', 0, 500);
    }
}
