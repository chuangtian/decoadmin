<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'organization.view',
        'organization.update',
        'store.view',
        'store.create',
        'store.update',
        'store.delete',
        'store.connect',
        'store.disconnect',
        'shopify.view',
        'shopify.authorize',
        'shopify.sync',
        'orders.view',
        'orders.export',
        'orders.update',
        'orders.cancel',
        'orders.refund',
        'products.view',
        'products.create',
        'products.update',
        'products.delete',
        'products.sync',
        'customers.view',
        'customers.export',
        'customers.update',
        'inventory.view',
        'inventory.update',
        'inventory.sync',
        'apps.view',
        'apps.create',
        'apps.update',
        'apps.install',
        'apps.configure',
        'apps.uninstall',
        'webhooks.view',
        'webhooks.retry',
        'sync.view',
        'sync.run',
        'sync.retry',
        'sync.cancel',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'users.assign_role',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'roles.assign',
        'audit.view',
        'system.settings.view',
        'system.settings.update',
        'system.health.view',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug) {
            [$group, $action] = explode('.', $slug, 2);
            $permission = Permission::withTrashed()->firstOrNew(['slug' => $slug]);
            $permission->fill([
                'name' => Str::headline($group).' · '.Str::headline($action),
                'group' => $group,
                'description' => sprintf('Allows %s access for %s.', $action, $group),
            ]);
            $permission->deleted_at = null;
            $permission->save();
        }
    }
}
