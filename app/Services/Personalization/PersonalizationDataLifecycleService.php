<?php

namespace App\Services\Personalization;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationCheckoutSetting;
use App\Models\PersonalizationDailyMetric;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationGlobalSetting;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PersonalizationDataLifecycleService
{
    public function __construct(
        private PersonalizationDailyMetricsService $dailyMetrics,
        private PersonalizationShopGuard $shopGuard,
    ) {}

    /** @return array<string, int> */
    public function pruneRetention(): array
    {
        $rawDays = (int) config('personalization.retention.raw_event_days', 90);
        $attributionDays = (int) config('personalization.retention.attribution_days', 90);
        $aggregateMonths = (int) config('personalization.retention.aggregate_months', 13);
        $auditDays = (int) config('personalization.retention.audit_days', 365);
        $eventCutoff = now()->subDays($rawDays);
        $attributionCutoff = now()->subDays($attributionDays);
        $result = [
            'rollups' => 0,
            'events_deleted' => 0,
            'attributions_deleted' => 0,
            'aggregates_deleted' => 0,
            'audits_deleted' => 0,
            'deletion_snapshots_deleted' => 0,
            'strategies_purged' => 0,
        ];

        PersonalizationEventSource::query()
            ->with(['store.organization'])
            ->chunkById(100, function ($sources) use ($eventCutoff, $attributionCutoff, &$result): void {
                foreach ($sources as $source) {
                    $store = $source->store;
                    if (! $store || ! $store->organization) {
                        continue;
                    }
                    $this->shopGuard->assertAllowed((string) $store->shopify_domain);
                    $this->rollupPendingDates($store, $eventCutoff, $attributionCutoff, $result);
                    $result['attributions_deleted'] += PersonalizationAttribution::query()
                        ->where('organization_id', $store->organization_id)
                        ->where('store_id', $store->id)
                        ->where('ordered_at', '<', $attributionCutoff)
                        ->delete();
                    $result['events_deleted'] += PersonalizationEvent::query()
                        ->where('organization_id', $store->organization_id)
                        ->where('store_id', $store->id)
                        ->where('occurred_at', '<', $eventCutoff)
                        ->delete();
                }
            });

        $result['aggregates_deleted'] = PersonalizationDailyMetric::query()
            ->whereDate('metric_date', '<', CarbonImmutable::now('UTC')->subMonthsNoOverflow($aggregateMonths)->toDateString())
            ->delete();
        $result['audits_deleted'] = AuditLog::query()
            ->where('action', 'like', 'personalization_%')
            ->where('created_at', '<', now()->subDays($auditDays))
            ->delete();
        $result['deletion_snapshots_deleted'] = DB::table('personalization_strategy_deletions')
            ->where('deleted_at', '<', now()->subDays($auditDays))
            ->delete();
        PersonalizationRecommendationStrategy::onlyTrashed()
            ->where('status', 'archived')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($strategies) use (&$result): void {
                foreach ($strategies as $strategy) {
                    PersonalizationRecommendationComponent::withTrashed()
                        ->where('strategy_id', $strategy->id)
                        ->forceDelete();
                    $strategy->forceDelete();
                    $result['strategies_purged']++;
                }
            });

        return $result;
    }

    /** @return array{stores_purged: int} */
    public function purgeDueStores(): array
    {
        $purged = 0;
        PersonalizationEventSource::query()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->with(['store.organization'])
            ->orderBy('id')
            ->get()
            ->each(function (PersonalizationEventSource $source) use (&$purged): void {
                $store = $source->store;
                if ($store && $store->organization) {
                    $this->shopGuard->assertAllowed((string) $store->shopify_domain);
                    $this->purgeStore($store);
                    $purged++;
                }
            });

        return ['stores_purged' => $purged];
    }

    private function rollupPendingDates(
        Store $store,
        mixed $eventCutoff,
        mixed $attributionCutoff,
        array &$result,
    ): void {
        $timezone = $store->timezone ?: 'UTC';
        $dates = [];
        foreach (PersonalizationEvent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('occurred_at', '<', $eventCutoff)
            ->cursor(['occurred_at']) as $event) {
            $dates[$event->occurred_at->setTimezone($timezone)->toDateString()] = true;
        }
        foreach (PersonalizationAttribution::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('ordered_at', '<', $attributionCutoff)
            ->cursor(['ordered_at']) as $attribution) {
            $dates[$attribution->ordered_at->setTimezone($timezone)->toDateString()] = true;
        }
        $yesterday = CarbonImmutable::now($timezone)->subDay()->toDateString();
        $dates[$yesterday] = true;
        ksort($dates);
        foreach (array_keys($dates) as $date) {
            $this->dailyMetrics->rollup($store, $date);
            $result['rollups']++;
        }
    }

    private function purgeStore(Store $store): void
    {
        DB::transaction(function () use ($store): void {
            $app = App::query()->where('handle', (string) config('personalization.active.handle'))->first();
            if ($app) {
                AppInstallation::withTrashed()
                    ->where('app_id', $app->id)
                    ->where('store_id', $store->id)
                    ->update([
                        'access_token_encrypted' => null,
                        'refresh_token_encrypted' => null,
                        'token_type' => null,
                        'access_token_expires_at' => null,
                        'refresh_token_expires_at' => null,
                        'settings' => null,
                    ]);
                DB::table('webhook_events')
                    ->where('app_id', $app->id)
                    ->where('store_id', $store->id)
                    ->update([
                        'headers' => json_encode(['redacted' => true], JSON_THROW_ON_ERROR),
                        'payload' => json_encode(['storage' => 'redacted'], JSON_THROW_ON_ERROR),
                        'payload_encrypted' => null,
                        'payload_sha256' => null,
                    ]);
            }

            PersonalizationAttribution::query()->where('store_id', $store->id)->delete();
            PersonalizationDailyMetric::query()->where('store_id', $store->id)->delete();
            PersonalizationEvent::query()->where('store_id', $store->id)->delete();
            PersonalizationEventSource::query()->where('store_id', $store->id)->delete();
            PersonalizationCheckoutSetting::query()->where('store_id', $store->id)->delete();
            PersonalizationSmartCartSetting::query()->where('store_id', $store->id)->delete();
            PersonalizationGlobalSetting::query()->where('store_id', $store->id)->delete();
            PersonalizationRecommendationComponent::withTrashed()->where('store_id', $store->id)->forceDelete();
            PersonalizationRecommendationStrategy::withTrashed()->where('store_id', $store->id)->forceDelete();
            DB::table('personalization_strategy_deletions')->where('store_id', $store->id)->delete();

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'personalization_online_data_purged',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'retention_policy' => 'uninstall_or_shop_redact',
                ],
            ]);
        });
    }
}
