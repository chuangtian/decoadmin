<?php

namespace App\Services\Personalization;

use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationDailyMetric;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PersonalizationDailyMetricsService
{
    /** @return array{rows: int, events: int, orders: int} */
    public function rollup(Store $store, string $localDate): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $timezone);
        if (! $day || $day->format('Y-m-d') !== $localDate) {
            throw new \InvalidArgumentException('Invalid Personalization metric date.');
        }
        $start = $day->startOfDay()->utc();
        $end = $day->endOfDay()->utc();
        $defaultCurrency = strtoupper((string) ($store->currency ?: 'USD'));
        $metrics = [];
        $componentKeys = [];
        $strategyKeys = [];
        $eventCount = 0;
        $orderCount = 0;

        foreach (PersonalizationEvent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->whereIn('event_name', [
                PersonalizationEventIngestionService::IMPRESSION,
                PersonalizationEventIngestionService::CLICK,
                PersonalizationEventIngestionService::ADD_TO_CART,
            ])
            ->orderBy('id')
            ->cursor(['event_name', 'component_id', 'strategy_id', 'placement']) as $event) {
            $componentKey = $this->componentKey($event->component_id, $componentKeys);
            $strategyKey = $this->strategyKey($event->strategy_id, $strategyKeys);
            $key = $this->dimensionKey($event->placement ?: 'unknown', $componentKey, $strategyKey, $defaultCurrency);
            $metrics[$key] ??= $this->row($store, $localDate, $event->placement ?: 'unknown', $componentKey, $strategyKey, $defaultCurrency);
            $column = match ($event->event_name) {
                PersonalizationEventIngestionService::IMPRESSION => 'impressions',
                PersonalizationEventIngestionService::CLICK => 'clicks',
                default => 'add_to_carts',
            };
            $metrics[$key][$column]++;
            $eventCount++;
        }

        foreach (PersonalizationAttribution::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereBetween('ordered_at', [$start, $end])
            ->whereIn('status', ['attributed', 'partially_refunded'])
            ->orderBy('id')
            ->cursor(['component_id', 'strategy_id', 'placement', 'currency', 'attributed_revenue']) as $attribution) {
            $componentKey = $this->componentKey($attribution->component_id, $componentKeys);
            $strategyKey = $this->strategyKey($attribution->strategy_id, $strategyKeys);
            $currency = strtoupper((string) $attribution->currency);
            $key = $this->dimensionKey($attribution->placement ?: 'unknown', $componentKey, $strategyKey, $currency);
            $metrics[$key] ??= $this->row($store, $localDate, $attribution->placement ?: 'unknown', $componentKey, $strategyKey, $currency);
            $metrics[$key]['orders']++;
            $metrics[$key]['attributed_revenue'] += max(0.0, (float) $attribution->attributed_revenue);
            $orderCount++;
        }

        DB::transaction(function () use ($store, $localDate, $metrics): void {
            PersonalizationDailyMetric::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->whereDate('metric_date', $localDate)
                ->delete();
            foreach ($metrics as $metric) {
                $metric['attributed_revenue'] = number_format($metric['attributed_revenue'], 4, '.', '');
                PersonalizationDailyMetric::query()->create($metric);
            }
        });

        return ['rows' => count($metrics), 'events' => $eventCount, 'orders' => $orderCount];
    }

    /** @param array<int, string> $cache */
    private function componentKey(?int $id, array &$cache): string
    {
        if (! $id) {
            return '';
        }

        return $cache[$id] ??= (string) (PersonalizationRecommendationComponent::withTrashed()
            ->whereKey($id)
            ->value('uuid') ?? '');
    }

    /** @param array<int, string> $cache */
    private function strategyKey(?int $id, array &$cache): string
    {
        if (! $id) {
            return '';
        }

        return $cache[$id] ??= (string) (PersonalizationRecommendationStrategy::withTrashed()
            ->whereKey($id)
            ->value('uuid') ?? '');
    }

    private function dimensionKey(string $placement, string $component, string $strategy, string $currency): string
    {
        return implode('|', [$placement, $component, $strategy, $currency]);
    }

    /** @return array<string, mixed> */
    private function row(
        Store $store,
        string $date,
        string $placement,
        string $component,
        string $strategy,
        string $currency,
    ): array {
        return [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'metric_date' => $date,
            'placement' => $placement,
            'component_key' => $component,
            'strategy_key' => $strategy,
            'currency' => $currency,
            'impressions' => 0,
            'clicks' => 0,
            'add_to_carts' => 0,
            'orders' => 0,
            'attributed_revenue' => 0.0,
        ];
    }
}
