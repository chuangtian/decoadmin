<?php

namespace App\Services;

use App\Models\SeoGoalWorkRecord;
use App\Models\Store;
use App\Services\Feishu\FeishuBitableArchiveSyncService;
use App\Services\Feishu\FeishuBitableClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SeoGoalDataSyncService
{
    private const WORK_SHEETS = [
        'new_blog' => ['token' => 'seo_work_new_blog_token', 'sheet' => 'seo_work_new_blog_sheet'],
        'old_blog' => ['token' => 'seo_work_old_blog_token', 'sheet' => 'seo_work_old_blog_sheet'],
        'backlinks' => ['token' => 'seo_work_backlinks_token', 'sheet' => 'seo_work_backlinks_sheet'],
        'ai_automation' => ['token' => 'seo_work_ai_token', 'sheet' => 'seo_work_ai_sheet'],
    ];

    public function __construct(
        private StoreFeishuDataLinkService $dataLinks,
        private FeishuBitableArchiveSyncService $archive,
        private FeishuBitableClient $client,
    ) {}

    /** @return array{daily_records: int, work_records: int, synced_at: string} */
    public function sync(Store $store): array
    {
        $daily = $this->dataLinks->valuesForSync($store, 'seo_geo');
        $work = $this->dataLinks->valuesForSync($store, 'seo_work');
        $appToken = trim((string) ($daily['seo_app_token'] ?? ''));
        $dailyTableId = trim((string) ($daily['seo_daily_table_id'] ?? ''));

        if ($appToken === '' || $dailyTableId === '') {
            throw new RuntimeException('当前店铺尚未完整配置 SEO 日度多维表格。');
        }

        foreach (self::WORK_SHEETS as $definition) {
            if (blank($work[$definition['token']] ?? null) || blank($work[$definition['sheet']] ?? null)) {
                throw new RuntimeException('当前店铺尚未完整配置 4 张 SEO 工作推进表。');
            }
        }

        $dailySummary = $this->archive->syncTableById($store, 'seo-goal', $appToken, $dailyTableId);
        $rowsBySource = [];

        foreach (self::WORK_SHEETS as $sourceKey => $definition) {
            $rowsBySource[$sourceKey] = $this->readWorkSheet(
                $sourceKey,
                trim((string) $work[$definition['token']]),
                trim((string) $work[$definition['sheet']]),
            );
        }

        $syncedAt = now();
        DB::transaction(function () use ($store, $rowsBySource, $syncedAt): void {
            SeoGoalWorkRecord::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->id)
                ->whereIn('source_key', array_keys(self::WORK_SHEETS))
                ->delete();

            foreach ($rowsBySource as $sourceKey => $rows) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    SeoGoalWorkRecord::query()->insert(array_map(
                        fn (array $row): array => [
                            ...$row,
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->id,
                            'source_key' => $sourceKey,
                            'synced_at' => $syncedAt,
                            'created_at' => $syncedAt,
                            'updated_at' => $syncedAt,
                        ],
                        $chunk,
                    ));
                }
            }
        });

        return [
            'daily_records' => $dailySummary['records'],
            'work_records' => array_sum(array_map(count(...), $rowsBySource)),
            'synced_at' => $syncedAt->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function readWorkSheet(string $sourceKey, string $token, string $sheetId): array
    {
        $sheet = collect($this->client->spreadsheetSheets($token))
            ->first(fn (array $item): bool => trim((string) ($item['sheet_id'] ?? '')) === $sheetId);

        if (! is_array($sheet)) {
            throw new RuntimeException("SEO 工作推进表 {$sourceKey} 的 Sheet ID 不存在。");
        }

        $rowCount = min(10000, max(1, (int) data_get($sheet, 'grid_properties.row_count', 2000)));
        $columnCount = min(200, max(1, (int) data_get($sheet, 'grid_properties.column_count', 52)));
        $values = $this->client->spreadsheetValues($token, $sheetId, $rowCount, $columnCount);

        return $this->normalizeRows($sourceKey, $values);
    }

    /** @param list<array<int, mixed>> $values @return list<array<string, mixed>> */
    private function normalizeRows(string $sourceKey, array $values): array
    {
        $requiredHeaders = match ($sourceKey) {
            'new_blog' => ['实际上线日', '博客URL'],
            'old_blog' => ['实际上线日', '旧博客URL'],
            'backlinks' => ['合作月份'],
            'ai_automation' => ['本月计入', '流程名称'],
            default => throw new RuntimeException("不支持的 SEO 工作表 {$sourceKey}。"),
        };
        [$headerRow, $columns] = $this->locateHeaders($values, $requiredHeaders);
        $normalized = [];

        foreach (array_slice($values, $headerRow + 1, null, true) as $index => $row) {
            $record = [
                'source_row' => $index + 1,
                'actual_date' => null,
                'cooperation_month' => null,
                'cooperation_month_number' => null,
                'name' => null,
                'url_present' => false,
                'included' => false,
            ];

            if ($sourceKey === 'new_blog' || $sourceKey === 'old_blog') {
                $record['actual_date'] = $this->toDate($row[$columns['实际上线日']] ?? null);
                $urlHeader = $sourceKey === 'new_blog' ? '博客URL' : '旧博客URL';
                $record['url_present'] = $this->hasContent($row[$columns[$urlHeader]] ?? null);
            } elseif ($sourceKey === 'backlinks') {
                [$yearMonth, $monthNumber] = $this->toYearMonth($row[$columns['合作月份']] ?? null);
                $record['cooperation_month'] = $yearMonth;
                $record['cooperation_month_number'] = $monthNumber;
            } else {
                $record['name'] = $this->limitedText($row[$columns['流程名称']] ?? null);
                $record['included'] = trim($this->cellText($row[$columns['本月计入']] ?? null)) === '计入';
            }

            if ($record['actual_date'] !== null || $record['cooperation_month'] !== null
                || $record['cooperation_month_number'] !== null || $record['name'] !== null
                || $record['url_present'] || $record['included']) {
                $normalized[] = $record;
            }
        }

        return $normalized;
    }

    /** @param list<array<int, mixed>> $values @param list<string> $headers @return array{int, array<string, int>} */
    private function locateHeaders(array $values, array $headers): array
    {
        foreach (array_slice($values, 0, 8, true) as $rowIndex => $row) {
            $columns = [];
            foreach ($row as $column => $cell) {
                $text = trim($this->cellText($cell));
                foreach ($headers as $header) {
                    if ($text === $header) {
                        $columns[$header] = (int) $column;
                    }
                }
            }
            if (count($columns) === count($headers)) {
                return [(int) $rowIndex, $columns];
            }
        }

        throw new RuntimeException('SEO 工作推进表缺少字段：'.implode('、', $headers).'。');
    }

    private function toDate(mixed $value): ?string
    {
        if (is_numeric($value)) {
            $number = (float) $value;
            if ($number > 100000000000) {
                return CarbonImmutable::createFromTimestampMs((int) $number, 'UTC')->setTimezone('Asia/Shanghai')->toDateString();
            }
            if ($number > 1000000000) {
                return CarbonImmutable::createFromTimestamp((int) $number, 'UTC')->setTimezone('Asia/Shanghai')->toDateString();
            }
            if ($number >= 40000 && $number <= 80000) {
                return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays((int) floor($number))->toDateString();
            }
        }

        $text = trim(str_replace('/', '-', $this->cellText($value)));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $matches) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createSafe((int) $matches[1], (int) $matches[2], (int) $matches[3])->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{string|null, int|null} */
    private function toYearMonth(mixed $value): array
    {
        $date = $this->toDate($value);
        if ($date !== null) {
            return [substr($date, 0, 7), (int) substr($date, 5, 2)];
        }

        $text = trim(str_replace('/', '-', $this->cellText($value)));
        if (preg_match('/(\d{4})-(\d{1,2})/', $text, $matches) === 1) {
            $month = max(1, min(12, (int) $matches[2]));

            return [sprintf('%04d-%02d', (int) $matches[1], $month), $month];
        }
        if (preg_match('/(?:^|\D)(\d{1,2})\s*月/u', $text, $matches) === 1
            || preg_match('/^(\d{1,2})$/', $text, $matches) === 1) {
            $month = (int) $matches[1];

            return [null, $month >= 1 && $month <= 12 ? $month : null];
        }

        return [null, null];
    }

    private function hasContent(mixed $value): bool
    {
        $text = trim($this->cellText($value));

        return $text !== '' && ! in_array($text, ['/', '-', '—'], true);
    }

    private function limitedText(mixed $value): ?string
    {
        $text = trim($this->cellText($value));

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return collect($value)->map(function (mixed $item): string {
                if (is_array($item)) {
                    if (array_key_exists('text', $item) || array_key_exists('link', $item)) {
                        return $this->cellText($item['text'] ?? $item['link']);
                    }

                    return collect($item)->map(fn (mixed $nested): string => $this->cellText($nested))->implode('');
                }

                return is_scalar($item) ? (string) $item : '';
            })->implode('');
        }

        return '';
    }
}
