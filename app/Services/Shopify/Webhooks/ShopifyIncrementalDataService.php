<?php

namespace App\Services\Shopify\Webhooks;

use App\Exceptions\ShopifyApiException;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\AnalyticsCacheVersionService;
use App\Services\Shopify\Customers\ShopifyCustomerDataService;
use App\Services\Shopify\Orders\ShopifyOrderDataService;
use App\Services\Shopify\Products\ShopifyCollectionDataService;
use App\Services\Shopify\Products\ShopifyProductDataService;
use Carbon\CarbonImmutable;

class ShopifyIncrementalDataService
{
    public function __construct(
        private ShopifyProductDataService $products,
        private ShopifyOrderDataService $orders,
        private ShopifyCustomerDataService $customers,
        private ShopifyCollectionDataService $collections,
        private ?AnalyticsCacheVersionService $analyticsCache = null,
    ) {}

    public function handle(WebhookEvent $event): void
    {
        $event->loadMissing('store');
        $store = $event->store;
        $payload = $event->decodedPayload();

        if (! $store || ! is_array($payload)) {
            throw new ShopifyApiException('Webhook 缺少有效店铺或 Payload。');
        }

        match ($event->topic) {
            'products/create', 'products/update' => $this->upsertProduct($store, $payload),
            'products/delete' => $this->deleteProduct($store->getKey(), $payload),
            'orders/create', 'orders/updated', 'orders/cancelled' => $this->upsertOrder($store, $payload),
            'customers/create', 'customers/update' => $this->upsertCustomer($store, $payload),
            'customers/delete' => $this->deleteCustomer($store->getKey(), $payload),
            'inventory_levels/update' => $this->updateInventoryLevel($store->getKey(), $payload),
            default => throw new ShopifyApiException("不支持的增量主题 [{$event->topic}]。"),
        };

        $this->analyticsCache?->bump((int) $store->getKey());
    }

    private function upsertProduct(Store $store, array $payload): void
    {
        $images = collect($payload['images'] ?? [])
            ->filter(fn ($image) => is_array($image) && isset($image['id']))
            ->keyBy(fn (array $image): string => (string) $image['id']);
        $optionNames = collect($payload['options'] ?? [])
            ->filter(fn ($option) => is_array($option) && isset($option['position']) && is_string($option['name'] ?? null))
            ->mapWithKeys(fn (array $option): array => [(int) $option['position'] => trim($option['name'])]);
        $currencyVariants = collect($payload['variants'] ?? [])->filter(fn ($variant) => is_array($variant))->map(function (array $variant) use ($images, $optionNames): array {
            $node = [
                'id' => $this->gid('ProductVariant', $variant['id'] ?? null),
                'title' => (string) ($variant['title'] ?? 'Default Title'),
                'sku' => $variant['sku'] ?? null,
                'price' => (string) ($variant['price'] ?? '0'),
                'inventoryItem' => isset($variant['inventory_item_id'])
                    ? ['id' => $this->gid('InventoryItem', $variant['inventory_item_id'])]
                    : null,
            ];
            if (array_key_exists('compare_at_price', $variant)) {
                $node['compareAtPrice'] = $variant['compare_at_price'];
            }
            if (array_key_exists('available', $variant)) {
                $node['availableForSale'] = (bool) $variant['available'];
            }
            if ($optionNames->isNotEmpty()) {
                $node['selectedOptions'] = $optionNames
                    ->map(fn (string $name, int $position): ?array => isset($variant["option{$position}"])
                        ? ['name' => $name, 'value' => (string) $variant["option{$position}"]]
                        : null)
                    ->filter()
                    ->values()
                    ->all();
            }
            if (array_key_exists('image_id', $variant)) {
                $image = $variant['image_id'] !== null ? $images->get((string) $variant['image_id']) : null;
                $node['media'] = ['nodes' => $image ? [$this->mediaImage($image)] : []];
            }

            return $node;
        })->values()->all();

        $productNode = [
            'id' => $this->gid('Product', $payload['id'] ?? null),
            'title' => (string) ($payload['title'] ?? ''),
            'handle' => (string) ($payload['handle'] ?? ''),
            'status' => strtoupper((string) ($payload['status'] ?? 'draft')),
            'vendor' => $payload['vendor'] ?? null,
            'productType' => $payload['product_type'] ?? null,
            'description' => $payload['body_html'] ?? null,
        ];
        if (array_key_exists('tags', $payload)) {
            $productNode['tags'] = is_array($payload['tags'])
                ? $payload['tags']
                : array_values(array_filter(array_map('trim', explode(',', (string) $payload['tags']))));
        }
        if (array_key_exists('created_at', $payload)) {
            $productNode['createdAt'] = $payload['created_at'];
        }
        if (array_key_exists('published_at', $payload)) {
            $productNode['publishedAt'] = $payload['published_at'];
        }
        if (array_key_exists('image', $payload)) {
            $productNode['featuredMedia'] = is_array($payload['image'])
                ? $this->mediaImage($payload['image'])
                : null;
        }

        $this->products->upsert($store, $productNode, $currencyVariants);
    }

