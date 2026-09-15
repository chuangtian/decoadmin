<?php

namespace App\Services\Advertising;

use App\Events\AdvertisingChannelSyncStatusChanged;
use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\BingAdsCampaignDailyMetric;
use App\Models\CriteoCampaignDailyMetric;
use App\Models\GoogleAdsCampaignDailyMetric;
use App\Models\GoogleAdsKeywordDailyMetric;
use App\Models\GoogleAdsSearchTermDailyMetric;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Models\TikTokAdsAdDailyMetric;
use App\Models\TikTokAdsCampaignDailyMetric;
use App\Support\CurrentYearSyncWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdvertisingChannelSyncService
{
    /** @var array<string, array{provider: string, label: string, required: list<string>}> */
    public const CHANNELS = [
        'google' => ['provider' => 'google_ads', 'label' => 'Google Ads', 'required' => ['client_id', 'client_secret', 'refresh_token', 'developer_token', 'customer_id']],
        'tiktok' => ['provider' => 'tiktok_ads', 'label' => 'TikTok Ads', 'required' => ['access_token', 'advertiser_ids']],
        'bing' => ['provider' => 'bing_ads', 'label' => 'Bing Ads', 'required' => ['client_id', 'client_secret', 'refresh_token', 'developer_token', 'account_id']],
        'criteo' => ['provider' => 'criteo', 'label' => 'Criteo', 'required' => ['api_key', 'client_secret']],
    ];

    public function __construct(
        private AdvertisingChannelApiService $api,
        private CurrentYearSyncWindow $currentYear,
    ) {}

    public function sync(Store $store, string $channel, string $mode, ?string $credentialVersion = null): void
    {
        $this->assertChannel($channel);
        if (! in_array($mode, ['priority', 'backfill', 'incremental', 'attribution', 'reconcile', 'realtime'], true)
            || (in_array($mode, ['attribution', 'reconcile', 'realtime'], true) && $channel !== 'google')) {
            throw new RuntimeException('广告渠道同步模式无效。');
        }
        if ($store->status !== 'active' || ! $this->configured($store, $channel)) {
            return;
        }
        if ($credentialVersion !== null && ! hash_equals($credentialVersion, $this->credentialVersion($store, $channel))) {
            return;
        }
        if ($this->cooldownSeconds($store, $channel, $credentialVersion) > 0) {
            return;
        }

        [$since, $until] = $this->period($store, $mode, $channel);
        $chunks = $this->chunks($since, $until);
        $job = $this->startJob($store, $channel, $mode, $since, $until, count($chunks), $credentialVersion);
        $this->broadcastStatus($store, $channel, 'started', $mode, 0);
        // Resume the saved reporting window, even if a long cooldown crosses midnight.
        $chunks = $this->chunks(CarbonImmutable::instance($job->since_at)->setTimezone($since->getTimezone()),
            CarbonImmutable::instance($job->until_at)->setTimezone($until->getTimezone()));

        try {
            $processed = min(count($chunks), max(0, (int) $job->processed_items));
            $records = max(0, (int) data_get($job->result, 'records_count', 0));
            foreach (array_slice($chunks, $processed) as [$chunkFrom, $chunkTo]) {
                $this->assertCredentialVersion($store, $channel, $credentialVersion);
                $payload = in_array($mode, ['attribution', 'realtime'], true)
                    ? $this->api->syncPayload($store, $channel, $chunkFrom, $chunkTo, true)
                    : $this->api->syncPayload($store, $channel, $chunkFrom, $chunkTo);
                $this->assertCredentialVersion($store, $channel, $credentialVersion);
                $replaceGoogleDetails = $channel === 'google'
                    && ! in_array($mode, ['attribution', 'realtime'], true);
                $records += $this->persist(
                    $store,
                    $channel,
                    $payload,
                    $replaceGoogleDetails ? $chunkFrom : null,
                    $replaceGoogleDetails ? $chunkTo : null,
                );
                $processed++;
                $job->forceFill([
                    'processed_items' => $processed,
                    'result' => ['records_count' => $records, 'last_chunk' => [$chunkFrom, $chunkTo]],
                ])->save();
                $this->broadcastStatus(
                    $store,
                    $channel,
                    'progress',
                    $mode,
                    count($chunks) > 0 ? round(($processed / count($chunks)) * 100, 1) : 100,
                );
            }

            $this->completeJob($job, $mode, $records);
            $this->broadcastStatus($store, $channel, 'completed', $mode, 100);
            if ($mode === 'priority') {
                SyncAdvertisingChannelForStore::dispatch(
                    (int) $store->organization_id,
                    (int) $store->getKey(),
                    $channel,
                    'backfill',
                    $credentialVersion,
                )->delay(now()->addSeconds(30));
            }
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);
            $this->broadcastStatus($store, $channel, 'failed', $mode, message: '广告数据同步失败。');
            throw $exception;
        }
    }

    public function configured(Store $store, string $channel): bool
    {
        $definition = $this->definition($channel);
        $configuredKeys = StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('provider', $definition['provider'])
            ->whereIn('credential_key', $definition['required'])
            ->pluck('credential_key');

        return collect($definition['required'])->every(fn (string $key): bool => $configuredKeys->contains($key));
    }

    public function cooldownSeconds(Store $store, string $channel, ?string $credentialVersion = null): int
    {
        if ($channel !== 'criteo') {
            return 0;
        }
        $state = StoreSyncState::query()->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)->where('sync_type', $this->syncType($channel))->with('lastJob')->first();
        if (! $state || ! AdvertisingSyncFailurePolicy::isTransient($state->last_error_code)
            || ! $state->next_sync_at?->isFuture()
            || data_get($state->lastJob?->payload, 'credential_version') !== $credentialVersion) {
            return 0;
        }

        return (int) ceil(now()->diffInSeconds($state->next_sync_at));
    }

    public function resumeMode(Store $store, string $channel, string $mode, ?string $credentialVersion): string
    {
        if ($channel !== 'criteo' || $mode !== 'incremental') {
            return $mode;
        }
        $state = StoreSyncState::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('sync_type', $this->syncType($channel))->with('lastJob')->first();
        $job = $state?->lastJob;
        if ($job?->status === 'failed' && $job->created_at->gte(now()->subHours(48))
            && AdvertisingSyncFailurePolicy::isTransient($job->error_code)
            && data_get($job->payload, 'credential_version') === $credentialVersion
            && in_array($job->mode, ['priority', 'backfill'], true)) {
            return $job->mode;
        }

        return $mode;
    }

    public function channelForProvider(string $provider): ?string
    {
        foreach (self::CHANNELS as $channel => $definition) {
            if ($definition['provider'] === $provider) {
                return $channel;
            }
        }

        return null;
    }

    public function credentialVersion(Store $store, string $channel): string
    {
        $provider = $this->definition($channel)['provider'];
        $versions = StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('provider', $provider)
            ->orderBy('credential_key')
            ->get(['credential_key', 'updated_at'])
            ->map(fn (StoreBusinessCredential $credential): string => $credential->credential_key.':'.($credential->updated_at?->utc()->format('Y-m-d H:i:s.u') ?? ''))
            ->implode('|');

        return hash('sha256', $versions);
    }

    public function syncType(string $channel): string
    {
        $this->assertChannel($channel);

        return "advertising_channel:{$channel}";
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Store $store, string $mode, string $channel): array
    {
        $timezone = $channel === 'criteo' ? 'UTC' : ($store->timezone ?: 'UTC');
        $now = CarbonImmutable::now($timezone)->startOfHour();
        $priorityDays = max(1, (int) config('services.advertising_sync.priority_days', 7));
        $rollingDays = max(1, (int) config(
            $channel === 'google'
                ? 'services.advertising_sync.google_rolling_days'
                : 'services.advertising_sync.rolling_days',
            $channel === 'google' ? 8 : 3,
        ));

        $range = match ($mode) {
            'realtime' => [$now->startOfDay(), $now],
            'attribution' => [
                $now->subDays(max(1, (int) config('services.advertising_sync.google_attribution_days', 30)) - 1)->startOfDay(),
                $now,
            ],
            'priority' => [$now->subDays($priorityDays - 1)->startOfDay(), $now],
            'backfill' => [
                $now->subMonthsNoOverflow(max(1, (int) config('services.advertising_sync.history_months', 6)))->startOfDay(),
                $now->subDays($priorityDays)->endOfDay(),
            ],
            // Refresh historical attribution separately from the lightweight hourly sync.
            'reconcile' => [
                $now->subDays(max(1, (int) config('services.advertising_sync.google_reconcile_days', 90)) - 1)->startOfDay(),
                $now,
            ],
            'incremental' => [
                $now->subDays($rollingDays - 1)->startOfDay(),
                $now,
            ],
        };

        return $this->currentYear->clampGeneratedRange($range[0], $range[1], $timezone);
    }

    /** @return list<array{0: string, 1: string}> */
    private function chunks(CarbonImmutable $since, CarbonImmutable $until): array
    {
        if ($since->gt($until)) {
            return [];
        }

        $chunks = [];
        $cursor = $since->startOfDay();
        $lastDate = $until->toDateString();
        $days = max(1, (int) config('services.advertising_sync.chunk_days', 31));
        while ($cursor->toDateString() <= $lastDate) {
            $chunkEnd = $cursor->addDays($days - 1);
            if ($chunkEnd->toDateString() > $lastDate) {
                $chunkEnd = CarbonImmutable::parse($lastDate, $cursor->getTimezone());
            }
            $chunks[] = [$cursor->toDateString(), $chunkEnd->toDateString()];
            $cursor = $chunkEnd->addDay();
        }

        return $chunks;
    }

    /** @param array{accounts: list<array<string, mixed>>, daily_metrics: list<array<string, mixed>>, campaign_daily_metrics?: list<array<string, mixed>>, ad_daily_metrics?: list<array<string, mixed>>, search_term_daily_metrics?: list<array<string, mixed>>, keyword_daily_metrics?: list<array<string, mixed>>} $payload */
    private function persist(
        Store $store,
        string $channel,
        array $payload,
        ?string $replaceGoogleDetailsFrom = null,
        ?string $replaceGoogleDetailsTo = null,
    ): int {
        return DB::transaction(function () use ($store, $channel, $payload, $replaceGoogleDetailsFrom, $replaceGoogleDetailsTo): int {
            $timestamp = now();
            $accounts = collect($payload['accounts'])
                ->filter(fn (array $account): bool => filled($account['external_account_id'] ?? null))
                ->keyBy(fn (array $account): string => (string) $account['external_account_id']);
            foreach ($payload['daily_metrics'] as $metric) {
                $accountId = trim((string) ($metric['external_account_id'] ?? ''));
                if ($accountId !== '' && ! $accounts->has($accountId)) {
                    $accounts->put($accountId, ['external_account_id' => $accountId]);
                }
            }
            foreach ((array) ($payload['campaign_daily_metrics'] ?? []) as $metric) {
                $accountId = trim((string) ($metric['external_account_id'] ?? ''));
                if ($accountId !== '' && ! $accounts->has($accountId)) {
                    $accounts->put($accountId, ['external_account_id' => $accountId]);
                }
            }
            foreach ((array) ($payload['ad_daily_metrics'] ?? []) as $metric) {
                $accountId = trim((string) ($metric['external_account_id'] ?? ''));
                if ($accountId !== '' && ! $accounts->has($accountId)) {
                    $accounts->put($accountId, ['external_account_id' => $accountId]);
                }
            }
            foreach (['search_term_daily_metrics', 'keyword_daily_metrics'] as $metricGroup) {
                foreach ((array) ($payload[$metricGroup] ?? []) as $metric) {
                    $accountId = trim((string) ($metric['external_account_id'] ?? ''));
                    if ($accountId !== '' && ! $accounts->has($accountId)) {
                        $accounts->put($accountId, ['external_account_id' => $accountId]);
                    }
                }
            }

            AdvertisingChannelAccount::query()->upsert(
                $accounts->map(fn (array $account): array => [
                    'organization_id' => (int) $store->organization_id,
                    'store_id' => (int) $store->getKey(),
                    'provider' => $channel,
                    'external_account_id' => (string) $account['external_account_id'],
                    'name' => $this->text($account['name'] ?? null, 255),
                    'status' => $this->text($account['status'] ?? null, 50),
                    'currency' => $this->text($account['currency'] ?? null, 3),
                    'timezone' => $this->text($account['timezone'] ?? null, 100),
                    'raw_payload' => json_encode($account['raw_payload'] ?? $account, JSON_THROW_ON_ERROR),
                    'last_seen_at' => $timestamp,
                    'synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->values()->all(),
                ['organization_id', 'store_id', 'provider', 'external_account_id'],
                ['name', 'status', 'currency', 'timezone', 'raw_payload', 'last_seen_at', 'synced_at', 'updated_at'],
            );

            $accountIds = AdvertisingChannelAccount::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->getKey())
                ->where('provider', $channel)
                ->whereIn('external_account_id', $accounts->keys())
                ->pluck('id', 'external_account_id');

            if ($channel === 'google'
                && $replaceGoogleDetailsFrom !== null
                && $replaceGoogleDetailsTo !== null
                && $accountIds->isNotEmpty()) {
                foreach ([GoogleAdsCampaignDailyMetric::class, GoogleAdsSearchTermDailyMetric::class, GoogleAdsKeywordDailyMetric::class] as $model) {
                    $model::query()
                        ->where('organization_id', $store->organization_id)
                        ->where('store_id', $store->getKey())
                        ->whereIn('advertising_channel_account_id', $accountIds->values())
                        ->whereBetween('metric_date', [$replaceGoogleDetailsFrom, $replaceGoogleDetailsTo])
                        ->delete();
                }
            }
            $rows = collect($payload['daily_metrics'])->map(function (array $metric) use ($store, $channel, $accountIds, $timestamp): ?array {
                $externalId = trim((string) ($metric['external_account_id'] ?? ''));
                $date = trim((string) ($metric['date'] ?? ''));
                $accountId = $accountIds->get($externalId);
                if (! $accountId || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    return null;
                }

                return [
                    'organization_id' => (int) $store->organization_id,
                    'store_id' => (int) $store->getKey(),
                    'advertising_channel_account_id' => (int) $accountId,
                    'provider' => $channel,
                    'external_account_id' => $externalId,
                    'metric_date' => $date,
                    'spend' => max(0, (float) ($metric['spend'] ?? 0)),
                    'attributed_sales' => max(0, (float) ($metric['attributed_sales'] ?? 0)),
                    'conversion_value_by_conversion_date' => max(0, (float) ($metric['conversion_value_by_conversion_date'] ?? 0)),
                    'impressions' => max(0, (int) ($metric['impressions'] ?? 0)),
                    'clicks' => max(0, (int) ($metric['clicks'] ?? 0)),
                    'conversions' => max(0, (float) ($metric['conversions'] ?? 0)),
                    'all_conversions' => max(0, (float) ($metric['all_conversions'] ?? 0)),
                    'all_conversions_value' => max(0, (float) ($metric['all_conversions_value'] ?? 0)),
                    'all_conversions_value_by_conversion_date' => max(0, (float) ($metric['all_conversions_value_by_conversion_date'] ?? 0)),
                    'add_to_cart' => max(0, (float) ($metric['add_to_cart'] ?? 0)),
                    'initiate_checkout' => max(0, (float) ($metric['initiate_checkout'] ?? 0)),
                    'raw_payload' => json_encode($metric['raw_payload'] ?? $metric, JSON_THROW_ON_ERROR),
                    'synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })->filter()->values()->all();

            AdvertisingChannelDailyMetric::query()->upsert(
                $rows,
                ['organization_id', 'store_id', 'provider', 'external_account_id', 'metric_date'],
                ['advertising_channel_account_id', 'spend', 'attributed_sales', 'conversion_value_by_conversion_date', 'impressions', 'clicks', 'conversions', 'all_conversions', 'all_conversions_value', 'all_conversions_value_by_conversion_date', 'add_to_cart', 'initiate_checkout', 'raw_payload', 'synced_at', 'updated_at'],
            );

            $campaignRows = collect((array) ($payload['campaign_daily_metrics'] ?? []))
                ->map(function (array $metric) use ($store, $channel, $accountIds, $timestamp): ?array {
                    $externalId = trim((string) ($metric['external_account_id'] ?? ''));
                    $campaignId = trim((string) ($metric['campaign_id'] ?? ''));
                    $date = trim((string) ($metric['date'] ?? ''));
                    $accountId = $accountIds->get($externalId);
                    if (! $accountId || $campaignId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        return null;
                    }

                    $base = [
                        'organization_id' => (int) $store->organization_id,
                        'store_id' => (int) $store->getKey(),
                        'advertising_channel_account_id' => (int) $accountId,
                        'external_account_id' => $externalId,
                        'campaign_id' => $campaignId,
                        'campaign_name' => $this->text($metric['campaign_name'] ?? null, 500),
                        'campaign_status' => $this->text($metric['campaign_status'] ?? null, 50),
                        'metric_date' => $date,
                        'spend' => max(0, (float) ($metric['spend'] ?? 0)),
                        'impressions' => max(0, (int) ($metric['impressions'] ?? 0)),
                        'clicks' => max(0, (int) ($metric['clicks'] ?? 0)),
                        'conversions' => max(0, (float) ($metric['conversions'] ?? 0)),
                        'raw_payload' => json_encode($metric['raw_payload'] ?? $metric, JSON_THROW_ON_ERROR),
                        'synced_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];

                    if ($channel === 'tiktok') {
                        return [
                            ...$base,
                            'objective_type' => $this->text($metric['objective_type'] ?? null, 100),
                            'attributed_sales' => max(0, (float) ($metric['attributed_sales'] ?? 0)),
                        ];
                    }

                    if ($channel === 'bing') {
                        return [
                            ...$base,
                            'campaign_type' => $this->text($metric['campaign_type'] ?? null, 100),
                            'attributed_sales' => max(0, (float) ($metric['attributed_sales'] ?? 0)),
                        ];
                    }

                    if ($channel === 'criteo') {
                        return [
                            ...$base,
                            'attributed_sales' => max(0, (float) ($metric['attributed_sales'] ?? 0)),
                        ];
                    }

                    return [
                        ...$base,
                        'advertising_channel_type' => $this->text($metric['advertising_channel_type'] ?? null, 100),
                        'conversions_value' => max(0, (float) ($metric['conversions_value'] ?? 0)),
                        'conversion_value_by_conversion_date' => max(0, (float) ($metric['conversion_value_by_conversion_date'] ?? 0)),
                        'all_conversions' => max(0, (float) ($metric['all_conversions'] ?? 0)),
                        'all_conversions_value' => max(0, (float) ($metric['all_conversions_value'] ?? 0)),
                        'all_conversions_value_by_conversion_date' => max(0, (float) ($metric['all_conversions_value_by_conversion_date'] ?? 0)),
                    ];
                })->filter()->values()->all();

            if ($channel === 'google') {
                GoogleAdsCampaignDailyMetric::query()->upsert(
                    $campaignRows,
                    ['organization_id', 'store_id', 'external_account_id', 'campaign_id', 'metric_date'],
                    ['advertising_channel_account_id', 'campaign_name', 'campaign_status', 'advertising_channel_type', 'spend', 'impressions', 'clicks', 'conversions', 'conversions_value', 'conversion_value_by_conversion_date', 'all_conversions', 'all_conversions_value', 'all_conversions_value_by_conversion_date', 'raw_payload', 'synced_at', 'updated_at'],
                );
            } elseif ($channel === 'tiktok') {
                TikTokAdsCampaignDailyMetric::query()->upsert(
                    $campaignRows,
                    ['organization_id', 'store_id', 'external_account_id', 'campaign_id', 'metric_date'],
                    ['advertising_channel_account_id', 'campaign_name', 'campaign_status', 'objective_type', 'spend', 'attributed_sales', 'impressions', 'clicks', 'conversions', 'raw_payload', 'synced_at', 'updated_at'],
                );
            } elseif ($channel === 'bing') {
                BingAdsCampaignDailyMetric::query()->upsert(
                    $campaignRows,
                    ['organization_id', 'store_id', 'external_account_id', 'campaign_id', 'metric_date'],
                    ['advertising_channel_account_id', 'campaign_name', 'campaign_status', 'campaign_type', 'spend', 'attributed_sales', 'impressions', 'clicks', 'conversions', 'raw_payload', 'synced_at', 'updated_at'],
                );
            } elseif ($channel === 'criteo') {
                CriteoCampaignDailyMetric::query()->upsert(
                    $campaignRows,
                    ['organization_id', 'store_id', 'external_account_id', 'campaign_id', 'metric_date'],
                    ['advertising_channel_account_id', 'campaign_name', 'campaign_status', 'spend', 'attributed_sales', 'impressions', 'clicks', 'conversions', 'raw_payload', 'synced_at', 'updated_at'],
                );
            }

            $adRows = [];
            if ($channel === 'tiktok') {
                $adRows = collect((array) ($payload['ad_daily_metrics'] ?? []))
                    ->map(function (array $metric) use ($store, $accountIds, $timestamp): ?array {
                        $externalId = trim((string) ($metric['external_account_id'] ?? ''));
                        $adId = trim((string) ($metric['ad_id'] ?? ''));
                        $date = trim((string) ($metric['date'] ?? ''));
                        $accountId = $accountIds->get($externalId);
                        if (! $accountId || $adId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            return null;
                        }

                        return [
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->getKey(),
                            'advertising_channel_account_id' => (int) $accountId,
                            'external_account_id' => $externalId,
                            'campaign_id' => $this->text($metric['campaign_id'] ?? null, 128),
                            'campaign_name' => $this->text($metric['campaign_name'] ?? null, 500),
                            'adgroup_id' => $this->text($metric['adgroup_id'] ?? null, 128),
                            'ad_id' => $adId,
                            'ad_name' => $this->text($metric['ad_name'] ?? null, 500),
                            'ad_text' => $this->text($metric['ad_text'] ?? null, 65535),
                            'ad_texts' => json_encode(array_values((array) ($metric['ad_texts'] ?? [])), JSON_THROW_ON_ERROR),
                            'ad_format' => $this->text($metric['ad_format'] ?? null, 100),
                            'video_id' => $this->text($metric['video_id'] ?? null, 128),
                            'metric_date' => $date,
                            'spend' => max(0, (float) ($metric['spend'] ?? 0)),
                            'attributed_sales' => max(0, (float) ($metric['attributed_sales'] ?? 0)),
                            'impressions' => max(0, (int) ($metric['impressions'] ?? 0)),
                            'clicks' => max(0, (int) ($metric['clicks'] ?? 0)),
                            'conversions' => max(0, (float) ($metric['conversions'] ?? 0)),
                            'video_play_actions' => max(0, (int) ($metric['video_play_actions'] ?? 0)),
                            'video_watched_2s' => max(0, (int) ($metric['video_watched_2s'] ?? 0)),
                            'average_video_play' => max(0, (float) ($metric['average_video_play'] ?? 0)),
                            'raw_payload' => json_encode($metric['raw_payload'] ?? $metric, JSON_THROW_ON_ERROR),
                            'synced_at' => $timestamp,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    })->filter()->values()->all();

                TikTokAdsAdDailyMetric::query()->upsert(
                    $adRows,
                    ['organization_id', 'store_id', 'external_account_id', 'ad_id', 'metric_date'],
                    ['advertising_channel_account_id', 'campaign_id', 'campaign_name', 'adgroup_id', 'ad_name', 'ad_text', 'ad_texts', 'ad_format', 'video_id', 'spend', 'attributed_sales', 'impressions', 'clicks', 'conversions', 'video_play_actions', 'video_watched_2s', 'average_video_play', 'raw_payload', 'synced_at', 'updated_at'],
                );
            }

            $searchTermRows = [];
            $keywordRows = [];
            if ($channel === 'google') {
                $searchTermRows = collect((array) ($payload['search_term_daily_metrics'] ?? []))
                    ->map(function (array $metric) use ($store, $accountIds, $timestamp): ?array {
                        $externalId = trim((string) ($metric['external_account_id'] ?? ''));
                        $date = trim((string) ($metric['date'] ?? ''));
                        $dimensionKey = trim((string) ($metric['dimension_key'] ?? ''));
                        $searchTerm = trim((string) ($metric['search_term'] ?? ''));
                        $accountId = $accountIds->get($externalId);
                        if (! $accountId || $dimensionKey === '' || $searchTerm === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            return null;
                        }

                        return [
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->getKey(),
                            'advertising_channel_account_id' => (int) $accountId,
                            'external_account_id' => $externalId,
                            'metric_date' => $date,
                            'dimension_key' => mb_substr($dimensionKey, 0, 64),
                            'source_type' => $this->text($metric['source_type'] ?? 'STANDARD', 40) ?? 'STANDARD',
                            'search_term' => $searchTerm,
                            'normalized_search_term' => mb_strtolower($searchTerm),
                            'status' => $this->text($metric['status'] ?? null, 50),
                            'matched_keyword' => $this->text($metric['matched_keyword'] ?? null, 65535),
                            'match_type' => $this->text($metric['match_type'] ?? null, 50),
                            'campaign_id' => $this->text($metric['campaign_id'] ?? null, 128),
                            'campaign_name' => $this->text($metric['campaign_name'] ?? null, 500),
                            'ad_group_id' => $this->text($metric['ad_group_id'] ?? null, 128),
                            'ad_group_name' => $this->text($metric['ad_group_name'] ?? null, 500),
                            'advertising_channel_type' => $this->text($metric['advertising_channel_type'] ?? null, 100),
                            ...$this->performanceMetricValues($metric),
                            'raw_payload' => json_encode($metric['raw_payload'] ?? $metric, JSON_THROW_ON_ERROR),
                            'synced_at' => $timestamp,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    })->filter()->values()->all();

                $keywordRows = collect((array) ($payload['keyword_daily_metrics'] ?? []))
                    ->map(function (array $metric) use ($store, $accountIds, $timestamp): ?array {
                        $externalId = trim((string) ($metric['external_account_id'] ?? ''));
                        $date = trim((string) ($metric['date'] ?? ''));
                        $dimensionKey = trim((string) ($metric['dimension_key'] ?? ''));
                        $keyword = trim((string) ($metric['keyword'] ?? ''));
                        $accountId = $accountIds->get($externalId);
                        if (! $accountId || $dimensionKey === '' || $keyword === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            return null;
                        }

                        return [
                            'organization_id' => (int) $store->organization_id,
                            'store_id' => (int) $store->getKey(),
                            'advertising_channel_account_id' => (int) $accountId,
                            'external_account_id' => $externalId,
                            'metric_date' => $date,
                            'dimension_key' => mb_substr($dimensionKey, 0, 64),
                            'criterion_id' => $this->text($metric['criterion_id'] ?? null, 128),
                            'keyword' => $keyword,
                            'normalized_keyword' => mb_strtolower($keyword),
                            'match_type' => $this->text($metric['match_type'] ?? null, 50),
                            'status' => $this->text($metric['status'] ?? null, 50),
                            'campaign_id' => $this->text($metric['campaign_id'] ?? null, 128),
                            'campaign_name' => $this->text($metric['campaign_name'] ?? null, 500),
                            'ad_group_id' => $this->text($metric['ad_group_id'] ?? null, 128),
                            'ad_group_name' => $this->text($metric['ad_group_name'] ?? null, 500),
                            ...$this->performanceMetricValues($metric),
                            'raw_payload' => json_encode($metric['raw_payload'] ?? $metric, JSON_THROW_ON_ERROR),
                            'synced_at' => $timestamp,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    })->filter()->values()->all();

                GoogleAdsSearchTermDailyMetric::query()->upsert(
                    $searchTermRows,
                    ['organization_id', 'store_id', 'external_account_id', 'metric_date', 'dimension_key'],
                    ['advertising_channel_account_id', 'source_type', 'search_term', 'normalized_search_term', 'status', 'matched_keyword', 'match_type', 'campaign_id', 'campaign_name', 'ad_group_id', 'ad_group_name', 'advertising_channel_type', 'spend', 'revenue', 'impressions', 'clicks', 'conversions', 'raw_payload', 'synced_at', 'updated_at'],
                );
                GoogleAdsKeywordDailyMetric::query()->upsert(
                    $keywordRows,
                    ['organization_id', 'store_id', 'external_account_id', 'metric_date', 'dimension_key'],
                    ['advertising_channel_account_id', 'criterion_id', 'keyword', 'normalized_keyword', 'match_type', 'status', 'campaign_id', 'campaign_name', 'ad_group_id', 'ad_group_name', 'spend', 'revenue', 'impressions', 'clicks', 'conversions', 'raw_payload', 'synced_at', 'updated_at'],
                );
            }

            return count($accounts) + count($rows) + count($campaignRows) + count($adRows) + count($searchTermRows) + count($keywordRows);
        });
    }

    private function startJob(Store $store, string $channel, string $mode, CarbonImmutable $since, CarbonImmutable $until, int $chunks, ?string $credentialVersion): SyncJob
    {
        return DB::transaction(function () use ($store, $channel, $mode, $since, $until, $chunks, $credentialVersion): SyncJob {
            $existing = SyncJob::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->getKey())
                ->where('type', $this->syncType($channel))
                ->where('mode', $mode)
                ->when($mode === 'reconcile', fn ($query) => $query
                    ->where('since_at', $since->utc())
                    ->where('until_at', $until->utc()))
                ->whereIn('status', ['running', 'failed'])
                ->where('created_at', '>=', now()->subHours(48))
                ->latest('id')
                ->get()
                ->first(fn (SyncJob $job): bool => data_get($job->payload, 'credential_version') === $credentialVersion);
            if ($existing) {
                $existing->forceFill([
                    'status' => 'running',
                    'attempts' => $existing->attempts + 1,
                    'failed_items' => 0,
                    'failed_at' => null,
                    'finished_at' => null,
                    'last_error' => null,
                    'error_code' => null,
                ])->save();
                StoreSyncState::query()->updateOrCreate([
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->getKey(),
                    'sync_type' => $this->syncType($channel),
                ], [
                    'status' => 'running',
                    'last_job_id' => $existing->getKey(),
                    'last_error_code' => null,
                    'last_error' => null,
                ]);

                return $existing;
            }

            $job = SyncJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'type' => $this->syncType($channel),
                'direction' => 'pull',
                'mode' => $mode,
                'status' => 'running',
                'since_at' => $since->utc(),
                'until_at' => $until->utc(),
                'payload' => ['channel' => $channel, 'credential_version' => $credentialVersion, 'strategy' => 'monthly_chunks'],
                'total_items' => $chunks,
                'processed_items' => 0,
                'failed_items' => 0,
                'attempts' => 1,
                'max_attempts' => 3,
                'available_at' => now(),
                'started_at' => now(),
            ]);
            StoreSyncState::query()->updateOrCreate([
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'sync_type' => $this->syncType($channel),
            ], [
                'status' => 'running',
                'last_job_id' => $job->getKey(),
                'last_error_code' => null,
                'last_error' => null,
            ]);

            return $job;
        });
    }

    private function completeJob(SyncJob $job, string $mode, int $records): void
    {
        DB::transaction(function () use ($job, $mode, $records): void {
            $finishedAt = now();
            $job->forceFill([
                'status' => 'completed',
                'processed_items' => $job->total_items,
                'result' => ['success' => true, 'records_count' => $records],
                'finished_at' => $finishedAt,
                'completed_at' => $finishedAt,
                'last_error' => null,
                'error_code' => null,
            ])->save();
            $state = StoreSyncState::query()->where('last_job_id', $job->getKey())->lockForUpdate()->firstOrFail();
            $attributes = [
                'status' => $mode === 'priority' ? 'ready' : 'idle',
                'watermark_at' => $job->until_at,
                'last_success_at' => $finishedAt,
                'consecutive_failures' => 0,
                'next_sync_at' => $finishedAt->copy()->addHour(),
                'last_error_code' => null,
                'last_error' => null,
            ];
            if ($mode === 'backfill') {
                $attributes['last_full_sync_at'] = $finishedAt;
            } elseif (in_array($mode, ['incremental', 'realtime'], true)) {
                $attributes['last_incremental_sync_at'] = $finishedAt;
                if ($job->type !== $this->syncType('google')) {
                    $attributes['last_reconciled_at'] = $finishedAt;
                }
            } elseif (in_array($mode, ['attribution', 'reconcile'], true)) {
                $attributes['last_reconciled_at'] = $finishedAt;
            }
            $state->forceFill($attributes)->save();
            StoreAlert::query()->where('organization_id', $job->organization_id)->where('store_id', $job->store_id)
                ->where('type', 'sync')->where('source_type', SyncJob::class)->where('source_id', $job->id)
                ->whereIn('status', ['open', 'acknowledged'])->update(['status' => 'resolved', 'resolved_at' => $finishedAt]);
        });
    }

    /** @return list<string> */
    private function updatedViews(string $mode): array
    {
        return in_array($mode, ['attribution', 'realtime'], true)
            ? ['overview', 'trend']
            : ['overview', 'trend', 'campaigns', 'search-terms', 'keywords'];
    }

    private function broadcastStatus(
        Store $store,
        string $channel,
        string $state,
        string $mode,
        ?float $progressPercent = null,
        ?string $message = null,
    ): void {
        try {
            AdvertisingChannelSyncStatusChanged::dispatch(
                (int) $store->organization_id,
                (int) $store->getKey(),
                $channel,
                $state,
                mode: $mode,
                views: $this->updatedViews($mode),
                progressPercent: $progressPercent,
                message: $message,
            );
        } catch (Throwable $exception) {
            Log::warning('Advertising sync status broadcast failed.', [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->getKey(),
                'channel' => $channel,
                'state' => $state,
                'exception' => $exception::class,
            ]);
        }
    }

    private function failJob(SyncJob $job, Throwable $exception): void
    {
        DB::transaction(function () use ($job, $exception): void {
            $finishedAt = now();
            $channel = (string) data_get($job->payload, 'channel');
            $failure = AdvertisingSyncFailurePolicy::describe($exception);
            $previous = (array) data_get($job->payload, 'failure', []);
            $count = (int) ($previous['occurrences'] ?? 0) + 1;
            $retryAt = $channel === 'criteo' && $failure['transient']
                ? $finishedAt->copy()->addSeconds(AdvertisingSyncFailurePolicy::delay($count, $exception)) : null;
            $firstFailedAt = $previous['first_failed_at'] ?? $finishedAt->toIso8601String();
            $failure = [...$failure, 'occurrences' => $count, 'first_failed_at' => $firstFailedAt, 'retry_at' => $retryAt?->toIso8601String()];
            $label = self::CHANNELS[$channel]['label'] ?? '广告渠道';
            $message = $label.'：'.$failure['reason'].($failure['http_status'] ? '（HTTP '.$failure['http_status'].'）' : '').'。';
            if ($retryAt) {
                $message .= '已进入冷却，冷却结束后的下一轮定时任务将自动重试。';
            }
            $job->forceFill([
                'status' => 'failed',
                'failed_items' => max(1, $job->total_items - $job->processed_items),
                'finished_at' => $finishedAt,
                'failed_at' => $finishedAt,
                'last_error' => $message,
                'error_code' => $failure['code'],
                'available_at' => $retryAt ?? $finishedAt,
                'payload' => [...(array) $job->payload, 'failure' => $failure],
            ])->save();
            StoreSyncState::query()->where('last_job_id', $job->getKey())->update([
                'status' => 'failed',
                'last_failed_at' => $finishedAt,
                'last_error_code' => $failure['code'],
                'last_error' => $message,
                'next_sync_at' => $retryAt ?? $finishedAt,
                'consecutive_failures' => $count,
                'updated_at' => $finishedAt,
            ]);
        });
    }

    private function assertCredentialVersion(Store $store, string $channel, ?string $credentialVersion): void
    {
        if (! $this->configured($store, $channel)
            || ($credentialVersion !== null && ! hash_equals($credentialVersion, $this->credentialVersion($store, $channel)))) {
            throw new RuntimeException('广告渠道凭证已变更，本次旧同步已取消。');
        }
    }

    /** @return array{provider: string, label: string, required: list<string>} */
    private function definition(string $channel): array
    {
        $this->assertChannel($channel);

        return self::CHANNELS[$channel];
    }

    private function assertChannel(string $channel): void
    {
        if (! isset(self::CHANNELS[$channel])) {
            throw new RuntimeException('不支持的广告渠道。');
        }
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /** @return array{spend: float, revenue: float, impressions: int, clicks: int, conversions: float} */
    private function performanceMetricValues(array $metric): array
    {
        return [
            'spend' => max(0, (float) ($metric['spend'] ?? 0)),
            'revenue' => max(0, (float) ($metric['revenue'] ?? 0)),
            'impressions' => max(0, (int) ($metric['impressions'] ?? 0)),
            'clicks' => max(0, (int) ($metric['clicks'] ?? 0)),
            'conversions' => max(0, (float) ($metric['conversions'] ?? 0)),
        ];
    }
}
