<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /** @var array<string, string> */
    private array $groups = [
        'organization' => '组织', 'store' => '店铺', 'shopify' => 'Shopify', 'orders' => '订单',
        'products' => '商品', 'customers' => '客户', 'inventory' => '库存', 'apps' => '应用',
        'webhooks' => 'Webhook', 'sync' => '数据同步', 'users' => '用户', 'roles' => '角色',
        'audit' => '审计日志', 'system' => '系统',
    ];

    /** @var array<string, string> */
    private array $actions = [
        'view' => '查看', 'update' => '更新', 'create' => '创建', 'delete' => '删除',
        'connect' => '连接', 'disconnect' => '断开连接', 'authorize' => '授权', 'sync' => '同步',
        'export' => '导出', 'cancel' => '取消', 'refund' => '退款', 'install' => '安装',
        'configure' => '配置', 'uninstall' => '卸载', 'retry' => '重试', 'run' => '执行',
        'assign_role' => '分配角色', 'assign' => '分配', 'settings.view' => '查看设置',
        'settings.update' => '更新设置', 'health.view' => '查看运行状态',
    ];

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
        'orders.sync',
        'products.view',
        'products.create',
        'products.update',
        'products.delete',
        'products.sync',
        'customers.view',
        'customers.export',
        'customers.update',
        'customers.sync',
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
            $groupName = $this->groups[$group] ?? $group;
            $actionName = $this->actions[$action] ?? $action;
            $permission = Permission::withTrashed()->firstOrNew(['slug' => $slug]);
            $permission->fill([
                'name' => $groupName.' · '.$actionName,
                'group' => $group,
                'description' => "允许在{$groupName}范围内执行{$actionName}操作。",
            ]);
            $permission->deleted_at = null;
            $permission->save();
        }
    }
}
