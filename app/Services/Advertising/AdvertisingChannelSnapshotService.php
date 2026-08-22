<?php

namespace App\Services\Advertising;

use App\Jobs\RefreshAdvertisingChannelSnapshot;
use App\Models\AnalyticsSnapshot;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdvertisingChannelSnapshotService
{
    private const REPORT_KEY = 'ads:channel-performance';

    private const SCHEMA_VERSION = 1;

    private const FRESH_MINUTES = 360;

    private const RETRY_MINUTES = 15;

    public function __construct(private AdvertisingChannelApiService $api) {}

    /**
     * Read the persisted channel snapshot. This method never calls an ad API in
     * the web request; missing and stale snapshots are refreshed by the queue.
     *
     * @return array<string, mixed>
     */
    public function report(Store $store, string $from, string $to): array
    {
        $snapshot = $this->snapshot($store, $from, $to);

        if ($snapshot?->expires_at?->isFuture()) {
            return $this->stored($snapshot, false);
        }

        if ($snapshot) {
            $snapshot = $this->beginRefresh($snapshot);

            return $this->stored($snapshot, true);
        }

        $now = now();
        $snapshot = AnalyticsSnapshot::query()->firstOrCreate([
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
            'report_key' => self::REPORT_KEY,
            'period_from' => $from,
            'period_to' => $to,
            'schema_version' => self::SCHEMA_VERSION,
        ], [
            'timezone' => $store->timezone ?: 'UTC',
            'source' => 'refreshing',
            'payload' => $this->pendingPayload((string) Str::uuid()),
            'fetched_at' => $now,
            'expires_at' => $now->copy()->subSecond(),
        ]);

        $this->queueRefresh($snapshot);

        return $this->stored($snapshot->fresh() ?? $snapshot, true);
    }

    public function refreshChannel(int $snapshotId, string $channelKey, string $generation): void
    {
        $snapshot = AnalyticsSnapshot::query()->with('store')->find($snapshotId);
        if (! $snapshot || $snapshot->report_key !== self::REPORT_KEY || ! in_array($channelKey, $this->api->channelKeys(), true)) {
            return;
        }

        $store = $snapshot->store;
        if (! $store || (int) $store->organization_id !== (int) $snapshot->organization_id) {
            return;
        }

        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        if (($payload['refresh_generation'] ?? null) !== $generation) {
            return;
        }

        $channel = $this->api->fetchChannel(
            $store,
            $channelKey,
            $snapshot->period_from->toDateString(),
            $snapshot->period_to->toDateString(),
        );

        DB::transaction(function () use ($snapshotId, $channelKey, $generation, $channel): void {
            $current = AnalyticsSnapshot::query()->lockForUpdate()->find($snapshotId);
            if (! $current || $current->report_key !== self::REPORT_KEY) {
                return;
            }

            $payload = is_array($current->payload) ? $current->payload : [];
            if (($payload['refresh_generation'] ?? null) !== $generation) {
                return;
            }

            $channelOrder = array_flip($this->api->channelKeys());
            $channels = collect(is_array($payload['channels'] ?? null) ? $payload['channels'] : [])
                ->filter(fn (mixed $item): bool => is_array($item) && ($item['key'] ?? null) !== $channelKey)
                ->push($channel)
                ->sortBy(fn (array $item): int => $channelOrder[$item['key'] ?? ''] ?? PHP_INT_MAX)
                ->values()
                ->all();
            $completed = collect(is_array($payload['completed_channels'] ?? null) ? $payload['completed_channels'] : [])
                ->push($channelKey)
                ->unique()
                ->values()
                ->all();
            $pending = count($completed) < count($this->api->channelKeys());
            $failedChannels = collect($channels)
                ->filter(fn (array $item): bool => (bool) ($item['configured'] ?? false) && ! (bool) ($item['available'] ?? false))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            $configuredCount = collect($channels)->where('configured', true)->count();
            $available = collect($channels)->where('available', true)->isNotEmpty();
            $complete = ! $pending && $configuredCount > 0 && $failedChannels === [];
            $message = $pending ? '广告平台数据正在后台同步，请稍后重试。' : match (true) {
                $configuredCount === 0 => '尚未配置广告平台凭据。',
                $failedChannels !== [] && $available => '部分广告平台同步失败：'.implode('、', $failedChannels).'。',
                $failedChannels !== [] => '广告平台同步失败，请检查授权后重试。',
                default => null,
            };
            $payload = [
                'available' => $available,
                'complete' => $complete,
                'pending' => $pending,
                'source' => 'advertising_apis',
                'message' => $message,
                'failed_channels' => $failedChannels,
                'refresh_generation' => $generation,
                'refresh_started_at' => $payload['refresh_started_at'] ?? now()->toIso8601String(),
                'completed_channels' => $completed,
                'channels' => $channels,
            ];
            $now = now();

            $current->fill([
                'source' => $pending ? 'refreshing' : 'advertising_apis',
                'payload' => $payload,
                'fetched_at' => $pending ? $current->fetched_at : $now,
                'expires_at' => $pending
                    ? $now->copy()->subSecond()
                    : $now->copy()->addMinutes($complete || $configuredCount === 0 ? self::FRESH_MINUTES : self::RETRY_MINUTES),
            ])->save();
        });
    }

    private function snapshot(Store $store, string $from, string $to): ?AnalyticsSnapshot
    {
        return AnalyticsSnapshot::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('report_key', self::REPORT_KEY)
            ->whereDate('period_from', $from)
            ->whereDate('period_to', $to)
            ->where('schema_version', self::SCHEMA_VERSION)
            ->first();
    }

    /** @return array<string, mixed> */
    private function stored(AnalyticsSnapshot $snapshot, bool $stale): array
    {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : $this->pendingPayload((string) Str::uuid());
        $payload['storage'] = [
            'persisted' => true,
            'source' => 'database',
            'stale' => $stale,
            'pending' => $snapshot->source === 'refreshing' || (bool) ($payload['pending'] ?? false),
            'refreshing' => $stale,
            'fetched_at' => $snapshot->fetched_at?->toIso8601String(),
            'expires_at' => $snapshot->expires_at?->toIso8601String(),
        ];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function pendingPayload(string $generation): array
    {
        return [
            'available' => false,
            'complete' => false,
            'pending' => true,
            'source' => 'advertising_apis',
            'message' => '广告平台数据正在后台同步，请稍后重试。',
            'failed_channels' => [],
            'refresh_generation' => $generation,
            'refresh_started_at' => now()->toIso8601String(),
            'completed_channels' => [],
            'channels' => [],
        ];
    }

    private function beginRefresh(AnalyticsSnapshot $snapshot): AnalyticsSnapshot
    {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $startedAt = isset($payload['refresh_started_at'])
            ? CarbonImmutable::parse((string) $payload['refresh_started_at'])
            : null;
        $refreshing = $snapshot->source === 'refreshing' && $startedAt?->greaterThan(now()->subMinutes(10));
        if ($refreshing) {
            return $snapshot;
        }

        $generation = (string) Str::uuid();
        $payload = [
            ...$payload,
            'available' => (bool) ($payload['available'] ?? false),
            'complete' => false,
            'pending' => true,
            'source' => 'advertising_apis',
            'message' => '广告平台数据正在后台同步，请稍后重试。',
            'failed_channels' => [],
            'refresh_generation' => $generation,
            'refresh_started_at' => now()->toIso8601String(),
            'completed_channels' => [],
        ];
        $snapshot->fill(['source' => 'refreshing', 'payload' => $payload])->save();
        $this->queueRefresh($snapshot);

        return $snapshot->fresh() ?? $snapshot;
    }

    private function queueRefresh(AnalyticsSnapshot $snapshot): void
    {
        $generation = (string) data_get($snapshot->payload, 'refresh_generation', '');
        if ($generation === '') {
            return;
        }

        foreach ($this->api->channelKeys() as $channelKey) {
            RefreshAdvertisingChannelSnapshot::dispatch($snapshot->getKey(), $channelKey, $generation);
        }
    }
}
