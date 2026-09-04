<?php

$environment = (string) env('PERSONALIZATION_ENVIRONMENT', match ((string) env('APP_ENV', 'local')) {
    'staging', 'test', 'testing' => 'test',
    'production' => 'production',
    default => 'disabled',
});

$environments = [
    'disabled' => [
        'client_id' => '',
        'client_secret' => '',
        'name' => 'Deco 个性化推荐（未连接）',
        'handle' => 'deco-personalization-disabled',
        'app_url' => '',
        'proxy_path' => '',
    ],
    'test' => [
        'client_id' => env('PERSONALIZATION_TEST_CLIENT_ID', ''),
        'client_secret' => env('PERSONALIZATION_TEST_CLIENT_SECRET', ''),
        'name' => 'Deco 个性化推荐测试',
        'handle' => 'deco-personalization-test',
        'app_url' => 'https://testadmin.decomkt.com',
        'proxy_path' => '/apps/deco-personalization-test',
    ],
    'production' => [
        'client_id' => env('PERSONALIZATION_PRODUCTION_CLIENT_ID', ''),
        'client_secret' => env('PERSONALIZATION_PRODUCTION_CLIENT_SECRET', ''),
        'name' => 'Deco 个性化推荐',
        'handle' => 'deco-personalization',
        'app_url' => 'https://admin.decomkt.com',
        'proxy_path' => '/apps/deco-personalization',
    ],
];

$active = $environments[$environment] ?? $environments['disabled'];

return [
    'environment' => $environment,
    'active' => $active,
    'environments' => $environments,

    // Scopes are enabled only in the stage that implements the feature requiring
    // them, after validating the current Shopify configuration and documentation.
    'required_scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PERSONALIZATION_REQUIRED_SCOPES', 'write_app_proxy,write_pixels,read_customer_events,read_discounts,write_discounts')),
    ))),

    'id_token_leeway_seconds' => 5,
    'app_proxy_target' => '/api/shopify-app/personalization/proxy',
    'active_proxy_path' => $active['proxy_path'],

    'retention' => [
        'raw_event_days' => 90,
        'attribution_days' => 90,
        'aggregate_months' => 13,
        'audit_days' => 365,
        'uninstall_purge_hours' => 48,
        // Infrastructure backups must independently expire within this window.
        'backup_days' => 30,
    ],

    // Permanent safety boundary. Runtime code must never infer an allow-list from
    // this value; installations remain dynamic across all non-denied stores.
    'denied_shop_domains' => array_values(array_filter(array_map(
        fn (string $shop): string => strtolower(trim($shop)),
        explode(',', (string) env('PERSONALIZATION_DENIED_SHOP_DOMAINS', 'macfoxebike.myshopify.com')),
    ))),
];
