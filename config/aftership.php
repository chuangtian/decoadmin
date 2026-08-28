<?php

$environment = (string) env('AFTERSHIP_ENVIRONMENT', match ((string) env('APP_ENV', 'local')) {
    'production' => 'production',
    'staging', 'test', 'testing' => 'test',
    default => 'local',
});

$environments = [
    'local' => [
        'client_id' => env('AFTERSHIP_LOCAL_CLIENT_ID', ''),
        'client_secret' => env('AFTERSHIP_LOCAL_CLIENT_SECRET', env('AFTERSHIP_SHOPIFY_CLIENT_SECRET')),
        'name' => 'Deco-AfterShip-local',
        'handle' => 'deco-marketing',
        'app_url' => 'https://wendy-interim-classic-segment.trycloudflare.com',
    ],
    'test' => [
        'client_id' => '9972cd2dc0e808aae74c83b9d28d664d',
        'client_secret' => env('AFTERSHIP_TEST_CLIENT_SECRET', env('AFTERSHIP_SHOPIFY_CLIENT_SECRET')),
        'name' => 'Deco-AfterShip-test',
        'handle' => 'deco-marketing',
        'app_url' => 'https://testadmin.decomkt.com',
    ],
    'production' => [
        'client_id' => env('AFTERSHIP_PRODUCTION_CLIENT_ID', ''),
        'client_secret' => env('AFTERSHIP_PRODUCTION_CLIENT_SECRET', env('AFTERSHIP_SHOPIFY_CLIENT_SECRET')),
        'name' => 'Deco-AfterShip',
        'handle' => 'deco-marketing',
        'app_url' => 'https://admin.decomkt.com',
    ],
];

$active = $environments[$environment] ?? $environments['local'];
$appUrl = rtrim((string) $active['app_url'], '/');

return [
    'environment' => $environment,
    'active' => [
        ...$active,
        'launch_url' => $appUrl.'/shopify/aftership/launch',
        'redirect_uri' => $appUrl.'/shopify/aftership/oauth/callback',
    ],
    'environments' => $environments,
    'required_scopes' => [
        'read_products',
        'read_inventory',
        'read_orders',
        'read_customers',
        'read_locations',
        'read_reports',
        'read_discounts',
        'write_discounts',
    ],
];
