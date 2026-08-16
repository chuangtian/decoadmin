export interface MenuItem {
    label: string;
    route: string;
    icon: string;
    permission?: string;
    comingSoon?: boolean;
}

export interface MenuSection {
    label: string;
    items: MenuItem[];
}

export const menu: MenuSection[] = [
    { label: '工作台', items: [{ label: '概览', route: '/dashboard', icon: 'dashboard' }] },
    {
        label: '业务中心',
        items: [
            { label: '订单', route: '/orders', icon: 'orders', permission: 'orders.view', comingSoon: true },
            { label: '商品', route: '/products', icon: 'products', permission: 'products.view', comingSoon: true },
            { label: '客户', route: '/customers', icon: 'customers', permission: 'customers.view', comingSoon: true },
        ],
    },
    {
        label: 'Shopify',
        items: [
            { label: '店铺', route: '/stores', icon: 'stores', permission: 'store.view' },
            { label: '应用', route: '/apps', icon: 'apps', permission: 'apps.view', comingSoon: true },
            { label: '数据同步', route: '/sync', icon: 'sync', permission: 'sync.view', comingSoon: true },
            { label: 'Webhooks', route: '/webhooks', icon: 'webhooks', permission: 'webhooks.view', comingSoon: true },
        ],
    },
    {
        label: '系统管理',
        items: [
            { label: '用户', route: '/users', icon: 'users', permission: 'users.view' },
            { label: '角色', route: '/roles', icon: 'roles', permission: 'roles.view' },
            { label: '权限', route: '/permissions', icon: 'permissions', permission: 'roles.view' },
            { label: '审计日志', route: '/audit-logs', icon: 'audit', permission: 'audit.view', comingSoon: true },
            { label: '系统设置', route: '/settings', icon: 'settings', permission: 'system.settings.view', comingSoon: true },
        ],
    },
];
