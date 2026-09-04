<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_roles_and_unique_permissions_are_seeded_idempotently(): void
    {
        $this->seed(RbacSeeder::class);
        $counts = $this->counts();

        $this->seed(RbacSeeder::class);

        $this->assertSame($counts, $this->counts());
        $this->assertEqualsCanonicalizing(PermissionSeeder::PERMISSIONS, Permission::query()->pluck('slug')->all());
        $this->assertSame(Permission::query()->count(), Permission::query()->distinct()->count('slug'));
        $this->assertEqualsCanonicalizing([
            'super-admin',
            'organization-admin',
            'store-admin',
            'developer',
            'operator',
            'marketing',
            'customer-service',
            'viewer',
        ], Role::query()->pluck('slug')->all());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'users' => User::query()->count(),
            'organizations' => Organization::query()->count(),
            'permissions' => Permission::query()->count(),
            'roles' => Role::query()->count(),
            'organization_users' => DB::table('organization_users')->count(),
            'role_permissions' => DB::table('role_permissions')->count(),
            'user_roles' => DB::table('user_roles')->count(),
        ];
    }
}
