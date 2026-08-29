<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersonalizationPermissionProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_system_roles_receive_personalization_permissions_idempotently(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Existing Organization',
            'code' => 'existing-organization',
        ]);
        DB::table('permissions')->insert([
            'name' => '应用 · 查看',
            'slug' => 'apps.view',
            'group' => 'apps',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing', 'developer'] as $slug) {
            Role::query()->create([
                'organization_id' => $organization->id,
                'name' => $slug,
                'slug' => $slug,
                'is_system' => true,
            ]);
        }

        $migration = require database_path('migrations/2026_08_29_013000_provision_personalization_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('permissions', 5);
        $this->assertDatabaseCount('role_permissions', 18);

        foreach (['super-admin', 'organization-admin', 'store-admin', 'operator', 'marketing'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();

            $this->assertTrue($role->hasPermission('personalization.view'));
            $this->assertTrue($role->hasPermission('personalization.manage'));
            $this->assertTrue($role->hasPermission('personalization.analytics.read'));
            $this->assertSame(
                in_array($slug, ['super-admin', 'organization-admin', 'store-admin'], true),
                $role->hasPermission('personalization.smart_cart.manage'),
            );
        }

        $developer = Role::query()->where('slug', 'developer')->firstOrFail();
        $this->assertFalse($developer->permissions()->where('group', 'personalization')->exists());
    }
}
