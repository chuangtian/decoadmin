<?php

namespace App\Services\Advertising;

use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\AdvertisingChannelAccount;
use App\Models\AdvertisingChannelDailyMetric;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private AdvertisingChannelApiService $api) {}

    public function sync(Store $store, string $channel, string $mode, ?string $credentialVersion = null): void
    {
        $this->assertChannel($channel);
        if (! in_array($mode, ['priority', 'backfill', 'incremental'], true)) {
            throw new RuntimeException('广告渠道同步模式无效。');
        }
        if ($store->status !== 'active' || ! $this->configured($store, $channel)) {
            return;
        }
        if ($credentialVersion !== null && ! hash_equals($credentialVersion, $this->credentialVersion($store, $channel))) {
            return;
        }

        [$since, $until] = $this->period($store, $mode);
        $chunks = $this->chunks($since, $until);
        $job = $this->startJob($store, $channel, $mode, $since, $until, count($chunks), $credentialVersion);

        try {
            $processed = min(count($chunks), max(0, (int) $job->processed_items));
            $records = max(0, (int) data_get($job->result, 'records_count', 0));
            foreach (array_slice($chunks, $processed) as [$chunkFrom, $chunkTo]) {
                $this->assertCredentialVersion($store, $channel, $credentialVersion);
                $payload = $this->api->syncPayload($store, $channel, $chunkFrom, $chunkTo);
                $this->assertCredentialVersion($store, $channel, $credentialVersion);
                $records += $this->persist($store, $channel, $payload);
                $processed++;
                $job->forceFill([
                    'processed_items' => $processed,
                    'result' => ['records_count' => $records, 'last_chunk' => [$chunkFrom, $chunkTo]],
                ])->save();
            }

            $this->completeJob($job, $mode, $records);
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
    private function period(Store $store, string $mode): array
    {
        $now = CarbonImmutable::now($store->timezone ?: 'UTC')->startOfHour();
        $priorityDays = max(1, (int) config('services.advertising_sync.priority_days', 7));
        $rollingDays = max(1, (int) config('services.advertising_sync.rolling_days', 3));

        return match ($mode) {
            'priority' => [$now->subDays($priorityDays - 1)->startOfDay(), $now],
            'backfill' => [
                $now->subMonthsNoOverflow(max(1, (int) config('services.advertising_sync.history_months', 6)))->startOfDay(),
                $now->subDays($priorityDays)->endOfDay(),
            ],
            'incremental' => [
                $now->subDays($rollingDays - 1)->startOfDay(),
                $now,
            ],
        };
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

    /** @param array{accounts: list<array<string, mixed>>, daily_metrics: list<array<string, mixed>>} $payload */
    private function persist(Store $store, string $channel, array $payload): int
    {
        return DB::transaction(function () use ($store, $channel, $payload): int {
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
                    'impressions' => max(0, (int) ($metric['impressions'] ?? 0)),
                    'clicks' => max(0, (int) ($metric['clicks'] ?? 0)),
                    'conversions' => max(0, (float) ($metric['conversions'] ?? 0)),
                    'raw_payload' => json_encode($metric['raw_payload'] ?? $metric, JSON_THROW_ON_ERROR),
                    'synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })->filter()->values()->all();

            AdvertisingChannelDailyMetric::query()->upsert(
                $rows,
                ['organization_id', 'store_id', 'provider', 'external_account_id', 'metric_date'],
                ['advertising_channel_account_id', 'spend', 'attributed_sales', 'impressions', 'clicks', 'conversions', 'raw_payload', 'synced_at', 'updated_at'],
            );

            return count($accounts) + count($rows);
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
            } elseif ($mode === 'incremental') {
                $attributes['last_incremental_sync_at'] = $finishedAt;
                $attributes['last_reconciled_at'] = $finishedAt;
            }
            $state->forceFill($attributes)->save();
        });
    }

    private function failJob(SyncJob $job, Throwable $exception): void
    {
        DB::transaction(function () use ($job): void {
            $finishedAt = now();
            $message = '广告渠道同步失败，请检查授权或稍后重试。';
            $job->forceFill([
                'status' => 'failed',
                'failed_items' => max(1, $job->total_items - $job->processed_items),
                'finished_at' => $finishedAt,
                'failed_at' => $finishedAt,
                'last_error' => $message,
                'error_code' => 'advertising_channel_sync_failed',
            ])->save();
            StoreSyncState::query()->where('last_job_id', $job->getKey())->update([
                'status' => 'failed',
                'last_failed_at' => $finishedAt,
                'last_error_code' => 'advertising_channel_sync_failed',
                'last_error' => $message,
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
}
