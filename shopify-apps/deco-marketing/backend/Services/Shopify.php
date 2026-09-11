<?php

namespace DecoMarketing\Services;

use App\Models\AppInstallation;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Attribution;
use DecoMarketing\Models\CheckoutSnapshot;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\OrderSnapshot;
use DecoMarketing\Models\Waitlist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class Shopify
{
    public function installation(Store $store): ?AppInstallation
    {
        app(Guard::class)->store($store);
        if (! config('marketing.active.client_id')) {
            return null;
        }

        return AppInstallation::where('store_id', $store->id)->where('status', 'active')->where('settings->environment', config('marketing.environment'))
            ->whereHas('app', fn ($q) => $q->where('handle', config('marketing.active.handle'))->where('client_id', config('marketing.active.client_id'))->where('status', 'active'))->first();
    }

    public function query(Store $store, string $query, array $variables = []): array
    {
        app(Guard::class)->store($store);

        abort_unless(preg_match('/^\s*query\b/', $query) && ! preg_match('/\bmutation\b/i', $query), 403, '营销数据连接仅允许查询。');

        return app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, app(Tokens::class)->access($store), $query, $variables, 15, config('marketing.active.api_version'));
    }

    public function stopReason(Store $store, string $flow, array $context): ?string
    {
        try {
            if (isset($context['order_id'])) {
                $order = data_get($this->query($store, 'query MarketingOrder($id: ID!) { order(id: $id) { id cancelledAt displayFinancialStatus } }', ['id' => $context['order_id']]), 'data.order');
                if (! $order) {
                    return 'order_unavailable';
                }
                if ($flow === 'cancelled') {
                    return empty($order['cancelledAt']) && ! in_array($order['displayFinancialStatus'], ['REFUNDED', 'PARTIALLY_REFUNDED']) ? 'not_cancelled_or_refunded' : null;
                }
                if (! empty($order['cancelledAt'])) {
                    return 'order_cancelled';
                }
                if ($flow === 'payment' && ! in_array($order['displayFinancialStatus'], ['PENDING', 'PARTIALLY_PAID'])) {
                    return 'payment_no_longer_pending';
                }
            }
            if (isset($context['checkout_id'])) {
                $checkout = data_get($this->query($store, 'query MarketingCheckout($id: ID!) { node(id: $id) { ... on AbandonedCheckout { id completedAt } } }', ['id' => $context['checkout_id']]), 'data.node');
                if (! $checkout) {
                    return 'checkout_unavailable';
                }
                if (! empty($checkout['completedAt'])) {
                    return 'checkout_completed';
                }
            }
            if (isset($context['variant_id'])) {
                $variant = data_get($this->query($store, 'query MarketingVariant($id: ID!) { productVariant(id: $id) { id inventoryQuantity product { status } } }', ['id' => $context['variant_id']]), 'data.productVariant');
                if (! $variant || $variant['product']['status'] !== 'ACTIVE' || ($variant['inventoryQuantity'] ?? 0) <= 0) {
                    return 'out_of_stock';
                }
            }
            if ($flow === 'welcome' && isset($context['customer_id'])) {
                $customer = data_get($this->query($store, 'query MarketingBuyer($id: ID!) { customer(id: $id) { numberOfOrders } }', ['id' => $context['customer_id']]), 'data.customer');
                if (! $customer) {
                    return 'customer_unavailable';
                }
                if ((int) $customer['numberOfOrders'] > 0) {
                    return 'customer_purchased';
                }
            }

            return null;
        } catch (\Throwable) {
            return 'verification_unavailable';
        }
    }

    public function sync(Store $store, ?array $kinds = null): array
    {
        app(Guard::class)->store($store);

        return Cache::lock('marketing:sync:'.$store->id, 180)->block(1, function () use ($store, $kinds) {
            $settings = app(Catalog::class)->settings($store);
            $counts = ['contacts' => 0, 'orders' => 0, 'checkouts' => 0];
            $queries = [
                'contacts' => ['customers', 'query MarketingCustomers($after: String, $query: String) { customers(first: 100, after: $after, query: $query, sortKey: UPDATED_AT) { nodes { id firstName numberOfOrders updatedAt defaultEmailAddress { emailAddress marketingState marketingOptInLevel marketingUpdatedAt } } pageInfo { hasNextPage endCursor } } }'],
                'orders' => ['orders', 'query MarketingOrders($after: String, $query: String) { orders(first: 100, after: $after, query: $query, sortKey: UPDATED_AT) { nodes { '.self::ORDER_FIELDS.' } pageInfo { hasNextPage endCursor } } }'],
                'checkouts' => ['abandonedCheckouts', 'query MarketingCheckouts($after: String, $query: String) { abandonedCheckouts(first: 100, after: $after, query: $query, sortKey: CREATED_AT) { nodes { id abandonedCheckoutUrl createdAt completedAt customer { id } '.self::PRODUCT_FIELDS.' } pageInfo { hasNextPage endCursor } } }'],
            ];
            if ($kinds !== null) {
                $queries = array_intersect_key($queries, array_flip($kinds));
            }
            foreach ($queries as $kind => [$root,$query]) {
                $settings->refresh();
                $state = $settings->sync_state ?? [];
                $checkpoint = $state[$kind] ?? [];
                $until = $checkpoint['until'] ?? now()->toIso8601String();
                $filter = 'updated_at:<="'.$until.'"';
                if (! empty($checkpoint['since'])) {
                    $filter .= ' updated_at:>="'.$checkpoint['since'].'"';
                }
                $result = data_get($this->query($store, $query, ['after' => $checkpoint['cursor'] ?? null, 'query' => $filter]), 'data.'.$root);
                if (! is_array($result) || ! isset($result['nodes'],$result['pageInfo'])) {
                    throw ValidationException::withMessages(['shopify' => '同步响应不完整，进度已保留。']);
                }
                foreach ($result['nodes'] as $node) {
                    if ($kind === 'contacts') {
                        $this->customer($store, $node);
                    } elseif ($kind === 'orders') {
                        $this->order($store, $node);
                    } else {
                        CheckoutSnapshot::forStore($store)->updateOrCreate(['checkout_id' => $node['id']], ['organization_id' => $store->organization_id, 'store_id' => $store->id, 'occurred_at' => CarbonImmutable::parse($node['createdAt']), 'completed_at' => ! empty($node['completedAt']) ? CarbonImmutable::parse($node['completedAt']) : null]);
                        $contact = Contact::forStore($store)->where('customer_id', data_get($node, 'customer.id'))->whereNotNull('customer_id')->first();
                        if ($contact && ! $node['completedAt']) {
                            app(Engine::class)->enroll($store, $contact, 'abandoned', $node['id'], ['checkout_id' => $node['id'], 'url' => $node['abandonedCheckoutUrl'], ...$this->productContext($node)], CarbonImmutable::parse($node['createdAt']));
                        }
                    }
                    $counts[$kind]++;
                }
                $more = (bool) $result['pageInfo']['hasNextPage'];
                if ($more && empty($result['pageInfo']['endCursor'])) {
                    throw ValidationException::withMessages(['shopify' => '同步响应缺少分页游标。']);
                }
                $state[$kind] = $more ? ['cursor' => $result['pageInfo']['endCursor'], 'until' => $until, 'since' => $checkpoint['since'] ?? null] : ['cursor' => null, 'until' => null, 'since' => CarbonImmutable::parse($until)->subMinutes(5)->toIso8601String()];
                $settings->update(['sync_state' => $state]);
            }
            $this->syncWaitlist($store);

            return $counts;
        });
    }

    private const PRODUCT_FIELDS = 'lineItems(first: 20) { nodes { title quantity image { url } originalUnitPriceSet { shopMoney { amount currencyCode } } } }';

    private const ORDER_FIELDS = 'id name email createdAt cancelledAt displayFinancialStatus statusPageUrl paymentCollectionDetails { additionalPaymentCollectionUrl } customer { id } totalPriceSet { shopMoney { amount currencyCode } } totalRefundedSet { shopMoney { amount currencyCode } } totalOutstandingSet { shopMoney { amount currencyCode } } refunds(first: 20) { id createdAt } fulfillments(first: 5) { id createdAt status } '.self::PRODUCT_FIELDS;

    public function syncCustomer(Store $store, string $id): void
    {
        $node = data_get($this->query($store, 'query MarketingCustomer($id: ID!) { customer(id: $id) { id firstName numberOfOrders updatedAt defaultEmailAddress { emailAddress marketingState marketingUpdatedAt } } }', ['id' => $id]), 'data.customer');
        if ($node) {
            $this->customer($store, $node);
        }
    }

    public function syncOrder(Store $store, string $id): void
    {
        $node = data_get($this->query($store, 'query MarketingOrderSync($id: ID!) { order(id: $id) { '.self::ORDER_FIELDS.' } }', ['id' => $id]), 'data.order');
        if ($node) {
            $this->order($store, $node);
        }
    }

    public function syncWaitlist(Store $store): void
    {
        app(Guard::class)->store($store);
        $settings = app(Catalog::class)->settings($store);
        $state = $settings->sync_state ?? [];
        $rows = Waitlist::forStore($store)->where('status', 'waiting')->where('id', '>', $state['stock_cursor'] ?? 0)->with('contact')->orderBy('id')->limit(3)->get();
        if ($rows->isEmpty()) {
            $state['stock_cursor'] = 0;
            $settings->update(['sync_state' => $state]);

            return;
        }
        foreach ($rows as $waiting) {
            // Match the exact SKU and reject ambiguous catalog matches.
            $search = 'sku:"'.addcslashes($waiting->sku, '"\\').'"';
            $nodes = data_get($this->query($store, 'query MarketingStock($query: String!) { productVariants(first: 10, query: $query) { nodes { id sku inventoryQuantity product { title handle status } } } }', ['query' => $search]), 'data.productVariants.nodes', []);
            $state['stock_cursor'] = $waiting->id;
            $settings->update(['sync_state' => $state]);
            $matches = array_values(array_filter($nodes, fn ($n) => $n['sku'] === $waiting->sku));
            if (count($matches) !== 1) {
                continue;
            }
            $node = $matches[0];
            if (($node['inventoryQuantity'] ?? 0) <= 0 || data_get($node, 'product.status') !== 'ACTIVE') {
                continue;
            }
            $waiting->update(['variant_id' => $node['id']]);
            app(Engine::class)->enroll($store, $waiting->contact, 'back_in_stock', $waiting->uuid, ['variant_id' => $node['id'], 'waitlist_id' => $waiting->id, 'product_title' => $node['product']['title'], 'url' => 'https://'.$store->shopify_domain.'/products/'.$node['product']['handle']]);
        }
    }

    public function customer(Store $store, array $node): void
    {
        $email = data_get($node, 'defaultEmailAddress.emailAddress');
        if (! $email) {
            return;
        }
        $state = strtolower(data_get($node, 'defaultEmailAddress.marketingState', 'NOT_SUBSCRIBED'));
        if (! in_array($state, ['subscribed', 'unsubscribed', 'pending', 'not_subscribed'])) {
            $state = 'not_subscribed';
        }
        $at = data_get($node, 'defaultEmailAddress.marketingUpdatedAt');
        if ($state === 'subscribed' && ! $at) {
            $state = 'not_subscribed';
        }
        $contact = app(Contacts::class)->upsert($store, ['email' => $email, 'name' => $node['firstName'] ?? '', 'customer_id' => $node['id'], 'orders_count' => $node['numberOfOrders'] ?? 0, 'consent' => $state, 'consent_at' => $at ?? $node['updatedAt']], 'shopify');
        if ($state === 'subscribed' && $at && ! $contact->orders_count) {
            app(Engine::class)->enroll($store, $contact, 'welcome', 'consent:'.hash('sha256', $node['id'].'|'.$at), ['customer_id' => $node['id']], CarbonImmutable::parse($at));
        }
    }

    private function productContext(array $node): array
    {
        $products = array_map(fn (array $item) => [
            'title' => $item['title'], 'quantity' => $item['quantity'], 'image' => data_get($item, 'image.url'),
            'price' => data_get($item, 'originalUnitPriceSet.shopMoney.amount', ''),
            'currency' => data_get($item, 'originalUnitPriceSet.shopMoney.currencyCode', ''),
        ], array_slice(data_get($node, 'lineItems.nodes', []), 0, 20));

        return ['products' => $products, 'product_title' => $products[0]['title'] ?? 'your item'];
    }

    public function order(Store $store, array $node): void
    {
        app(Guard::class)->store($store);
        $contact = Contact::forStore($store)->where('customer_id', data_get($node, 'customer.id'))->whereNotNull('customer_id')->first();
        if (! $contact && ! empty($node['email'])) {
            $contact = Contact::forStore($store)->where('email_hash', Contacts::hash($node['email']))->first();
        }
        if (! $contact && filter_var($node['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            // Order pagination can run ahead of customer pagination. Never drop that order.
            $contact = app(Contacts::class)->upsert($store, [
                'email' => $node['email'], 'customer_id' => data_get($node, 'customer.id'),
                'consent' => 'not_subscribed', 'consent_at' => $node['createdAt'],
            ], 'shopify');
        }
        OrderSnapshot::forStore($store)->updateOrCreate(['order_id' => $node['id']], [
            'organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact?->id,
            'name' => $node['name'], 'financial_status' => $node['displayFinancialStatus'], 'currency' => data_get($node, 'totalPriceSet.shopMoney.currencyCode', 'USD'),
            'total' => data_get($node, 'totalPriceSet.shopMoney.amount', 0), 'refunded' => data_get($node, 'totalRefundedSet.shopMoney.amount', 0),
            'outstanding' => data_get($node, 'totalOutstandingSet.shopMoney.amount'), 'ordered_at' => $node['createdAt'], 'cancelled_at' => $node['cancelledAt'] ?? null,
        ]);
        if (! $contact) {
            return; // Financial snapshot retained even when Shopify has no customer email.
        }
        $refundAt = collect($node['refunds'] ?? [])->pluck('createdAt')->filter()->sort()->first();
        if (! empty($node['cancelledAt']) || $refundAt) {
            $flow = 'cancelled';
        } elseif (in_array($node['displayFinancialStatus'], ['PENDING', 'PARTIALLY_PAID'])) {
            $flow = 'payment';
        } else {
            $flow = null;
        }
        if ($flow === 'payment') {
            $url = data_get($node, 'paymentCollectionDetails.additionalPaymentCollectionUrl') ?? ($node['statusPageUrl'] ?? null);
            if ($url) {
                app(Engine::class)->enroll($store, $contact, 'payment', $node['id'], ['order_id' => $node['id'], 'order_name' => $node['name'], 'url' => $url, ...$this->productContext($node)], CarbonImmutable::parse($node['createdAt']));
            }
        }
        if (! $flow && $node['displayFinancialStatus'] === 'PAID') {
            $fulfillment = collect($node['fulfillments'] ?? [])->first(fn ($f) => $f['status'] === 'SUCCESS');
            if ($fulfillment) {
                app(Engine::class)->enroll($store, $contact, 'advocacy', $node['id'], ['order_id' => $node['id'], 'order_name' => $node['name'], ...$this->productContext($node)], CarbonImmutable::parse($fulfillment['createdAt']));
            }
        }
        if ($flow === 'cancelled') {
            app(Engine::class)->enroll($store, $contact, $flow, $node['id'], ['order_id' => $node['id'], 'order_name' => $node['name'], ...$this->productContext($node)], CarbonImmutable::parse($node['cancelledAt'] ?? $refundAt));
        }
        if ($node['displayFinancialStatus'] === 'PAID') {
            Enrollment::forStore($store)->where('contact_id', $contact->id)->where('flow_key', 'welcome')->where('status', 'active')->update(['status' => 'stopped', 'stop_reason' => 'customer_purchased', 'next_at' => null]);
            $clicked = Delivery::forStore($store)->where('kind', 'automation')->where('contact_id', $contact->id)->whereNotNull('sent_at')->where('clicked_at', '<=', CarbonImmutable::parse($node['createdAt']))->where('clicked_at', '>=', CarbonImmutable::parse($node['createdAt'])->subDays(5))->latest('clicked_at')->first();
            if ($clicked) {
                Attribution::forStore($store)->updateOrCreate(['order_id' => $node['id']], ['organization_id' => $store->organization_id, 'store_id' => $store->id, 'delivery_id' => $clicked->id, 'revenue' => data_get($node, 'totalPriceSet.shopMoney.amount', 0), 'currency' => data_get($node, 'totalPriceSet.shopMoney.currencyCode', 'USD'), 'status' => 'paid', 'ordered_at' => $node['createdAt']]);
            }
        }
        if (! empty($node['cancelledAt']) || $node['displayFinancialStatus'] === 'REFUNDED') {
            Attribution::forStore($store)->where('order_id', $node['id'])->update(['status' => 'reversed', 'revenue' => 0]);
        } elseif ($node['displayFinancialStatus'] === 'PARTIALLY_REFUNDED') {
            $total = data_get($node, 'totalPriceSet.shopMoney.amount');
            $refunded = data_get($node, 'totalRefundedSet.shopMoney.amount');
            Attribution::forStore($store)->where('order_id', $node['id'])->update(is_numeric($total) && is_numeric($refunded) ? ['status' => 'partially_refunded', 'revenue' => max(0, round((float) $total - (float) $refunded, 2))] : ['status' => 'adjustment_required']);
        }
    }
}
