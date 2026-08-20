<?php

namespace App\Services\Shopify\Orders;

use App\Exceptions\ShopifyApiException;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopifyOrderDataService
{
    /**
     * Persist one complete Shopify order snapshot.
     *
     * This entry point is intentionally reusable by future orders/create,
     * orders/updated and orders/cancelled webhook handlers.
     *
     * @param  array<string, mixed>  $orderNode
     * @param  list<array<string, mixed>>  $lineItemNodes
     * @return array{order: Order, created: bool, items_created: int, items_updated: int}
     */
    public function upsert(Store $store, array $orderNode, array $lineItemNodes): array
    {
        return DB::transaction(function () use ($store, $orderNode, $lineItemNodes): array {
            $shopifyOrderId = $this->numericId($orderNode['id'] ?? null, 'Order');
            $order = Order::query()->firstOrNew([
                'store_id' => $store->getKey(),
                'shopify_order_id' => $shopifyOrderId,
            ]);
            $created = ! $order->exists;
            $currency = $this->requiredString($orderNode, 'currencyCode');
            $orderAppId = $this->nullableNumericId(data_get($orderNode, 'app.id'), 'App');
            $posLocationId = $this->nullableNumericId(data_get($orderNode, 'retailLocation.id'), 'Location');
            $staffFieldProvided = array_key_exists('staffMember', $orderNode);
            $posStaffId = $staffFieldProvided
                ? $this->nullableNumericId(data_get($orderNode, 'staffMember.id'), 'StaffMember')
                : $order->pos_staff_id;
            $locationName = $this->nullableString(data_get($orderNode, 'retailLocation.name'))
                ?? ($posLocationId ? Location::query()->forStore($store)->where('shopify_location_id', $posLocationId)->value('name') : null)
                ?? ($posLocationId !== null && (string) $order->pos_location_id === (string) $posLocationId ? $order->pos_location_name : null);

            $order->fill([
                'organization_id' => $store->organization_id,
                'shopify_customer_id' => $this->nullableNumericId(data_get($orderNode, 'customer.id'), 'Customer'),
                'order_number' => $this->requiredString($orderNode, 'name'),
                'email' => $this->nullableString($orderNode['email'] ?? null),
                'financial_status' => $this->normalizedStatus($orderNode['displayFinancialStatus'] ?? null),
                'fulfillment_status' => $this->normalizedStatus($orderNode['displayFulfillmentStatus'] ?? null),
                'sales_channel' => $this->normalizedStatus($orderNode['sourceName'] ?? null),
                'shopify_order_app_id' => $orderAppId,
                'sales_channel_name' => $this->nullableString(data_get($orderNode, 'app.name'))
                    ?? ($orderAppId !== null && (string) $order->shopify_order_app_id === (string) $orderAppId ? $order->sales_channel_name : null),
                'pos_location_id' => $posLocationId,
                'pos_location_name' => $locationName,
                'pos_staff_id' => $posStaffId,
                'pos_staff_name' => $staffFieldProvided
                    ? ($this->nullableString(data_get($orderNode, 'staffMember.name'))
                        ?? ($posStaffId !== null && (string) $order->pos_staff_id === (string) $posStaffId ? $order->pos_staff_name : null))
                    : $order->pos_staff_name,
                'currency' => strtoupper($currency),
                'total_price' => $this->moneyWithFallback($orderNode, 'currentTotalPriceSet', 'totalPriceSet', $currency),
                'subtotal_price' => $this->money($orderNode, 'subtotalPriceSet', $currency),
                'net_sales' => $this->moneyWithFallback($orderNode, 'currentSubtotalPriceSet', 'subtotalPriceSet', $currency),
                'discount_total' => $this->optionalMoney($orderNode, 'currentTotalDiscountsSet', $currency),
                'refund_total' => $this->optionalMoney($orderNode, 'totalRefundedSet', $currency),
                'shipping_total' => $this->optionalMoney($orderNode, 'currentShippingPriceSet', $currency),
                'total_tax' => $this->moneyWithFallback($orderNode, 'currentTotalTaxSet', 'totalTaxSet', $currency),
                'is_test' => (bool) ($orderNode['test'] ?? false),
                'processed_at' => $this->nullableDate($orderNode['processedAt'] ?? null, 'processedAt'),
                'cancelled_at' => $this->nullableDate($orderNode['cancelledAt'] ?? null, 'cancelledAt'),
                'created_at_shopify' => $this->requiredDate($orderNode['createdAt'] ?? null, 'createdAt'),
                'updated_at_shopify' => $this->nullableDate($orderNode['updatedAt'] ?? null, 'updatedAt'),
                'synced_at' => now(),
            ])->save();

            $itemsCreated = 0;
            $itemsUpdated = 0;
            $seenLineItemIds = [];

            foreach ($lineItemNodes as $lineItemNode) {
                $shopifyLineItemId = $this->numericId($lineItemNode['id'] ?? null, 'LineItem');
                $seenLineItemIds[] = $shopifyLineItemId;
                $shopifyProductId = $this->nullableNumericId(data_get($lineItemNode, 'product.id'), 'Product');
                $shopifyVariantId = $this->nullableNumericId(data_get($lineItemNode, 'variant.id'), 'ProductVariant');
                $product = $shopifyProductId
                    ? Product::query()->forStore($store)->where('shopify_product_id', $shopifyProductId)->first()
                    : null;
                $variant = $product && $shopifyVariantId
                    ? ProductVariant::query()
                        ->whereBelongsTo($product)
                        ->where('shopify_variant_id', $shopifyVariantId)
                        ->first()
                    : null;
                $item = OrderItem::query()->firstOrNew([
                    'order_id' => $order->getKey(),
                    'shopify_line_item_id' => $shopifyLineItemId,
                ]);

                $item->exists ? $itemsUpdated++ : $itemsCreated++;
                $quantity = $lineItemNode['quantity'] ?? null;
                $currentQuantity = $lineItemNode['currentQuantity'] ?? $quantity;

                if (! is_int($quantity) || $quantity < 0 || ! is_int($currentQuantity) || $currentQuantity < 0) {
                    throw new ShopifyApiException('Shopify 订单行项目缺少有效数量。');
                }

                $price = $this->money($lineItemNode, 'originalUnitPriceSet', $currency);
                $attributedSales = data_get($lineItemNode, 'priceAfterAllDiscountsBeforeTaxesSet.shopMoney.amount') !== null
                    ? $this->money($lineItemNode, 'priceAfterAllDiscountsBeforeTaxesSet', $currency)
                    : (string) round((float) $price * $currentQuantity, 4);
                $lineStaffFieldProvided = array_key_exists('staffMember', $lineItemNode);
                $lineStaffId = $lineStaffFieldProvided
                    ? $this->nullableNumericId(data_get($lineItemNode, 'staffMember.id'), 'StaffMember')
                    : $item->shopify_staff_id;

                $item->fill([
                    'product_id' => $product?->getKey(),
                    'variant_id' => $variant?->getKey(),
                    'shopify_product_id' => $shopifyProductId,
                    'shopify_variant_id' => $shopifyVariantId,
                    'title' => $this->requiredString($lineItemNode, 'title'),
                    'quantity' => $quantity,
                    'current_quantity' => $currentQuantity,
                    'price' => $price,
                    'attributed_sales' => $attributedSales,
                    'shopify_staff_id' => $lineStaffId,
                    'staff_name' => $lineStaffFieldProvided
                        ? ($this->nullableString(data_get($lineItemNode, 'staffMember.name'))
                            ?? ($lineStaffId !== null && (string) $item->shopify_staff_id === (string) $lineStaffId ? $item->staff_name : null))
                        : $item->staff_name,
                ])->save();
            }

            $removedItems = $order->items();

            if ($seenLineItemIds !== []) {
                $removedItems->whereNotIn('shopify_line_item_id', $seenLineItemIds);
            }

            $removedItems->delete();

            return [
                'order' => $order,
                'created' => $created,
                'items_created' => $itemsCreated,
                'items_updated' => $itemsUpdated,
            ];
        });
    }

    private function numericId(mixed $gid, string $resource): string
    {
        if (! is_string($gid) || preg_match("#^gid://shopify/{$resource}/([0-9]+)$#", $gid, $matches) !== 1) {
            throw new ShopifyApiException("Shopify {$resource} ID 格式无效。");
        }

        return $matches[1];
    }

    private function nullableNumericId(mixed $gid, string $resource): ?string
    {
        return $gid === null ? null : $this->numericId($gid, $resource);
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new ShopifyApiException("Shopify 订单数据缺少字段 [{$key}]。");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normalizedStatus(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    /** @param array<string, mixed> $node */
    private function money(array $node, string $field, string $expectedCurrency): string
    {
        $amount = data_get($node, "{$field}.shopMoney.amount");
        $currency = data_get($node, "{$field}.shopMoney.currencyCode");

        if (! is_scalar($amount) || ! is_numeric((string) $amount)) {
            throw new ShopifyApiException("Shopify 订单数据缺少有效金额 [{$field}]。");
        }

        if (! is_string($currency) || strtoupper($currency) !== strtoupper($expectedCurrency)) {
            throw new ShopifyApiException("Shopify 订单金额币种 [{$field}] 不一致。");
        }

        return (string) $amount;
    }

    /** @param array<string, mixed> $node */
    private function moneyWithFallback(array $node, string $field, string $fallback, string $expectedCurrency): string
    {
        return data_get($node, "{$field}.shopMoney.amount") !== null
            ? $this->money($node, $field, $expectedCurrency)
            : $this->money($node, $fallback, $expectedCurrency);
    }

    /** @param array<string, mixed> $node */
    private function optionalMoney(array $node, string $field, string $expectedCurrency): string
    {
        return data_get($node, "{$field}.shopMoney.amount") !== null
            ? $this->money($node, $field, $expectedCurrency)
            : '0';
    }

    private function requiredDate(mixed $value, string $field): string
    {
        $date = $this->nullableDate($value, $field);

        if ($date === null) {
            throw new ShopifyApiException("Shopify 订单数据缺少日期 [{$field}]。");
        }

        return $date;
    }

    private function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new ShopifyApiException("Shopify 订单日期 [{$field}] 格式无效。");
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (\Throwable) {
            throw new ShopifyApiException("Shopify 订单日期 [{$field}] 格式无效。");
        }
    }
}
