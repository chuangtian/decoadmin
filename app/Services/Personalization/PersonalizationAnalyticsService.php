<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventSource;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;

class PersonalizationAnalyticsService
{
    private const ACTIVE_ATTRIBUTION_STATUSES = ['attributed', 'partially_refunded'];

    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return array<string, mixed> */
    public function dashboard(Store $store, User $actor, int $days = 30): array
    {
        $this->authorize($store, $actor);
        $days = max(1, min($days, 90));
        $timezone = $store->timezone ?: 'UTC';
        $localEnd = CarbonImmutable::now($timezone)->endOfDay();
        $localStart = $localEnd->startOfDay()->subDays($days - 1);
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
            ];
        }
        $placements = [];
        $totals = [
            'impressions' => 0,
            'clicks' => 0,
            'add_to_carts' => 0,
            'orders' => 0,
            'attributed_revenue' => 0.0,
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
            ])
            ->orderBy('id')
            ->cursor(['event_name', 'placement', 'occurred_at']) as $event) {
            $metric = match ($event->event_name) {
                PersonalizationEventIngestionService::IMPRESSION => 'impressions',
                PersonalizationEventIngestionService::CLICK => 'clicks',
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
        }

        foreach (PersonalizationAttribution::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereBetween('ordered_at', [$start, $end])
            ->orderBy('id')
            ->cursor(['status', 'currency', 'placement', 'attributed_revenue', 'ordered_at']) as $attribution) {
            if (! in_array($attribution->status, self::ACTIVE_ATTRIBUTION_STATUSES, true)) {
                $totals['reversed_orders']++;

                continue;
            }
            if (strtoupper((string) $attribution->currency) !== $currency) {
                $totals['excluded_currency_orders']++;

                continue;
            }
            $revenue = max(0.0, (float) $attribution->attributed_revenue);
            $totals['orders']++;
            $totals['attributed_revenue'] += $revenue;
            $date = $attribution->ordered_at->setTimezone($timezone)->toDateString();
            if (isset($daily[$date])) {
                $daily[$date]['orders']++;
                $daily[$date]['attributed_revenue'] += $revenue;
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
        }

        $formattedDaily = array_map(function (array $row): array {
            $row['attributed_revenue'] = number_format($row['attributed_revenue'], 2, '.', '');

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
        ];
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
                '无权查看当前店铺的个性化推荐分析。',
                403,
            );
        }
    }
}
