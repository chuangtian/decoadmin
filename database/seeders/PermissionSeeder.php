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
        'audit' => '审计日志', 'alerts' => '异常告警', 'finance' => '公司财务', 'reports' => '报表', 'system' => '系统',
        'codex' => 'Codex 插件',
        'student_discount' => '学生优惠', 'instagram_feed' => 'Instagram 内容',
        'personalization' => '个性化推荐',
    ];

    /** @var array<string, string> */
    private array $actions = [
        'view' => '查看', 'update' => '更新', 'create' => '创建', 'delete' => '删除',
        'connect' => '连接', 'disconnect' => '断开连接', 'authorize' => '授权', 'sync' => '同步',
        'export' => '导出', 'refresh' => '刷新', 'cancel' => '取消', 'refund' => '退款', 'install' => '安装',
        'configure' => '配置', 'uninstall' => '卸载', 'retry' => '重试', 'run' => '执行',
        'assign_role' => '分配角色', 'assign' => '分配', 'settings.view' => '查看设置',
        'settings.update' => '更新设置', 'health.view' => '查看运行状态', 'manage' => '管理',
        'claim.read' => '查看申请', 'view_evidence' => '查看证件', 'approve' => '审核通过',
        'claim.delete' => '删除申请',
        'reject' => '拒绝申请', 'campaign.manage' => '管理活动', 'email_template.manage' => '管理邮件内容',
        'analytics.read' => '查看分析', 'audit.read' => '查看审计',
        'gallery.manage' => '管理展示组', 'publish' => '发布前台',
        'smart_cart.manage' => '管理 Smart Cart',
        'tokens.view' => '查看授权', 'tokens.manage' => '管理授权',
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
        'alerts.view',
        'alerts.manage',
        'finance.view',
        'finance.manage',
        'reports.view',
        'reports.export',
        'reports.refresh',
        'codex.tokens.view',
        'codex.tokens.manage',
        'reports.manage',
        'system.settings.view',
        'system.settings.update',
        'system.health.view',
        'student_discount.claim.read',
        'student_discount.claim.delete',
        'student_discount.view_evidence',
        'student_discount.approve',
        'student_discount.reject',
        'student_discount.campaign.manage',
        'student_discount.email_template.manage',
        'student_discount.analytics.read',
        'student_discount.audit.read',
        'instagram_feed.view',
        'instagram_feed.connect',
        'instagram_feed.sync',
        'instagram_feed.gallery.manage',
        'instagram_feed.publish',
        'personalization.view',
        'personalization.manage',
        'personalization.analytics.read',
        'personalization.smart_cart.manage',
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
