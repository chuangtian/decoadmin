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
    timezone: string;
    currency: string;
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

export interface ShopifyConnectionHistory {
    id: number;
    action: string;
    actor: string;
    previous_status: ShopifyConnectionStatus | null;
    new_status: ShopifyConnectionStatus | null;
    reason: string | null;
    created_at: string | null;
}

export interface AppInstallationSummary {
    id: number;
    status: string;
    installed_at: string | null;
    app: { id: number; name: string; handle: string; status: string } | null;
}

export interface StoreAppSummary {
    id: number;
    name: string;
    handle: string;
    description: string | null;
    app_status: string;
    installable: boolean;
    status: 'active' | 'uninstalled' | 'pending';
    installed_at: string | null;
    uninstalled_at: string | null;
}

export interface ShopifyApp {
    id: number;
    name: string;
    slug: string;
    type: string;
    status: string;
    description: string | null;
    installations_count: number;
    created_at: string | null;
}

export interface AppInstallation {
    id: number;
    status: string;
    installed_at: string | null;
    uninstalled_at: string | null;
    store: {
        id: number;
        name: string;
        shopify_domain: string;
        status: string;
        timezone: string;
    };
}

export type WebhookEventStatus = 'received' | 'queued' | 'processing' | 'processed' | 'failed' | 'retrying';

export interface WebhookEventSummary {
    id: number;
    webhook_id: string;
    topic: string;
    status: WebhookEventStatus;
    processing_result: 'handled' | 'unsupported' | 'failed' | null;
    attempts: number;
    received_at: string | null;
    processed_at: string | null;
    store: {
        id: number;
        name: string;
        shopify_domain: string;
        timezone: string;
    };
}

export interface WebhookEventDetail extends WebhookEventSummary {
    api_version: string | null;
    handler: string | null;
    unsupported_reason: string | null;
    processing_started_at: string | null;
    processing_duration_ms: number | null;
    headers: Record<string, string>;
    payload: Record<string, unknown> | unknown[] | null;
    payload_integrity_valid: boolean;
    last_error: string | null;
    next_retry_at: string | null;
    app: { id: number; name: string; slug: string } | null;
}

export type SyncJobStatus = 'pending' | 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
export type SyncJobType = 'products' | 'orders' | 'customers' | 'inventory';
export type SyncJobMode = 'full' | 'incremental' | 'reconcile';

export interface SyncJobInstallation {
    id: number;
    status: string;
    app: { id: number; name: string; handle: string } | null;
}

export interface SyncJobStoreOption {
    id: number;
    name: string;
    shopify_domain: string;
    can_run: boolean;
    installations: SyncJobInstallation[];
}

export interface SyncJobSummary {
    id: number;
    uuid: string;
    type: SyncJobType;
    mode: SyncJobMode;
    status: SyncJobStatus;
    since_at: string | null;
    until_at: string | null;
    error_code: string | null;
    correlation_id: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
    store: {
        id: number;
        name: string;
        shopify_domain: string;
        timezone: string;
    };
    app_installation: SyncJobInstallation | null;
}

export interface StoreSyncStateSummary {
    type: SyncJobType;
    status: 'idle' | 'running' | 'failed';
    watermark_at: string | null;
    last_full_sync_at: string | null;
    last_incremental_sync_at: string | null;
    last_reconciled_at: string | null;
    last_success_at: string | null;
    last_failed_at: string | null;
    next_sync_at: string | null;
    consecutive_failures: number;
    last_error_code: string | null;
    last_job: { id: number; uuid: string; mode: SyncJobMode; status: SyncJobStatus; error_code: string | null; correlation_id: string | null } | null;
}

export interface StoreSyncStatusSummary {
    store: { id: number; name: string; shopify_domain: string; timezone: string };
    status: 'healthy' | 'running' | 'warning' | 'critical';
    connection_status: string;
    last_verified_at: string | null;
    running_jobs: number;
    open_alerts: number;
    latest_webhook: { topic: string; status: string; received_at: string | null; processed_at: string | null } | null;
    sync_states: StoreSyncStateSummary[];
}

export interface SyncJobLog {
    level: 'info' | 'success' | 'warning' | 'error';
    message: string;
    at: string;
}

export interface SyncJobResult {
    success: boolean;
    status: string;
    message: string;
    records_count: number;
    errors: Array<{ code: string; message: string }>;
    metadata: {
        duration_ms?: number;
        framework_only?: boolean;
        [key: string]: unknown;
    };
}

export interface SyncJobDetail extends SyncJobSummary {
    direction: string;
    attempts: number;
    max_attempts: number;
    total_items: number;
    processed_items: number;
    failed_items: number;
    error: string | null;
    logs: SyncJobLog[];
    result: SyncJobResult | null;
}

export type AuditLogResult = 'success' | 'warning' | 'error' | 'info';

export interface AuditLogSummary {
    id: number;
    uuid: string;
    action: string;
    action_label: string;
    category: string;
    result: AuditLogResult;
    actor: { id: number; name: string; email: string } | null;
    store: { id: number; name: string; shopify_domain: string; timezone: string } | null;
    subject: { type: string; id: number | null } | null;
    created_at: string | null;
}

export interface AuditLogDetail extends AuditLogSummary {
    ip_address: string | null;
    user_agent: string | null;
    old_values: Record<string, unknown> | unknown[] | null;
    new_values: Record<string, unknown> | unknown[] | null;
    metadata: Record<string, unknown> | unknown[] | null;
}

export interface StoreOperations {
    capabilities: {
        sync_view: boolean;
        sync_run: boolean;
        sync_retry: boolean;
        webhooks_view: boolean;
        webhooks_retry: boolean;
        audit_view: boolean;
    };
    sync: {
        summary: { total: number; running: number; completed: number; failed: number };
        runnable_types: SyncJobType[];
        installations: SyncJobInstallation[];
        jobs: Array<{
            id: number;
            uuid: string;
            type: SyncJobType;
            mode: SyncJobMode;
            status: SyncJobStatus;
            processed_items: number;
            failed_items: number;
            error_code: string | null;
            started_at: string | null;
            finished_at: string | null;
            created_at: string | null;
            can_retry: boolean;
        }>;
    };
    webhooks: {
        summary: { total: number; processed: number; active: number; failed: number };
        events: Array<Omit<WebhookEventSummary, 'store'> & { can_retry: boolean }>;
    };
    logs: {
        summary: { operations: number; integrations: number; exceptions: number };
        items: Array<{
            key: string;
            source: 'audit' | 'sync' | 'webhook';
            title: string;
            description: string;
            status: AuditLogResult;
            occurred_at: string | null;
            href: string;
        }>;
    };
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
        user: { id: number; name: string; email: string; avatar_url: string | null } | null;
        permissions: string[];
    };
    currentOrganization: { id: number; name: string; code: string } | null;
    currentStore: (StoreOption & { organization_id: number }) | null;
    availableOrganizations: OrganizationOption[];
    flash: FlashMessages;
    [key: string]: unknown;
}
