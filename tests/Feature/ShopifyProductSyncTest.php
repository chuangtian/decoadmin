<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Product;
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

class ShopifyProductSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_create_product_sync_job(): void
    {
        Queue::fake();
        [$admin, $organization, $store, $installation] = $this->context('organization-admin');

        $response = $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'products',
            ]);

        $syncJob = SyncJob::query()->sole();
        $response->assertRedirect(route('sync.show', $syncJob));
        $this->assertSame('queued', $syncJob->status);
        $this->assertFalse($syncJob->payload['framework_only']);
        Queue::assertPushed(ProcessSyncJob::class);
    }

    public function test_developer_cannot_create_product_sync_job(): void
    {
        Queue::fake();
        [$developer, $organization, $store, $installation] = $this->context('developer');

        $this->actingAs($developer)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'products',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sync_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_product_sync_parses_graphql_and_saves_products_and_variants(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->productsPayload([
                $this->productNode(101, 'Macfox X1', [
                    $this->variantNode(201, 'Black', 'X1-BLK', '1299.00', 301),
                    $this->variantNode(202, 'White', null, '1399.50', 302),
                ]),
            ]))
            ->push($this->collectionsPayload([]));

        $syncJob = $this->syncJob($organization, $store, $installation);
        $result = app(SyncProcessor::class)->process($syncJob->id);
        $syncJob->refresh();

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->recordsCount);
        $this->assertSame('completed', $syncJob->status);
        $this->assertSame(1, $syncJob->processed_items);
        $this->assertSame(2, $syncJob->result['metadata']['variants_count']);
        $this->assertGreaterThanOrEqual(0, $syncJob->result['metadata']['duration_ms']);
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => 101,
            'title' => 'Macfox X1',
            'handle' => 'macfox-x1',
            'status' => 'active',
            'vendor' => 'Macfox',
            'product_type' => 'Ebike',
            'featured_image_url' => 'https://cdn.shopify.com/macfox-x1.jpg',
        ]);
        $product = Product::query()->sole();
        $this->assertNotNull($product->synced_at);
        $this->assertSame(['Ebike', 'Featured'], $product->tags);
        $this->assertSame('2026-01-02T03:04:05+00:00', $product->created_at_shopify?->toIso8601String());
        $this->assertSame('2026-01-03T03:04:05+00:00', $product->published_at_shopify?->toIso8601String());
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'shopify_variant_id' => 201,
            'sku' => 'X1-BLK',
            'price' => '1299.0000',
            'compare_at_price' => '1499.0000',
            'available_for_sale' => true,
            'image_url' => 'https://cdn.shopify.com/variant-201.jpg',
            'inventory_item_id' => 301,
        ]);
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_product_and_variant_connections_are_paginated(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->productsPayload([
                $this->productNode(101, 'Macfox X1', [
                    $this->variantNode(201, 'Black', 'X1-BLK', '1299.00', 301),
                ], true, 'variant-cursor'),
            ], true, 'product-cursor'))
            ->push($this->variantsPayload([
                $this->variantNode(202, 'White', 'X1-WHT', '1399.00', 302),
            ]))
            ->push($this->productsPayload([
                $this->productNode(102, 'Macfox X2', [
                    $this->variantNode(203, 'Default', 'X2', '1599.00', 303),
                ]),
            ]))
            ->push($this->collectionsPayload([]));

        $syncJob = $this->syncJob($organization, $store, $installation);
        $result = app(SyncProcessor::class)->process($syncJob->id);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->recordsCount);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_variants', 3);
        $this->assertSame(2, $result->metadata['product_pages']);
        $this->assertSame(3, $result->metadata['variant_pages']);

        $requests = [];
        Http::assertSent(function (Request $request) use (&$requests): bool {
            $requests[] = $request->data()['variables'];

            return true;
        });
        $this->assertNull($requests[0]['after']);
        $this->assertSame('variant-cursor', $requests[1]['after']);
        $this->assertSame('product-cursor', $requests[2]['after']);
        $this->assertNull($requests[3]['after']);
    }

    public function test_repeated_sync_updates_product_and_variant_without_duplicates(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->productsPayload([
                $this->productNode(101, 'Old title', [
                    $this->variantNode(201, 'Default', 'OLD-SKU', '100.00', 301),
                ]),
            ]))
            ->push($this->collectionsPayload([]))
            ->push($this->productsPayload([
                $this->productNode(101, 'New title', [
                    $this->variantNode(201, 'Updated', 'NEW-SKU', '120.50', 301),
                ]),
            ]))
            ->push($this->collectionsPayload([]));
        app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('products', ['shopify_product_id' => 101, 'title' => 'New title']);
        $this->assertDatabaseHas('product_variants', [
            'shopify_variant_id' => 201,
            'title' => 'Updated',
            'sku' => 'NEW-SKU',
            'price' => '120.5000',
        ]);
        $this->assertSame(0, $result->metadata['products_created']);
        $this->assertSame(1, $result->metadata['products_updated']);
        $this->assertSame(0, $result->metadata['variants_created']);
        $this->assertSame(1, $result->metadata['variants_updated']);
    }

    public function test_same_shopify_id_remains_isolated_by_store_and_organization(): void
    {
        [, $organizationA, $storeA, $installationA] = $this->context('store-admin');
        [, $organizationB, $storeB, $installationB] = $this->context('store-admin');
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            return str_contains($query, 'SyncCollections')
                ? Http::response($this->collectionsPayload([]))
                : Http::response($this->productsPayload([
                    $this->productNode(101, 'Shared Shopify ID', [
                        $this->variantNode(201, 'Default', null, '99.00', 301),
                    ]),
                ]));
        });

        app(SyncProcessor::class)->process($this->syncJob($organizationA, $storeA, $installationA)->id);
        app(SyncProcessor::class)->process($this->syncJob($organizationB, $storeB, $installationB)->id);

        $this->assertDatabaseCount('products', 2);
        $this->assertSame(1, Product::query()->forStore($storeA)->count());
        $this->assertSame(1, Product::query()->forStore($storeB)->count());
        $this->assertSame(1, Product::query()->forOrganization($organizationA)->count());
        $this->assertSame(1, Product::query()->forOrganization($organizationB)->count());
    }

    public function test_shopify_api_failure_marks_sync_job_failed_without_saving_products(): void
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
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_collection_and_membership_connections_are_paginated_and_store_scoped(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->productsPayload([
                $this->productNode(101, 'Macfox X1', [$this->variantNode(201, 'Default', 'X1', '1299', 301)]),
                $this->productNode(102, 'Macfox X2', [$this->variantNode(202, 'Default', 'X2', '1499', 302)]),
            ]))
            ->push($this->collectionsPayload([
                $this->collectionNode(501, 'Ebike', [101], true, 'membership-cursor'),
            ]))
            ->push($this->collectionPayload(
                $this->collectionNode(501, 'Ebike', [102]),
            ));

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->metadata['collections_count']);
        $this->assertSame(2, $result->metadata['membership_pages']);
        $this->assertSame(2, $result->metadata['memberships_count']);
        $this->assertSame(0, $result->metadata['unresolved_memberships']);
        $this->assertDatabaseHas('product_collections', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => 501,
            'handle' => 'ebike',
        ]);
        $this->assertDatabaseCount('product_collection_memberships', 2);
        $this->assertSame(
            [101, 102],
            Product::query()->whereHas('collections', fn ($query) => $query
                ->where('shopify_collection_id', 501))->orderBy('shopify_product_id')->pluck('shopify_product_id')->map(fn ($id) => (int) $id)->all(),
        );
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
            'handle' => 'product-sync-'.Str::lower(Str::random(10)),
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'product-sync-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'product-sync-token',
            'token_type' => 'offline',
            'scopes' => ['read_products', 'read_inventory'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products', 'read_inventory'],
            'installed_at' => now(),
        ]);

        return [$user, $organization, $store, $installation];
    }

    private function syncJob(
        Organization $organization,
        Store $store,
        AppInstallation $installation,
    ): SyncJob {
        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'app_id' => $installation->app_id,
            'app_installation_id' => $installation->id,
            'type' => 'products',
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
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function productsPayload(array $products, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'products' => [
                    'nodes' => $products,
                    'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function variantsPayload(array $variants, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'product' => [
                    'variants' => [
                        'nodes' => $variants,
                        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                    ],
                ],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $collections */
    private function collectionsPayload(array $collections, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'collections' => [
                    'nodes' => $collections,
                    'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $collection */
    private function collectionPayload(array $collection): array
    {
        return ['data' => ['collection' => $collection]];
    }

    /** @param list<int> $productIds
     * @return array<string, mixed>
     */
    private function collectionNode(
        int $id,
        string $title,
        array $productIds,
        bool $hasNextPage = false,
        ?string $endCursor = null,
    ): array {
        return [
            'id' => "gid://shopify/Collection/{$id}",
            'title' => $title,
            'handle' => Str::slug($title),
            'updatedAt' => '2026-01-04T03:04:05Z',
            'sortOrder' => 'MANUAL',
            'products' => [
                'nodes' => array_map(fn (int $productId): array => [
                    'id' => "gid://shopify/Product/{$productId}",
                ], $productIds),
                'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function productNode(
        int $id,
        string $title,
        array $variants,
        bool $hasNextVariants = false,
        ?string $variantCursor = null,
    ): array {
        return [
            'id' => "gid://shopify/Product/{$id}",
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'ACTIVE',
            'vendor' => 'Macfox',
            'productType' => 'Ebike',
            'description' => 'Product description',
            'tags' => ['Ebike', 'Featured'],
            'createdAt' => '2026-01-02T03:04:05Z',
            'publishedAt' => '2026-01-03T03:04:05Z',
            'onlineStoreUrl' => 'https://example.myshopify.com/products/'.Str::slug($title),
            'featuredMedia' => [
                'alt' => $title,
                'image' => [
                    'url' => 'https://cdn.shopify.com/'.Str::slug($title).'.jpg',
                    'width' => 1200,
                    'height' => 800,
                ],
            ],
            'variants' => [
                'nodes' => $variants,
                'pageInfo' => ['hasNextPage' => $hasNextVariants, 'endCursor' => $variantCursor],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function variantNode(
        int $id,
        string $title,
        ?string $sku,
        string $price,
        int $inventoryItemId,
    ): array {
        return [
            'id' => "gid://shopify/ProductVariant/{$id}",
            'title' => $title,
            'sku' => $sku,
            'price' => $price,
            'compareAtPrice' => (string) ((float) $price + 200),
            'availableForSale' => true,
            'selectedOptions' => [['name' => 'Color', 'value' => $title]],
            'media' => [
                'nodes' => [[
                    'alt' => $title,
                    'image' => [
                        'url' => "https://cdn.shopify.com/variant-{$id}.jpg",
                        'width' => 900,
                        'height' => 600,
                    ],
                ]],
            ],
            'inventoryItem' => ['id' => "gid://shopify/InventoryItem/{$inventoryItemId}"],
        ];
    }
}
