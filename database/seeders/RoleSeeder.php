<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string, permissions: list<string>}> */
    private array $roles = [
        'super-admin' => [
            'name' => 'Super Admin',
            'description' => 'Unrestricted platform administrator.',
            'permissions' => ['*'],
        ],
        'organization-admin' => [
            'name' => 'Organization Admin',
            'description' => 'Manages users, roles, stores, and organization data.',
            'permissions' => ['organization.*', 'store.*', 'users.*', 'roles.*', '*.view'],
        ],
        'store-admin' => [
            'name' => 'Store Admin',
            'description' => 'Manages an assigned store and its commerce operations.',
            'permissions' => ['store.view', 'store.update', 'orders.*', 'products.*', 'customers.*', 'inventory.*', 'sync.*'],
        ],
        'developer' => [
            'name' => 'Developer',
            'description' => 'Manages integrations, applications, webhooks, sync, and logs.',
            'permissions' => ['store.view', 'apps.*', 'shopify.*', 'webhooks.*', 'sync.*', 'audit.view', 'system.health.view'],
        ],
        'operator' => [
            'name' => 'Operator',
            'description' => 'Runs day-to-day store operations.',
            'permissions' => ['orders.view', 'orders.update', 'products.view', 'products.update', 'customers.view', 'inventory.view', 'sync.view'],
        ],
        'marketing' => [
            'name' => 'Marketing',
            'description' => 'Reads product, customer, and audit information.',
            'permissions' => ['products.view', 'customers.view', 'audit.view'],
        ],
        'customer-service' => [
            'name' => 'Customer Service',
            'description' => 'Reads order and customer information.',
            'permissions' => ['orders.view', 'customers.view'],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to assigned resources.',
            'permissions' => ['*.view'],
        ],
    ];

    public function run(): void
    {
        $permissions = Permission::query()->get();

        Organization::query()->each(function (Organization $organization) use ($permissions): void {
            foreach ($this->roles as $slug => $definition) {
                $role = Role::withTrashed()->firstOrNew([
                    'organization_id' => $organization->getKey(),
                    'slug' => $slug,
                ]);
                $role->fill([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]);
                $role->deleted_at = null;
                $role->save();

                $permissionIds = $permissions
                    ->filter(fn (Permission $permission) => $this->matches($permission->slug, $definition['permissions']))
                    ->modelKeys();
                $role->permissions()->sync($permissionIds);
            }

            User::query()->get()
                ->filter(fn (User $user) => (bool) data_get($user->metadata, 'is_super_admin')
                    && $user->organizations()->whereKey($organization->getKey())->exists())
                ->each(fn (User $user) => $this->assignSuperAdmin($organization, $user));
        });
    }

    /** @param list<string> $patterns */
    private function matches(string $permission, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || Str::is($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function assignSuperAdmin(Organization $organization, User $user): void
    {
        $role = $organization->roles()->where('slug', 'super-admin')->firstOrFail();
        $assignment = DB::table('user_roles')
            ->where('organization_id', $organization->getKey())
            ->whereNull('store_id')
            ->where('user_id', $user->getKey())
            ->where('role_id', $role->getKey())
            ->first();
        $values = [
            'expires_at' => null,
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($assignment) {
            DB::table('user_roles')->where('id', $assignment->id)->update($values);
        } else {
            DB::table('user_roles')->insert([
                ...$values,
                'organization_id' => $organization->getKey(),
                'store_id' => null,
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'granted_by' => $user->getKey(),
                'created_at' => now(),
            ]);
        }
    }
}
