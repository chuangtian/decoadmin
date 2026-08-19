<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StorefrontEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_insights_are_store_scoped_and_include_channel_pos_and_funnel_data(): void
    {
        [$user, $organization, $store] = $this->context();
        $other = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-business-insights.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $order = $this->order($organization, $store, 'visible', 75, [
            'sales_channel' => 'pos',
            'sales_channel_name' => 'Point of Sale',
            'pos_location_id' => 501,
            'pos_location_name' => 'Downtown',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'shopify_line_item_id' => 991,
            'title' => 'POS item',
            'quantity' => 2,
            'current_quantity' => 2,
            'price' => 40,
            'attributed_sales' => 75,
            'shopify_staff_id' => 601,
            'staff_name' => 'Alex Chen',
        ]);
        $this->order($organization, $other, 'hidden', 999, ['sales_channel' => 'web']);
        $this->event($organization, $store, 'evt-page', 'page_viewed', 'session-one');
        $this->event($organization, $store, 'evt-product', 'product_viewed', 'session-one');
        $this->event($organization, $store, 'evt-cart', 'product_added_to_cart', 'session-one');
        $this->event($organization, $store, 'evt-checkout', 'checkout_started', 'session-one');
        $this->event($organization, $store, 'evt-search', 'search_submitted', 'session-one', 'helmet');
        $this->event($organization, $store, 'evt-complete', 'checkout_completed', 'session-one');
        $this->event($organization, $other, 'evt-hidden', 'page_viewed', 'session-hidden');

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('business.insights'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Business/Insights')
                ->where('store.id', $store->id)
                ->where('insights.channels.0.key', 'pos')
                ->where('insights.channels.0.orders', 1)
                ->where('insights.channels.0.total_sales', 75)
                ->where('insights.pos_locations.0.name', 'Downtown')
                ->where('insights.pos_staff.0.name', 'Alex Chen')
                ->where('insights.pos_staff.0.attributed_sales', 75)
                ->where('insights.traffic.sessions', 1)
                ->where('insights.traffic.converted_sessions', 1)
                ->where('insights.search.searches', 1)
                ->where('insights.search.converted_sessions', 1)
                ->where('insights.funnel.4.key', 'checkout_completed')
                ->where('insights.funnel.4.sessions', 1)
                ->where('insights.integration.order_sync_ready', true)
                ->where('insights.privacy.raw_ip_collected', false));
    }

    public function test_pixel_endpoint_deduplicates_events_hashes_identifiers_and_redacts_search_pii(): void
    {
        [, , $store] = $this->context();
        $payload = [
            'event_id' => 'shopify-event-1001',
            'event_name' => 'search_submitted',
            'client_id' => 'raw-client-id',
            'session_id' => 'raw-session-id',
            'occurred_at' => now()->toIso8601String(),
            'path' => '/search?email=secret@example.com',
            'search_query' => 'contact secret@example.com or +1 (555) 123-4567',
        ];

        $this->call('POST', route('shopify.pixels.receive', ['store' => $store->analytics_ingest_key]), [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        ], json_encode($payload, JSON_THROW_ON_ERROR))->assertAccepted()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->call('POST', route('shopify.pixels.receive', ['store' => $store->analytics_ingest_key]), [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        ], json_encode($payload, JSON_THROW_ON_ERROR))->assertOk()->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('storefront_events', 1);
        $event = StorefrontEvent::query()->sole();
        $this->assertNotSame('raw-client-id', $event->client_id_hash);
        $this->assertNotSame('raw-session-id', $event->session_id_hash);
        $this->assertSame('/search', $event->path);
        $this->assertStringNotContainsString('secret@example.com', (string) $event->search_query);
        $this->assertStringNotContainsString('555', (string) $event->search_query);
        $this->assertFalse(Schema::hasColumn('storefront_events', 'ip'));
        $this->assertFalse(Schema::hasColumn('storefront_events', 'payload'));
    }

    public function test_read_reports_uses_shopifyql_for_native_channel_pos_session_search_and_funnel_metrics(): void
    {
        [$user, $organization, $store] = $this->context();
        $store->shopifyConnection()->update(['scopes' => ['read_orders', 'read_locations', 'read_reports']]);
        config()->set('inertia.ssr.enabled', false);
        Http::fake(function (Request $request) {
            $shopifyql = (string) data_get($request->data(), 'variables.query');
            $rows = match (true) {
                str_contains($shopifyql, 'GROUP BY sales_channel') => [[
                    'sales_channel' => 'Online Store', 'net_sales' => '90', 'total_sales' => '100', 'orders' => '2',
                ]],
                str_contains($shopifyql, 'GROUP BY pos_location_id') => [[
                    'pos_location_id' => '501', 'pos_location_name' => 'Downtown', 'net_sales' => '80', 'total_sales' => '90', 'orders' => '1',
                ]],
                str_contains($shopifyql, 'GROUP BY staff_id') => [[
                    'staff_id' => '601', 'staff_member_name' => 'Alex Chen', 'net_sales' => '75', 'total_sales' => '80', 'orders' => '1', 'net_items_sold' => '2',
                ]],
                str_contains($shopifyql, 'FROM sessions') => [[
                    'sessions' => '20', 'pageviews' => '50', 'sessions_with_cart_additions' => '8', 'sessions_that_reached_checkout' => '5', 'sessions_that_completed_checkout' => '4', 'conversion_rate' => '0.2',
                ]],
                str_contains($shopifyql, 'FROM search_conversions') => [[
                    'sessions_with_searches' => '6', 'search_sessions_with_clicks' => '5', 'search_sessions_with_cart_additions' => '3', 'search_sessions_that_completed_checkout' => '2', 'search_conversion_rate' => '0.3333',
                ]],
                str_contains($shopifyql, 'FROM searches') => [[
                    'search_query' => 'contact test@example.com', 'searches' => '7', 'searches__totals' => '7',
                ]],
                default => [],
            };

            return Http::response(['data' => ['shopifyqlQuery' => [
                'tableData' => ['columns' => [], 'rows' => $rows],
                'parseErrors' => [],
            ]]]);
        });

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('business.insights'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('insights.data_source.primary', 'shopifyql')
                ->where('insights.integration.reports_ready', true)
                ->where('insights.channels.0.name', 'Online Store')
                ->where('insights.pos_locations.0.name', 'Downtown')
                ->where('insights.pos_staff.0.name', 'Alex Chen')
                ->where('insights.traffic.sessions', 20)
                ->where('insights.traffic.conversion_rate', 20)
                ->where('insights.search.searches', 7)
                ->where('insights.search.converted_sessions', 2)
                ->where('insights.search.top_queries.0.query', 'contact [email]')
                ->where('insights.funnel.3.key', 'checkout_completed')
                ->where('insights.funnel.3.sessions', 4));

        Http::assertSentCount(6);
    }

    public function test_pixel_endpoint_rejects_unknown_or_stale_events(): void
    {
        [, , $store] = $this->context();
        $payload = [
            'event_id' => 'bad-event',
            'event_name' => 'unknown_event',
            'client_id' => 'client',
            'session_id' => 'session',
            'occurred_at' => now()->subDays(8)->toIso8601String(),
        ];

        $this->call('POST', route('shopify.pixels.receive', ['store' => $store->analytics_ingest_key]), [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();
        $this->assertDatabaseCount('storefront_events', 0);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Business Insights Org',
            'code' => Str::lower(Str::random(12)),
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Business Store',
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'organization-admin')->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_orders', 'read_locations'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        return [$user, $organization, $store];
    }

    /** @param array<string, mixed> $values */
    private function order(Organization $organization, Store $store, string $suffix, float $total, array $values = []): Order
    {
        return Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => (string) fake()->unique()->numberBetween(10000, 999999),
            'order_number' => '#'.$suffix,
            'currency' => 'USD',
            'total_price' => $total,
            'subtotal_price' => $total,
            'net_sales' => $total,
            'discount_total' => 0,
            'refund_total' => 0,
            'shipping_total' => 0,
            'total_tax' => 0,
            'is_test' => false,
            'created_at_shopify' => now(),
            'processed_at' => now(),
            'synced_at' => now(),
            ...$values,
        ]);
    }

    private function event(
        Organization $organization,
        Store $store,
        string $eventId,
        string $eventName,
        string $session,
        ?string $query = null,
    ): StorefrontEvent {
        return StorefrontEvent::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'event_id' => $eventId,
            'event_name' => $eventName,
            'client_id_hash' => hash('sha256', $session),
            'session_id_hash' => hash('sha256', $session),
            'occurred_at' => now(),
            'search_query' => $query,
            'received_at' => now(),
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
}
