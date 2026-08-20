<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\InventoryItem;
use App\Models\Location;
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

class ShopifyInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_create_inventory_sync_job(): void
    {
        Queue::fake();
        [$admin, $organization, $store, $installation] = $this->context('organization-admin');

        $response = $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'inventory',
            ]);

        $syncJob = SyncJob::query()->sole();
        $response->assertRedirect(route('sync.show', $syncJob));
        $this->assertSame('queued', $syncJob->status);
        $this->assertFalse($syncJob->payload['framework_only']);
        Queue::assertPushed(ProcessSyncJob::class);
    }

    public function test_developer_cannot_create_inventory_sync_job(): void
    {
        Queue::fake();
        [$developer, $organization, $store, $installation] = $this->context('developer');

        $this->actingAs($developer)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'inventory',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sync_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_inventory_sync_works_without_location_details_scope(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        $installation->shopifyConnection->forceFill([
            'scopes' => ['read_inventory', 'read_products'],
        ])->save();

        Http::fake(['*' => Http::response($this->inventoryItemsPayload([
            [
                'id' => 'gid://shopify/InventoryItem/901',
                'sku' => 'LIMITED-SCOPE',
                'tracked' => true,
                'variant' => null,
                'inventoryLevels' => [
                    'nodes' => [[
                        'location' => ['id' => 'gid://shopify/Location/1001'],
                        'quantities' => [['name' => 'available', 'quantity' => 8]],
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ],
            ],
        ]))]);

        $result = app(SyncProcessor::class)->process(
            $this->syncJob($organization, $store, $installation)->id,
        );

        $this->assertTrue($result->success);
        $this->assertSame('success', $result->status);
        $this->assertDatabaseHas('locations', [
            'shopify_location_id' => 1001,
            'name' => 'Shopify Location 1001',
            'active' => true,
        ]);
        $this->assertDatabaseHas('inventory_levels', [
            'shopify_location_id' => 1001,
            'available' => 8,
        ]);
    }

    public function test_inventory_sync_parses_graphql_and_saves_location_item_and_level(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        [, $variant] = $this->localProduct($organization, $store, 701, 801);
        Http::fake(['*' => Http::response($this->inventoryItemsPayload([
            $this->inventoryItemNode(901, 'X1-BLK', true, 801, [
                $this->inventoryLevelNode(1001, 'Macfox Warehouse', 12),
                $this->inventoryLevelNode(1002, 'Macfox Retail', -2, false),
            ]),
        ]))]);

        $syncJob = $this->syncJob($organization, $store, $installation);
        $result = app(SyncProcessor::class)->process($syncJob->id);
        $syncJob->refresh();

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->recordsCount);
        $this->assertSame('completed', $syncJob->status);
        $this->assertSame(1, $syncJob->processed_items);
        $this->assertSame(1, $result->metadata['created_count']);
        $this->assertSame(2, $result->metadata['levels_created']);
        $this->assertDatabaseHas('inventory_items', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_inventory_item_id' => 901,
            'variant_id' => $variant->id,
            'shopify_variant_id' => 801,
            'sku' => 'X1-BLK',
            'tracked' => true,
        ]);
        $inventoryItem = InventoryItem::query()->sole();
        $this->assertNotNull($inventoryItem->synced_at);
        $this->assertDatabaseHas('locations', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_location_id' => 1001,
            'name' => 'Macfox Warehouse',
            'active' => true,
        ]);
        $location = Location::query()->where('shopify_location_id', 1001)->sole();
        $this->assertSame('123 Commerce St', $location->address['address1']);
        $this->assertSame('US', $location->address['country_code']);
        $this->assertDatabaseHas('inventory_levels', [
            'inventory_item_id' => $inventoryItem->id,
            'location_id' => $location->id,
            'shopify_location_id' => 1001,
            'available' => 12,
        ]);
        $this->assertDatabaseHas('inventory_levels', [
            'shopify_location_id' => 1002,
            'available' => -2,
        ]);
        $this->assertDatabaseCount('locations', 2);
        $this->assertDatabaseCount('inventory_levels', 2);
    }

    public function test_inventory_sync_skips_bundle_level_without_available_quantity(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->inventoryItemsPayload([
            $this->inventoryItemNode(901, 'BUNDLE-X7-BLACK-X1S-BLACK', true, null, [[
                'location' => [
                    'id' => 'gid://shopify/Location/1001',
                    'name' => 'Bundle Location',
                    'isActive' => true,
                ],
                'quantities' => [],
            ]]),
        ]))]);

        $result = app(SyncProcessor::class)->process(
            $this->syncJob($organization, $store, $installation)->id,
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->recordsCount);
        $this->assertSame(1, $result->metadata['levels_unavailable']);
        $this->assertSame(0, $result->metadata['levels_removed']);
        $this->assertStringContainsString('1 个库存级别没有地点可用数量，已跳过', $result->message);
        $this->assertDatabaseHas('inventory_items', [
            'store_id' => $store->id,
            'shopify_inventory_item_id' => 901,
            'sku' => 'BUNDLE-X7-BLACK-X1S-BLACK',
        ]);
        $this->assertDatabaseCount('inventory_levels', 0);
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_inventory_sync_removes_stale_level_when_available_quantity_disappears(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->inventoryItemsPayload([
                $this->inventoryItemNode(901, 'BUNDLE', true, null, [
                    $this->inventoryLevelNode(1001, 'Warehouse', 10),
                ]),
            ]))
            ->push($this->inventoryItemsPayload([
                $this->inventoryItemNode(901, 'BUNDLE', true, null, [[
                    'location' => ['id' => 'gid://shopify/Location/1001'],
                    'quantities' => [],
                ]]),
            ]));

        app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);
        $result = app(SyncProcessor::class)->process(
            $this->syncJob($organization, $store, $installation)->id,
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->metadata['levels_unavailable']);
        $this->assertSame(1, $result->metadata['levels_removed']);
        $this->assertDatabaseCount('inventory_items', 1);
        $this->assertDatabaseCount('inventory_levels', 0);
    }

    public function test_missing_local_variant_does_not_block_inventory_sync(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->inventoryItemsPayload([
            $this->inventoryItemNode(901, 'EXTERNAL', true, 9999, [
                $this->inventoryLevelNode(1001, 'Warehouse', 4),
            ]),
        ]))]);

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertTrue($result->success);
        $this->assertDatabaseHas('inventory_items', [
            'shopify_inventory_item_id' => 901,
            'variant_id' => null,
            'shopify_variant_id' => 9999,
        ]);
    }

    public function test_inventory_item_and_level_connections_are_cursor_paginated(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->inventoryItemsPayload([
                $this->inventoryItemNode(901, 'FIRST', true, null, [
                    $this->inventoryLevelNode(1001, 'Warehouse', 10),
                ], true, 'level-cursor'),
            ], true, 'item-cursor'))
            ->push($this->inventoryLevelsPayload([
                $this->inventoryLevelNode(1002, 'Retail', 5),
            ]))
            ->push($this->inventoryItemsPayload([
                $this->inventoryItemNode(902, 'SECOND', false, null, [
                    $this->inventoryLevelNode(1001, 'Warehouse', 0),
                ]),
            ]));

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->recordsCount);
        $this->assertSame(2, $result->metadata['inventory_item_pages']);
        $this->assertSame(3, $result->metadata['inventory_level_pages']);
        $this->assertDatabaseCount('inventory_items', 2);
        $this->assertDatabaseCount('inventory_levels', 3);

        $requests = [];
        Http::assertSent(function (Request $request) use (&$requests): bool {
            $requests[] = $request->data()['variables'];

            return true;
        });
        $this->assertNull($requests[0]['after']);
        $this->assertSame('level-cursor', $requests[1]['after']);
        $this->assertSame('item-cursor', $requests[2]['after']);
    }

    public function test_repeated_sync_updates_inventory_without_duplicates(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->inventoryItemsPayload([
                $this->inventoryItemNode(901, 'OLD-SKU', true, null, [
                    $this->inventoryLevelNode(1001, 'Old Warehouse', 10),
                ]),
            ]))
            ->push($this->inventoryItemsPayload([
                $this->inventoryItemNode(901, 'NEW-SKU', false, null, [
                    $this->inventoryLevelNode(1001, 'New Warehouse', 25),
                ]),
            ]));
        app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertDatabaseCount('inventory_items', 1);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('inventory_levels', 1);
        $this->assertDatabaseHas('inventory_items', [
            'shopify_inventory_item_id' => 901,
            'sku' => 'NEW-SKU',
            'tracked' => false,
        ]);
        $this->assertDatabaseHas('locations', [
            'shopify_location_id' => 1001,
            'name' => 'New Warehouse',
        ]);
        $this->assertDatabaseHas('inventory_levels', [
            'shopify_location_id' => 1001,
            'available' => 25,
        ]);
        $this->assertSame(0, $result->metadata['created_count']);
        $this->assertSame(1, $result->metadata['updated_count']);
        $this->assertSame(1, $result->metadata['levels_updated']);
    }

    public function test_same_shopify_ids_remain_isolated_by_store_and_organization(): void
    {
        [, $organizationA, $storeA, $installationA] = $this->context('store-admin');
        [, $organizationB, $storeB, $installationB] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->inventoryItemsPayload([
            $this->inventoryItemNode(901, 'SHARED', true, null, [
                $this->inventoryLevelNode(1001, 'Warehouse', 10),
            ]),
        ]))]);

        app(SyncProcessor::class)->process($this->syncJob($organizationA, $storeA, $installationA)->id);
        app(SyncProcessor::class)->process($this->syncJob($organizationB, $storeB, $installationB)->id);

        $this->assertDatabaseCount('inventory_items', 2);
        $this->assertDatabaseCount('locations', 2);
        $this->assertSame(1, InventoryItem::query()->forStore($storeA)->count());
        $this->assertSame(1, InventoryItem::query()->forStore($storeB)->count());
        $this->assertSame(1, InventoryItem::query()->forOrganization($organizationA)->count());
        $this->assertSame(1, InventoryItem::query()->forOrganization($organizationB)->count());
        $this->assertSame(1, Location::query()->forStore($storeA)->count());
        $this->assertSame(1, Location::query()->forStore($storeB)->count());
    }

    public function test_shopify_api_failure_marks_sync_job_failed_without_saving_inventory(): void
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
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('locations', 0);
        $this->assertDatabaseCount('inventory_levels', 0);
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
            'handle' => 'inventory-sync-'.Str::lower(Str::random(10)),
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'inventory-sync-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'inventory-sync-token',
            'token_type' => 'offline',
            'scopes' => ['read_inventory', 'read_products', 'read_locations'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_inventory', 'read_products', 'read_locations'],
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
            'inventory_item_id' => 901,
        ]);

        return [$product, $variant];
    }

    private function syncJob(Organization $organization, Store $store, AppInstallation $installation): SyncJob
    {
        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'app_id' => $installation->app_id,
            'app_installation_id' => $installation->id,
            'type' => 'inventory',
            'direction' => 'pull',
            'status' => 'queued',
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
     * @param  list<array<string, mixed>>  $inventoryItems
     * @return array<string, mixed>
     */
    private function inventoryItemsPayload(array $inventoryItems, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'inventoryItems' => [
                    'nodes' => $inventoryItems,
                    'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     * @return array<string, mixed>
     */
    private function inventoryLevelsPayload(array $levels, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'inventoryItem' => [
                    'inventoryLevels' => [
                        'nodes' => $levels,
                        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     * @return array<string, mixed>
     */
    private function inventoryItemNode(
        int $id,
        ?string $sku,
        bool $tracked,
        ?int $variantId,
        array $levels,
        bool $hasNextLevels = false,
        ?string $levelCursor = null,
    ): array {
        return [
            'id' => "gid://shopify/InventoryItem/{$id}",
            'sku' => $sku,
            'tracked' => $tracked,
            'variants' => [
                'nodes' => $variantId ? [['id' => "gid://shopify/ProductVariant/{$variantId}"]] : [],
            ],
            'inventoryLevels' => [
                'nodes' => $levels,
                'pageInfo' => ['hasNextPage' => $hasNextLevels, 'endCursor' => $levelCursor],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function inventoryLevelNode(int $locationId, string $name, int $available, bool $active = true): array
    {
        return [
            'location' => [
                'id' => "gid://shopify/Location/{$locationId}",
                'name' => $name,
                'isActive' => $active,
                'address' => [
                    'address1' => '123 Commerce St',
                    'address2' => null,
                    'city' => 'Los Angeles',
                    'province' => 'California',
                    'provinceCode' => 'CA',
                    'country' => 'United States',
                    'countryCode' => 'US',
                    'zip' => '90001',
                    'phone' => '+12135550101',
                ],
            ],
            'quantities' => [
                ['name' => 'available', 'quantity' => $available, 'updatedAt' => '2026-08-17T12:00:00Z'],
            ],
        ];
    }
}
