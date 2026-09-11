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
        'student_discount' => '学生优惠', 'instagram_feed' => 'Instagram Feed',
        'personalization' => '个性化推荐',
        'discounts' => '折扣管理',
        'affiliate' => '推荐与联盟',
        'design_requests' => '设计需求',
        'technical_requests' => '技术需求',
        'request_approvals' => '需求审批',
        'expense_requests' => '费用申请',
        'expense_claims' => '发票报销',
        'marketing' => '营销自动化',
    ];

    /** @var array<string, string> */
    private array $actions = [
        'view' => '查看', 'view_all' => '查看全部', 'update' => '更新', 'create' => '创建', 'delete' => '删除',
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
        'dashboard.view' => '查看概览', 'programs.view' => '查看推广计划', 'programs.manage' => '管理推广计划',
        'promoters.view' => '查看推广者', 'promoters.manage' => '管理推广者',
        'conversions.view' => '查看推荐订单', 'conversions.override' => '纠正订单归因',
        'commissions.view' => '查看佣金', 'commissions.adjust' => '调整佣金', 'commissions.approve' => '审批佣金',
        'payouts.view' => '查看结算', 'payouts.create' => '创建结算', 'payouts.confirm' => '确认付款',
        'fraud.view' => '查看风险', 'fraud.review' => '审核风险', 'settings.manage' => '管理设置',
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
        // Instagram Feed 只剩后台只读页要判权；连接、同步、编排、配置都在
        // Shopify App 内嵌页完成，那条链路按 shop domain 判定店铺、不做按人 RBAC，
        // 所以 connect / sync / gallery.manage / publish 这四个权限已经退役
        // （见 2026_09_10_000200 迁移，它把这四条软删除）。
        'instagram_feed.view',
        'personalization.view',
        'personalization.manage',
        'personalization.analytics.read',
        'personalization.smart_cart.manage',
        'discounts.view',
        'discounts.manage',
        'affiliate.dashboard.view',
        'affiliate.programs.view',
        'affiliate.programs.manage',
        'affiliate.promoters.view',
        'affiliate.promoters.manage',
        'affiliate.conversions.view',
        'affiliate.conversions.override',
        'affiliate.commissions.view',
        'affiliate.commissions.adjust',
        'affiliate.commissions.approve',
        'affiliate.payouts.view',
        'affiliate.payouts.create',
        'affiliate.payouts.confirm',
        'affiliate.fraud.view',
        'affiliate.fraud.review',
        'affiliate.settings.manage',
        'affiliate.reports.export',
        'design_requests.view',
        'design_requests.create',
        'design_requests.view_all',
        'design_requests.manage',
        'technical_requests.view',
        'technical_requests.create',
        'technical_requests.view_all',
        'technical_requests.manage',
        'request_approvals.view',
        'request_approvals.manage',
        'expense_requests.view',
        'expense_requests.create',
        'expense_requests.view_all',
        'expense_requests.manage',
        'expense_claims.view',
        'expense_claims.create',
        'expense_claims.view_all',
        'expense_claims.manage',
        'marketing.view',
        'marketing.manage',
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
