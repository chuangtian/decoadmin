<?php

namespace App\Services\NaturalTraffic;

use App\Models\FeishuBitableField;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class BrandSocialCsvImportService
{
    public const SOURCE_SECTION = 'natural-traffic:social-manual';

    private const MAX_ROWS = 50000;

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
    ];

    /**
     * @return array{platform: string, rows: int, created: int, updated: int, outliers: int, checksum: string}
     */
    public function import(Store $store, UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw new RuntimeException('无法读取上传的 CSV 文件。');
        }

        [$headers, $rows] = $this->readCsv($path);
        $format = $this->detectFormat($headers);
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw new RuntimeException('无法校验上传的 CSV 文件。');
        }

        return DB::transaction(function () use ($store, $file, $headers, $rows, $format, $checksum): array {
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
                        'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'checksum' => $checksum,
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

            $existingIds = FeishuBitableRecord::query()
                ->where('feishu_bitable_table_id', $table->id)
                ->whereIn('source_record_id', array_keys($rows))
                ->pluck('source_record_id')
                ->all();
            $existing = array_fill_keys($existingIds, true);
            $created = 0;
            $updated = 0;
            $outliers = 0;

            foreach ($rows as $recordId => $fields) {
                $fields = ['平台' => $format['platform'], ...$fields];
                FeishuBitableRecord::query()->updateOrCreate(
                    ['feishu_bitable_table_id' => $table->id, 'source_record_id' => $recordId],
                    [
                        'organization_id' => $store->organization_id,
                        'store_id' => $store->id,
                        'fields_encrypted' => $fields,
                        'synced_at' => $now,
                    ],
                );

                isset($existing[$recordId]) ? $updated++ : $created++;
                if ($format['platform'] === 'Instagram' && $this->instagramMetric($fields) > 100000) {
                    $outliers++;
                }
            }

            return [
                'platform' => $format['platform'],
                'rows' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'outliers' => $outliers,
                'checksum' => $checksum,
            ];
        });
    }

    /** @return array{list<string>, array<string, array<string, string>>} */
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
            $rows['post:'.$postId] = $fields;
        }

        if ($rows === []) {
            throw new RuntimeException('CSV 文件没有可导入的数据行。');
        }

        return [$headers, $rows];
    }

    /** @param list<string> $headers @return array{platform: string, table_id: string, table_name: string, required: list<string>} */
    private function detectFormat(array $headers): array
    {
        foreach (self::FORMATS as $format) {
            if (collect($format['required'])->every(fn (string $header): bool => in_array($header, $headers, true))) {
                return $format;
            }
        }

        throw new RuntimeException('无法识别 CSV：请上传 Meta Business Suite 原始导出的 Instagram 或 Facebook 帖子数据。');
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