    private function upsertOrder(Store $store, array $payload): void
    {
        $currency = strtoupper((string) ($payload['currency'] ?? 'USD'));
        $money = fn (mixed $amount) => ['shopMoney' => ['amount' => (string) ($amount ?? '0'), 'currencyCode' => $currency]];
        $shipping = data_get($payload, 'current_shipping_price_set.shop_money.amount')
            ?? data_get($payload, 'total_shipping_price_set.shop_money.amount')
            ?? $payload['total_shipping_price']
            ?? '0';
        $refunded = $payload['total_refunded'] ?? collect($payload['refunds'] ?? [])
            ->filter(fn ($refund) => is_array($refund))
            ->flatMap(fn (array $refund) => $refund['transactions'] ?? [])
            ->filter(fn ($transaction) => is_array($transaction)
                && ($transaction['kind'] ?? null) === 'refund'
                && in_array($transaction['status'] ?? null, ['success', null], true))
            ->sum(fn (array $transaction): float => (float) ($transaction['amount'] ?? 0));
        $items = collect($payload['line_items'] ?? [])->filter(fn ($item) => is_array($item))->map(function (array $item) use ($money): array {
            $quantity = (int) ($item['quantity'] ?? 0);
            $currentQuantity = (int) ($item['current_quantity'] ?? $quantity);
            $discount = (float) ($item['total_discount'] ?? 0);
            $attributedSales = max(0, (float) ($item['price'] ?? 0) * $currentQuantity - $discount);
            $staffId = data_get($item, 'staff_member.id') ?? $item['staff_member_id'] ?? null;

            return [
                'id' => $this->gid('LineItem', $item['id'] ?? null),
                'title' => (string) ($item['title'] ?? ''),
                'quantity' => $quantity,
                'currentQuantity' => $currentQuantity,
                'originalUnitPriceSet' => $money($item['price'] ?? '0'),
                'priceAfterAllDiscountsBeforeTaxesSet' => $money($attributedSales),
                'staffMember' => $staffId ? [
                    'id' => $this->gid('StaffMember', $staffId),
                    'name' => data_get($item, 'staff_member.name'),
                ] : null,
                'product' => isset($item['product_id']) ? ['id' => $this->gid('Product', $item['product_id'])] : null,
                'variant' => isset($item['variant_id']) ? ['id' => $this->gid('ProductVariant', $item['variant_id'])] : null,
            ];
        })->values()->all();
        $appId = $payload['app_id'] ?? null;
        $locationId = $payload['location_id'] ?? null;
        $staffId = $payload['user_id'] ?? null;

        $this->orders->upsert($store, [
            'id' => $this->gid('Order', $payload['id'] ?? null),
            'name' => (string) ($payload['name'] ?? $payload['order_number'] ?? ''),
            'email' => $payload['email'] ?? null,
            'displayFinancialStatus' => $payload['financial_status'] ?? null,
            'displayFulfillmentStatus' => $payload['fulfillment_status'] ?? null,
            'sourceName' => $payload['source_name'] ?? null,
            'app' => $appId ? ['id' => $this->gid('App', $appId), 'name' => $payload['source_name'] ?? null] : null,
            'retailLocation' => $locationId ? ['id' => $this->gid('Location', $locationId), 'name' => null] : null,
            'staffMember' => $staffId ? ['id' => $this->gid('StaffMember', $staffId), 'name' => null] : null,
            'currencyCode' => $currency,
            'totalPriceSet' => $money($payload['total_price'] ?? '0'),
            'currentTotalPriceSet' => $money($payload['current_total_price'] ?? $payload['total_price'] ?? '0'),
            'subtotalPriceSet' => $money($payload['subtotal_price'] ?? '0'),
            'currentSubtotalPriceSet' => $money($payload['current_subtotal_price'] ?? $payload['subtotal_price'] ?? '0'),
            'currentTotalDiscountsSet' => $money($payload['current_total_discounts'] ?? $payload['total_discounts'] ?? '0'),
            'totalRefundedSet' => $money($refunded),
            'currentShippingPriceSet' => $money($shipping),
            'totalTaxSet' => $money($payload['total_tax'] ?? '0'),
            'currentTotalTaxSet' => $money($payload['current_total_tax'] ?? $payload['total_tax'] ?? '0'),
            'test' => (bool) ($payload['test'] ?? false),
            'processedAt' => $payload['processed_at'] ?? null,
            'cancelledAt' => $payload['cancelled_at'] ?? null,
            'createdAt' => $payload['created_at'] ?? now()->toIso8601String(),
            'updatedAt' => $payload['updated_at'] ?? null,
            'customer' => isset($payload['customer']['id'])
                ? ['id' => $this->gid('Customer', $payload['customer']['id'])]
                : null,
        ], $items);
    }

