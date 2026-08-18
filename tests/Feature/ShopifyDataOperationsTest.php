<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\ShopifyIncrementalDataService;
use App\Services\Shopify\Webhooks\ShopifyWebhookSubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyDataOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_pages_are_scoped_to_current_store_and_permission(): void
    {
        [$user, $organization, $store] = $this->adminContext();
        $otherStore = $organization->stores()->create(['name' => 'EU', 'shopify_domain' => 'eu.myshopify.com', 'status' => 'active']);
        Product::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => '101', 'title' => 'Visible Product', 'handle' => 'visible', 'status' => 'active', 'synced_at' => now()]);
        Product::query()->create(['organization_id' => $organization->id, 'store_id' => $otherStore->id, 'shopify_product_id' => '102', 'title' => 'Hidden Product', 'handle' => 'hidden', 'status' => 'active', 'synced_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('products.data.0.title', 'Visible Product')
                ->missing('products.data.1'));
    }

    public function test_product_order_customer_and_inventory_webhooks_update_local_data(): void
    {
        [, $organization, $store, $app, $connection] = $this->installedContext();
        $service = app(ShopifyIncrementalDataService::class);

        $service->handle($this->event($organization, $store, $app, $connection, 'products/update', [
            'id' => 1001, 'title' => 'Webhook Bike', 'handle' => 'webhook-bike', 'status' => 'active',
            'vendor' => 'Deco', 'product_type' => 'Bike', 'body_html' => '<p>Bike</p>',
            'variants' => [['id' => 2001, 'title' => 'Black', 'sku' => 'BIKE-BLK', 'price' => '999.00', 'inventory_item_id' => 3001]],
        ]));
        $product = Product::query()->where('shopify_product_id', '1001')->sole();
        $this->assertSame('Webhook Bike', $product->title);

        $service->handle($this->event($organization, $store, $app, $connection, 'orders/create', [
            'id' => 4001, 'name' => '#1001', 'email' => 'buyer@example.com', 'financial_status' => 'paid',
            'fulfillment_status' => null, 'currency' => 'USD', 'total_price' => '999.00', 'subtotal_price' => '999.00',
            'total_tax' => '0.00', 'processed_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
            'line_items' => [['id' => 5001, 'title' => 'Webhook Bike', 'quantity' => 1, 'price' => '999.00', 'product_id' => 1001, 'variant_id' => 2001]],
        ]));
        $this->assertSame('paid', Order::query()->where('shopify_order_id', '4001')->sole()->financial_status);

        $service->handle($this->event($organization, $store, $app, $connection, 'customers/update', [
            'id' => 6001, 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.com',
            'state' => 'enabled', 'verified_email' => true, 'orders_count' => 1, 'total_spent' => '999.00',
            'currency' => 'USD', 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]));
        $this->assertSame('buyer@example.com', Customer::query()->where('shopify_customer_id', '6001')->sole()->email);

        $inventoryItem = InventoryItem::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_inventory_item_id' => '3001', 'sku' => 'BIKE-BLK', 'tracked' => true, 'synced_at' => now()]);
        $location = Location::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_location_id' => '7001', 'name' => 'Main', 'active' => true, 'synced_at' => now()]);
        $service->handle($this->event($organization, $store, $app, $connection, 'inventory_levels/update', [
            'inventory_item_id' => 3001, 'location_id' => 7001, 'available' => 18, 'updated_at' => now()->toIso8601String(),
        ]));
        $this->assertSame(18, InventoryLevel::query()->where('inventory_item_id', $inventoryItem->id)->where('location_id', $location->id)->sole()->available);
    }

    public function test_webhook_subscriptions_are_registered_idempotently(): void
    {
        [, , $store, , , $installation] = $this->installedContext();
        config(['shopify.app_url' => 'https://testadmin.example.com']);
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;
            $query = (string) $request['query'];

            if (str_contains($query, 'RegisteredWebhookSubscriptions')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['nodes' => []]]]);
            }

            $topic = (string) data_get($request->data(), 'variables.topic');

            return Http::response(['data' => ['webhookSubscriptionCreate' => [
                'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/'.random_int(1, 999999), 'topic' => $topic, 'uri' => data_get($request->data(), 'variables.webhookSubscription.uri')],
                'userErrors' => [],
            ]]]);
        });

        $result = app(ShopifyWebhookSubscriptionService::class)->reconcile($installation);

        $this->assertSame(11, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(12, $requests);
        $this->assertSame('https://testadmin.example.com/shopify/webhooks/shopify-commerce-hub', $result['endpoint']);
        $this->assertSame($store->id, $installation->store_id);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function adminContext(): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Deco', 'code' => 'deco']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'US', 'shopify_domain' => 'us.myshopify.com', 'status' => 'active']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'organization-admin')->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @return array{0: User, 1: Organization, 2: Store, 3: App, 4: ShopifyConnection, 5: AppInstallation} */
    private function installedContext(): array
    {
        [$user, $organization, $store] = $this->adminContext();
        $app = App::query()->create(['name' => 'Commerce Hub', 'handle' => 'shopify-commerce-hub', 'client_id' => 'client', 'client_secret_encrypted' => 'secret', 'distribution' => 'custom', 'status' => 'active']);
        $connection = ShopifyConnection::query()->create(['store_id' => $store->id, 'shop_domain' => $store->shopify_domain, 'access_token_encrypted' => 'token', 'token_type' => 'offline', 'scopes' => ['read_products', 'read_orders', 'read_customers', 'read_inventory', 'read_locations'], 'api_version' => '2026-07', 'status' => 'connected']);
        $installation = AppInstallation::query()->create(['app_id' => $app->id, 'store_id' => $store->id, 'shopify_connection_id' => $connection->id, 'installed_by' => $user->id, 'status' => 'active', 'granted_scopes' => $connection->scopes, 'installed_at' => now()]);

        return [$user, $organization, $store, $app, $connection, $installation];
    }

    /** @param array<string, mixed> $payload */
    private function event(Organization $organization, Store $store, App $app, ShopifyConnection $connection, string $topic, array $payload): WebhookEvent
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return WebhookEvent::query()->create(['webhook_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_connection_id' => $connection->id, 'app_id' => $app->id, 'topic' => $topic, 'api_version' => '2026-07', 'headers' => ['topic' => $topic], 'payload' => ['storage' => 'encrypted'], 'payload_encrypted' => $raw, 'payload_sha256' => hash('sha256', $raw), 'status' => 'processing', 'received_at' => now()]);
    }
}
