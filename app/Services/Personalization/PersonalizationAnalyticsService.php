<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationStrategyVersion;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PersonalizationAnalyticsService
{
    private const ACTIVE_ATTRIBUTION_STATUSES = ['attributed', 'partially_refunded'];

    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return array<string, mixed> */
    public function dashboard(Store $store, User $actor, int $days = 30, ?string $from = null, ?string $to = null): array
    {
        $this->authorize($store, $actor);
        $days = max(1, min($days, 90));
        $timezone = $store->timezone ?: 'UTC';
        $localEnd = $to
            ? CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->endOfDay();
        $localStart = $from
            ? CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)->startOfDay()
            : $localEnd->startOfDay()->subDays($days - 1);
        $days = (int) $localStart->diffInDays($localEnd) + 1;
        $start = $localStart->utc();
        $end = $localEnd->utc();
        $currency = strtoupper((string) ($store->currency ?: 'USD'));

        $daily = [];
        for ($cursor = $localStart; $cursor->lte($localEnd); $cursor = $cursor->addDay()) {
            $daily[$cursor->toDateString()] = [
                'date' => $cursor->toDateString(),
                'impressions' => 0,
                'clicks' => 0,
                'add_to_carts' => 0,
                'orders' => 0,
                'attributed_revenue' => 0.0,
                'quantity' => 0,
                'sales' => 0.0,
                'discounts' => 0.0,
                'revenue' => 0.0,
            ];
        }
        $placements = [];
        $dimensions = [];
        $totals = [
            'impressions' => 0,
            'clicks' => 0,
            'add_to_carts' => 0,
            'orders' => 0,
            'attributed_revenue' => 0.0,
            'quantity' => 0,
            'sales' => 0.0,
            'discounts' => 0.0,
            'revenue' => 0.0,
            'reversed_orders' => 0,
            'excluded_currency_orders' => 0,
        ];

        foreach (PersonalizationEvent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->whereIn('event_name', [
                PersonalizationEventIngestionService::IMPRESSION,
                PersonalizationEventIngestionService::CLICK,
                PersonalizationEventIngestionService::ADD_TO_CART,
                PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_IMPRESSION,
                PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_CLICK,
                PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_ADD_SUCCESS,
            ])
            ->orderBy('id')
            ->cursor(['event_name', 'component_id', 'strategy_id', 'strategy_version_id', 'placement', 'occurred_at']) as $event) {
            $metric = match ($event->event_name) {
                PersonalizationEventIngestionService::IMPRESSION,
                PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_IMPRESSION => 'impressions',
                PersonalizationEventIngestionService::CLICK,
                PersonalizationEventIngestionService::CHECKOUT_RECOMMENDATION_CLICK => 'clicks',
                default => 'add_to_carts',
            };
            $date = $event->occurred_at->setTimezone($timezone)->toDateString();
            if (isset($daily[$date])) {
                $daily[$date][$metric]++;
            }
            $totals[$metric]++;
            $placement = $event->placement ?: 'unknown';
            $placements[$placement] ??= [
                'placement' => $placement,
                'impressions' => 0,
                'clicks' => 0,
                'add_to_carts' => 0,
                'orders' => 0,
                'attributed_revenue' => 0.0,
            ];
            $placements[$placement][$metric]++;
            $dimensionKey = implode('|', [
                (string) ($event->strategy_id ?? 0),
                (string) ($event->strategy_version_id ?? 0),
                (string) ($event->component_id ?? 0),
                $placement,
            ]);
            $dimensions[$dimensionKey] ??= $this->dimensionRow($event->strategy_id, $event->strategy_version_id, $event->component_id, $placement);
            $dimensions[$dimensionKey][$metric]++;
        }

        $orderQuantities = DB::table('order_items')
            ->select('order_id')
            ->selectRaw('SUM(current_quantity) as quantity, SUM(attributed_sales) as sales')
            ->groupBy('order_id');

        foreach (PersonalizationAttribution::query()
            ->leftJoin('orders', function ($join): void {
                $join->on('orders.id', '=', 'personalization_attributions.order_id')
                    ->on('orders.organization_id', '=', 'personalization_attributions.organization_id')
                    ->on('orders.store_id', '=', 'personalization_attributions.store_id');
            })
            ->leftJoinSub($orderQuantities, 'order_quantities', fn ($join) => $join->on('order_quantities.order_id', '=', 'personalization_attributions.order_id'))
            ->where('personalization_attributions.organization_id', $store->organization_id)
            ->where('personalization_attributions.store_id', $store->id)
            ->whereBetween('personalization_attributions.ordered_at', [$start, $end])
            ->orderBy('personalization_attributions.id')
            ->select([
                'personalization_attributions.status',
                'personalization_attributions.currency',
                'personalization_attributions.component_id',
                'personalization_attributions.strategy_id',
                'personalization_attributions.strategy_version_id',
                'personalization_attributions.placement',
                'personalization_attributions.attributed_revenue',
                'personalization_attributions.ordered_at',
                DB::raw('COALESCE(order_quantities.quantity, 0) as attributed_quantity'),
                DB::raw('COALESCE(order_quantities.sales, 0) as attributed_sales'),
                DB::raw('COALESCE(orders.discount_total, 0) as attributed_discounts'),
            ])
            ->cursor() as $attribution) {
            if (! in_array($attribution->status, self::ACTIVE_ATTRIBUTION_STATUSES, true)) {
                $totals['reversed_orders']++;

                continue;
            }
            if (strtoupper((string) $attribution->currency) !== $currency) {
                $totals['excluded_currency_orders']++;

                continue;
            }
            $revenue = max(0.0, (float) $attribution->attributed_revenue);
            $quantity = max(0, (int) $attribution->attributed_quantity);
            $sales = max(0.0, (float) $attribution->attributed_sales);
            $discounts = max(0.0, (float) $attribution->attributed_discounts);
            $totals['orders']++;
            $totals['attributed_revenue'] += $revenue;
            $totals['quantity'] += $quantity;
            $totals['sales'] += $sales;
            $totals['discounts'] += $discounts;
            $totals['revenue'] += $revenue;
            $date = $attribution->ordered_at->setTimezone($timezone)->toDateString();
            if (isset($daily[$date])) {
                $daily[$date]['orders']++;
                $daily[$date]['attributed_revenue'] += $revenue;
                $daily[$date]['quantity'] += $quantity;
                $daily[$date]['sales'] += $sales;
                $daily[$date]['discounts'] += $discounts;
                $daily[$date]['revenue'] += $revenue;
            }
            $placement = $attribution->placement ?: 'unknown';
            $placements[$placement] ??= [
                'placement' => $placement,
                'impressions' => 0,
                'clicks' => 0,
                'add_to_carts' => 0,
                'orders' => 0,
                'attributed_revenue' => 0.0,
            ];
            $placements[$placement]['orders']++;
            $placements[$placement]['attributed_revenue'] += $revenue;
            $dimensionKey = implode('|', [
                (string) ($attribution->strategy_id ?? 0),
                (string) ($attribution->strategy_version_id ?? 0),
                (string) ($attribution->component_id ?? 0),
                $placement,
            ]);
            $dimensions[$dimensionKey] ??= $this->dimensionRow(
                $attribution->strategy_id,
                $attribution->strategy_version_id,
                $attribution->component_id,
                $placement,
            );
            $dimensions[$dimensionKey]['orders']++;
            $dimensions[$dimensionKey]['attributed_revenue'] += $revenue;
            $dimensions[$dimensionKey]['quantity'] += $quantity;
            $dimensions[$dimensionKey]['sales'] += $sales;
            $dimensions[$dimensionKey]['discounts'] += $discounts;
            $dimensions[$dimensionKey]['revenue'] += $revenue;
        }

        $formattedDaily = array_map(function (array $row): array {
            $row['attributed_revenue'] = number_format($row['attributed_revenue'], 2, '.', '');
            $row['discounts'] = number_format($row['discounts'], 2, '.', '');
            $row['sales'] = number_format($row['sales'], 2, '.', '');
            $row['revenue'] = number_format($row['revenue'], 2, '.', '');

            return $row;
        }, array_values($daily));
        $formattedPlacements = array_map(function (array $row): array {
            $row['attributed_revenue'] = number_format($row['attributed_revenue'], 2, '.', '');
            $row['click_through_rate'] = $row['impressions'] > 0
                ? round($row['clicks'] / $row['impressions'] * 100, 2)
                : 0.0;

            return $row;
        }, array_values($placements));
        usort($formattedPlacements, fn (array $left, array $right): int => (float) $right['attributed_revenue'] <=> (float) $left['attributed_revenue']);
        $formattedDimensions = $this->formatDimensions($dimensions, $store);

        $source = PersonalizationEventSource::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->first();
        $revenue = round($totals['attributed_revenue'], 2);

        return [
            'status' => $source?->last_event_at ? 'active' : 'awaiting_events',
            'period' => [
                'days' => $days,
                'from' => $localStart->toDateString(),
                'to' => $localEnd->toDateString(),
                'timezone' => $timezone,
            ],
            'currency' => $currency,
            'impressions' => $totals['impressions'],
            'clicks' => $totals['clicks'],
            'add_to_carts' => $totals['add_to_carts'],
            'orders' => $totals['orders'],
            'attributed_revenue' => number_format($revenue, 2, '.', ''),
            'aov' => number_format($totals['orders'] > 0 ? $revenue / $totals['orders'] : 0, 2, '.', ''),
            'quantity' => $totals['quantity'],
            'sales' => number_format($totals['sales'], 2, '.', ''),
            'discounts' => number_format($totals['discounts'], 2, '.', ''),
            'revenue' => number_format($totals['revenue'], 2, '.', ''),
            'click_through_rate' => $totals['impressions'] > 0
                ? round($totals['clicks'] / $totals['impressions'] * 100, 2)
                : 0.0,
            'add_to_cart_rate' => $totals['clicks'] > 0
                ? round($totals['add_to_carts'] / $totals['clicks'] * 100, 2)
                : 0.0,
            'reversed_orders' => $totals['reversed_orders'],
            'excluded_currency_orders' => $totals['excluded_currency_orders'],
            'attribution' => [
                'model' => PersonalizationAttributionService::MODEL,
                'window_days' => PersonalizationAttributionService::WINDOW_DAYS,
                'click_only' => true,
                'refund_cancel_reversal' => true,
            ],
            'daily' => $formattedDaily,
            'placements' => $formattedPlacements,
            'dimensions' => $formattedDimensions,
        ];
    }

    /** @return array<string, mixed> */
    private function dimensionRow(?int $strategyId, ?int $versionId, ?int $componentId, string $placement): array
    {
        return [
            'strategy_id' => $strategyId,
            'strategy_version_id' => $versionId,
            'component_id' => $componentId,
            'placement' => $placement,
            'impressions' => 0,
            'clicks' => 0,
            'add_to_carts' => 0,
            'orders' => 0,
            'attributed_revenue' => 0.0,
            'quantity' => 0,
            'sales' => 0.0,
            'discounts' => 0.0,
            'revenue' => 0.0,
        ];
    }

    /** @param array<string, array<string, mixed>> $dimensions @return list<array<string, mixed>> */
    private function formatDimensions(array $dimensions, Store $store): array
    {
        $strategyIds = collect($dimensions)->pluck('strategy_id')->filter()->unique()->values();
        $versionIds = collect($dimensions)->pluck('strategy_version_id')->filter()->unique()->values();
        $componentIds = collect($dimensions)->pluck('component_id')->filter()->unique()->values();
        $strategies = PersonalizationRecommendationStrategy::withTrashed()
            ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('id', $strategyIds)->get()->keyBy('id');
        $versions = PersonalizationStrategyVersion::query()
            ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('id', $versionIds)->get()->keyBy('id');
        $components = PersonalizationRecommendationComponent::withTrashed()
            ->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('id', $componentIds)->get()->keyBy('id');

        $rows = array_map(function (array $row) use ($strategies, $versions, $components): array {
            $version = $versions->get($row['strategy_version_id']);
            $component = $components->get($row['component_id']);
            $strategy = $strategies->get($row['strategy_id']);
            $row['strategy_uuid'] = $strategy?->uuid;
            $row['strategy_name'] = $strategy?->name ?? 'Unknown strategy';
            $row['strategy_version_uuid'] = $version?->uuid;
            $row['strategy_version'] = $version?->version_number;
            $row['component_uuid'] = $component?->uuid;
            $row['component_name'] = $component?->name ?? 'Unknown component';
            $row['attributed_revenue'] = number_format((float) $row['attributed_revenue'], 2, '.', '');
            $row['discounts'] = number_format((float) $row['discounts'], 2, '.', '');
            $row['sales'] = number_format((float) $row['sales'], 2, '.', '');
            $row['revenue'] = number_format((float) $row['revenue'], 2, '.', '');
            $row['click_through_rate'] = $row['impressions'] > 0
                ? round($row['clicks'] / $row['impressions'] * 100, 2)
                : 0.0;

            return $row;
        }, array_values($dimensions));
        usort($rows, fn (array $left, array $right): int => (float) $right['attributed_revenue'] <=> (float) $left['attributed_revenue']);

        return $rows;
    }

    private function authorize(Store $store, User $actor): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $organization = $store->organization;
        if (! $organization instanceof Organization
            || (int) $store->organization_id !== (int) $organization->id
            || $store->status !== 'active'
            || $organization->status !== 'active'
            || ! $actor->canAccessStore($store)
            || ! $actor->hasPermission('personalization.analytics.read', $organization, $store)) {
            throw new PersonalizationException(
                'PERSONALIZATION_ANALYTICS_ACCESS_DENIED',
                'You do not have permission to view personalization analytics for this store.',
                403,
            );
        }
    }
}
