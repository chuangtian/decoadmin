<?php

namespace App\Services\Personalization;

use App\Models\Order;
use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationEvent;
use App\Models\Store;
use Carbon\CarbonInterface;

class PersonalizationAttributionService
{
    public const MODEL = 'last_recommendation_click';

    public const WINDOW_DAYS = 7;

    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return array{examined: int, attributed: int, updated: int, skipped: int} */
    public function reconcileStore(Store $store, int $limit = 2000): array
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $limit = max(1, min($limit, 10_000));
        $events = PersonalizationEvent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('event_name', PersonalizationEventIngestionService::CHECKOUT_COMPLETED)
            ->whereNotNull('shopify_order_id')
            ->where('occurred_at', '>=', now()->subDays(90))
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        $result = ['examined' => 0, 'attributed' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($events->groupBy(fn (PersonalizationEvent $event): string => (string) $event->shopify_order_id) as $checkouts) {
            /** @var PersonalizationEvent $checkout */
            $checkout = $checkouts->sortBy('occurred_at')->first();
            $result['examined']++;
            $outcome = $this->reconcileCheckout($store, $checkout);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return 'attributed'|'updated'|'skipped' */
    public function reconcileCheckout(Store $store, PersonalizationEvent $checkout): string
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        if ((int) $checkout->organization_id !== (int) $store->organization_id
            || (int) $checkout->store_id !== (int) $store->id
            || $checkout->event_name !== PersonalizationEventIngestionService::CHECKOUT_COMPLETED
            || ! $checkout->shopify_order_id) {
            return 'skipped';
        }

        $order = Order::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('shopify_order_id', $checkout->shopify_order_id)
            ->first();
        if (! $order) {
            return 'skipped';
        }
        if ($order->is_test) {
            PersonalizationAttribution::query()->where('order_id', $order->id)->delete();

            return 'skipped';
        }

        $orderedAt = $order->processed_at ?? $order->created_at_shopify;
        if (! $orderedAt instanceof CarbonInterface) {
            return 'skipped';
        }
        $canonicalCheckout = PersonalizationEvent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('event_name', PersonalizationEventIngestionService::CHECKOUT_COMPLETED)
            ->where('shopify_order_id', $order->shopify_order_id)
            ->whereBetween('occurred_at', [
                $orderedAt->copy()->subHour(),
                $orderedAt->copy()->addDay(),
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();
        if (! $canonicalCheckout || (int) $canonicalCheckout->id !== (int) $checkout->id) {
            return 'skipped';
        }

        $click = PersonalizationEvent::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('event_name', PersonalizationEventIngestionService::CLICK)
            ->where('client_id_hash', $checkout->client_id_hash)
            ->whereBetween('occurred_at', [
                $checkout->occurred_at->copy()->subDays(self::WINDOW_DAYS),
                $checkout->occurred_at,
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->with(['products' => fn ($query) => $query->orderBy('rank')->orderBy('id')])
            ->first();
        if (! $click) {
            return 'skipped';
        }

        $gross = max(0.0, (float) $order->total_price);
        $refund = max(0.0, min($gross, (float) $order->refund_total));
        $status = 'attributed';
        $revenue = max(0.0, $gross - $refund);
        if ($order->cancelled_at) {
            $status = 'cancelled';
            $revenue = 0.0;
        } elseif ($gross <= 0.0
            || $refund >= $gross
            || strtolower((string) $order->financial_status) === 'refunded') {
            $status = 'refunded';
            $revenue = 0.0;
        } elseif ($refund > 0.0
            || strtolower((string) $order->financial_status) === 'partially_refunded') {
            $status = 'partially_refunded';
        }

        $clickedProduct = $click->products->first();
        $attribution = PersonalizationAttribution::query()->firstOrNew(['order_id' => $order->id]);
        $created = ! $attribution->exists;
        $attribution->fill([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'checkout_event_id' => $checkout->id,
            'click_event_id' => $click->id,
            'component_id' => $click->component_id,
            'strategy_id' => $click->strategy_id,
            'product_id' => $clickedProduct?->product_id,
            'shopify_product_id' => $clickedProduct?->shopify_product_id,
            'placement' => $click->placement,
            'model' => self::MODEL,
            'window_days' => self::WINDOW_DAYS,
            'status' => $status,
            'currency' => strtoupper((string) $order->currency),
            'gross_revenue' => number_format($gross, 4, '.', ''),
            'refund_amount' => number_format($refund, 4, '.', ''),
            'attributed_revenue' => number_format($revenue, 4, '.', ''),
            'clicked_at' => $click->occurred_at,
            'ordered_at' => $orderedAt,
            'reconciled_at' => now(),
        ])->save();

        return $created ? 'attributed' : 'updated';
    }
}
