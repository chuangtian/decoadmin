<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SidebarMenuAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_only_receives_permissions_granted_to_the_current_user(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $viewer = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $user->roles()->attach($viewer, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $organization->id,
                'current_store_id' => $store->id,
            ])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions', fn (Collection $permissions) => $permissions->contains('store.view')
                    && $permissions->contains('organization.view')
                    && ! $permissions->contains('store.create')
                    && ! $permissions->contains('apps.install')));
    }
}
