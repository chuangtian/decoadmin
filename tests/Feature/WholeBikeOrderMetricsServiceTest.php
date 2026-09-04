<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Services\WholeBikeOrderMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholeBikeOrderMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_only_orders_containing_one_of_the_five_configured_whole_bikes(): void
    {
        $organization = Organization::query()->create(['name' => 'Bike Org', 'code' => 'bike-org']);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike',
            'shopify_domain' => 'macfoxebike.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/Los_Angeles',
        ]);
        $products = collect([
            'macfox-x1',
            'macfox-x7',
            'x1s-x-bs-zay',
            'macfox-x2',
            'macfox-m16-ebike',
            'macfox-accessory-bag',
        ])->mapWithKeys(fn (string $handle, int $index): array => [
            $handle => Product::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'shopify_product_id' => (string) (1000 + $index),
                'title' => $handle,
                'handle' => $handle,
                'status' => 'active',
                'synced_at' => now(),
            ]),
        ]);

        $bikeOrder = $this->order($organization->id, $store, 2001, '2026-08-10 19:00:00', 1100);
        $this->item($bikeOrder, $products['macfox-x1'], 3001, 1000);
        $this->item($bikeOrder, $products['macfox-accessory-bag'], 3002, 100);

        $accessoryOrder = $this->order($organization->id, $store, 2002, '2026-08-11 19:00:00', 100);
        $this->item($accessoryOrder, $products['macfox-accessory-bag'], 3003, 100);

        $comparisonOrder = $this->order($organization->id, $store, 2003, '2026-07-10 19:00:00', 800);
        $this->item($comparisonOrder, $products['macfox-x7'], 3004, 800);

        $result = app(WholeBikeOrderMetricsService::class)->forStore(
            $store,
            [
                'from' => '2026-08-01',
                'to' => '2026-08-31',
                'include_test' => false,
                'include_cancelled' => true,
            ],
            ['from' => '2026-07-01', 'to' => '2026-07-31'],
        );

        $this->assertTrue($result['available']);
        $this->assertSame(5, $result['product_count']);
        $this->assertSame(1, $result['current']['orders']);
        $this->assertSame(1100.0, $result['current']['net_sales']);
        $this->assertSame(1100.0, $result['current']['average_order_value']);
        $this->assertSame(1, $result['comparison']['orders']);
        $this->assertSame(800.0, $result['comparison']['average_order_value']);
        $this->assertSame(1, collect($result['current']['trend'])->firstWhere('date', '2026-08-10')['orders']);
        $this->assertSame(0, collect($result['current']['trend'])->firstWhere('date', '2026-08-11')['orders']);
    }

    public function test_it_does_not_apply_partial_product_configuration(): void
    {
        $organization = Organization::query()->create(['name' => 'Partial Org', 'code' => 'partial-org']);
        $store = $organization->stores()->create([
            'name' => 'Partial Store',
            'shopify_domain' => 'partial.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => '1000',
            'title' => 'Macfox X1',
            'handle' => 'macfox-x1',
            'status' => 'active',
            'synced_at' => now(),
        ]);

        $result = app(WholeBikeOrderMetricsService::class)->forStore(
            $store,
            ['from' => '2026-08-01', 'to' => '2026-08-31'],
            null,
        );

        $this->assertFalse($result['available']);
        $this->assertSame(1, $result['product_count']);
        $this->assertNull($result['current']);
    }

    private function order(int $organizationId, Store $store, int $shopifyId, string $createdAt, float $netSales): Order
    {
        return Order::query()->create([
            'organization_id' => $organizationId,
            'store_id' => $store->id,
            'shopify_order_id' => (string) $shopifyId,
            'order_number' => '#'.$shopifyId,
            'currency' => 'USD',
            'total_price' => $netSales,
            'subtotal_price' => $netSales,
            'net_sales' => $netSales,
            'total_tax' => 0,
            'created_at_shopify' => $createdAt,
            'synced_at' => now(),
        ]);
    }

    private function item(Order $order, Product $product, int $shopifyId, float $price): void
    {
        OrderItem::query()->create([
            'order_id' => $order->id,
            'shopify_line_item_id' => (string) $shopifyId,
            'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_product_id,
            'title' => $product->title,
            'quantity' => 1,
            'current_quantity' => 1,
            'price' => $price,
            'attributed_sales' => $price,
        ]);
    }
}
