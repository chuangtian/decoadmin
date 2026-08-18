<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Jobs\ProcessSyncJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Customer;
use App\Models\Organization;
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

class ShopifyCustomerSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_create_customer_sync_job(): void
    {
        Queue::fake();
        [$admin, $organization, $store, $installation] = $this->context('organization-admin');

        $response = $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'customers',
            ]);

        $syncJob = SyncJob::query()->sole();
        $response->assertRedirect(route('sync.show', $syncJob));
        $this->assertSame('queued', $syncJob->status);
        $this->assertFalse($syncJob->payload['framework_only']);
        Queue::assertPushed(ProcessSyncJob::class);
    }

    public function test_developer_cannot_create_customer_sync_job(): void
    {
        Queue::fake();
        [$developer, $organization, $store, $installation] = $this->context('developer');

        $this->actingAs($developer)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('sync.store'), [
                'store_id' => $store->id,
                'app_installation_id' => $installation->id,
                'type' => 'customers',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sync_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_customer_sync_parses_graphql_and_saves_customer(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->customersPayload([
            $this->customerNode(101, 'Ada', 'Lovelace', 'ada@example.com', '+14155550101', 'ENABLED', true, '3', '245.75'),
            $this->customerNode(102, null, null, null, null, 'DISABLED', false, '0', '0.00'),
        ]))]);

        $syncJob = $this->syncJob($organization, $store, $installation);
        $result = app(SyncProcessor::class)->process($syncJob->id);
        $syncJob->refresh();

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->recordsCount);
        $this->assertSame('completed', $syncJob->status);
        $this->assertSame(2, $syncJob->processed_items);
        $this->assertSame(['USD'], $syncJob->result['metadata']['currencies']);
        $this->assertGreaterThanOrEqual(0, $syncJob->result['metadata']['duration_ms']);
        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_customer_id' => 101,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+14155550101',
            'state' => 'enabled',
            'verified_email' => true,
            'orders_count' => 3,
            'total_spent' => '245.7500',
        ]);
        $customer = Customer::query()->where('shopify_customer_id', 101)->sole();
        $this->assertNotNull($customer->created_at_shopify);
        $this->assertNotNull($customer->updated_at_shopify);
        $this->assertNotNull($customer->synced_at);
        $this->assertDatabaseHas('customers', [
            'shopify_customer_id' => 102,
            'first_name' => null,
            'email' => null,
            'phone' => null,
        ]);
    }

    public function test_customer_connection_is_cursor_paginated(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->customersPayload([
                $this->customerNode(101, 'Ada'),
            ], true, 'customer-cursor'))
            ->push($this->customersPayload([
                $this->customerNode(102, 'Grace'),
            ]));

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->recordsCount);
        $this->assertSame(2, $result->metadata['customer_pages']);
        $this->assertDatabaseCount('customers', 2);

        $requests = [];
        Http::assertSent(function (Request $request) use (&$requests): bool {
            $requests[] = $request->data()['variables'];

            return true;
        });
        $this->assertNull($requests[0]['after']);
        $this->assertSame('customer-cursor', $requests[1]['after']);
    }

    public function test_repeated_sync_updates_customer_without_duplicates(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fakeSequence()
            ->push($this->customersPayload([
                $this->customerNode(101, 'Old', 'Name', 'old@example.com', null, 'DISABLED', false, '1', '10.00'),
            ]))
            ->push($this->customersPayload([
                $this->customerNode(101, 'New', 'Name', 'new@example.com', '+14155550101', 'ENABLED', true, '2', '50.50'),
            ]));
        app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $result = app(SyncProcessor::class)->process($this->syncJob($organization, $store, $installation)->id);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'shopify_customer_id' => 101,
            'first_name' => 'New',
            'email' => 'new@example.com',
            'phone' => '+14155550101',
            'state' => 'enabled',
            'verified_email' => true,
            'orders_count' => 2,
            'total_spent' => '50.5000',
        ]);
        $this->assertSame(0, $result->metadata['customers_created']);
        $this->assertSame(1, $result->metadata['customers_updated']);
    }

    public function test_same_shopify_id_remains_isolated_by_store_and_organization(): void
    {
        [, $organizationA, $storeA, $installationA] = $this->context('store-admin');
        [, $organizationB, $storeB, $installationB] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->customersPayload([
            $this->customerNode(101, 'Shared'),
        ]))]);

        app(SyncProcessor::class)->process($this->syncJob($organizationA, $storeA, $installationA)->id);
        app(SyncProcessor::class)->process($this->syncJob($organizationB, $storeB, $installationB)->id);

        $this->assertDatabaseCount('customers', 2);
        $this->assertSame(1, Customer::query()->forStore($storeA)->count());
        $this->assertSame(1, Customer::query()->forStore($storeB)->count());
        $this->assertSame(1, Customer::query()->forOrganization($organizationA)->count());
        $this->assertSame(1, Customer::query()->forOrganization($organizationB)->count());
    }

    public function test_updated_at_filter_is_reserved_in_graphql_variables(): void
    {
        [, $organization, $store, $installation] = $this->context('store-admin');
        Http::fake(['*' => Http::response($this->customersPayload([]))]);
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

    public function test_shopify_api_failure_marks_sync_job_failed_without_saving_customers(): void
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
        $this->assertDatabaseCount('customers', 0);
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
            'handle' => 'customer-sync-'.Str::lower(Str::random(10)),
            'client_id' => Str::uuid()->toString(),
            'client_secret_encrypted' => 'customer-sync-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'customer-sync-token',
            'token_type' => 'offline',
            'scopes' => ['read_products', 'read_inventory', 'read_orders', 'read_customers'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products', 'read_inventory', 'read_orders', 'read_customers'],
            'installed_at' => now(),
        ]);

        return [$user, $organization, $store, $installation];
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
            'type' => 'customers',
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
     * @param  list<array<string, mixed>>  $customers
     * @return array<string, mixed>
     */
    private function customersPayload(array $customers, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'customers' => [
                    'nodes' => $customers,
                    'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function customerNode(
        int $id,
        ?string $firstName,
        ?string $lastName = 'Customer',
        ?string $email = 'customer@example.com',
        ?string $phone = null,
        string $state = 'ENABLED',
        bool $verifiedEmail = true,
        string $ordersCount = '1',
        string $totalSpent = '99.00',
    ): array {
        return [
            'id' => "gid://shopify/Customer/{$id}",
            'firstName' => $firstName,
            'lastName' => $lastName,
            'defaultEmailAddress' => $email ? ['emailAddress' => $email] : null,
            'defaultPhoneNumber' => $phone ? ['phoneNumber' => $phone] : null,
            'state' => $state,
            'verifiedEmail' => $verifiedEmail,
            'numberOfOrders' => $ordersCount,
            'amountSpent' => ['amount' => $totalSpent, 'currencyCode' => 'USD'],
            'createdAt' => '2026-08-16T10:00:00Z',
            'updatedAt' => '2026-08-17T10:00:00Z',
        ];
    }
}
