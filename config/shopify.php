<?php

return [
    'client_id' => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    'requested_scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SHOPIFY_REQUESTED_SCOPES', 'read_products,read_inventory,read_orders,read_customers,read_locations,read_reports')),
    ))),
    'app_name' => env('SHOPIFY_APP_NAME', 'DecoAdmin Shopify 应用'),
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'shopify-commerce-hub'),
    'app_url' => env('SHOPIFY_APP_URL', env('APP_URL', 'http://localhost:8000')),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI'),
    'state_ttl_minutes' => (int) env('SHOPIFY_OAUTH_STATE_TTL', 10),
    'analytics_snapshot_ttl_minutes' => (int) env('SHOPIFY_ANALYTICS_SNAPSHOT_TTL', 15),
    'analytics_snapshot_refresh_lock_seconds' => (int) env('SHOPIFY_ANALYTICS_REFRESH_LOCK_SECONDS', 90),
    'analytics_snapshot_refresh_wait_seconds' => (int) env('SHOPIFY_ANALYTICS_REFRESH_WAIT_SECONDS', 35),
    'analytics_snapshot_retention_days' => (int) env('SHOPIFY_ANALYTICS_SNAPSHOT_RETENTION_DAYS', 365),
    'analytics_snapshot_prune_batch_size' => (int) env('SHOPIFY_ANALYTICS_SNAPSHOT_PRUNE_BATCH_SIZE', 1000),
    'scheduled_sync' => [
        'enabled' => (bool) env('SHOPIFY_SCHEDULED_SYNC_ENABLED', true),
        'timezone' => env('SHOPIFY_SCHEDULED_SYNC_TIMEZONE', 'America/New_York'),
        'incremental_interval_minutes' => (int) env('SHOPIFY_INCREMENTAL_SYNC_INTERVAL', 15),
        'inventory_interval_minutes' => (int) env('SHOPIFY_INVENTORY_SYNC_INTERVAL', 30),
        'overlap_minutes' => (int) env('SHOPIFY_INCREMENTAL_OVERLAP_MINUTES', 5),
        'max_attempts' => (int) env('SHOPIFY_SYNC_MAX_ATTEMPTS', 3),
        'job_timeout_seconds' => (int) env('SHOPIFY_SYNC_JOB_TIMEOUT', 1800),
        'reconciliation_time' => env('SHOPIFY_RECONCILIATION_TIME', env('SHOPIFY_SCHEDULED_SYNC_TIME', '03:00')),
        'full_sync_weekday' => (int) env('SHOPIFY_FULL_SYNC_WEEKDAY', 1),
        'full_sync_time' => env('SHOPIFY_FULL_SYNC_TIME', '02:00'),
        'stalled_after_minutes' => (int) env('SHOPIFY_SYNC_STALLED_AFTER_MINUTES', 45),
    ],
];
