<?php

namespace App\Services\MetaAds;

use App\Exceptions\MetaAdsApiException;
use App\Jobs\SyncMetaAdsForStore;
use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdCampaign;
use App\Models\MetaAdCreative;
use App\Models\MetaAdInsight;
use App\Models\MetaAdSet;
use App\Models\MetaAdSyncShard;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Support\CurrentYearSyncWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MetaAdsSyncService
{
    public const SYNC_TYPE = 'meta_ads';

    private const SYNC_MODES = ['full', 'priority', 'backfill', 'incremental', 'structure'];

    private const INSIGHT_LEVELS = ['account', 'campaign', 'adset', 'ad'];

    public function __construct(
        private MetaAdsApiClient $api,
        private CurrentYearSyncWindow $currentYear,
    ) {}

    /** @return array<string, int|string> */
    public function sync(Store $store, string $requestedMode = 'incremental'): array
    {
        if (! in_array($requestedMode, ['full', 'incremental'], true)) {
            throw new MetaAdsApiException('Meta Ads 同步模式无效。', 'meta_ads_invalid_mode');
        }
        if ($store->status !== 'active') {
            throw new MetaAdsApiException('当前店铺未启用，不能同步 Meta Ads。', 'meta_ads_store_inactive');
        }

        $state = $this->state($store);
        $mode = $requestedMode === 'incremental' && ! $state->last_full_sync_at
            ? 'full'
            : $requestedMode;
        [$since, $until] = $this->period($store, $mode, false);
        $syncJob = $this->startJob($store, $mode, $requestedMode, $since, $until);

        try {
            $counts = $this->pull($store, $since, $until, $mode);
            $result = [
                'success' => true,
                'mode' => $mode,
                'accounts' => $counts['accounts'],
                'campaigns' => $counts['campaigns'],
                'ad_sets' => $counts['ad_sets'],
                'ads' => $counts['ads'],
                'creatives' => $counts['creatives'],
                'insights' => $counts['insights'],
                'records_count' => array_sum($counts),
            ];
            $this->completeJob($syncJob, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->failJob($syncJob, $exception);

            throw $exception;
        }
    }

    /**
     * Persist an exact campaign aggregate for the selected UI period. The
     * overview remains database-only; this focused background sync supplies
     * Meta's deduplicated reach/frequency without delaying page rendering.
     */
    public function syncCampaignPeriodSnapshot(
        Store $store,
        string $accountFilter,
        string $since,
        string $until,
        ?string $credentialVersion = null,
    ): int {
        $this->validateSync($store, 'incremental', $credentialVersion);

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $since, 'UTC');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $until, 'UTC');
        } catch (Throwable) {
            throw new MetaAdsApiException('Meta Ads 聚合快照日期无效。', 'meta_ads_period_invalid');
        }
        if (! $from || ! $to
            || $from->toDateString() !== $since
            || $to->toDateString() !== $until
            || $from->greaterThan($to)
            || $from->diffInDays($to) > 366) {
            throw new MetaAdsApiException('Meta Ads 聚合快照日期无效。', 'meta_ads_period_invalid');
        }

        $accounts = $this->storedAccounts($store);
        if ($accountFilter !== 'all') {
            $numeric = preg_replace('/^act_/', '', $accountFilter) ?: $accountFilter;
            $accepted = array_values(array_unique([$accountFilter, $numeric, 'act_'.$numeric]));
            $accounts = $accounts
                ->filter(fn (MetaAdAccount $account): bool => in_array($account->meta_account_id, $accepted, true))
                ->values();
        }
        if ($accounts->isEmpty()) {
            throw new MetaAdsApiException('所选 Meta Ads 广告账户不存在。', 'meta_ads_account_not_found');
        }

        $syncedAt = now();
        $fetched = [];
        foreach ($accounts as $account) {
            $fetched[(int) $account->getKey()] = [];
            $this->api->eachInsightPeriodPage(
                $store,
                $account->meta_account_id,
                'campaign',
                $from->toDateString(),
                $to->toDateString(),
                function (array $rows) use ($account, &$fetched): void {
                    array_push($fetched[(int) $account->getKey()], ...$rows);
                },
            );
        }

        return DB::transaction(function () use ($store, $accounts, $fetched, $from, $to, $syncedAt): int {
            MetaAdInsight::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->getKey())
                ->whereIn('meta_ad_account_id', $accounts->modelKeys())
                ->where('level', 'campaign')
                ->where('granularity', 'period')
                ->whereDate('date_start', $from->toDateString())
                ->whereDate('date_stop', $to->toDateString())
                ->delete();

            $count = 0;
            foreach ($accounts as $account) {
                $count += $this->persistInsights(
                    $store,
                    $account,
                    'campaign',
                    $fetched[(int) $account->getKey()] ?? [],
                    $syncedAt,
                    false,
                    'period',
                );
            }

            return $count;
        });
    }

    /**
     * Prepare a resumable store-level run and return only shards that still
     * need work. Completed shards remain checkpoints and are never repeated.
     *
     * @return array{sync_job_id: int, shard_ids: list<int>, resumed: bool, mode: string}
     */
    public function orchestrate(
        Store $store,
        string $requestedMode = 'incremental',
        ?string $credentialVersion = null,
        bool $reconcileIncremental = true,
    ): array {
        $this->validateSync($store, $requestedMode, $credentialVersion);
        $state = $this->state($store);
        $mode = $requestedMode === 'incremental' && ! $state->last_success_at
            ? 'priority'
            : $requestedMode;
        $existing = $this->resumableJob($store, $mode);

        if ($existing) {
            $this->resumeJob($store, $existing);

            return [
                'sync_job_id' => (int) $existing->getKey(),
                'shard_ids' => $this->pendingShardIds($existing),
                'resumed' => true,
                'mode' => (string) $existing->mode,
            ];
        }

        [$since, $until] = $this->period($store, $mode, $reconcileIncremental);
        $syncJob = $this->startJob(
            $store,
            $mode,
            $requestedMode,
            $since,
            $until,
            'resumable_shards',
            $credentialVersion,
        );

        try {
            $accounts = $mode === 'incremental'
                ? $this->storedAccounts($store)
                : $this->prepareAccounts($store, in_array($mode, ['full', 'priority', 'structure'], true), $credentialVersion);
            $rows = $this->shardRows(
                $store,
                $syncJob,
                $accounts,
                $since,
                $until,
                $mode,
                $reconcileIncremental,
            );
            MetaAdSyncShard::query()->insertOrIgnore($rows);
            $shards = MetaAdSyncShard::query()
                ->where('sync_job_id', $syncJob->getKey())
                ->orderBy('id')
                ->get();
            $payload = [
                ...($syncJob->payload ?? []),
                'strategy' => 'resumable_shards',
                'insight_strategy' => 'async_report_full_range_v1',
                'eta_started_at' => now()->toIso8601String(),
                'eta_baseline_completed' => 0,
                'accounts' => $mode === 'incremental' ? 0 : $accounts->count(),
                'account_scope' => $accounts->count(),
                'shards' => $shards->count(),
            ];
            $syncJob->forceFill([
                'payload' => $payload,
                'total_items' => $shards->count(),
                'processed_items' => 0,
                'failed_items' => 0,
            ])->save();

            return [
                'sync_job_id' => (int) $syncJob->getKey(),
                'shard_ids' => $shards->modelKeys(),
                'resumed' => false,
                'mode' => $mode,
            ];
        } catch (Throwable $exception) {
            if (! ($exception instanceof MetaAdsApiException && $exception->errorCode === 'meta_ads_sync_cancelled')
                && SyncJob::query()->whereKey($syncJob->getKey())->exists()) {
                $this->failJob($syncJob, $exception);
            }

            throw $exception;
        }
    }

    /** @return array<string, int> */
    public function runShard(MetaAdSyncShard $shard): array
    {
        if ($shard->status === 'completed') {
            return $shard->result ?? [];
        }

        $store = Store::query()
            ->where('organization_id', $shard->organization_id)
            ->whereKey($shard->store_id)
            ->where('status', 'active')
            ->firstOrFail();
        $account = MetaAdAccount::query()
            ->forOrganization((int) $shard->organization_id)
            ->forStore((int) $shard->store_id)
            ->whereKey($shard->meta_ad_account_id)
            ->firstOrFail();
        $since = $shard->since_at ? CarbonImmutable::parse($shard->since_at)->utc() : null;
        $until = $shard->until_at ? CarbonImmutable::parse($shard->until_at)->utc() : null;
        if (! $until) {
            throw new MetaAdsApiException('Meta Ads 分片缺少结束时间。', 'meta_ads_shard_period_invalid');
        }
        if ($since) {
            $range = $this->currentYear->clampExistingRange($since, $until, $store->timezone ?: 'UTC');
            if ($range === null) {
                $counts = $this->partialShardCounts($shard);
                $shard->forceFill([
                    'status' => 'completed',
                    'records_count' => array_sum($counts),
                    'result' => [...($shard->result ?? []), ...$counts, 'skipped_before_current_year' => true],
                    'finished_at' => now(),
                    'error_code' => null,
                    'last_error' => null,
                ])->save();
                $this->refreshJobProgress((int) $shard->sync_job_id);

                return $counts;
            }
            [$since, $until] = $range;
        }

        $shard->forceFill([
            'status' => 'running',
            'attempts' => $shard->attempts + 1,
            'started_at' => $shard->started_at ?? now(),
            'finished_at' => null,
            'error_code' => null,
            'last_error' => null,
        ])->save();
        $syncedAt = now();
        $counts = $this->partialShardCounts($shard);
        $updatedSince = $since?->subSecond()->getTimestamp();
        $replacementShardIds = [];
        $resumeCursor = trim((string) data_get($shard->result, 'pagination_after', ''));
        $afterByAccount = $resumeCursor === '' ? [] : [$account->meta_account_id => $resumeCursor];
        $checkpoint = function (string $ignoredAccountId, ?string $cursor) use ($shard, &$counts): void {
            $result = $shard->result ?? [];
            if ($cursor === null) {
                unset($result['pagination_after']);
            } else {
                $result['pagination_after'] = $cursor;
            }
            $result['partial_counts'] = $counts;
            $result['pagination_checkpoint_at'] = now()->toIso8601String();
            $shard->forceFill(['result' => $result])->save();
        };

        try {
            $this->ensureShardActive($shard, $store);
            $shardFinished = true;
            match ($shard->kind) {
                'campaigns' => $this->api->eachCampaignPageForAccounts(
                    $store,
                    [$account->meta_account_id],
                    function (string $ignored, array $rows) use ($shard, $store, $account, $since, $until, $syncedAt, &$counts): void {
                        $this->ensureShardActive($shard, $store);
                        $counts['campaigns'] += $this->persistCampaigns(
                            $store,
                            $account,
                            $this->updatedWithin($rows, $since, $until),
                            $syncedAt,
                        );
                    },
                    $updatedSince,
                    $afterByAccount,
                    $checkpoint,
                ),
                'ad_sets' => $this->api->eachAdSetPageForAccounts(
                    $store,
                    [$account->meta_account_id],
                    function (string $ignored, array $rows) use ($shard, $store, $account, $since, $until, $syncedAt, &$counts): void {
                        $this->ensureShardActive($shard, $store);
                        $counts['ad_sets'] += $this->persistAdSets(
                            $store,
                            $account,
                            $this->updatedWithin($rows, $since, $until),
                            $syncedAt,
                        );
                    },
                    $updatedSince,
                    $afterByAccount,
                    $checkpoint,
                ),
                'ads' => $this->api->eachAdPageForAccounts(
                    $store,
                    [$account->meta_account_id],
                    function (string $ignored, array $rows) use ($shard, $store, $account, $since, $until, $syncedAt, &$counts): void {
                        $this->ensureShardActive($shard, $store);
                        $persisted = $this->persistAds(
                            $store,
                            $account,
                            $this->updatedWithin($rows, $since, $until),
                            $syncedAt,
                        );
                        $counts['ads'] += $persisted['ads'];
                        $counts['creatives'] += $persisted['creatives'];
                    },
                    $updatedSince,
                    $afterByAccount,
                    $checkpoint,
                ),
                'insights' => $shardFinished = $this->runInsightShard(
                    $shard,
                    $store,
                    $account,
                    $since,
                    $until,
                    $syncedAt,
                    $counts,
                    $replacementShardIds,
                ),
                default => throw new MetaAdsApiException('Meta Ads 分片类型无效。', 'meta_ads_shard_kind_invalid'),
            };
            $this->ensureShardActive($shard, $store);
        } catch (Throwable $exception) {
            if ($exception instanceof MetaAdsApiException && $exception->errorCode === 'meta_ads_sync_cancelled') {
                MetaAdSyncShard::query()->whereKey($shard->getKey())->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'error_code' => $exception->errorCode,
                    'last_error' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);

                return $counts;
            }
            if ($exception instanceof MetaAdsApiException && $exception->errorCode === 'meta_ads_async_report_terminal_failed') {
                $this->recordShardError($shard, $exception, 'failed');
                $this->refreshJobProgress((int) $shard->sync_job_id);

                return [...$counts, 'terminal_failed' => 1];
            }
            $this->recordShardError($shard, $exception, 'queued');

            throw $exception;
        }

        if (! $shardFinished) {
            $shard->forceFill([
                'status' => 'queued',
                'finished_at' => null,
                'error_code' => null,
                'last_error' => null,
            ])->save();

            return [
                ...$counts,
                'async_pending' => 1,
                'retry_after_seconds' => max(
                    5,
                    (int) config('services.meta_ads.async_poll_interval_seconds', 15),
                ),
            ];
        }

        $records = array_sum($counts);
        $result = [...($shard->result ?? []), ...$counts];
        unset($result['partial_counts'], $result['pagination_after']);
        if ($replacementShardIds !== []) {
            $result['replacement_shard_ids'] = $replacementShardIds;
        }
        $shard->forceFill([
            'status' => 'completed',
            'records_count' => $records,
            'result' => $result,
            'finished_at' => now(),
            'error_code' => null,
            'last_error' => null,
        ])->save();
        $this->refreshJobProgress((int) $shard->sync_job_id);

        return $replacementShardIds === []
            ? $counts
            : [...$counts, 'replacement_shard_ids' => $replacementShardIds];
    }

    public function markShardFailed(int $shardId, ?Throwable $exception): void
    {
        $shard = MetaAdSyncShard::query()->find($shardId);
        if (! $shard || $shard->status === 'completed') {
            return;
        }

        if ($shard->last_error && $shard->error_code) {
            $shard->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
            ])->save();
            $this->refreshJobProgress((int) $shard->sync_job_id);

            return;
        }

        $this->recordShardError(
            $shard,
            $exception ?? new MetaAdsApiException('Meta Ads 分片任务意外终止。', 'meta_ads_shard_failed'),
            'failed',
        );
        $this->refreshJobProgress((int) $shard->sync_job_id);
    }

    /** Return true when the run reached a terminal state. */
    public function finalizeShardedSync(int $syncJobId): bool
    {
        $job = SyncJob::query()->find($syncJobId);
        if (! $job || in_array($job->status, ['completed', 'failed'], true)) {
            return true;
        }

        $statuses = MetaAdSyncShard::query()
            ->where('sync_job_id', $job->getKey())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        if ((int) ($statuses['failed'] ?? 0) > 0) {
            $failed = MetaAdSyncShard::query()
                ->where('sync_job_id', $job->getKey())
                ->where('status', 'failed')
                ->first();
            $this->failJob(
                $job,
                new MetaAdsApiException(
                    $failed?->last_error ?: 'Meta Ads 分片同步失败。',
                    $failed?->error_code ?: 'meta_ads_shard_failed',
                ),
            );

            return true;
        }

        if ((int) ($statuses['queued'] ?? 0) > 0 || (int) ($statuses['running'] ?? 0) > 0) {
            return false;
        }

        $store = Store::query()
            ->where('organization_id', $job->organization_id)
            ->whereKey($job->store_id)
            ->firstOrFail();
        $counts = ['accounts' => (int) data_get($job->payload, 'accounts', 0), 'campaigns' => 0, 'ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'insights' => 0];

        MetaAdSyncShard::query()
            ->where('sync_job_id', $job->getKey())
            ->get(['result'])
            ->each(function (MetaAdSyncShard $shard) use (&$counts): void {
                foreach ($shard->result ?? [] as $key => $value) {
                    if (array_key_exists($key, $counts)) {
                        $counts[$key] += (int) $value;
                    }
                }
            });

        if (in_array($job->mode, ['full', 'backfill'], true) && $job->since_at) {
            $this->pruneBefore($store, CarbonImmutable::parse($job->since_at));
        }

        $this->completeJob($job, [
            'success' => true,
            'mode' => (string) $job->mode,
            ...$counts,
            'records_count' => array_sum($counts),
            'shards' => MetaAdSyncShard::query()->where('sync_job_id', $job->getKey())->count(),
            'resumable' => true,
        ]);

        if ($job->mode === 'priority') {
            $credentialVersion = data_get($job->payload, 'credential_version');
            SyncMetaAdsForStore::dispatch(
                (int) $job->organization_id,
                (int) $job->store_id,
                'backfill',
                is_string($credentialVersion) ? $credentialVersion : null,
            )->delay(now()->addSeconds(30));
        }

        return true;
    }

    public function markUnexpectedFailure(int $organizationId, int $storeId): void
    {
        $syncJob = SyncJob::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('type', self::SYNC_TYPE)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($syncJob) {
            $this->failJob(
                $syncJob,
                new MetaAdsApiException('Meta Ads 队列任务意外终止。', 'meta_ads_job_failed'),
            );
        }
    }

    /**
     * @return array{accounts: int, campaigns: int, ad_sets: int, ads: int, creatives: int, insights: int}
     */
    private function pull(
        Store $store,
        ?CarbonImmutable $since,
        CarbonImmutable $until,
        string $mode,
    ): array {
        $syncedAt = now();
        $accounts = $this->api->accounts($store);
        $accountRows = [];

        foreach ($accounts as $account) {
            $accountId = $this->text($account['id'] ?? null, 64);
            if ($accountId === null) {
                continue;
            }

            $accountRows[$accountId] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->getKey(),
                'meta_account_id' => $accountId,
                'name' => $this->text($account['name'] ?? null, 255),
                'account_status' => $this->unsignedInteger($account['account_status'] ?? null, 65535),
                'currency' => $this->text($account['currency'] ?? null, 3),
                'timezone_name' => $this->text($account['timezone_name'] ?? null, 100),
                'timezone_offset_hours_utc' => $this->number($account['timezone_offset_hours_utc'] ?? null),
                'business_name' => $this->text($account['business_name'] ?? null, 255),
                'disable_reason' => $this->unsignedInteger($account['disable_reason'] ?? null, 65535),
                'spend_cap' => $this->number($account['spend_cap'] ?? null),
                'amount_spent' => $this->number($account['amount_spent'] ?? null),
                'balance' => $this->number($account['balance'] ?? null),
                'raw_payload' => $this->json($account),
                'last_seen_at' => $syncedAt,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        if ($accountRows === []) {
            throw new MetaAdsApiException('Meta Ads 授权下没有可访问的广告账户。', 'meta_ads_accounts_empty');
        }

        MetaAdAccount::query()->upsert(
            array_values($accountRows),
            ['organization_id', 'store_id', 'meta_account_id'],
            [
                'name', 'account_status', 'currency', 'timezone_name', 'timezone_offset_hours_utc',
                'business_name', 'disable_reason', 'spend_cap', 'amount_spent', 'balance',
                'raw_payload', 'last_seen_at', 'synced_at', 'updated_at',
            ],
        );

        $accountModels = MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->whereIn('meta_account_id', array_keys($accountRows))
            ->get()
            ->keyBy('meta_account_id');
        $counts = [
            'accounts' => count($accountRows),
            'campaigns' => 0,
            'ad_sets' => 0,
            'ads' => 0,
            'creatives' => 0,
            'insights' => 0,
        ];

        $accountExternalIds = array_keys($accountRows);
        $updatedSince = $since?->utc()->subSecond()->getTimestamp();
        $accountFor = function (string $accountExternalId) use ($accountModels): MetaAdAccount {
            /** @var MetaAdAccount|null $account */
            $account = $accountModels->get($accountExternalId);
            if (! $account) {
                throw new MetaAdsApiException('Meta Ads 广告账户落库失败。', 'meta_ads_account_persistence_failed');
            }

            return $account;
        };

        $this->api->eachCampaignPageForAccounts(
            $store,
            $accountExternalIds,
            function (string $accountExternalId, array $rows) use ($store, $accountFor, $since, $until, $syncedAt, &$counts): void {
                $counts['campaigns'] += $this->persistCampaigns(
                    $store,
                    $accountFor($accountExternalId),
                    $this->updatedWithin($rows, $since, $until),
                    $syncedAt,
                );
            },
            $updatedSince,
        );
        $this->api->eachAdSetPageForAccounts(
            $store,
            $accountExternalIds,
            function (string $accountExternalId, array $rows) use ($store, $accountFor, $since, $until, $syncedAt, &$counts): void {
                $counts['ad_sets'] += $this->persistAdSets(
                    $store,
                    $accountFor($accountExternalId),
                    $this->updatedWithin($rows, $since, $until),
                    $syncedAt,
                );
            },
            $updatedSince,
        );
        $this->api->eachAdPageForAccounts(
            $store,
            $accountExternalIds,
            function (string $accountExternalId, array $rows) use ($store, $accountFor, $since, $until, $syncedAt, &$counts): void {
                $result = $this->persistAds(
                    $store,
                    $accountFor($accountExternalId),
                    $this->updatedWithin($rows, $since, $until),
                    $syncedAt,
                );
                $counts['ads'] += $result['ads'];
                $counts['creatives'] += $result['creatives'];
            },
            $updatedSince,
        );
        $insightSince = $since?->toDateString();
        $insightUntil = $until->toDateString();
        $this->api->eachInsightPageForAccounts(
            $store,
            $accountExternalIds,
            self::INSIGHT_LEVELS,
            $insightSince,
            $insightUntil,
            function (
                string $accountExternalId,
                string $level,
                array $rows,
            ) use ($store, $accountFor, $syncedAt, &$counts): void {
                $account = $accountFor($accountExternalId);
                $counts['insights'] += $this->persistInsights(
                    $store,
                    $account,
                    $level,
                    $rows,
                    $syncedAt,
                    false,
                );
            },
            false,
        );

        if ($mode === 'full' && $since !== null) {
            $this->pruneBefore($store, $since);
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $records */
    private function persistCampaigns(Store $store, MetaAdAccount $account, array $records, mixed $syncedAt): int
    {
        $rows = [];

        foreach ($records as $record) {
            $campaignId = $this->text($record['id'] ?? null, 64);
            if ($campaignId === null) {
                continue;
            }

            $rows[$campaignId] = [
                ...$this->scope($store, $account),
                'meta_campaign_id' => $campaignId,
                'name' => $this->text($record['name'] ?? null, 500),
                'status' => $this->text($record['status'] ?? null, 64),
                'effective_status' => $this->text($record['effective_status'] ?? null, 64),
                'objective' => $this->text($record['objective'] ?? null, 100),
                'buying_type' => $this->text($record['buying_type'] ?? null, 64),
                'daily_budget' => $this->number($record['daily_budget'] ?? null),
                'lifetime_budget' => $this->number($record['lifetime_budget'] ?? null),
                'budget_remaining' => $this->number($record['budget_remaining'] ?? null),
                'start_time' => $this->timestamp($record['start_time'] ?? null),
                'stop_time' => $this->timestamp($record['stop_time'] ?? null),
                'source_created_at' => $this->timestamp($record['created_time'] ?? null),
                'source_updated_at' => $this->timestamp($record['updated_time'] ?? null),
                'special_ad_categories' => $this->jsonOrNull($record['special_ad_categories'] ?? null),
                'raw_payload' => $this->json($record),
                'last_seen_at' => $syncedAt,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $this->upsertRows(
            MetaAdCampaign::class,
            $rows,
            ['organization_id', 'store_id', 'meta_campaign_id'],
            [
                'meta_ad_account_id', 'name', 'status', 'effective_status', 'objective', 'buying_type',
                'daily_budget', 'lifetime_budget', 'budget_remaining', 'start_time', 'stop_time',
                'source_created_at', 'source_updated_at', 'special_ad_categories', 'raw_payload',
                'last_seen_at', 'synced_at', 'updated_at',
            ],
        );

        return count($rows);
    }

    /** @param list<array<string, mixed>> $records */
    private function persistAdSets(Store $store, MetaAdAccount $account, array $records, mixed $syncedAt): int
    {
        $rows = [];

        foreach ($records as $record) {
            $adSetId = $this->text($record['id'] ?? null, 64);
            if ($adSetId === null) {
                continue;
            }

            $rows[$adSetId] = [
                ...$this->scope($store, $account),
                'meta_ad_set_id' => $adSetId,
                'meta_campaign_id' => $this->text($record['campaign_id'] ?? null, 64),
                'name' => $this->text($record['name'] ?? null, 500),
                'status' => $this->text($record['status'] ?? null, 64),
                'effective_status' => $this->text($record['effective_status'] ?? null, 64),
                'optimization_goal' => $this->text($record['optimization_goal'] ?? null, 100),
                'billing_event' => $this->text($record['billing_event'] ?? null, 100),
                'bid_strategy' => $this->text($record['bid_strategy'] ?? null, 100),
                'daily_budget' => $this->number($record['daily_budget'] ?? null),
                'lifetime_budget' => $this->number($record['lifetime_budget'] ?? null),
                'budget_remaining' => $this->number($record['budget_remaining'] ?? null),
                'start_time' => $this->timestamp($record['start_time'] ?? null),
                'end_time' => $this->timestamp($record['end_time'] ?? null),
                'source_created_at' => $this->timestamp($record['created_time'] ?? null),
                'source_updated_at' => $this->timestamp($record['updated_time'] ?? null),
                'targeting' => $this->jsonOrNull($record['targeting'] ?? null),
                'promoted_object' => $this->jsonOrNull($record['promoted_object'] ?? null),
                'raw_payload' => $this->json($record),
                'last_seen_at' => $syncedAt,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $this->upsertRows(
            MetaAdSet::class,
            $rows,
            ['organization_id', 'store_id', 'meta_ad_set_id'],
            [
                'meta_ad_account_id', 'meta_campaign_id', 'name', 'status', 'effective_status',
                'optimization_goal', 'billing_event', 'bid_strategy', 'daily_budget', 'lifetime_budget',
                'budget_remaining', 'start_time', 'end_time', 'source_created_at', 'source_updated_at',
                'targeting', 'promoted_object', 'raw_payload', 'last_seen_at', 'synced_at', 'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{ads: int, creatives: int}
     */
    private function persistAds(Store $store, MetaAdAccount $account, array $records, mixed $syncedAt): array
    {
        $ads = [];
        $creatives = [];

        foreach ($records as $record) {
            $adId = $this->text($record['id'] ?? null, 64);
            if ($adId === null) {
                continue;
            }

            $creative = is_array($record['creative'] ?? null) ? $record['creative'] : [];
            $creativeId = $this->text($creative['id'] ?? null, 64);
            if ($creativeId !== null) {
                $creatives[$creativeId] = [
                    ...$this->scope($store, $account),
                    'meta_creative_id' => $creativeId,
                    'name' => $this->text($creative['name'] ?? null, 500),
                    'title' => $this->text($creative['title'] ?? null),
                    'body' => $this->text($creative['body'] ?? null),
                    'call_to_action_type' => $this->text($creative['call_to_action_type'] ?? null, 100),
                    'object_story_id' => $this->text($creative['object_story_id'] ?? null, 128),
                    'image_url' => $this->text($creative['image_url'] ?? null),
                    'thumbnail_url' => $this->text($creative['thumbnail_url'] ?? null),
                    'url_tags' => $this->text($creative['url_tags'] ?? null),
                    'asset_feed_spec' => $this->jsonOrNull($creative['asset_feed_spec'] ?? null),
                    'object_story_spec' => $this->jsonOrNull($creative['object_story_spec'] ?? null),
                    'raw_payload' => $this->json($creative),
                    'last_seen_at' => $syncedAt,
                    'synced_at' => $syncedAt,
                    'created_at' => $syncedAt,
                    'updated_at' => $syncedAt,
                ];
            }

            $ads[$adId] = [
                ...$this->scope($store, $account),
                'meta_ad_id' => $adId,
                'meta_campaign_id' => $this->text($record['campaign_id'] ?? null, 64),
                'meta_ad_set_id' => $this->text($record['adset_id'] ?? null, 64),
                'meta_creative_id' => $creativeId,
                'name' => $this->text($record['name'] ?? null, 500),
                'status' => $this->text($record['status'] ?? null, 64),
                'effective_status' => $this->text($record['effective_status'] ?? null, 64),
                'tracking_specs' => $this->jsonOrNull($record['tracking_specs'] ?? null),
                'conversion_specs' => $this->jsonOrNull($record['conversion_specs'] ?? null),
                'source_created_at' => $this->timestamp($record['created_time'] ?? null),
                'source_updated_at' => $this->timestamp($record['updated_time'] ?? null),
                'raw_payload' => $this->json($record),
                'last_seen_at' => $syncedAt,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $this->upsertRows(
            MetaAdCreative::class,
            $creatives,
            ['organization_id', 'store_id', 'meta_creative_id'],
            [
                'meta_ad_account_id', 'name', 'title', 'body', 'call_to_action_type',
                'object_story_id', 'image_url', 'thumbnail_url', 'url_tags', 'asset_feed_spec',
                'object_story_spec', 'raw_payload', 'last_seen_at', 'synced_at', 'updated_at',
            ],
        );
        $this->upsertRows(
            MetaAd::class,
            $ads,
            ['organization_id', 'store_id', 'meta_ad_id'],
            [
                'meta_ad_account_id', 'meta_campaign_id', 'meta_ad_set_id', 'meta_creative_id',
                'name', 'status', 'effective_status', 'tracking_specs', 'conversion_specs',
                'source_created_at', 'source_updated_at', 'raw_payload', 'last_seen_at',
                'synced_at', 'updated_at',
            ],
        );

        return ['ads' => count($ads), 'creatives' => count($creatives)];
    }

    /** @param list<array<string, mixed>> $records */
    private function persistInsights(
        Store $store,
        MetaAdAccount $account,
        string $level,
        array $records,
        mixed $syncedAt,
        bool $hourly = false,
        string $aggregateGranularity = 'day',
    ): int {
        if (! in_array($aggregateGranularity, ['day', 'period'], true)) {
            throw new MetaAdsApiException('Meta Ads 洞察粒度无效。', 'meta_ads_invalid_granularity');
        }

        $rows = [];

        foreach ($records as $record) {
            $entityId = $this->insightEntityId($record, $level);
            $dateStart = $this->date($record['date_start'] ?? null);
            $dateStop = $this->date($record['date_stop'] ?? null);
            if ($entityId === null || $dateStart === null || $dateStop === null) {
                continue;
            }

            $granularity = $hourly ? 'hour' : $aggregateGranularity;
            $hourlyRange = $hourly
                ? $this->text($record['hourly_stats_aggregated_by_advertiser_time_zone'] ?? null, 32)
                : '';
            $hourStartAt = ! $hourly || $hourlyRange === null
                ? null
                : $this->hourStart($dateStart, $hourlyRange, $account->timezone_name ?: ($store->timezone ?: 'UTC'));
            if ($hourly && ($hourlyRange === null || $hourStartAt === null)) {
                continue;
            }

            $actions = is_array($record['actions'] ?? null) ? $record['actions'] : [];
            $values = is_array($record['action_values'] ?? null) ? $record['action_values'] : [];
            $costs = is_array($record['cost_per_action_type'] ?? null) ? $record['cost_per_action_type'] : [];
            $purchaseRoas = is_array($record['purchase_roas'] ?? null) ? $record['purchase_roas'] : [];
            $websitePurchaseRoas = is_array($record['website_purchase_roas'] ?? null) ? $record['website_purchase_roas'] : [];
            $key = implode('|', [$level, $entityId, $dateStart, $dateStop, $granularity, $hourlyRange]);
            $rows[$key] = [
                ...$this->scope($store, $account),
                'level' => $level,
                'entity_id' => $entityId,
                // Meta insight payloads return a numeric account_id while the account
                // endpoint returns act_{id}. Persist one canonical value for filtering.
                'account_external_id' => $account->meta_account_id,
                'account_name' => $this->text($record['account_name'] ?? null, 255),
                'meta_campaign_id' => $this->text($record['campaign_id'] ?? null, 64),
                'campaign_name' => $this->text($record['campaign_name'] ?? null, 500),
                'meta_ad_set_id' => $this->text($record['adset_id'] ?? null, 64),
                'ad_set_name' => $this->text($record['adset_name'] ?? null, 500),
                'meta_ad_id' => $this->text($record['ad_id'] ?? null, 64),
                'ad_name' => $this->text($record['ad_name'] ?? null, 500),
                'date_start' => $dateStart,
                'date_stop' => $dateStop,
                'granularity' => $granularity,
                'hourly_range' => $hourlyRange,
                'hour_start_at' => $hourStartAt,
                'hour_end_at' => $hourStartAt?->addHour()->subSecond(),
                'spend' => $this->number($record['spend'] ?? 0) ?? '0',
                'impressions' => $this->unsignedInteger($record['impressions'] ?? 0) ?? 0,
                'reach' => $this->unsignedInteger($record['reach'] ?? 0) ?? 0,
                'clicks' => $this->unsignedInteger($record['clicks'] ?? 0) ?? 0,
                'unique_clicks' => $this->unsignedInteger($record['unique_clicks'] ?? 0) ?? 0,
                'inline_link_clicks' => $this->unsignedInteger($record['inline_link_clicks'] ?? 0) ?? 0,
                'ctr' => $this->number($record['ctr'] ?? null),
                'unique_ctr' => $this->number($record['unique_ctr'] ?? null),
                'cpc' => $this->number($record['cpc'] ?? null),
                'cpm' => $this->number($record['cpm'] ?? null),
                'cpp' => $this->number($record['cpp'] ?? null),
                'frequency' => $this->number($record['frequency'] ?? null),
                'purchases' => $this->actionMetric($actions, $this->purchaseTypes()) ?? '0',
                'purchase_value' => $this->actionMetric($values, $this->purchaseTypes()) ?? '0',
                'add_to_cart' => $this->actionMetric($actions, ['omni_add_to_cart', 'add_to_cart', 'offsite_conversion.fb_pixel_add_to_cart']) ?? '0',
                'initiate_checkout' => $this->actionMetric($actions, ['omni_initiated_checkout', 'initiate_checkout', 'offsite_conversion.fb_pixel_initiate_checkout']) ?? '0',
                'leads' => $this->actionMetric($actions, ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']) ?? '0',
                'landing_page_views' => $this->actionMetric($actions, ['landing_page_view']) ?? '0',
                'cost_per_purchase' => $this->actionMetric($costs, $this->purchaseTypes()),
                'purchase_roas' => $this->actionMetric($websitePurchaseRoas, $this->purchaseTypes())
                    ?? $this->actionMetric($purchaseRoas, $this->purchaseTypes())
                    ?? $this->firstActionValue($websitePurchaseRoas)
                    ?? $this->firstActionValue($purchaseRoas),
                'outbound_clicks' => null,
                'actions' => null,
                'action_values' => null,
                'cost_per_action_type' => null,
                'purchase_roas_breakdown' => null,
                'website_purchase_roas' => null,
                'raw_payload' => '{}',
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $this->upsertRows(
            MetaAdInsight::class,
            $rows,
            [
                'organization_id', 'store_id', 'level', 'entity_id', 'date_start', 'date_stop',
                'granularity', 'hourly_range',
            ],
            [
                'meta_ad_account_id', 'account_external_id', 'account_name', 'meta_campaign_id',
                'campaign_name', 'meta_ad_set_id', 'ad_set_name', 'meta_ad_id', 'ad_name',
                'hour_start_at', 'hour_end_at',
                'spend', 'impressions', 'reach', 'clicks', 'unique_clicks', 'inline_link_clicks',
                'ctr', 'unique_ctr', 'cpc', 'cpm', 'cpp', 'frequency', 'purchases', 'purchase_value',
                'add_to_cart', 'initiate_checkout', 'leads', 'landing_page_views', 'cost_per_purchase',
                'purchase_roas', 'outbound_clicks', 'actions', 'action_values', 'cost_per_action_type',
                'purchase_roas_breakdown', 'website_purchase_roas', 'raw_payload', 'synced_at', 'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function runInsightShard(
        MetaAdSyncShard $shard,
        Store $store,
        MetaAdAccount $account,
        ?CarbonImmutable $since,
        CarbonImmutable $until,
        mixed $syncedAt,
        array &$counts,
        array &$replacementShardIds,
    ): bool {
        $level = (string) $shard->level;
        $incrementalDay = $shard->mode === 'incremental_day';
        if (! in_array($level, self::INSIGHT_LEVELS, true)) {
            throw new MetaAdsApiException('Meta Ads 洞察分片层级无效。', 'meta_ads_invalid_level');
        }

        $consume = function (array $rows) use ($shard, $store, $account, $level, $syncedAt, &$counts): void {
            $this->ensureShardActive($shard, $store);
            $counts['insights'] += $this->persistInsights(
                $store,
                $account,
                $level,
                $rows,
                $syncedAt,
                false,
            );
        };

        if (! $incrementalDay) {
            $sinceDate = $shard->since_date?->toDateString();
            $untilDate = $shard->until_date?->toDateString();
            if (! $sinceDate || ! $untilDate) {
                throw new MetaAdsApiException('Meta Ads 洞察分片缺少日期范围。', 'meta_ads_shard_period_invalid');
            }

            $reportId = trim((string) data_get($shard->result, 'async_report_id', ''));
            if ($reportId === '') {
                $reportId = $this->api->startAsyncInsightReport(
                    $store,
                    $account->meta_account_id,
                    $level,
                    $sinceDate,
                    $untilDate,
                );
                $this->ensureShardActive($shard, $store);
                $shard->forceFill(['result' => [
                    ...($shard->result ?? []),
                    'async_report_id' => $reportId,
                    'async_percent' => 0,
                    'async_submitted_at' => now()->toIso8601String(),
                ]])->save();

                return false;
            }

            $report = $this->api->asyncInsightReportStatus($store, $reportId);
            $this->ensureShardActive($shard, $store);
            if ($report['state'] === 'failed') {
                return $this->recoverFailedAsyncReport(
                    $shard,
                    $account,
                    $sinceDate,
                    $untilDate,
                    $reportId,
                    $replacementShardIds,
                );
            }
            if ($report['state'] !== 'completed') {
                $shard->forceFill(['result' => [
                    ...($shard->result ?? []),
                    'async_percent' => $report['percent'],
                    'async_checked_at' => now()->toIso8601String(),
                ]])->save();

                return false;
            }

            $this->api->eachAsyncInsightReportPage($store, $reportId, $consume);

            return true;
        }

        $this->api->eachInsightPage(
            $store,
            $account->meta_account_id,
            $level,
            $shard->since_date?->toDateString(),
            $shard->until_date?->toDateString(),
            $consume,
            false,
        );

        return true;
    }

    /**
     * Meta may permanently fail a large async report. Replace a failed large
     * range with bounded child shards, then resubmit small ranges only a
     * limited number of times so the import can never retry forever.
     *
     * @param  list<int>  $replacementShardIds
     */
    private function recoverFailedAsyncReport(
        MetaAdSyncShard $shard,
        MetaAdAccount $account,
        string $sinceDate,
        string $untilDate,
        string $reportId,
        array &$replacementShardIds,
    ): bool {
        $result = $shard->result ?? [];
        $failures = max(0, (int) ($result['async_failure_count'] ?? 0)) + 1;
        $history = array_values(array_filter((array) ($result['async_failed_reports'] ?? []), 'is_array'));
        $history[] = [
            'report_id' => $reportId,
            'failed_at' => now()->toIso8601String(),
        ];
        $history = array_slice($history, -10);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $sinceDate, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $untilDate, 'UTC');
        if (! $start || ! $end || $start->greaterThan($end)) {
            throw new MetaAdsApiException('Meta Ads 洞察分片日期范围无效。', 'meta_ads_shard_period_invalid');
        }

        $days = $start->diffInDays($end) + 1;
        $recoveryWindow = max(2, (int) config('services.meta_ads.async_recovery_window_days', 31));
        $minimumWindow = min(
            $recoveryWindow,
            max(1, (int) config('services.meta_ads.async_min_window_days', 7)),
        );
        $maxResubmissions = max(0, (int) config('services.meta_ads.async_max_resubmissions', 2));

        if ($days > $recoveryWindow || ($failures > $maxResubmissions && $days > $minimumWindow)) {
            $windowDays = $days > $recoveryWindow ? $recoveryWindow : $minimumWindow;
            $replacementShardIds = $this->createInsightRecoveryShards(
                $shard,
                $account,
                $start,
                $end,
                $windowDays,
            );
            $shard->forceFill(['result' => [
                ...$result,
                'async_failure_count' => $failures,
                'async_failed_reports' => $history,
                'async_recovered_by_split' => true,
                'replacement_shard_ids' => $replacementShardIds,
            ]])->save();

            return true;
        }

        if ($failures <= $maxResubmissions) {
            unset(
                $result['async_report_id'],
                $result['async_percent'],
                $result['async_submitted_at'],
                $result['async_checked_at'],
            );
            $shard->forceFill(['result' => [
                ...$result,
                'async_failure_count' => $failures,
                'async_failed_reports' => $history,
                'async_resubmit_after' => now()
                    ->addSeconds(max(5, (int) config('services.meta_ads.async_poll_interval_seconds', 15)))
                    ->toIso8601String(),
            ]])->save();

            return false;
        }

        throw new MetaAdsApiException(
            "Meta Ads 异步洞察报表在 {$sinceDate} 至 {$untilDate} 范围内连续生成失败。",
            'meta_ads_async_report_terminal_failed',
        );
    }

    /** @return list<int> */
    private function createInsightRecoveryShards(
        MetaAdSyncShard $parent,
        MetaAdAccount $account,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $windowDays,
    ): array {
        $keys = [];
        $rows = [];
        $cursor = $start;
        $timestamp = now();

        while ($cursor->lessThanOrEqualTo($end)) {
            $rangeEnd = $cursor->addDays($windowDays - 1);
            if ($rangeEnd->greaterThan($end)) {
                $rangeEnd = $end;
            }
            $sinceDate = $cursor->toDateString();
            $untilDate = $rangeEnd->toDateString();
            $key = "{$account->meta_account_id}:insights:{$parent->level}:{$sinceDate}:{$untilDate}:async";
            $keys[] = mb_substr($key, 0, 191);
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'sync_job_id' => (int) $parent->sync_job_id,
                'organization_id' => (int) $parent->organization_id,
                'store_id' => (int) $parent->store_id,
                'meta_ad_account_id' => (int) $account->getKey(),
                'shard_key' => mb_substr($key, 0, 191),
                'kind' => 'insights',
                'level' => $parent->level,
                'mode' => $parent->mode,
                'status' => 'queued',
                'since_at' => $parent->since_at,
                'until_at' => $parent->until_at,
                'since_date' => $sinceDate,
                'until_date' => $untilDate,
                'attempts' => 0,
                'records_count' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $cursor = $rangeEnd->addDay();
        }

        MetaAdSyncShard::query()->insertOrIgnore($rows);
        $ids = MetaAdSyncShard::query()
            ->where('sync_job_id', $parent->sync_job_id)
            ->whereIn('shard_key', $keys)
            ->where('status', 'queued')
            ->orderBy('since_date')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $total = MetaAdSyncShard::query()->where('sync_job_id', $parent->sync_job_id)->count();
        $job = SyncJob::query()->find($parent->sync_job_id);
        if ($job) {
            $job->forceFill([
                'total_items' => $total,
                'payload' => [...($job->payload ?? []), 'shards' => $total],
            ])->save();
        }

        return $ids;
    }

    /** @return array{campaigns: int, ad_sets: int, ads: int, creatives: int, insights: int} */
    private function partialShardCounts(MetaAdSyncShard $shard): array
    {
        $partial = (array) data_get($shard->result, 'partial_counts', []);

        return [
            'campaigns' => max(0, (int) ($partial['campaigns'] ?? 0)),
            'ad_sets' => max(0, (int) ($partial['ad_sets'] ?? 0)),
            'ads' => max(0, (int) ($partial['ads'] ?? 0)),
            'creatives' => max(0, (int) ($partial['creatives'] ?? 0)),
            'insights' => max(0, (int) ($partial['insights'] ?? 0)),
        ];
    }

    private function validateSync(Store $store, string $requestedMode, ?string $credentialVersion = null): void
    {
        if (! in_array($requestedMode, self::SYNC_MODES, true)) {
            throw new MetaAdsApiException('Meta Ads 同步模式无效。', 'meta_ads_invalid_mode');
        }
        if ($store->status !== 'active') {
            throw new MetaAdsApiException('当前店铺未启用，不能同步 Meta Ads。', 'meta_ads_store_inactive');
        }

        $credential = $this->metaCredential($store);
        if (! $credential) {
            throw new MetaAdsApiException('当前店铺尚未配置 Meta Ads Token。', 'meta_ads_not_configured');
        }

        $currentVersion = $credential->updated_at?->utc()->format('Y-m-d H:i:s') ?? '';
        if ($credentialVersion !== null && ! hash_equals($credentialVersion, $currentVersion)) {
            throw new MetaAdsApiException('Meta Ads Token 已变更，本次旧同步已取消。', 'meta_ads_sync_cancelled');
        }
    }

    /** @return Collection<int, MetaAdAccount> */
    private function storedAccounts(Store $store): Collection
    {
        $accounts = MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->get();

        if ($accounts->isEmpty()) {
            throw new MetaAdsApiException(
                'Meta Ads 广告账户尚未完成结构同步。',
                'meta_ads_accounts_not_synced',
            );
        }

        return $accounts;
    }

    /** @return Collection<int, MetaAdAccount> */
    private function prepareAccounts(Store $store, bool $forceRefresh, ?string $credentialVersion = null): Collection
    {
        $query = MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey());
        $existing = $query->get();
        $refreshHours = max(1, (int) config('services.meta_ads.account_refresh_hours', 24));
        $fresh = $existing->isNotEmpty() && $existing->every(
            fn (MetaAdAccount $account): bool => $account->synced_at?->greaterThanOrEqualTo(now()->subHours($refreshHours)) ?? false,
        );
        if (! $forceRefresh && $fresh) {
            return $existing;
        }

        $syncedAt = now();
        $rows = [];
        $accounts = $this->api->accounts($store);
        // The API call can take long enough for an administrator to replace
        // or clear the token. Re-check before writing its account payload.
        $this->validateSync($store, 'full', $credentialVersion);
        foreach ($accounts as $account) {
            $accountId = $this->text($account['id'] ?? null, 64);
            if ($accountId === null) {
                continue;
            }

            $rows[$accountId] = [
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->getKey(),
                'meta_account_id' => $accountId,
                'name' => $this->text($account['name'] ?? null, 255),
                'account_status' => $this->unsignedInteger($account['account_status'] ?? null, 65535),
                'currency' => $this->text($account['currency'] ?? null, 3),
                'timezone_name' => $this->text($account['timezone_name'] ?? null, 100),
                'timezone_offset_hours_utc' => $this->number($account['timezone_offset_hours_utc'] ?? null),
                'business_name' => $this->text($account['business_name'] ?? null, 255),
                'disable_reason' => $this->unsignedInteger($account['disable_reason'] ?? null, 65535),
                'spend_cap' => $this->number($account['spend_cap'] ?? null),
                'amount_spent' => $this->number($account['amount_spent'] ?? null),
                'balance' => $this->number($account['balance'] ?? null),
                'raw_payload' => $this->json($account),
                'last_seen_at' => $syncedAt,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }
        if ($rows === []) {
            throw new MetaAdsApiException('Meta Ads 授权下没有可访问的广告账户。', 'meta_ads_accounts_empty');
        }

        MetaAdAccount::query()->upsert(
            array_values($rows),
            ['organization_id', 'store_id', 'meta_account_id'],
            [
                'name', 'account_status', 'currency', 'timezone_name', 'timezone_offset_hours_utc',
                'business_name', 'disable_reason', 'spend_cap', 'amount_spent', 'balance',
                'raw_payload', 'last_seen_at', 'synced_at', 'updated_at',
            ],
        );

        return MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->whereIn('meta_account_id', array_keys($rows))
            ->get();
    }

    /**
     * @param  Collection<int, MetaAdAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function shardRows(
        Store $store,
        SyncJob $job,
        Collection $accounts,
        CarbonImmutable $since,
        CarbonImmutable $until,
        string $mode,
        bool $reconcileIncremental = true,
    ): array {
        $rows = [];
        $timestamp = now();
        $timezone = $store->timezone ?: 'UTC';

        foreach ($accounts as $account) {
            if ($mode !== 'incremental') {
                foreach (['campaigns', 'ad_sets', 'ads'] as $kind) {
                    $key = "{$account->meta_account_id}:{$kind}:{$since->utc()->format('YmdHi')}:{$until->utc()->format('YmdHi')}";
                    $rows[] = $this->shardRow($store, $job, $account, $key, $kind, null, $mode, $since, $until, null, null, $timestamp);
                }
            }

            if ($mode === 'structure') {
                continue;
            }

            if ($mode === 'incremental') {
                $accountTimezone = $account->timezone_name ?: $timezone;
                $hourSince = $until->subHour();
                try {
                    $date = $hourSince->setTimezone($accountTimezone)->toDateString();
                } catch (Throwable) {
                    $date = $hourSince->utc()->toDateString();
                }
                foreach (self::INSIGHT_LEVELS as $level) {
                    $key = "{$account->meta_account_id}:insights:{$level}:{$date}:day";
                    $rows[] = $this->shardRow(
                        $store,
                        $job,
                        $account,
                        $key,
                        'insights',
                        $level,
                        'incremental_day',
                        $hourSince,
                        $until,
                        $date,
                        $date,
                        $timestamp,
                    );
                }

                if (! $reconcileIncremental) {
                    continue;
                }

                $firstDate = $since->setTimezone($timezone)->startOfDay()->toDateString();
                $lastDate = $until->setTimezone($timezone)->toDateString();
                foreach (self::INSIGHT_LEVELS as $level) {
                    $key = "{$account->meta_account_id}:insights:{$level}:{$firstDate}:{$lastDate}:reconcile";
                    $rows[] = $this->shardRow(
                        $store,
                        $job,
                        $account,
                        $key,
                        'insights',
                        $level,
                        'reconcile',
                        $since,
                        $until,
                        $firstDate,
                        $lastDate,
                        $timestamp,
                    );
                }

                continue;
            }

            $firstDate = $since->setTimezone($timezone)->startOfDay()->toDateString();
            $lastDate = $until->setTimezone($timezone)->toDateString();
            foreach (self::INSIGHT_LEVELS as $level) {
                $key = "{$account->meta_account_id}:insights:{$level}:{$firstDate}:{$lastDate}:async";
                $rows[] = $this->shardRow(
                    $store,
                    $job,
                    $account,
                    $key,
                    'insights',
                    $level,
                    $mode,
                    $since,
                    $until,
                    $firstDate,
                    $lastDate,
                    $timestamp,
                );
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function shardRow(
        Store $store,
        SyncJob $job,
        MetaAdAccount $account,
        string $key,
        string $kind,
        ?string $level,
        string $mode,
        CarbonImmutable $since,
        CarbonImmutable $until,
        ?string $sinceDate,
        ?string $untilDate,
        mixed $timestamp,
    ): array {
        return [
            'uuid' => (string) Str::uuid(),
            'sync_job_id' => (int) $job->getKey(),
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->getKey(),
            'meta_ad_account_id' => (int) $account->getKey(),
            'shard_key' => mb_substr($key, 0, 191),
            'kind' => $kind,
            'level' => $level,
            'mode' => $mode,
            'status' => 'queued',
            'since_at' => $since->utc(),
            'until_at' => $until->utc(),
            'since_date' => $sinceDate,
            'until_date' => $untilDate,
            'attempts' => 0,
            'records_count' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function resumableJob(Store $store, string $mode): ?SyncJob
    {
        return SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', self::SYNC_TYPE)
            ->where('mode', $mode)
            ->whereIn('status', ['running', 'failed'])
            ->where('created_at', '>=', now()->subHours(48))
            ->latest('id')
            ->get()
            ->first(fn (SyncJob $job): bool => data_get($job->payload, 'strategy') === 'resumable_shards'
                && MetaAdSyncShard::query()->where('sync_job_id', $job->getKey())->exists());
    }

    private function resumeJob(Store $store, SyncJob $job): void
    {
        DB::transaction(function () use ($store, $job): void {
            $this->upgradeFullInsightShards($store, $job);
            MetaAdSyncShard::query()
                ->where('sync_job_id', $job->getKey())
                ->where(function ($query): void {
                    $query->where('status', 'failed')
                        ->orWhere(function ($stale): void {
                            $stale->where('status', 'running')->where('updated_at', '<', now()->subMinutes(20));
                        });
                })
                ->update([
                    'status' => 'queued',
                    'finished_at' => null,
                    'error_code' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            $job->forceFill([
                'status' => 'running',
                'finished_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'error_code' => null,
            ])->save();
            $this->state($store)->forceFill([
                'status' => 'running',
                'last_job_id' => $job->getKey(),
                'last_error_code' => null,
                'last_error' => null,
            ])->save();
        });
        $this->refreshJobProgress((int) $job->getKey());
    }

    /**
     * Older in-flight imports used one synchronous Insights request for each
     * account, level, and month. Replace only unfinished checkpoints with one
     * resumable async report per account and level. Completed data remains in
     * place and stale queued jobs safely become no-ops when their shard row is
     * gone.
     */
    private function upgradeFullInsightShards(Store $store, SyncJob $job): void
    {
        if (! in_array($job->mode, ['full', 'priority', 'backfill'], true)
            || data_get($job->payload, 'insight_strategy') === 'async_report_full_range_v1'
            || ! $job->since_at
            || ! $job->until_at) {
            return;
        }

        $baselineCompleted = MetaAdSyncShard::query()
            ->where('sync_job_id', $job->getKey())
            ->where('status', 'completed')
            ->count();
        MetaAdSyncShard::query()
            ->where('sync_job_id', $job->getKey())
            ->where('kind', 'insights')
            ->where('status', '!=', 'completed')
            ->delete();

        $accounts = MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->get();
        $rows = array_values(array_filter(
            $this->shardRows(
                $store,
                $job,
                $accounts,
                CarbonImmutable::parse($job->since_at),
                CarbonImmutable::parse($job->until_at),
                (string) $job->mode,
            ),
            fn (array $row): bool => $row['kind'] === 'insights',
        ));
        MetaAdSyncShard::query()->insertOrIgnore($rows);

        $totalItems = MetaAdSyncShard::query()->where('sync_job_id', $job->getKey())->count();
        $payload = [
            ...($job->payload ?? []),
            'insight_strategy' => 'async_report_full_range_v1',
            'eta_started_at' => now()->toIso8601String(),
            'eta_baseline_completed' => $baselineCompleted,
            'shards' => $totalItems,
        ];
        $job->forceFill([
            'payload' => $payload,
            'total_items' => $totalItems,
        ])->save();
    }

    /** @return list<int> */
    private function pendingShardIds(SyncJob $job): array
    {
        return MetaAdSyncShard::query()
            ->where('sync_job_id', $job->getKey())
            ->where('status', 'queued')
            ->orderByRaw("CASE WHEN kind = 'insights' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function recordShardError(MetaAdSyncShard $shard, Throwable $exception, string $status): void
    {
        $safeMessage = $exception instanceof MetaAdsApiException
            ? $exception->getMessage()
            : 'Meta Ads 分片同步失败，请稍后重试。';
        $errorCode = $exception instanceof MetaAdsApiException
            ? $exception->errorCode
            : 'meta_ads_shard_failed';
        $shard->forceFill([
            'status' => $status,
            'finished_at' => $status === 'failed' ? now() : null,
            'last_error' => mb_substr($safeMessage, 0, 2000),
            'error_code' => $errorCode,
        ])->save();
    }

    private function refreshJobProgress(int $syncJobId): void
    {
        $completed = MetaAdSyncShard::query()->where('sync_job_id', $syncJobId)->where('status', 'completed')->count();
        $failed = MetaAdSyncShard::query()->where('sync_job_id', $syncJobId)->where('status', 'failed')->count();
        SyncJob::query()->whereKey($syncJobId)->update([
            'processed_items' => $completed,
            'failed_items' => $failed,
            'updated_at' => now(),
        ]);
    }

    private function state(Store $store): StoreSyncState
    {
        return StoreSyncState::query()->firstOrCreate([
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
            'sync_type' => self::SYNC_TYPE,
        ], [
            'status' => 'idle',
            'next_sync_at' => now(),
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(Store $store, string $mode, bool $rollingIncremental = true): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $until = CarbonImmutable::now($timezone)->startOfHour();

        if ($mode === 'full') {
            $historyMonths = max(1, (int) config('services.meta_ads.history_months', 1));

            return $this->currentYear->clampGeneratedRange(
                $until->subMonthsNoOverflow($historyMonths)->startOfDay(),
                $until,
                $timezone,
            );
        }

        if ($mode === 'priority') {
            $priorityDays = max(1, (int) config('services.meta_ads.priority_days', 7));

            return $this->currentYear->clampGeneratedRange(
                $until->subDays($priorityDays - 1)->startOfDay(),
                $until,
                $timezone,
            );
        }

        if ($mode === 'backfill') {
            $historyMonths = max(1, (int) config('services.meta_ads.history_months', 6));
            $priorityDays = max(1, (int) config('services.meta_ads.priority_days', 7));

            return $this->currentYear->clampGeneratedRange(
                $until->subMonthsNoOverflow($historyMonths)->startOfDay(),
                $until->subDays($priorityDays)->endOfDay(),
                $timezone,
            );
        }

        if ($mode === 'structure') {
            $rollingDays = max(1, (int) config('services.meta_ads.rolling_days', 3));

            return $this->currentYear->clampGeneratedRange(
                $until->subDays($rollingDays - 1)->startOfDay(),
                $until,
                $timezone,
            );
        }

        if (! $rollingIncremental) {
            return $this->currentYear->clampGeneratedRange($until->subHour(), $until, $timezone);
        }

        $rollingDays = max(1, (int) config('services.meta_ads.rolling_days', 3));

        return $this->currentYear->clampGeneratedRange(
            $until->subDays($rollingDays - 1)->startOfDay(),
            $until,
            $timezone,
        );
    }

    private function startJob(
        Store $store,
        string $mode,
        string $requestedMode,
        ?CarbonInterface $since,
        CarbonInterface $until,
        string $strategy = 'single_job',
        ?string $credentialVersion = null,
    ): SyncJob {
        return DB::transaction(function () use ($store, $mode, $requestedMode, $since, $until, $strategy, $credentialVersion): SyncJob {
            $job = SyncJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'type' => self::SYNC_TYPE,
                'direction' => 'pull',
                'mode' => $mode,
                'status' => 'running',
                'since_at' => $since?->utc(),
                'until_at' => $until->utc(),
                'payload' => [
                    'source' => 'scheduled_meta_api',
                    'requested_mode' => $requestedMode,
                    'effective_mode' => $mode,
                    'scope' => 'store',
                    'strategy' => $strategy,
                    'credential_version' => $credentialVersion,
                ],
                'logs' => [[
                    'level' => 'info',
                    'message' => 'Meta Ads 店铺级同步已启动。',
                    'at' => now()->toIso8601String(),
                ]],
                'attempts' => 1,
                'max_attempts' => 1,
                'available_at' => now(),
                'started_at' => now(),
            ]);

            $this->state($store)->forceFill([
                'status' => 'running',
                'last_job_id' => $job->getKey(),
                'last_error_code' => null,
                'last_error' => null,
            ])->save();

            return $job;
        });
    }

    private function ensureShardActive(MetaAdSyncShard $shard, Store $store): void
    {
        if (! MetaAdSyncShard::query()->whereKey($shard->getKey())->whereIn('status', ['queued', 'running'])->exists()) {
            throw new MetaAdsApiException('Meta Ads 同步已取消。', 'meta_ads_sync_cancelled');
        }

        $this->ensureStoreCredentialActive($store, $shard->sync_job_id);
    }

    private function ensureStoreCredentialActive(Store $store, ?int $syncJobId = null): void
    {
        $credential = $this->metaCredential($store);
        if (! $credential) {
            throw new MetaAdsApiException('Meta Ads 同步已取消。', 'meta_ads_sync_cancelled');
        }

        if ($syncJobId === null) {
            return;
        }

        $expected = data_get(SyncJob::query()->find($syncJobId)?->payload, 'credential_version');
        $current = $credential->updated_at?->utc()->format('Y-m-d H:i:s') ?? '';
        if (is_string($expected) && $expected !== '' && ! hash_equals($expected, $current)) {
            throw new MetaAdsApiException('Meta Ads Token 已变更，本次旧同步已取消。', 'meta_ads_sync_cancelled');
        }
    }

    private function metaCredential(Store $store): ?StoreBusinessCredential
    {
        return StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('provider', 'meta_ads')
            ->where('credential_key', 'access_token')
            ->first();
    }

    /** @param array<string, int|string|bool> $result */
    private function completeJob(SyncJob $job, array $result): void
    {
        $freshness = MetaAdInsight::query()
            ->forOrganization((int) $job->organization_id)
            ->forStore((int) $job->store_id)
            ->where('level', 'account')
            ->where('granularity', 'day')
            ->latest('date_stop')
            ->latest('synced_at')
            ->first(['date_stop', 'synced_at']);

        DB::transaction(function () use ($freshness, $job, $result): void {
            $finishedAt = now();
            $records = (int) ($result['records_count'] ?? 0);
            $job->forceFill([
                'status' => 'completed',
                'result' => $result,
                'total_items' => $records,
                'processed_items' => $records,
                'finished_at' => $finishedAt,
                'completed_at' => $finishedAt,
                'failed_at' => null,
                'last_error' => null,
                'error_code' => null,
                'logs' => [
                    ...($job->logs ?? []),
                    ['level' => 'success', 'message' => 'Meta Ads 店铺级同步完成。', 'at' => $finishedAt->toIso8601String()],
                ],
            ])->save();

            $state = StoreSyncState::query()->where([
                'organization_id' => $job->organization_id,
                'store_id' => $job->store_id,
                'sync_type' => self::SYNC_TYPE,
            ])->lockForUpdate()->firstOrFail();
            $attributes = [
                'status' => 'idle',
                'watermark_at' => $job->until_at ?? $finishedAt,
                'last_success_at' => $finishedAt,
                'last_job_id' => $job->getKey(),
                'consecutive_failures' => 0,
                'next_sync_at' => $finishedAt->copy()->addHour(),
                'last_error_code' => null,
                'last_error' => null,
            ];
            if ($freshness) {
                $attributes['last_metric_date'] = $freshness->date_stop;
                $attributes['data_synced_at'] = $freshness->synced_at;
            }
            if ($job->mode === 'incremental') {
                $attributes['last_incremental_sync_at'] = $finishedAt;
                $attributes['last_reconciled_at'] = $finishedAt;
            } elseif (in_array($job->mode, ['full', 'backfill'], true)) {
                $attributes['last_full_sync_at'] = $finishedAt;
            }
            $state->forceFill($attributes)->save();
        });
    }

    private function failJob(SyncJob $job, Throwable $exception): void
    {
        DB::transaction(function () use ($job, $exception): void {
            $finishedAt = now();
            $safeMessage = $exception instanceof MetaAdsApiException
                ? $exception->getMessage()
                : 'Meta Ads 同步失败，请检查授权或稍后重试。';
            $errorCode = $exception instanceof MetaAdsApiException
                ? $exception->errorCode
                : 'meta_ads_sync_failed';
            $job->forceFill([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'failed_at' => $finishedAt,
                'last_error' => mb_substr($safeMessage, 0, 2000),
                'error_code' => $errorCode,
                'logs' => [
                    ...($job->logs ?? []),
                    ['level' => 'error', 'message' => mb_substr($safeMessage, 0, 500), 'at' => $finishedAt->toIso8601String()],
                ],
            ])->save();

            $state = StoreSyncState::query()->where([
                'organization_id' => $job->organization_id,
                'store_id' => $job->store_id,
                'sync_type' => self::SYNC_TYPE,
            ])->lockForUpdate()->first();
            if ($state) {
                $state->forceFill([
                    'status' => 'failed',
                    'last_job_id' => $job->getKey(),
                    'last_failed_at' => $finishedAt,
                    'consecutive_failures' => $state->consecutive_failures + 1,
                    'next_sync_at' => $finishedAt->copy()->addMinutes(15),
                    'last_error_code' => $errorCode,
                    'last_error' => mb_substr($safeMessage, 0, 2000),
                ])->save();
            }
        });
    }

    /**
     * Meta's object edges only support a lower-bound updated_time filter. Apply
     * the upper bound locally so an hourly run never imports the current,
     * incomplete hour.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function updatedWithin(
        array $records,
        ?CarbonImmutable $since,
        CarbonImmutable $until,
    ): array {
        return array_values(array_filter($records, function (array $record) use ($since, $until): bool {
            $value = $record['updated_time'] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return false;
            }

            try {
                $updatedAt = CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                return false;
            }

            return ($since === null || $updatedAt->greaterThanOrEqualTo($since->utc()))
                && $updatedAt->lessThan($until->utc());
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function forPreviousAccountHour(
        array $records,
        MetaAdAccount $account,
        Store $store,
        CarbonImmutable $since,
        CarbonImmutable $until,
    ): array {
        if (! $since->addHour()->equalTo($until)) {
            return [];
        }

        $timezone = $account->timezone_name ?: ($store->timezone ?: 'UTC');

        try {
            $accountHour = $since->setTimezone($timezone);
        } catch (Throwable) {
            $accountHour = $since->utc();
        }

        $expectedDate = $accountHour->toDateString();
        $hour = $accountHour->format('H');
        $expectedRange = "{$hour}:00:00 - {$hour}:59:59";

        return array_values(array_filter(
            $records,
            fn (array $record): bool => ($record['date_start'] ?? null) === $expectedDate
                && ($record['date_stop'] ?? null) === $expectedDate
                && ($record['hourly_stats_aggregated_by_advertiser_time_zone'] ?? null) === $expectedRange,
        ));
    }

    private function hourStart(string $date, string $hourlyRange, string $timezone): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{2}):00:00 - \d{2}:59:59$/', $hourlyRange, $matches)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                "{$date} {$matches[1]}:00:00",
                $timezone,
            )->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function pruneBefore(Store $store, CarbonImmutable $cutoff): void
    {
        $organizationId = (int) $store->organization_id;
        $storeId = (int) $store->getKey();
        $cutoffUtc = $cutoff->utc();

        DB::transaction(function () use ($organizationId, $storeId, $cutoff, $cutoffUtc): void {
            MetaAdInsight::query()
                ->forOrganization($organizationId)
                ->forStore($storeId)
                ->where('date_stop', '<', $cutoff->toDateString())
                ->delete();

            foreach ([MetaAd::class, MetaAdSet::class, MetaAdCampaign::class] as $model) {
                $model::query()
                    ->forOrganization($organizationId)
                    ->forStore($storeId)
                    ->where(function ($query) use ($cutoffUtc): void {
                        $query->whereNull('source_updated_at')
                            ->orWhere('source_updated_at', '<', $cutoffUtc);
                    })
                    ->delete();
            }

            $referencedCreativeIds = MetaAd::query()
                ->forOrganization($organizationId)
                ->forStore($storeId)
                ->whereNotNull('meta_creative_id')
                ->select('meta_creative_id');
            MetaAdCreative::query()
                ->forOrganization($organizationId)
                ->forStore($storeId)
                ->whereNotIn('meta_creative_id', $referencedCreativeIds)
                ->delete();
        });
    }

    /** @return array{organization_id: int, store_id: int, meta_ad_account_id: int} */
    private function scope(Store $store, MetaAdAccount $account): array
    {
        return [
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->getKey(),
            'meta_ad_account_id' => (int) $account->getKey(),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    private function upsertRows(string $model, array $rows, array $uniqueBy, array $update): void
    {
        if ($rows !== []) {
            $model::query()->upsert(array_values($rows), $uniqueBy, $update);
        }
    }

    /** @param array<string, mixed> $record */
    private function insightEntityId(array $record, string $level): ?string
    {
        return $this->text(match ($level) {
            'account' => $record['account_id'] ?? null,
            'campaign' => $record['campaign_id'] ?? null,
            'adset' => $record['adset_id'] ?? null,
            'ad' => $record['ad_id'] ?? null,
            default => null,
        }, 64);
    }

    /** @return list<string> */
    private function purchaseTypes(): array
    {
        return [
            'omni_purchase',
            'purchase',
            'offsite_conversion.fb_pixel_purchase',
            'onsite_conversion.purchase',
        ];
    }

    /** @param list<array<string, mixed>> $actions @param list<string> $types */
    private function actionMetric(array $actions, array $types): ?string
    {
        foreach ($types as $type) {
            foreach ($actions as $action) {
                if (is_array($action) && ($action['action_type'] ?? null) === $type) {
                    return $this->number($action['value'] ?? null);
                }
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $actions */
    private function firstActionValue(array $actions): ?string
    {
        foreach ($actions as $action) {
            if (is_array($action) && ($value = $this->number($action['value'] ?? null)) !== null) {
                return $value;
            }
        }

        return null;
    }

    private function text(mixed $value, ?int $limit = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $limit === null ? $text : mb_substr($text, 0, $limit);
    }

    private function number(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }

    private function unsignedInteger(mixed $value, int $maximum = PHP_INT_MAX): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min($maximum, (int) $value));
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($value)->utc();
            $unixTimestamp = $timestamp->getTimestamp();

            return $unixTimestamp >= 1 && $unixTimestamp <= 2_147_483_647
                ? $timestamp
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? $this->json($value) : null;
    }
}
