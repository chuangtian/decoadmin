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
    { label: 'Workspace', items: [{ label: 'Dashboard', route: '/dashboard', icon: 'dashboard' }] },
    {
        label: 'Commerce',
        items: [
            { label: 'Orders', route: '/orders', icon: 'orders', permission: 'orders.view', comingSoon: true },
            { label: 'Products', route: '/products', icon: 'products', permission: 'products.view', comingSoon: true },
            { label: 'Customers', route: '/customers', icon: 'customers', permission: 'customers.view', comingSoon: true },
        ],
    },
    {
        label: 'Shopify',
        items: [
            { label: 'Stores', route: '/stores', icon: 'stores', permission: 'store.view', comingSoon: true },
            { label: 'Apps', route: '/apps', icon: 'apps', permission: 'apps.view', comingSoon: true },
            { label: 'Sync', route: '/sync', icon: 'sync', permission: 'sync.view', comingSoon: true },
            { label: 'Webhooks', route: '/webhooks', icon: 'webhooks', permission: 'webhooks.view', comingSoon: true },
        ],
    },
    {
        label: 'System',
        items: [
            { label: 'Users', route: '/users', icon: 'users', permission: 'users.view' },
            { label: 'Roles', route: '/roles', icon: 'roles', permission: 'roles.view' },
            { label: 'Permissions', route: '/permissions', icon: 'permissions', permission: 'roles.view' },
            { label: 'Audit logs', route: '/audit-logs', icon: 'audit', permission: 'audit.view', comingSoon: true },
            { label: 'Settings', route: '/settings', icon: 'settings', permission: 'system.settings.view', comingSoon: true },
        ],
    },
];
