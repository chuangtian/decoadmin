<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyAppCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_list_requires_permission_and_supports_search_and_status_filter(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $this->app($organization, 'Orders Connector', 'orders-connector', 'active');
        $matching = $this->app($organization, 'Product Bridge', 'product-bridge', 'inactive');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('apps.index', ['search' => 'Product', 'status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Index')
                ->has('apps.data', 1)
                ->where('apps.data.0.id', $matching->id)
                ->where('apps.data.0.slug', 'product-bridge')
                ->where('apps.data.0.type', 'custom')
                ->where('filters.search', 'Product')
                ->where('filters.status', 'inactive'));
    }

    public function test_app_detail_is_available_to_authorized_organization_admin_without_exposing_secrets(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $app = $this->app($organization, 'Shopify Commerce Hub', 'shopify-commerce-hub');
        $app->forceFill([
            'client_id' => 'private-client-id',
            'client_secret_encrypted' => 'private-client-secret',
        ])->save();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('apps.show', $app))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Show')
                ->where('app.data.id', $app->id)
                ->where('app.data.name', 'Shopify Commerce Hub')
                ->missing('app.data.client_id')
                ->missing('app.data.client_secret_encrypted'));
    }

    public function test_installation_list_only_contains_stores_the_user_can_access(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $authorized = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $unauthorized = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $authorized->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $app = $this->app($organization, 'Store Operations', 'store-operations');
        $this->installation($app, $authorized, $user);
        $this->installation($app, $unauthorized, $user);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $authorized->id,
            ])
            ->get(route('apps.show', $app))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.store.id', $authorized->id)
                ->where('app.data.installations_count', 1));
    }

    public function test_platform_app_is_visible_through_an_authorized_store_installation(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $platformApp = App::query()->create([
            'organization_id' => null,
            'name' => 'Shopify Commerce Hub',
            'handle' => 'shopify-commerce-hub',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $this->installation($platformApp, $store, $user);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('apps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 1)
                ->where('apps.data.0.id', $platformApp->id)
                ->where('apps.data.0.installations_count', 1));

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('apps.show', $platformApp))
            ->assertOk();
    }

    public function test_precreated_store_shows_configured_app_as_not_installed(): void
    {
        config()->set('shopify.app_handle', 'shopify-commerce-hub');
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        App::query()->create([
            'organization_id' => null,
            'name' => 'Deco Marketing',
            'handle' => 'shopify-commerce-hub',
            'distribution' => 'custom',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('stores.show', ['store' => $store, 'tab' => 'apps']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Show')
                ->has('storeApps', 1)
                ->where('storeApps.0.name', 'Deco Marketing')
                ->where('storeApps.0.status', 'uninstalled'));
    }

    public function test_app_from_another_organization_is_forbidden(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $otherOrganization = Organization::query()->create(['name' => 'Asiwo', 'code' => 'asiwo']);
        $otherApp = $this->app($otherOrganization, 'Asiwo App', 'asiwo-app');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('apps.show', $otherApp))
            ->assertForbidden();
    }

    public function test_viewer_cannot_access_app_registry_or_app_detail(): void
    {
        [$viewer, $organization] = $this->userWithRole('viewer');
        $app = $this->app($organization, 'Hidden App', 'hidden-app');

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('apps.index'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('apps.show', $app))
            ->assertForbidden();
    }

    public function test_app_and_store_expose_the_same_installation_relationship(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $app = $this->app($organization, 'Relationship App', 'relationship-app');
        $installation = $this->installation($app, $store, $user);

        $this->assertTrue($app->installations()->whereKey($installation->id)->exists());
        $this->assertTrue($store->appInstallations()->whereKey($installation->id)->exists());
        $this->assertTrue($installation->app->is($app));
        $this->assertTrue($installation->store->is($store));
    }

    /** @return array{0: User, 1: Organization} */
    private function userWithRole(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        return [$user, $organization];
    }

    private function app(Organization $organization, string $name, string $handle, string $status = 'active'): App
    {
        return App::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'handle' => $handle,
            'distribution' => 'custom',
            'status' => $status,
            'description' => "{$name} description",
        ]);
    }

    private function store(Organization $organization, string $name, string $domain): Store
    {
        return $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
        ]);
    }

    private function installation(App $app, Store $store, User $user): AppInstallation
    {
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'installed_at' => now(),
        ]);
    }
}
