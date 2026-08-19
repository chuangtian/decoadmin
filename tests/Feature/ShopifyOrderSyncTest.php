<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\SyncProcessor;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopifyOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_create_order_sync_job(): void
    {
        Queue::fake();
        [$admin, $organization, $store, $installation] = $this->context('organization-admin');

        $response = $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'orders',
            ]);

        $syncJob = SyncJob::query()->sole();
        $response->assertRedirect(route('sync.show', $syncJob));
        $this->assertSame('queued', $syncJob->status);
        $this->assertFalse($syncJob->payload['framework_only']);
        Queue::assertPushed(ProcessSyncJob::class);
    }

    public function test_developer_cannot_create_order_sync_job(): void
    {
        Queue::fake();
        [$developer, $organization, $store, $installation] = $this->context('developer');

        $this->actingAs($developer)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'orders',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sync_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_order_sync_parses_graphql_and_saves_order_and_line_items(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        [$product, $variant] = $this->localProduct($organization, $store, 701, 801);
        Http::fake(['*' => Http::response($this->ordersPayload([
            $this->orderNode(101, '#1001', [
                $this->lineItemNode(201, 'Macfox X1', 2, '1299.00', 701, 801),
                $this->lineItemNode(202, 'Warranty', 1, '99.50'),
            ]),
        ]))]);

        $syncJob = $this->syncJob($organization, $store, $installation);
        $result = app(SyncProcessor::class)->process($syncJob->id);
        $syncJob->refresh();

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->recordsCount);
        $this->assertSame('completed', $syncJob->status);
        $this->assertSame(2, $syncJob->result['metadata']['line_items_count']);
        $this->assertGreaterThanOrEqual(0, $syncJob->result['metadata']['duration_ms']);
        $this->assertDatabaseHas('orders', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => 101,
            'order_number' => '#1001',
            'email' => 'customer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => '2697.5000',
            'subtotal_price' => '2697.5000',
            'total_tax' => '0.0000',
        ]);
        $order = Order::query()->sole();
        $this->assertNotNull($order->synced_at);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'shopify_line_item_id' => 201,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'shopify_product_id' => 701,
            'shopify_variant_id' => 801,
            'quantity' => 2,
            'price' => '1299.0000',
        ]);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_missing_local_product_associations_do_not_block_order_sync(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->ordersPayload([
            $this->orderNode(101, '#1001', [
                $this->lineItemNode(201, 'External Product', 1, '25.00', 999, 1999),
            ]),
        ]))]);

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertTrue($result->success);
        $this->assertDatabaseHas('order_items', [
            'shopify_line_item_id' => 201,
            'product_id' => null,
            'variant_id' => null,
            'shopify_product_id' => 999,
            'shopify_variant_id' => 1999,
        ]);
    }

    public function test_order_and_line_item_connections_are_paginated(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->ordersPayload([
                $this->orderNode(101, '#1001', [
                    $this->lineItemNode(201, 'First item', 1, '10.00'),
                ], true, 'line-item-cursor'),
            ], true, 'order-cursor'))
            ->push($this->lineItemsPayload([
                $this->lineItemNode(202, 'Second item', 2, '20.00'),
            ]))
            ->push($this->ordersPayload([
                $this->orderNode(102, '#1002', [
                    $this->lineItemNode(203, 'Third item', 1, '30.00'),
                ]),
            ]));

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->recordsCount);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('order_items', 3);
        $this->assertSame(2, $result->metadata['order_pages']);
        $this->assertSame(3, $result->metadata['line_item_pages']);

        $requests = [];
        Http::assertSent(function (Request $request) use (&$requests): bool {
            $requests[] = $request->data()['variables'];

            return true;
        });
        $this->assertNull($requests[0]['after']);
        $this->assertSame('line-item-cursor', $requests[1]['after']);
        $this->assertSame('order-cursor', $requests[2]['after']);
    }

    public function test_repeated_sync_updates_order_and_line_item_without_duplicates(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->ordersPayload([
                $this->orderNode(101, '#1001', [
                    $this->lineItemNode(201, 'Old title', 1, '10.00'),
                ], total: '10.00'),
            ]))
            ->push($this->ordersPayload([
                $this->orderNode(101, '#1001', [
                    $this->lineItemNode(201, 'Updated title', 3, '12.50'),
                ], total: '37.50', financialStatus: 'PARTIALLY_REFUNDED'),
            ]));
        app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('orders', [
            'shopify_order_id' => 101,
            'financial_status' => 'partially_refunded',
            'total_price' => '37.5000',
        ]);
        $this->assertDatabaseHas('order_items', [
            'shopify_line_item_id' => 201,
            'title' => 'Updated title',
            'quantity' => 3,
            'price' => '12.5000',
        ]);
        $this->assertSame(0, $result->metadata['orders_created']);
        $this->assertSame(1, $result->metadata['orders_updated']);
        $this->assertSame(0, $result->metadata['line_items_created']);
        $this->assertSame(1, $result->metadata['line_items_updated']);
    }

    public function test_same_shopify_id_remains_isolated_by_store_and_organization(): void
    {
        [, $organizationA, $storeA, $installationA] = $this->context('store-admin');
        [, $organizationB, $storeB, $installationB] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->ordersPayload([
            $this->orderNode(101, '#1001', [$this->lineItemNode(201, 'Item', 1, '10.00')]),
        ]))]);

        app(SyncProcessor::class)->process($this->syncJob($organizationA, $storeA, $installationA)->id);
        app(SyncProcessor::class)->process($this->syncJob($organizationB, $storeB, $installationB)->id);

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(1, Order::query()->forStore($storeA)->count());
        $this->assertSame(1, Order::query()->forStore($storeB)->count());
        $this->assertSame(1, Order::query()->forOrganization($organizationA)->count());
        $this->assertSame(1, Order::query()->forOrganization($organizationB)->count());
    }

    public function test_time_filter_is_reserved_in_graphql_variables(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->ordersPayload([]))]);
        $syncJob = $this->syncJob($organization, $store, $installation, [
            'filters' => [
                'updated_at_from' => '2026-08-01T00:00:00+08:00',
                'updated_at_to' => '2026-08-02T00:00:00+08:00',
            ],
        ]);

        $result = app(SyncProcessor::class)->process($syncJob->id);

        $this->assertTrue($result->success);
        $this->assertTrue($result->metadata['time_filter_applied']);
        Http::assertSent(fn (Request $request): bool => $request->data()['variables']['query']
            === "updated_at:>='2026-07-31T16:00:00Z' updated_at:<='2026-08-01T16:00:00Z'");
    }

    public function test_shopify_api_failure_marks_sync_job_failed_without_saving_orders(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response([], 500)]);
        $syncJob = $this->syncJob($organization, $store, $installation);

        try {
            app(SyncProcessor::class)->process($syncJob->id);
            $this->fail('Expected Shopify API exception.');
        } catch (ShopifyApiException) {
            // Horizon retries transient Shopify API failures.
        }

        $syncJob->refresh();
        $this->assertSame('failed', $syncJob->status);
        $this->assertNotNull($syncJob->last_error);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    /** @return array{0: User, 1: Organization, 2: Store, 3: AppInstallation} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Organization '.Str::random(6),
            'code' => Str::lower(Str::random(12)),
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Store '.Str::random(6),
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        $app = App::query()->create([
            'name' => 'Shopify Commerce Hub',
            'handle' => 'order-sync-'.Str::lower(Str::random(10)),
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'order-sync-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'order-sync-token',
            'token_type' => 'offline',
            'scopes' => ['read_products', 'read_inventory', 'read_orders'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products', 'read_inventory', 'read_orders'],
            'installed_at' => now(),
        ]);

        return [$user, $organization, $store, $installation];
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function localProduct(Organization $organization, Store $store, int $productId, int $variantId): array
    {
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $productId,
            'title' => 'Local product',
            'handle' => 'local-product',
            'status' => 'active',
            'synced_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'shopify_variant_id' => $variantId,
            'title' => 'Local variant',
            'price' => '1299.00',
        ]);

        return [$product, $variant];
    }

    /** @param array<string, mixed> $payload */
    private function syncJob(
        Organization $organization,
        Store $store,
        AppInstallation $installation,
        array $payload = [],
    ): SyncJob {
        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'app_id' => $installation->app_id,
            'app_installation_id' => $installation->id,
            'type' => 'orders',
            'direction' => 'pull',
            'status' => 'queued',
            'payload' => $payload,
            'logs' => [],
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array<string, mixed>
     */
    private function ordersPayload(array $orders, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'orders' => [
                    'nodes' => $orders,
                    'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, mixed>
     */
    private function lineItemsPayload(array $lineItems, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'order' => [
                    'lineItems' => [
                        'nodes' => $lineItems,
                        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, mixed>
     */
    private function orderNode(
        int $id,
        string $number,
        array $lineItems,
        bool $hasNextItems = false,
        ?string $lineItemCursor = null,
        string $total = '2697.50',
        string $financialStatus = 'PAID',
    ): array {
        return [
            'id' => "gid://shopify/Order/{$id}",
            'name' => $number,
            'email' => 'customer@example.com',
            'displayFinancialStatus' => $financialStatus,
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'currencyCode' => 'USD',
            'totalPriceSet' => $this->moneyBag($total),
            'subtotalPriceSet' => $this->moneyBag($total),
            'totalTaxSet' => $this->moneyBag('0.00'),
            'processedAt' => '2026-08-17T10:00:00Z',
            'createdAt' => '2026-08-17T09:55:00Z',
            'lineItems' => [
                'nodes' => $lineItems,
                'pageInfo' => ['hasNextPage' => $hasNextItems, 'endCursor' => $lineItemCursor],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function lineItemNode(
        int $id,
        string $title,
        int $quantity,
        string $price,
        ?int $productId = null,
        ?int $variantId = null,
    ): array {
        return [
            'id' => "gid://shopify/LineItem/{$id}",
            'title' => $title,
            'quantity' => $quantity,
            'originalUnitPriceSet' => $this->moneyBag($price),
            'product' => $productId ? ['id' => "gid://shopify/Product/{$productId}"] : null,
            'variant' => $variantId ? ['id' => "gid://shopify/ProductVariant/{$variantId}"] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function moneyBag(string $amount): array
    {
        return ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];
    }
}
