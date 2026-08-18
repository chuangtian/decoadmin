<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_has_every_permission(): void
    {
        $user = User::factory()->create(['metadata' => ['is_super_admin' => true]]);

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->hasPermission('orders.view'));
        $this->assertTrue($user->hasPermission('permission.that.does.not.exist'));
    }

    public function test_operator_permission_set_is_restricted(): void
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Operations',
            'code' => 'operations',
        ]);
        $user = User::factory()->create();
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);

        $operator = Role::query()
            ->whereBelongsTo($organization)
            ->where('slug', 'operator')
            ->firstOrFail();
        $user->roles()->attach($operator, ['organization_id' => $organization->id]);

        $this->assertTrue($user->hasRole('operator', $organization));
        $this->assertTrue($user->hasPermission('orders.view', $organization));
        $this->assertFalse($user->hasPermission('users.delete', $organization));
        $this->assertFalse($user->hasPermission('system.settings.update', $organization));
    }
}
