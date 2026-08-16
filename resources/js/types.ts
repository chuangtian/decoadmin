export interface Permission {
    id: number;
    name: string;
    slug: string;
    group: string;
    description: string | null;
}

export interface Role {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_system: boolean;
    permissions_count?: number;
    permissions?: Permission[];
    store_id?: number | null;
}

export interface StoreOption {
    id: number;
    name: string;
    status: string;
}

export type ShopifyConnectionStatus = 'connected' | 'warning' | 'invalid' | 'disconnected';

export interface ShopifyConnectionSummary {
    id: number;
    status: ShopifyConnectionStatus;
    api_version: string;
    last_api_check: string | null;
    scopes: string[];
    installed_at: string | null;
    last_verified_at: string | null;
    last_error: string | null;
    last_error_at: string | null;
}

export interface AppInstallationSummary {
    id: number;
    status: string;
    installed_at: string | null;
    app: { id: number; name: string; handle: string; status: string } | null;
}

export interface ShopifyStore {
    id: number;
    name: string;
    shopify_domain: string;
    status: string;
    timezone: string;
    currency: string;
    country_code: string | null;
    plan_name: string | null;
    platform: string;
    environment: 'production' | 'development';
    connection_status: ShopifyConnectionStatus | 'pending';
    installed_apps_count: number;
    last_sync: { status: string; at: string | null } | null;
    created_at: string | null;
    connection: ShopifyConnectionSummary | null;
    app_installations: AppInstallationSummary[];
}

export interface OrganizationOption {
    id: number;
    name: string;
    code: string;
    stores: StoreOption[];
}

export interface User {
    id: number;
    name: string;
    email: string;
    status: string;
    avatar_url: string | null;
    roles: Role[];
    stores: StoreOption[];
    created_at: string;
}

export interface ResourceCollection<T> {
    data: T[];
}

export interface PaginatedResource<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: { current_page: number; last_page: number; total: number };
}

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

export type ToastType = 'success' | 'error' | 'warning' | 'info';

export interface FlashMessages {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
}

export interface SharedProps {
    appName: string;
    auth: {
        user: { id: number; name: string; email: string } | null;
        permissions: string[];
    };
    currentOrganization: { id: number; name: string; code: string } | null;
    currentStore: (StoreOption & { organization_id: number }) | null;
    availableOrganizations: OrganizationOption[];
    flash: FlashMessages;
    [key: string]: unknown;
}