    private function upsertCustomer(Store $store, array $payload): void
    {
        $this->customers->upsert($store, [
            'id' => $this->gid('Customer', $payload['id'] ?? null),
            'firstName' => $payload['first_name'] ?? null,
            'lastName' => $payload['last_name'] ?? null,
            'defaultEmailAddress' => isset($payload['email']) ? ['emailAddress' => $payload['email']] : null,
            'defaultPhoneNumber' => isset($payload['phone']) ? ['phoneNumber' => $payload['phone']] : null,
            'state' => $payload['state'] ?? null,
            'verifiedEmail' => (bool) ($payload['verified_email'] ?? false),
            'numberOfOrders' => (string) ($payload['orders_count'] ?? 0),
            'amountSpent' => [
                'amount' => (string) ($payload['total_spent'] ?? '0'),
                'currencyCode' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            ],
            'createdAt' => $payload['created_at'] ?? now()->toIso8601String(),
            'updatedAt' => $payload['updated_at'] ?? now()->toIso8601String(),
        ]);
    }

    private function deleteProduct(int $storeId, array $payload): void
    {
        $store = Store::query()->findOrFail($storeId);
        $shopifyProductId = $this->numericId($payload['id'] ?? null);
        $this->collections->deleteProductMemberships($store, $shopifyProductId);
        Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('shopify_product_id', $shopifyProductId)
            ->delete();
    }

    /** @param array<string, mixed> $image
     * @return array{alt: mixed, image: array{url: mixed, width: mixed, height: mixed}}
     */
    private function mediaImage(array $image): array
    {
        return [
            'alt' => $image['alt'] ?? null,
            'image' => [
                'url' => $image['url'] ?? $image['src'] ?? null,
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
            ],
        ];
    }

    private function deleteCustomer(int $storeId, array $payload): void
    {
        Customer::query()->where('store_id', $storeId)->where('shopify_customer_id', $this->numericId($payload['id'] ?? null))->delete();
    }

    private function updateInventoryLevel(int $storeId, array $payload): void
    {
        $inventoryItem = InventoryItem::query()
            ->where('store_id', $storeId)
            ->where('shopify_inventory_item_id', $this->numericId($payload['inventory_item_id'] ?? null))
            ->first();
        $location = Location::query()
            ->where('store_id', $storeId)
            ->where('shopify_location_id', $this->numericId($payload['location_id'] ?? null))
            ->first();

        if (! $inventoryItem || ! $location) {
            throw new ShopifyApiException('库存增量事件缺少本地库存项目或地点，请先执行一次库存全量同步。');
        }

        InventoryLevel::query()->updateOrCreate([
            'inventory_item_id' => $inventoryItem->getKey(),
            'location_id' => $location->getKey(),
        ], [
            'shopify_location_id' => $location->shopify_location_id,
            'available' => (int) ($payload['available'] ?? 0),
            'updated_at_shopify' => isset($payload['updated_at'])
                ? CarbonImmutable::parse((string) $payload['updated_at'])->utc()->toDateTimeString()
                : now()->utc(),
            'synced_at' => now(),
        ]);
        $inventoryItem->forceFill(['synced_at' => now()])->save();
    }

    private function gid(string $resource, mixed $id): string
    {
        return "gid://shopify/{$resource}/".$this->numericId($id);
    }

    private function numericId(mixed $id): string
    {
        $id = is_int($id) || is_string($id) ? (string) $id : '';

        if (preg_match('/^[0-9]+$/', $id) !== 1) {
            throw new ShopifyApiException('Webhook Payload 缺少有效 Shopify ID。');
        }

        return $id;
    }
}
