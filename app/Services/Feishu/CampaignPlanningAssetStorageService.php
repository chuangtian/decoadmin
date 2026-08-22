<?php

namespace App\Services\Feishu;

use App\Models\CampaignPlanningAsset;
use App\Models\CampaignPlanningDocument;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CampaignPlanningAssetStorageService
{
    public function __construct(
        private FeishuBitableClient $client,
        private ImageOptimizationService $imageOptimizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{token: string, token_hash: string, type: string, name: string|null}>
     */
    public function definitions(array $blocks): array
    {
        $definitions = [];

        foreach ($blocks as $block) {
            $type = (int) ($block['block_type'] ?? 0);
            $assetType = match ($type) {
                27 => 'image',
                23 => 'file',
                default => null,
            };

            if ($assetType === null) {
                continue;
            }

            $token = trim((string) data_get($block, "{$assetType}.token", ''));

            if ($token === '') {
                continue;
            }

            $tokenHash = hash('sha256', $token);
            $name = trim((string) data_get($block, "{$assetType}.name", ''));
            $definitions[$tokenHash] = [
                'token' => $token,
                'token_hash' => $tokenHash,
                'type' => $assetType,
                'name' => $name !== '' ? mb_substr($name, 0, 500) : null,
            ];
        }

        return array_values($definitions);
    }

    /**
     * @param  list<array{token: string, token_hash: string, type: string, name: string|null}>  $definitions
     */
    public function allStored(CampaignPlanningDocument $document, array $definitions): bool
    {
        $assets = $document->assets()
            ->whereIn('source_file_token_hash', array_column($definitions, 'token_hash'))
            ->get()
            ->keyBy('source_file_token_hash');

        if ($assets->count() !== count($definitions)) {
            return false;
        }

        return $assets->every(fn (CampaignPlanningAsset $asset): bool => Storage::disk($asset->local_disk)
            ->exists($asset->local_path));
    }

    /**
     * @param  list<array{token: string, token_hash: string, type: string, name: string|null}>  $definitions
     * @return array<string, string>
     */
    public function synchronize(CampaignPlanningDocument $document, array $definitions): array
    {
        $expectedHashes = array_column($definitions, 'token_hash');
        $existing = $document->assets()->get()->keyBy('source_file_token_hash');
        $urls = [];

        foreach ($definitions as $definition) {
            $asset = $existing->get($definition['token_hash']);

            if ($asset instanceof CampaignPlanningAsset
                && Storage::disk($asset->local_disk)->exists($asset->local_path)) {
                $urls[$definition['token']] = $asset->local_url;

                continue;
            }

            $stored = $this->downloadAndStore($document, $definition);
            $asset = $document->assets()->updateOrCreate(
                ['source_file_token_hash' => $definition['token_hash']],
                [
                    'organization_id' => $document->organization_id,
                    'store_id' => $document->store_id,
                    'asset_type' => $definition['type'],
                    'original_name' => $definition['name'],
                    'local_disk' => 'local',
                    'local_path' => $stored['path'],
                    'local_url' => $this->localUrl($document, $definition['token_hash']),
                    'mime_type' => $stored['mime_type'],
                    'file_size' => strlen($stored['contents']),
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'content_hash' => hash('sha256', $stored['contents']),
                ],
            );
            $urls[$definition['token']] = $asset->local_url;
        }

        $obsolete = $document->assets()
            ->when(
                $expectedHashes !== [],
                fn ($query) => $query->whereNotIn('source_file_token_hash', $expectedHashes),
            )
            ->get();

        foreach ($obsolete as $asset) {
            Storage::disk($asset->local_disk)->delete($asset->local_path);
            $asset->delete();
        }

        return $urls;
    }

    /**
     * @param  list<array{token: string, token_hash: string, type: string, name: string|null}>  $definitions
     * @return array<string, string>
     */
    public function storedUrls(CampaignPlanningDocument $document, array $definitions): array
    {
        $assets = $document->assets()
            ->whereIn('source_file_token_hash', array_column($definitions, 'token_hash'))
            ->get()
            ->keyBy('source_file_token_hash');
        $urls = [];

        foreach ($definitions as $definition) {
            $asset = $assets->get($definition['token_hash']);

            if ($asset instanceof CampaignPlanningAsset) {
                $urls[$definition['token']] = $asset->local_url;
            }
        }

        return $urls;
    }

    /**
     * @param  array{token: string, token_hash: string, type: string, name: string|null}  $definition
     * @return array{path: string, contents: string, mime_type: string|null, width: int|null, height: int|null}
     */
    private function downloadAndStore(CampaignPlanningDocument $document, array $definition): array
    {
        $download = $this->client->downloadMedia($definition['token']);
        $contents = $download['contents'];
        $maxBytes = max(1, (int) config('services.feishu_table.max_attachment_bytes', 52428800));

        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException('飞书策划书资源超过允许的单文件大小。');
        }

        $mimeType = $download['content_type'];
        $width = null;
        $height = null;

        if ($definition['type'] === 'image') {
            $optimized = $this->imageOptimizer->toWebp($contents, '飞书策划书图片');
            $contents = $optimized['contents'];
            $mimeType = $optimized['mime_type'];
            $width = $optimized['width'];
            $height = $optimized['height'];
            $extension = 'webp';
        } else {
            $extension = $this->fileExtension($definition['name'], $mimeType);
        }

        $contentHash = hash('sha256', $contents);
        $path = implode('/', [
            'feishu',
            'campaign-planning',
            (string) $document->organization_id,
            (string) $document->store_id,
            (string) $document->id,
            $definition['token_hash'],
            $contentHash.'.'.$extension,
        ]);

        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('飞书策划书资源保存到服务器失败。');
        }

        return [
            'path' => $path,
            'contents' => $contents,
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function localUrl(CampaignPlanningDocument $document, string $tokenHash): string
    {
        return '/campaign-planning-documents/'.$document->id.'/assets/'.$tokenHash;
    }

    private function fileExtension(?string $name, ?string $mimeType): string
    {
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        if ($extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            return $extension;
        }

        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/zip' => 'zip',
            default => 'bin',
        };
    }
}
