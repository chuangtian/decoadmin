<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\ScheduledShopifySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduledShopifySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_four_daily_reconciliation_jobs_once(): void
    {
        Queue::fake();
        $installation = $this->installation([
            'read_products',
            'read_orders',
            'read_customers',
            'read_inventory',
            'read_locations',
        ]);

        $first = app(ScheduledShopifySyncService::class)->dispatch();
        $second = app(ScheduledShopifySyncService::class)->dispatch();

        $this->assertSame(['stores' => 1, 'created' => 4, 'duplicate' => 0, 'missing_scope' => 0], $first);
        $this->assertSame(['stores' => 1, 'created' => 0, 'duplicate' => 4, 'missing_scope' => 0], $second);
        $this->assertEqualsCanonicalizing(
            ['products', 'orders', 'customers', 'inventory'],
            SyncJob::query()->pluck('type')->all(),
        );
        $this->assertTrue(SyncJob::query()->get()->every(
            fn (SyncJob $job): bool => $job->payload['source'] === 'scheduled'
                && $job->payload['requested_by'] === null
                && $job->app_installation_id === $installation->id,
        ));
        Queue::assertPushed(ProcessSyncJob::class, 4);
    }

    public function test_it_skips_types_without_required_shopify_scopes(): void
    {
        Queue::fake();
        $this->installation(['read_customers']);

        $result = app(ScheduledShopifySyncService::class)->dispatch();

        $this->assertSame(['stores' => 1, 'created' => 1, 'duplicate' => 0, 'missing_scope' => 3], $result);
        $this->assertSame('customers', SyncJob::query()->sole()->type);
        Queue::assertPushed(ProcessSyncJob::class, 1);
    }

    private function installation(array $scopes): AppInstallation
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $app = App::query()->create([
            'name' => 'Shopify Commerce Hub',
            'handle' => 'shopify-commerce-hub',
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'test-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-token',
            'token_type' => 'offline',
            'scopes' => $scopes,
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => $scopes,
            'installed_at' => now(),
        ]);
    }
}
