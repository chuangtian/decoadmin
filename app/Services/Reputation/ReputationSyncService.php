<?php

namespace App\Services\Reputation;

use App\Models\ReputationMention;
use App\Models\ReputationRisk;
use App\Models\ReputationSyncRun;
use App\Models\Store;
use App\Services\Feishu\FeishuBitableClient;
use App\Services\StoreFeishuDataLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReputationSyncService
{
    private const SOURCE_KEYS = ['reputation_wiki_node', 'reddit_wiki_node', 'threads_wiki_node'];

    private const AI_FIELDS = [
        '帖子类型', '目标关键词', '内容效率分（Heat Score）', 'AI分析', 'AI 分析',
        'AI标签', 'AI 标签', '情感分析', '自动分类',
    ];

    public function __construct(
        private StoreFeishuDataLinkService $dataLinks,
        private FeishuBitableClient $client,
    ) {}

    /** @return array{raw_rows: int, mentions: int, source_counts: array<string, int>, sheets: list<string>} */
    public function sync(Store $store, ReputationSyncRun $run): array
    {
        abort_unless((int) $run->organization_id === (int) $store->organization_id && (int) $run->store_id === (int) $store->id, 404);

        $links = $this->dataLinks->valuesForSync($store, 'reputation');
        $documents = [];

        foreach (self::SOURCE_KEYS as $sourceKey) {
            $wikiToken = trim((string) ($links[$sourceKey] ?? ''));
            if ($wikiToken === '') {
                continue;
            }

            $node = $this->client->wikiNode($wikiToken);
            if (strtolower(trim((string) ($node['obj_type'] ?? ''))) !== 'sheet') {
                throw new RuntimeException('舆情数据源必须指向飞书电子表格。');
            }

            $objectToken = trim((string) ($node['obj_token'] ?? ''));
            if ($objectToken === '') {
                throw new RuntimeException('舆情数据源缺少电子表格对象。');
            }

            $documentKey = hash('sha256', $objectToken);
            $documents[$documentKey] ??= ['token' => $objectToken, 'source_keys' => []];
            $documents[$documentKey]['source_keys'][] = $sourceKey;
        }

        if ($documents === []) {
            throw new RuntimeException('当前店铺尚未配置舆情数据源。');
        }

        $mapped = [];
        $sourceCounts = [];
        $sheetNames = [];
        $rawRows = 0;
        $documentIndex = 0;

        foreach ($documents as $document) {
            $documentIndex++;
            $sheets = $this->client->spreadsheetSheets((string) $document['token']);

            foreach ($sheets as $sheet) {
                $sheetName = trim((string) ($sheet['title'] ?? ''));
                $kind = $this->sheetKind($sheetName);
                if ($kind === null) {
                    continue;
                }

                $sheetId = trim((string) ($sheet['sheet_id'] ?? ''));
                $rowCount = max(1, (int) data_get($sheet, 'grid_properties.row_count', 1));
                $columnCount = max(1, (int) data_get($sheet, 'grid_properties.column_count', 1));
                if ($sheetId === '' || $rowCount > 50000 || $columnCount > 200) {
                    throw new RuntimeException('舆情电子表格规模超过安全上限。');
                }

                $values = $this->client->spreadsheetValues((string) $document['token'], $sheetId, $rowCount, $columnCount);
                $headers = array_map($this->cellText(...), $values[0] ?? []);
                $sheetNames[] = $sheetName;

                foreach (array_slice($values, 1, null, true) as $rowIndex => $cells) {
                    $payload = $this->payload($headers, $cells);
                    if ($payload === []) {
                        continue;
                    }

                    $row = $this->mapRow($kind, $sheetName, $rowIndex + 1, $payload, $store);
                    if ($row === null) {
                        continue;
                    }

                    $rawRows++;
                    $sourceCounts[$row['source']] = ($sourceCounts[$row['source']] ?? 0) + 1;
                    $mapKey = $row['source'].':'.$row['canonical_key'];
                    $mapped[$mapKey] = isset($mapped[$mapKey]) ? $this->mergeRows($mapped[$mapKey], $row) : $row;
                }
            }

            $run->forceFill([
                'progress_percent' => min(85, 10 + (int) round(($documentIndex / count($documents)) * 70)),
                'processed_rows' => $rawRows,
            ])->save();
        }

        DB::transaction(function () use ($store, $mapped): void {
            ReputationMention::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->id)
                ->where('origin', 'source')
                ->where('is_active', true)
                ->update(['is_active' => false]);

            foreach ($mapped as $row) {
                $mention = ReputationMention::query()
                    ->forOrganization((int) $store->organization_id)
                    ->forStore((int) $store->id)
                    ->where('source', $row['source'])
                    ->where('canonical_key', $row['canonical_key'])
                    ->first();

                $mention ??= new ReputationMention([
                    'organization_id' => (int) $store->organization_id,
                    'store_id' => (int) $store->id,
                    'origin' => 'source',
                    'source' => $row['source'],
                    'canonical_key' => $row['canonical_key'],
                ]);

                $mention->fill($this->mergeWithStoredMention($mention, $row));
                $mention->save();

                if ($row['is_negative']) {
                    $this->syncSourceRisk($store, $mention, $row);
                }
            }
        });

        return [
            'raw_rows' => $rawRows,
            'mentions' => count($mapped),
            'source_counts' => $sourceCounts,
            'sheets' => array_values(array_unique($sheetNames)),
        ];
    }

    private function sheetKind(string $sheetName): ?string
    {
        $name = mb_strtolower(trim($sheetName));

        return match (true) {
            $name === 'trustpilot' => 'trustpilot',
            $name === 'reddit' => 'reddit_base',
            str_contains($name, 'reddit') && str_contains($name, 'ai') => 'reddit_extended',
            $name === '官网' || str_contains($name, 'website') => 'website',
            str_contains($name, 'facebook') => 'facebook',
            str_contains($name, 'threads') => 'threads',
            str_contains($name, 'negative') && str_contains($name, 'review') => 'negative_review',
            default => null,
        };
    }

    /** @param list<string> $headers @param array<int, mixed> $cells @return array<string, mixed> */
    private function payload(array $headers, array $cells): array
    {
        $payload = [];
        foreach ($headers as $index => $header) {
            $header = trim($header);
            if ($header === '' || $this->isAiField($header)) {
                continue;
            }

            $value = $cells[$index] ?? null;
            if ($this->cellText($value) !== '') {
                $payload[$header] = $value;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    private function mapRow(string $kind, string $sheet, int $rowNumber, array $payload, Store $store): ?array
    {
        $text = fn (string $field): string => $this->cellText($payload[$field] ?? null);
        $number = fn (string $field): ?float => $this->number($payload[$field] ?? null);
        $firstText = function (array $fields) use ($payload): string {
            foreach ($fields as $field) {
                $value = $this->cellText($payload[$field] ?? null);
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };
        $firstValue = function (array $fields) use ($payload): mixed {
            foreach ($fields as $field) {
                if (array_key_exists($field, $payload) && $this->cellText($payload[$field]) !== '') {
                    return $payload[$field];
                }
            }

            return null;
        };
        $source = match ($kind) {
            'trustpilot' => 'trustpilot',
            'website' => 'website',
            'facebook' => 'facebook',
            'threads' => 'threads',
            'negative_review' => 'multiple',
            default => 'reddit',
        };

        $url = match ($kind) {
            'reddit_base' => $firstText(['帖子链接', '链接']),
            'reddit_extended' => $firstText(['发帖链接', '帖子链接', '链接']),
            'threads', 'negative_review' => $text('链接'),
            'facebook' => $firstText(['帖子链接', '链接']),
            default => '',
        };
        $url = $this->normalizeUrl($url);
        $publishedAt = $this->date(match ($kind) {
            'trustpilot', 'website' => $firstValue(['发布日期', '日期', '评论日期']),
            'reddit_base', 'facebook' => $firstValue(['发布日期', '日期']),
            'reddit_extended' => $firstValue(['发帖日期', '发布日期', '日期']),
            'threads' => $firstValue(['发布日期', '日期']),
            'negative_review' => $firstValue(['差评时间', '发布日期', '日期']),
            default => null,
        }, $store->timezone ?: 'UTC');

        $content = match ($kind) {
            'trustpilot', 'website' => $firstText(['评论详情', '评论']),
            'reddit_base', 'facebook' => $text('数据导出'),
            'reddit_extended' => $firstText(['发帖内容', '帖子正文', '内容']),
            'threads' => $firstText(['帖子正文', '内容']),
            'negative_review' => trim($text('差评内容').' '.$text('讨论内容')),
            default => '',
        };
        $title = $kind === 'reddit_extended' ? $text('发帖标题') : '';
        $rating = in_array($kind, ['trustpilot', 'website'], true) ? $number('星级') : null;
        $weekNumber = $this->weekNumber($payload['周数'] ?? null);
        $model = $kind === 'website' ? $text('车型') : '';
        $orderReference = $kind === 'website'
            ? $firstText(['订单号', '订单编号', '订单', 'Order Number', 'Order ID', 'order_number'])
            : '';
        $processingStatus = $kind === 'website' ? $text('处理结果') : '';
        $metrics = match ($kind) {
            'reddit_extended' => array_filter([
                'views' => $number('发帖 views'),
                'upvotes' => $number('Upvotes'),
                'comments' => $number('发帖评论'),
                'spend' => $number('发帖花费'),
                'comment_trend' => $text('评论走势') ?: null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'threads' => array_filter([
                'likes' => $number('Likes'),
                'replies' => $number(' Replies') ?? $number('Replies'),
                'reposts' => $number('Reposts'),
                'shares' => $number('Shares'),
                'data_capture' => $text('数据抓取') ?: null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'negative_review' => array_filter([
                'discussion_level' => $text('差评讨论度') ?: null,
                'search_terms' => $text('关键词搜索') ?: null,
                'notes' => $text('备注') ?: null,
                'discussion' => $text('讨论内容') ?: null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            default => [],
        };

        if ($url === '' && $content === '' && $title === '' && $rating === null) {
            return null;
        }

        $canonicalMaterial = $url !== ''
            ? $url
            : implode('|', [
                $publishedAt?->toIso8601String() ?? '', $title, $content,
                $rating === null ? '' : (string) $rating, $model,
                $orderReference === '' ? '' : hash('sha256', $orderReference),
            ]);
        $canonicalKey = hash('sha256', $source.'|'.$canonicalMaterial);
        $payloadKey = mb_substr($sheet, 0, 80).':'.$rowNumber;

        return [
            'origin' => 'source',
            'source' => $source,
            'canonical_key' => $canonicalKey,
            'source_sheets' => [$sheet],
            'source_payloads_encrypted' => [$payloadKey => $this->sanitizedPayload($payload)],
            'url' => $url ?: null,
            'url_hash' => $url === '' ? null : hash('sha256', $url),
            'title' => $title ?: null,
            'content' => $content ?: null,
            'rating' => $rating,
            'week_number' => $weekNumber,
            'published_at' => $publishedAt,
            'model_name' => $model ?: null,
            'order_reference_encrypted' => $orderReference ?: null,
            'processing_status' => $processingStatus ?: null,
            'metrics' => $metrics,
            'is_negative' => $kind === 'negative_review' || ($rating !== null && $rating <= 2),
            'is_active' => true,
            'synced_at' => now(),
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right @return array<string, mixed> */
    private function mergeRows(array $left, array $right): array
    {
        foreach (['url', 'url_hash', 'title', 'content', 'rating', 'week_number', 'published_at', 'model_name', 'order_reference_encrypted', 'processing_status'] as $field) {
            if (filled($right[$field] ?? null)) {
                $left[$field] = $right[$field];
            }
        }

        $left['source_sheets'] = array_values(array_unique([...(array) ($left['source_sheets'] ?? []), ...(array) ($right['source_sheets'] ?? [])]));
        $left['source_payloads_encrypted'] = [...(array) ($left['source_payloads_encrypted'] ?? []), ...(array) ($right['source_payloads_encrypted'] ?? [])];
        $left['metrics'] = [...(array) ($left['metrics'] ?? []), ...(array) ($right['metrics'] ?? [])];
        $left['is_negative'] = (bool) ($left['is_negative'] ?? false) || (bool) ($right['is_negative'] ?? false);
        $left['is_active'] = true;
        $left['synced_at'] = now();

        return $left;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mergeWithStoredMention(ReputationMention $mention, array $row): array
    {
        $stored = [
            ...$row,
            'source_sheets' => array_values(array_unique([...(array) $mention->source_sheets, ...(array) $row['source_sheets']])),
            'source_payloads_encrypted' => [...(array) $mention->source_payloads_encrypted, ...(array) $row['source_payloads_encrypted']],
            'metrics' => [...(array) $mention->metrics, ...(array) $row['metrics']],
            'is_negative' => $mention->is_negative || (bool) $row['is_negative'],
            'is_active' => true,
        ];

        foreach (['url', 'url_hash', 'title', 'content', 'rating', 'week_number', 'published_at', 'model_name', 'order_reference_encrypted', 'processing_status'] as $field) {
            if (blank($stored[$field] ?? null) && filled($mention->{$field})) {
                $stored[$field] = $mention->{$field};
            }
        }

        return $stored;
    }

    /** @param array<string, mixed> $row */
    private function syncSourceRisk(Store $store, ReputationMention $mention, array $row): void
    {
        $description = trim((string) ($row['content'] ?? $row['title'] ?? ''));
        if ($description === '') {
            $description = '来源数据中存在一条待处理的负面舆情记录。';
        }

        $risk = ReputationRisk::query()->firstOrNew([
            'store_id' => (int) $store->id,
            'source_key' => (string) $mention->canonical_key,
        ]);
        if (! $risk->exists) {
            $risk->fill([
                'organization_id' => (int) $store->organization_id,
                'reputation_mention_id' => (int) $mention->id,
                'origin' => 'source',
                'description' => mb_substr($description, 0, 5000),
                'severity' => 'medium',
                'source' => (string) $mention->source,
                'recommended_action' => data_get($row, 'metrics.notes'),
                'status' => 'pending',
                'occurred_at' => $mention->published_at,
            ]);
        } else {
            $risk->fill([
                'reputation_mention_id' => (int) $mention->id,
                'description' => mb_substr($description, 0, 5000),
                'recommended_action' => $risk->recommended_action ?: data_get($row, 'metrics.notes'),
                'occurred_at' => $risk->occurred_at ?: $mention->published_at,
            ]);
        }
        $risk->save();
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function sanitizedPayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (! $this->isAiField((string) $key)) {
                $sanitized[$key] = mb_substr($this->cellText($value), 0, 20000);
            }
        }

        return $sanitized;
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($value, function (mixed $part, string|int $key) use (&$parts): void {
            if (is_scalar($part) && (is_int($key) || in_array((string) $key, ['text', 'name', 'link', 'url'], true))) {
                $parts[] = trim((string) $part);
            }
        });

        return trim(implode(' ', array_values(array_filter($parts))));
    }

    private function isAiField(string $header): bool
    {
        $header = trim($header);

        return in_array($header, self::AI_FIELDS, true)
            || preg_match('/(^|[\s_\-])ai([\s_\-]|$)/iu', $header) === 1;
    }

    private function number(mixed $value): ?float
    {
        $text = strtoupper(str_replace([',', '$', '￥', '%'], '', $this->cellText($value)));
        if ($text === '' || ! preg_match('/-?\d+(?:\.\d+)?/', $text, $match)) {
            return null;
        }

        $number = (float) $match[0];
        if (str_ends_with($text, 'K')) {
            $number *= 1000;
        } elseif (str_ends_with($text, 'M')) {
            $number *= 1000000;
        }

        return $number;
    }

    private function weekNumber(mixed $value): ?int
    {
        return preg_match('/\d{1,2}/', $this->cellText($value), $match) ? min(53, max(1, (int) $match[0])) : null;
    }

    private function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        $text = $this->cellText($value);
        if ($text === '') {
            return null;
        }

        try {
            if (is_numeric($text)) {
                $number = (float) $text;
                if ($number > 1000000000000) {
                    return CarbonImmutable::createFromTimestampMs((int) $number, $timezone)->utc();
                }
                if ($number > 1000000000) {
                    return CarbonImmutable::createFromTimestamp((int) $number, $timezone)->utc();
                }
                if ($number > 20000) {
                    return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $timezone)->addDays((int) $number)->utc();
                }
            }

            return CarbonImmutable::parse($text, $timezone)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('~https?://[^\s<>"\']+~iu', $url, $match) === 1) {
            $url = $match[0];
        }

        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;

        return rtrim($url, '/.,;:)]}');
    }
}
