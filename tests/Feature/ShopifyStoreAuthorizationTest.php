<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopifyStoreAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_sees_stores_they_are_allowed_to_access(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $authorized = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $unauthorized = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $authorized->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('stores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Index')
                ->has('stores.data', 1)
                ->where('stores.data.0.id', $authorized->id)
                ->where('stores.data.0.name', 'Macfox US')
                ->where('stores.data.0.platform', 'Shopify')
                ->where('stores.data.0.connection_status', 'pending')
                ->where('stores.data.0.installed_apps_count', 0)
                ->where('stores.data.0.last_sync', null));

        $this->assertNotSame($unauthorized->id, session('current_store_id'));
    }

    public function test_user_without_store_create_permission_cannot_start_connection(): void
    {
        [$user, $organization] = $this->userWithRole('viewer');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('stores.store'), [
                'name' => 'Blocked Store',
                'shop_domain' => 'blocked-store.myshopify.com',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('stores', ['shopify_domain' => 'blocked-store.myshopify.com']);
        $this->assertDatabaseCount('oauth_states', 0);
    }

    /** @return array{0: User, 1: Organization} */
    private function userWithRole(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Macfox',
            'code' => 'macfox',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        return [$user, $organization];
    }

    private function store(Organization $organization, string $name, string $domain): Store
    {
        return $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
        ]);
    }
}
