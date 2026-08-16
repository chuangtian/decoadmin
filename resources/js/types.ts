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

export interface SharedProps {
    appName: string;
    auth: {
        user: { id: number; name: string; email: string } | null;
        permissions: string[];
    };
    currentOrganization: { id: number; name: string; code: string } | null;
    currentStore: (StoreOption & { organization_id: number }) | null;
    availableOrganizations: OrganizationOption[];
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}
