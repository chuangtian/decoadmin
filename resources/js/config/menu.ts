export interface MenuItem {
    label: string;
    href: string;
    permission?: string;
    icon: string;
}

export const menu: MenuItem[] = [
    { label: 'People', href: '/users', permission: 'users.view', icon: 'PE' },
    { label: 'Roles', href: '/roles', permission: 'roles.view', icon: 'RO' },
    { label: 'Permissions', href: '/permissions', permission: 'roles.view', icon: 'PM' },
];
