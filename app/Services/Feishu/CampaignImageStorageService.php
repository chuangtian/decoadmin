<?php

namespace App\Services\Feishu;

use App\Models\Store;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CampaignImageStorageService
{
    private const ALLOWED_FIELDS = ['campaign_images', 'email_content'];

    public function __construct(
        private FeishuBitableClient $client,
        private ImageOptimizationService $imageOptimizer,
    ) {}

    /** @return list<string> */
    public function storeAttachments(Store $store, string $recordId, string $field, mixed $attachments): array
    {
        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new RuntimeException('不允许的飞书活动图片字段。');
        }

        $prefix = implode('/', [
            'feishu',
            'campaigns',
            (string) $store->organization_id,
            (string) $store->id,
            $this->safePathSegment($recordId),
            $field,
        ]);
        $items = is_array($attachments)
            ? (array_is_list($attachments) ? $attachments : [$attachments])
            : [];
        $urls = [];
        $expectedPaths = [];

        foreach ($items as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $fileToken = trim((string) ($attachment['file_token'] ?? ''));

            if ($fileToken === '') {
                continue;
            }

            $sourceSize = $attachment['size'] ?? null;
            $maxBytes = max(1, (int) config('services.feishu_table.max_attachment_bytes', 52428800));

            if (is_numeric($sourceSize) && (int) $sourceSize > $maxBytes) {
                throw new RuntimeException('飞书活动图片超过允许的单文件大小。');
            }

            $path = $prefix.'/optimized/'.$this->safePathSegment($fileToken).'.webp';
            $expectedPaths[] = $path;

            if (! Storage::disk('public')->exists($path)) {
                $legacyPath = collect(Storage::disk('public')->allFiles($prefix))
                    ->first(fn (string $candidate): bool => ! str_contains($candidate, '/optimized/')
                        && pathinfo($candidate, PATHINFO_FILENAME) === $this->safePathSegment($fileToken));
                $contents = is_string($legacyPath)
                    ? Storage::disk('public')->get($legacyPath)
                    : $this->client->downloadMedia($fileToken)['contents'];

                if (strlen($contents) > $maxBytes) {
                    throw new RuntimeException('飞书活动图片超过允许的单文件大小。');
                }

                $optimized = $this->imageOptimizer->toWebp($contents, '飞书活动图片')['contents'];

                if (! Storage::disk('public')->put($path, $optimized)) {
                    throw new RuntimeException('飞书活动图片保存到服务器失败。');
                }
            }

            $urls[] = '/storage/'.$path;
        }

        $obsoletePaths = array_values(array_diff(
            Storage::disk('public')->allFiles($prefix),
            $expectedPaths,
        ));

        if ($obsoletePaths !== []) {
            Storage::disk('public')->delete($obsoletePaths);
        }

        return array_values(array_unique($urls));
    }

    private function safePathSegment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($value));

        return trim((string) $value, '-') ?: 'unknown';
    }
}
