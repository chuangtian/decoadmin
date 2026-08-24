<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoAnalyticsSyncRun;
use App\Models\SeoGa4ChannelDailyMetric;
use App\Models\SeoGa4LandingPageDailyMetric;
use App\Models\SeoGscBreakdownDailyMetric;
use App\Models\SeoGscDailyMetric;
use App\Models\SeoGscPageDailyMetric;
use App\Models\SeoGscQueryDailyMetric;
use App\Models\SeoGscSearchTypeDailyMetric;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SeoAnalyticsSyncService
{
    public function __construct(
        private GoogleSeoApiClient $google,
        private SeoAnalyticsConfigurationService $configuration,
    ) {}

    public function syncRange(Store $store, string $from, string $to): int
    {
        $status = $this->configuration->status($store);
        if (! $status['configured']) {
            throw new RuntimeException('GA4 / GSC 数据源配置不完整：'.implode(', ', $status['missing']));
        }

        $rangeFrom = CarbonImmutable::parse($from)->startOfDay();
        $rangeTo = CarbonImmutable::parse($to)->startOfDay();

        return $this->syncGa4($store, $rangeFrom, $rangeTo)
            + $this->syncGsc($store, $rangeFrom, $rangeTo);
    }

    /** @return array{processed_rows: int, chunks: int} */
    public function sync(Store $store, SeoAnalyticsSyncRun $run): array
    {
        $status = $this->configuration->status($store);
        if (! $status['configured']) {
            throw new RuntimeException('GA4 / GSC 数据源配置不完整：'.implode(', ', $status['missing']));
        }

        $from = CarbonImmutable::parse((string) $run->date_from)->startOfDay();
        $to = CarbonImmutable::parse((string) $run->date_to)->startOfDay();
        $chunks = $this->monthChunks($from, $to);
        $processed = 0;
        $run->forceFill(['status' => 'running', 'started_at' => now(), 'progress_percent' => 1, 'last_error' => null])->save();

        try {
            foreach ($chunks as $index => [$chunkFrom, $chunkTo]) {
                $processed += $this->syncGa4($store, $chunkFrom, $chunkTo);
                $run->forceFill([
                    'processed_rows' => $processed,
                    'progress_percent' => min(99, (int) floor(((($index * 2) + 1) / (count($chunks) * 2)) * 100)),
                ])->save();
                $processed += $this->syncGsc($store, $chunkFrom, $chunkTo);
                $run->forceFill([
                    'processed_rows' => $processed,
                    'progress_percent' => min(99, (int) floor(((($index + 1) * 2) / (count($chunks) * 2)) * 100)),
                ])->save();
            }

            $run->forceFill([
                'status' => 'completed',
                'progress_percent' => 100,
                'processed_rows' => $processed,
                'result' => ['processed_rows' => $processed, 'chunks' => count($chunks)],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'queued',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'failed_at' => null,
            ])->save();
            throw $exception;
        }

        return ['processed_rows' => $processed, 'chunks' => count($chunks)];
    }

    private function syncGa4(Store $store, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $config = $this->configuration->forStore($store);
        $dimension = (string) $config['ga4_channel_dimension'];
        $filter = [['dimension' => $dimension, 'values' => SeoAnalyticsConfigurationService::CHANNELS]];
        $channelRows = $this->google->ga4Rows(
            $store,
            $from->toDateString(),
            $to->toDateString(),
            ['date', $dimension],
            ['totalRevenue', 'sessions'],
            $filter,
        );
        $landingRows = $this->google->ga4Rows(
            $store,
            $from->toDateString(),
            $to->toDateString(),
            ['date', 'landingPage', $dimension],
            ['sessions', 'activeUsers', 'newUsers', 'userEngagementDuration', 'keyEvents', 'totalRevenue', 'bounceRate', 'sessionKeyEventRate'],
            $filter,
        );
        $now = now();
        $scope = ['organization_id' => $store->organization_id, 'store_id' => $store->getKey()];
        $channels = collect($channelRows)->map(fn (array $row): array => [
            ...$scope,
            'metric_date' => $this->gaDate((string) ($row['date'] ?? '')),
            'channel_group' => mb_substr(trim((string) ($row[$dimension] ?? '')), 0, 120),
            'total_revenue' => max(0, (float) ($row['totalRevenue'] ?? 0)),
            'sessions' => max(0, (int) ($row['sessions'] ?? 0)),
            'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->filter(fn (array $row): bool => $row['metric_date'] !== null && $row['channel_group'] !== '')->values()->all();
        $landings = collect($landingRows)->map(function (array $row) use ($scope, $dimension, $now): array {
            $page = trim((string) ($row['landingPage'] ?? '')) ?: '(not set)';

            return [
                ...$scope,
                'metric_date' => $this->gaDate((string) ($row['date'] ?? '')),
                'landing_page_hash' => hash('sha256', $page),
                'landing_page' => $page,
                'channel_group' => mb_substr(trim((string) ($row[$dimension] ?? '')), 0, 120),
                'sessions' => max(0, (int) ($row['sessions'] ?? 0)),
                'active_users' => max(0, (int) ($row['activeUsers'] ?? 0)),
                'new_users' => max(0, (int) ($row['newUsers'] ?? 0)),
                'engagement_duration' => max(0, (float) ($row['userEngagementDuration'] ?? 0)),
                'key_events' => max(0, (float) ($row['keyEvents'] ?? 0)),
                'total_revenue' => max(0, (float) ($row['totalRevenue'] ?? 0)),
                'bounce_rate' => min(1, max(0, (float) ($row['bounceRate'] ?? 0))),
                'session_key_event_rate' => min(1, max(0, (float) ($row['sessionKeyEventRate'] ?? 0))),
                'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        })->filter(fn (array $row): bool => $row['metric_date'] !== null && $row['channel_group'] !== '')->values()->all();

        DB::transaction(function () use ($store, $from, $to, $channels, $landings): void {
            SeoGa4ChannelDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
            SeoGa4LandingPageDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
            foreach (array_chunk($channels, 1000) as $chunk) {
                SeoGa4ChannelDailyMetric::query()->insert($chunk);
            }
            foreach (array_chunk($landings, 1000) as $chunk) {
                SeoGa4LandingPageDailyMetric::query()->insert($chunk);
            }
        });

        return count($channels) + count($landings);
    }

    private function syncGsc(Store $store, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $segments = [
            'total' => [],
            'brand' => [['dimension' => 'query', 'operator' => 'includingRegex', 'expression' => SeoAnalyticsConfigurationService::BRAND_REGEX]],
            'industry' => [['dimension' => 'query', 'operator' => 'excludingRegex', 'expression' => SeoAnalyticsConfigurationService::BRAND_REGEX]],
            'blog' => [['dimension' => 'page', 'operator' => 'contains', 'expression' => '/blogs/']],
        ];
        $now = now();
        $scope = ['organization_id' => $store->organization_id, 'store_id' => $store->getKey()];
        $processed = 0;

        foreach ($segments as $segment => $filters) {
            $daily = $this->google->gscRows($store, $from->toDateString(), $to->toDateString(), ['date'], $filters);
            $indexed = collect($daily)->keyBy(fn (array $row): string => (string) data_get($row, 'keys.0'));
            $dailyRecords = [];
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $row = $indexed->get($date->toDateString(), []);
                $dailyRecords[] = [
                    ...$scope, 'metric_date' => $date->toDateString(), 'segment' => $segment,
                    'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
                    'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
                    'average_position' => max(0, (float) ($row['position'] ?? 0)),
                    'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::transaction(function () use ($store, $from, $to, $segment, $dailyRecords): void {
                SeoGscDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', $segment)
                    ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
                SeoGscDailyMetric::query()->insert($dailyRecords);
            });
            $processed += count($dailyRecords);

            if (in_array($segment, ['brand', 'industry'], true)) {
                SeoGscQueryDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', $segment)
                    ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
                foreach ($this->google->gscRowPages($store, $from->toDateString(), $to->toDateString(), ['date', 'query'], $filters) as $rows) {
                    $records = $this->gscDimensionRecords($rows, $scope, $segment, 'query', $now);
                    foreach (array_chunk($records, 1000) as $chunk) {
                        SeoGscQueryDailyMetric::query()->insert($chunk);
                    }
                    $processed += count($records);
                }
            }

            SeoGscPageDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)->where('segment', $segment)
                ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
            foreach ($this->google->gscRowPages($store, $from->toDateString(), $to->toDateString(), ['date', 'page'], $filters) as $rows) {
                $records = $this->gscDimensionRecords($rows, $scope, $segment, 'page', $now);
                foreach (array_chunk($records, 1000) as $chunk) {
                    SeoGscPageDailyMetric::query()->insert($chunk);
                }
                $processed += count($records);
            }
        }

        return $processed + $this->syncExtendedGsc($store, $from, $to, $scope, $now);
    }

    /** @param array{organization_id: int, store_id: int} $scope */
    private function syncExtendedGsc(Store $store, CarbonImmutable $from, CarbonImmutable $to, array $scope, mixed $now): int
    {
        $typeRecords = [];
        foreach (['web', 'image', 'video', 'news'] as $searchType) {
            $rows = $this->google->gscRows($store, $from->toDateString(), $to->toDateString(), ['date'], [], $searchType);
            $indexed = collect($rows)->keyBy(fn (array $row): string => (string) data_get($row, 'keys.0'));
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $row = $indexed->get($date->toDateString(), []);
                $typeRecords[] = [
                    ...$scope,
                    'metric_date' => $date->toDateString(),
                    'search_type' => $searchType,
                    'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
                    'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
                    'average_position' => max(0, (float) ($row['position'] ?? 0)),
                    'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $breakdownRecords = [];
        foreach (['country', 'device', 'searchAppearance'] as $dimension) {
            foreach ($this->google->gscRowPages($store, $from->toDateString(), $to->toDateString(), ['date', $dimension]) as $rows) {
                foreach ($rows as $row) {
                    $date = (string) data_get($row, 'keys.0');
                    $value = trim((string) data_get($row, 'keys.1'));
                    if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        continue;
                    }
                    $breakdownRecords[] = [
                        ...$scope,
                        'metric_date' => $date,
                        'search_type' => 'web',
                        'dimension' => $dimension === 'searchAppearance' ? 'search_appearance' : $dimension,
                        'value_hash' => hash('sha256', $value),
                        'value' => mb_substr($value, 0, 1000),
                        'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
                        'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
                        'average_position' => max(0, (float) ($row['position'] ?? 0)),
                        'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        DB::transaction(function () use ($store, $from, $to, $typeRecords, $breakdownRecords): void {
            SeoGscSearchTypeDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
            SeoGscBreakdownDailyMetric::query()->forOrganization($store->organization_id)->forStore($store->id)
                ->whereDate('metric_date', '>=', $from->toDateString())->whereDate('metric_date', '<=', $to->toDateString())->delete();
            foreach (array_chunk($typeRecords, 1000) as $chunk) {
                SeoGscSearchTypeDailyMetric::query()->insert($chunk);
            }
            foreach (array_chunk($breakdownRecords, 1000) as $chunk) {
                SeoGscBreakdownDailyMetric::query()->insert($chunk);
            }
        });

        return count($typeRecords) + count($breakdownRecords);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{organization_id: int, store_id: int}  $scope
     * @return list<array<string, mixed>>
     */
    private function gscDimensionRecords(array $rows, array $scope, string $segment, string $dimension, mixed $now): array
    {
        $hashColumn = $dimension.'_hash';

        return collect($rows)->map(function (array $row) use ($scope, $segment, $dimension, $hashColumn, $now): ?array {
            $value = trim((string) data_get($row, 'keys.1'));
            $date = (string) data_get($row, 'keys.0');
            if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return null;
            }

            return [
                ...$scope,
                'metric_date' => $date,
                'segment' => $segment,
                $hashColumn => hash('sha256', $value),
                $dimension => $value,
                'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
                'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
                'average_position' => max(0, (float) ($row['position'] ?? 0)),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->filter()->values()->all();
    }

    /** @return list<array{CarbonImmutable, CarbonImmutable}> */
    private function monthChunks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $chunks = [];
        for ($cursor = $from; $cursor->lte($to); $cursor = $cursor->addMonthNoOverflow()->startOfMonth()) {
            $end = $cursor->endOfMonth()->min($to);
            $chunks[] = [$cursor, $end];
        }

        return array_reverse($chunks);
    }

    private function gaDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $match)) {
            return "{$match[1]}-{$match[2]}-{$match[3]}";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }
}
