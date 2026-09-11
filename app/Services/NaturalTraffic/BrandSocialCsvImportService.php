<?php

namespace App\Services\NaturalTraffic;

use App\Models\BrandSocialPostState;
use App\Models\FeishuBitableField;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class BrandSocialCsvImportService
{
    public const SOURCE_SECTION = 'natural-traffic:social-manual';

    private const MAX_ROWS = 50000;

    private const SNAPSHOT_FIELDS = [
        '数据更新时间', '数据更新日期', '最后更新时间', '报告结束日期', '报告截止日期',
        '数据截止时间', '数据截至时间', '快照时间', '导出时间',
    ];

    private const PUBLISHED_FIELDS = ['发布时间', '发布日期'];

    private const CUMULATIVE_METRICS = [
        'views' => ['浏览量', '观看量'],
        'reach' => ['覆盖人数'],
        'impressions' => ['曝光量'],
        'likes' => ['赞', '心情'],
        'comments' => ['评论', '评论数'],
        'shares' => ['分享', '分享次数'],
        'saves' => ['收藏次数'],
        'clicks' => ['总点击量'],
        'followers' => ['关注者数'],
        'new_followers' => ['Instagram 新增关注人数'],
        'total_engagements' => ['心情、评论和分享'],
    ];

    /** @var array<string, array{platform: string, table_id: string, table_name: string, required: list<string>}> */
    private const FORMATS = [
        'instagram' => [
            'platform' => 'Instagram',
            'table_id' => 'manual:instagram',
            'table_name' => 'Instagram 手动导入',
            'required' => ['帖子编号', '账户编号', '发布时间', '帖子类型', '浏览量', '覆盖人数', '赞'],
        ],
        'facebook' => [
            'platform' => 'Facebook',
            'table_id' => 'manual:facebook',
            'table_name' => 'Facebook 手动导入',
            'required' => ['帖子编号', '公共主页编号', '发布时间', '帖子类型', '观看量', '覆盖人数', '心情'],
        ],
        'decoadmin' => [
            'platform' => '跨平台',
            'table_id' => 'manual:decoadmin',
            'table_name' => 'DecoAdmin 品牌官媒导入',
            'required' => ['平台', '帖子编号', '发布时间', '帖子类型', '浏览量', '赞', '评论数'],
        ],
    ];

    /**
     * @return array{platform: string, rows: int, unique_rows: int, created: int, updated: int, unchanged: int, skipped_stale: int, outliers: int, checksum: string}
     */
    public function import(Store $store, UploadedFile $file, ?string $expectedPlatform = null): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new RuntimeException('无法读取上传的 CSV 文件。');
        }

        [$headers, $csvRows] = $this->readCsv($path);
        $format = $this->detectFormat($headers);
        if ($expectedPlatform !== null) {
            $platform = match ($expectedPlatform) {
                'instagram' => 'Instagram',
                'facebook' => 'Facebook',
                default => throw new RuntimeException('不支持的导入平台。'),
            };
            foreach ($csvRows as $row) {
                if ($this->rowPlatform($row['fields'], $format) !== $platform) {
                    throw new RuntimeException("所选文件不是 {$platform} 数据，请使用对应平台的导入入口。");
                }
            }
        }
        $csvRows = $this->scopeRecordIds($csvRows, $format);
        [$rows, $duplicateUnchanged, $duplicateStale] = $this->coalesceRows(
            $csvRows,
            $store->timezone ?: 'UTC',
        );
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw new RuntimeException('无法校验上传的 CSV 文件。');
        }

        return DB::transaction(function () use ($store, $headers, $csvRows, $rows, $format, $checksum, $duplicateUnchanged, $duplicateStale): array {
            $now = now();
            $table = FeishuBitableTable::query()->updateOrCreate(
                [
                    'store_id' => $store->id,
                    'source_section' => self::SOURCE_SECTION,
                    'source_table_id' => $format['table_id'],
                ],
                [
                    'organization_id' => $store->organization_id,
                    'name' => $format['table_name'],
                    'metadata_encrypted' => [
                        'source' => 'manual_csv',
                        'platform' => $format['platform'],
                        'checksum' => $checksum,
                        'freshness_policy' => 'source_snapshot_then_monotonic_metrics',
                    ],
                    'synced_at' => $now,
                ],
            );

            foreach ($headers as $order => $header) {
                FeishuBitableField::query()->updateOrCreate(
                    ['feishu_bitable_table_id' => $table->id, 'source_field_id' => 'csv:'.hash('sha256', $header)],
                    [
                        'organization_id' => $store->organization_id,
                        'store_id' => $store->id,
                        'name' => $header,
                        'type' => null,
                        'field_order' => $order,
                        'is_primary' => $header === '帖子编号',
                        'metadata_encrypted' => ['source' => 'manual_csv'],
                        'synced_at' => $now,
                    ],
                );
            }

            $existing = collect();
            foreach (array_chunk(array_keys($rows), 500) as $recordIds) {
                $existing = $existing->merge(
                    FeishuBitableRecord::query()
                        ->where('feishu_bitable_table_id', $table->id)
                        ->whereIn('source_record_id', $recordIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('source_record_id'),
                );
            }
            $created = 0;
            $updated = 0;
            $unchanged = $duplicateUnchanged;
            $skippedStale = $duplicateStale;
            $outliers = 0;

            foreach ($rows as $recordId => $row) {
                $platform = $this->rowPlatform($row['fields'], $format);
                $fields = [...$row['fields'], '平台' => $platform];
                $existingRecord = $existing->get($recordId);

                if (! $existingRecord instanceof FeishuBitableRecord) {
                    FeishuBitableRecord::query()->create([
                        'organization_id' => $store->organization_id,
                        'store_id' => $store->id,
                        'feishu_bitable_table_id' => $table->id,
                        'source_record_id' => $recordId,
                        'fields_encrypted' => $fields,
                        'source_created_at' => $row['published_at'],
                        'source_updated_at' => $row['snapshot_at'],
                        'synced_at' => $now,
                    ]);
                    $created++;
                } else {
                    $decision = $this->freshnessDecision(
                        $fields,
                        $row['snapshot_at'],
                        $row['published_at'],
                        is_array($existingRecord->fields_encrypted) ? $existingRecord->fields_encrypted : [],
                        $existingRecord->source_updated_at
                            ? CarbonImmutable::instance($existingRecord->source_updated_at)
                            : null,
                        $existingRecord->source_created_at
                            ? CarbonImmutable::instance($existingRecord->source_created_at)
                            : null,
                    );

                    if ($decision === 'unchanged') {
                        $unchanged++;
                    } elseif ($decision === 'stale') {
                        $skippedStale++;
                    } else {
                        $existingRecord->update([
                            'organization_id' => $store->organization_id,
                            'store_id' => $store->id,
                            'fields_encrypted' => $fields,
                            'source_created_at' => $row['published_at'] ?? $existingRecord->source_created_at,
                            'source_updated_at' => $row['snapshot_at'] ?? $existingRecord->source_updated_at,
                            'synced_at' => $now,
                        ]);
                        $updated++;
                    }
                }

                $hidden = $this->rowHiddenState($fields);
                if ($hidden !== null) {
                    BrandSocialPostState::query()->updateOrCreate(
                        [
                            'store_id' => (int) $store->id,
                            'source_section' => self::SOURCE_SECTION,
                            'source_table_key' => $format['table_id'],
                            'source_record_id' => $recordId,
                        ],
                        [
                            'organization_id' => (int) $store->organization_id,
                            'is_hidden' => $hidden,
                            'hidden_by' => null,
                            'hidden_at' => $hidden ? $now : null,
                        ],
                    );
                }

                if ($platform === 'Instagram' && $this->instagramMetric($fields) > 100000) {
                    $outliers++;
                }
            }

            return [
                'platform' => $format['platform'],
                'rows' => count($csvRows),
                'unique_rows' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'skipped_stale' => $skippedStale,
                'outliers' => $outliers,
                'checksum' => $checksum,
            ];
        });
    }

    /** @return array{list<string>, list<array{record_id: string, fields: array<string, string>, row_number: int}>} */
    private function readCsv(string $path): array
    {
        $csv = new SplFileObject($path, 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl(',');

        $rawHeaders = $csv->fgetcsv();
        if (! is_array($rawHeaders)) {
            throw new RuntimeException('CSV 文件缺少表头。');
        }
        $headers = array_map(fn (mixed $header): string => $this->cleanCell($header, true), $rawHeaders);
        if ($headers === [] || in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
            throw new RuntimeException('CSV 表头为空或存在重复列，请使用平台原始导出文件。');
        }

        $rows = [];
        $rowNumber = 1;
        while (! $csv->eof()) {
            $values = $csv->fgetcsv();
            if (! is_array($values) || $values === [null]) {
                continue;
            }
            $rowNumber++;
            if ($rowNumber > self::MAX_ROWS + 1) {
                throw new RuntimeException('CSV 数据超过 50,000 行，请拆分后上传。');
            }
            if (count($values) !== count($headers)) {
                throw new RuntimeException("CSV 第 {$rowNumber} 行列数与表头不一致。");
            }

            $fields = [];
            foreach ($headers as $index => $header) {
                $fields[$header] = $this->cleanCell($values[$index] ?? null);
            }
            if (collect($fields)->every(fn (string $value): bool => $value === '')) {
                continue;
            }
            $postId = trim($fields['帖子编号'] ?? '');
            if ($postId === '') {
                throw new RuntimeException("CSV 第 {$rowNumber} 行缺少帖子编号。");
            }
            $rows[] = [
                'record_id' => 'post:'.$postId,
                'fields' => $fields,
                'row_number' => $rowNumber,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('CSV 文件没有可导入的数据行。');
        }

        return [$headers, $rows];
    }

    /**
     * @param  list<array{record_id: string, fields: array<string, string>, row_number: int}>  $rows
     * @return array{array<string, array{fields: array<string, string>, row_number: int, snapshot_at: ?CarbonImmutable, published_at: ?CarbonImmutable}>, int, int}
     */
    private function coalesceRows(array $rows, string $timezone): array
    {
        $selected = [];
        $unchanged = 0;
        $skippedStale = 0;

        foreach ($rows as $row) {
            $candidate = [
                ...$row,
                'snapshot_at' => $this->fieldDate($row['fields'], self::SNAPSHOT_FIELDS, $timezone),
                'published_at' => $this->fieldDate($row['fields'], self::PUBLISHED_FIELDS, $timezone),
            ];
            $existing = $selected[$row['record_id']] ?? null;
            if (! is_array($existing)) {
                $selected[$row['record_id']] = $candidate;

                continue;
            }

            $decision = $this->freshnessDecision(
                $candidate['fields'],
                $candidate['snapshot_at'],
                $candidate['published_at'],
                $existing['fields'],
                $existing['snapshot_at'],
                $existing['published_at'],
            );

            if ($decision === 'newer') {
                $selected[$row['record_id']] = $candidate;
                $skippedStale++;
            } elseif ($decision === 'unchanged') {
                $unchanged++;
            } else {
                $skippedStale++;
            }
        }

        return [$selected, $unchanged, $skippedStale];
    }

    /**
     * @param  array<string, string>  $incomingFields
     * @param  array<string, mixed>  $existingFields
     * @return 'newer'|'unchanged'|'stale'
     */
    private function freshnessDecision(
        array $incomingFields,
        ?CarbonImmutable $incomingSnapshot,
        ?CarbonImmutable $incomingPublished,
        array $existingFields,
        ?CarbonImmutable $existingSnapshot,
        ?CarbonImmutable $existingPublished,
    ): string {
        if ($incomingSnapshot && $existingSnapshot) {
            if ($incomingSnapshot->greaterThan($existingSnapshot)) {
                return 'newer';
            }
            if ($incomingSnapshot->lessThan($existingSnapshot)) {
                return 'stale';
            }
        } elseif ($incomingSnapshot && ! $existingSnapshot) {
            return $this->metricsRegress($incomingFields, $existingFields) ? 'stale' : 'newer';
        } elseif (! $incomingSnapshot && $existingSnapshot) {
            return 'stale';
        }

        if ($incomingPublished && $existingPublished) {
            if ($incomingPublished->greaterThan($existingPublished)) {
                return 'newer';
            }
            if ($incomingPublished->lessThan($existingPublished)) {
                return 'stale';
            }
        } elseif ($incomingPublished && ! $existingPublished) {
            return $this->metricsRegress($incomingFields, $existingFields) ? 'stale' : 'newer';
        } elseif (! $incomingPublished && $existingPublished) {
            return 'stale';
        }

        $metricComparison = $this->compareMetrics($incomingFields, $existingFields);
        if ($metricComparison === 'increased') {
            return 'newer';
        }
        if ($metricComparison === 'regressed') {
            return 'stale';
        }

        return hash_equals($this->fieldsFingerprint($existingFields), $this->fieldsFingerprint($incomingFields))
            ? 'unchanged'
            : 'stale';
    }

    /** @param array<string, mixed> $incoming @param array<string, mixed> $existing */
    private function metricsRegress(array $incoming, array $existing): bool
    {
        return $this->compareMetrics($incoming, $existing) === 'regressed';
    }

    /** @param array<string, mixed> $incoming @param array<string, mixed> $existing @return 'increased'|'equal'|'regressed' */
    private function compareMetrics(array $incoming, array $existing): string
    {
        $incomingMetrics = $this->metricVector($incoming);
        $existingMetrics = $this->metricVector($existing);
        $increased = false;

        foreach (array_keys(self::CUMULATIVE_METRICS) as $metric) {
            $incomingValue = $incomingMetrics[$metric];
            $existingValue = $existingMetrics[$metric];
            if ($incomingValue + 0.000001 < $existingValue) {
                return 'regressed';
            }
            if ($incomingValue > $existingValue + 0.000001) {
                $increased = true;
            }
        }

        return $increased ? 'increased' : 'equal';
    }

    /** @param array<string, mixed> $fields @return array<string, float> */
    private function metricVector(array $fields): array
    {
        $metrics = [];
        foreach (self::CUMULATIVE_METRICS as $metric => $aliases) {
            $value = $this->fieldValue($fields, $aliases);
            $number = preg_replace('/[^0-9.\-]/u', '', is_scalar($value) ? (string) $value : '');
            $metrics[$metric] = is_numeric($number) ? (float) $number : 0;
        }

        return $metrics;
    }

    /** @param array<string, mixed> $fields */
    private function fieldsFingerprint(array $fields): string
    {
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /** @param array<string, mixed> $fields @param list<string> $names */
    private function fieldValue(array $fields, array $names): mixed
    {
        foreach ($names as $name) {
            foreach ($fields as $key => $value) {
                if (mb_strtolower(trim((string) $key)) === mb_strtolower($name)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, string> $fields @param list<string> $names */
    private function fieldDate(array $fields, array $names, string $timezone): ?CarbonImmutable
    {
        $value = $this->fieldValue($fields, $names);
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse(trim((string) $value), $timezone)->utc();

            return $date->year >= 2000 && $date->year <= now()->addYear()->year ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $headers @return array{platform: string, table_id: string, table_name: string, required: list<string>} */
    private function detectFormat(array $headers): array
    {
        foreach (self::FORMATS as $format) {
            if (collect($format['required'])->every(fn (string $header): bool => in_array($header, $headers, true))) {
                return $format;
            }
        }

        throw new RuntimeException('无法识别 CSV：请上传 Meta Business Suite 原始导出文件或 DecoAdmin 品牌官媒模板。');
    }

    /**
     * @param  list<array{record_id: string, fields: array<string, string>, row_number: int}>  $rows
     * @param  array{platform: string, table_id: string, table_name: string, required: list<string>}  $format
     * @return list<array{record_id: string, fields: array<string, string>, row_number: int}>
     */
    private function scopeRecordIds(array $rows, array $format): array
    {
        if ($format['platform'] !== '跨平台') {
            return $rows;
        }

        return array_map(function (array $row) use ($format): array {
            $platform = $this->rowPlatform($row['fields'], $format);

            return [
                ...$row,
                'record_id' => 'post:'.mb_strtolower($platform).':'.mb_substr($row['record_id'], 5),
            ];
        }, $rows);
    }

    /** @param array<string, string> $fields @param array{platform: string, table_id: string, table_name: string, required: list<string>} $format */
    private function rowPlatform(array $fields, array $format): string
    {
        if ($format['platform'] !== '跨平台') {
            return $format['platform'];
        }

        $value = mb_strtoupper(trim((string) ($fields['平台'] ?? '')));
        $platform = match ($value) {
            'IG', 'INS', 'INSTAGRAM' => 'Instagram',
            'FB', 'FACEBOOK' => 'Facebook',
            'YT', 'YOUTUBE' => 'YouTube',
            default => '',
        };
        if ($platform === '') {
            throw new RuntimeException('DecoAdmin 模板中的平台必须是 Instagram、Facebook 或 YouTube。');
        }

        return $platform;
    }

    /** @param array<string, string> $fields */
    private function rowHiddenState(array $fields): ?bool
    {
        $raw = $this->fieldValue($fields, ['显示状态', 'visibility', '是否显示']);
        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }

        $value = mb_strtolower(trim((string) $raw));
        if (in_array($value, ['已隐藏', '隐藏', 'hidden', '否', 'no', 'false', '0'], true)) {
            return true;
        }
        if (in_array($value, ['可见', '显示', 'visible', '是', 'yes', 'true', '1'], true)) {
            return false;
        }

        throw new RuntimeException('显示状态只能填写“可见”或“已隐藏”。');
    }

    private function cleanCell(mixed $value, bool $header = false): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $text);
        if ($header) {
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        }

        return mb_substr(trim($text), 0, $header ? 255 : 20000);
    }

    /** @param array<string, string> $fields */
    private function instagramMetric(array $fields): float
    {
        $type = mb_strtolower($fields['帖子类型'] ?? '');
        $primary = str_contains($type, 'reel') || str_contains($type, '视频')
            ? ($fields['浏览量'] ?? '')
            : ($fields['曝光量'] ?? $fields['覆盖人数'] ?? $fields['浏览量'] ?? '');
        $number = preg_replace('/[^0-9.\-]/u', '', $primary);

        return is_numeric($number) ? (float) $number : 0;
    }
}
