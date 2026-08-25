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

class ShopifyAppLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.app_handle', 'deco-marketing');
    }

    public function test_guest_is_sent_to_existing_decoadmin_login_before_shopify_launch(): void
    {
        $this->get(route('shopify.app.launch', ['shop' => 'macfox-us.myshopify.com']))
            ->assertRedirect(route('login'));
    }

    public function test_assigned_user_enters_current_store_marketing_home_with_all_modules_enabled(): void
    {
        [$user, $organization] = $this->userWithRole('viewer');
        $store = $this->store($organization);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->installation($store, $user);

        $this->actingAs($user)
            ->get(route('shopify.app.launch', ['shop' => $store->shopify_domain]))
            ->assertRedirect(route('stores.marketing.home', $store))
            ->assertSessionHas('current_organization_id', $organization->id)
            ->assertSessionHas('current_store_id', $store->id);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('stores.marketing.home', $store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Home')
                ->has('modules', 6)
                ->where('modules.0.enabled', true)
                ->where('store.id', $store->id));
    }

    public function test_unassigned_user_cannot_enter_store_from_shopify_launch(): void
    {
        [$user, $organization] = $this->userWithRole('viewer');
        $store = $this->store($organization);
        $installer = User::factory()->create();
        $this->installation($store, $installer);

        $this->actingAs($user)
            ->get(route('shopify.app.launch', ['shop' => $store->shopify_domain]))
            ->assertForbidden();
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

    private function store(Organization $organization): Store
    {
        return $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
    }

    private function installation(Store $store, User $user): AppInstallation
    {
        $app = App::query()->create([
            'name' => 'Deco Marketing',
            'handle' => 'deco-marketing',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
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
            'settings' => ['modules' => array_fill_keys(array_keys(config('shopify.marketing_modules')), true)],
            'installed_at' => now(),
        ]);
    }
}
