export interface MenuItem {
    name: string;
    route?: string;
    icon: string;
    section?: string;
    sectionDivider?: boolean;
    permission?: string;
    children?: MenuItem[];
    comingSoon?: boolean;
}

export const menu: MenuItem[] = [
    {
        name: '工作台',
        icon: 'dashboard',
        section: 'Shopify 运营',
        children: [
            { name: '概览', route: '/dashboard', icon: 'dashboard', permission: 'organization.view' },
        ],
    },
    {
        name: '业务中心',
        icon: 'business',
        children: [
            { name: '订单管理', route: '/orders', icon: 'orders', permission: 'orders.view', comingSoon: true },
            { name: '商品管理', route: '/products', icon: 'products', permission: 'products.view', comingSoon: true },
            { name: '客户管理', route: '/customers', icon: 'customers', permission: 'customers.view', comingSoon: true },
            { name: '库存管理', route: '/inventory', icon: 'inventory', permission: 'inventory.view', comingSoon: true },
        ],
    },
    {
        name: '应用中心',
        icon: 'app-center',
        children: [
            { name: '我的应用', route: '/app-center', icon: 'apps', permission: 'apps.view', comingSoon: true },
            { name: '安装管理', route: '/app-installations', icon: 'installations', permission: 'apps.install', comingSoon: true },
            { name: '应用配置', route: '/app-configurations', icon: 'settings', permission: 'apps.configure', comingSoon: true },
            { name: '应用日志', route: '/app-logs', icon: 'audit', permission: 'audit.view', comingSoon: true },
        ],
    },
    {
        name: '数据分析',
        icon: 'analytics',
        children: [
            { name: '销售分析', route: '/analytics/sales', icon: 'analytics', permission: 'orders.view', comingSoon: true },
            { name: '店铺对比', route: '/analytics/stores', icon: 'stores', permission: 'store.view', comingSoon: true },
            { name: '报表中心', route: '/reports', icon: 'reports', permission: 'orders.export', comingSoon: true },
        ],
    },
    {
        name: 'Shopify',
        icon: 'shopify',
        section: '系统',
        sectionDivider: true,
        children: [
            { name: '店铺管理', route: '/stores', icon: 'stores', permission: 'store.view' },
            { name: '应用管理', route: '/apps', icon: 'apps', permission: 'apps.view' },
            { name: '数据同步', route: '/sync', icon: 'sync', permission: 'sync.view' },
            { name: 'Webhook', route: '/webhooks', icon: 'webhooks', permission: 'webhooks.view' },
            { name: 'API 状态', route: '/system/api-status', icon: 'status', permission: 'system.health.view', comingSoon: true },
        ],
    },
    {
        name: '协作管理',
        icon: 'collaboration',
        children: [
            { name: '用户管理', route: '/users', icon: 'users', permission: 'users.view' },
            { name: '团队成员', route: '/team', icon: 'team', permission: 'users.view', comingSoon: true },
        ],
    },
    {
        name: '系统管理',
        icon: 'system',
        children: [
            { name: '角色权限', route: '/roles', icon: 'roles', permission: 'roles.view' },
            { name: '审计日志', route: '/audit-logs', icon: 'audit', permission: 'audit.view', comingSoon: true },
            { name: '系统状态', route: '/system/status', icon: 'status', permission: 'system.health.view', comingSoon: true },
            { name: '系统设置', route: '/settings', icon: 'settings', permission: 'system.settings.view', comingSoon: true },
        ],
    },
];
