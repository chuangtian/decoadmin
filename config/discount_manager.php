<?php

$environment = (string) env('DISCOUNT_MANAGER_ENVIRONMENT', match ((string) env('APP_ENV', 'local')) {
    'staging', 'test', 'testing' => 'test',
    'production' => 'production',
    default => 'disabled',
});

$environments = [
    'disabled' => [
        'client_id' => '',
        'client_secret' => '',
        'name' => 'Deco 折扣管理（未连接）',
        'handle' => 'deco-discount-manager-disabled',
        'app_url' => '',
    ],
    'test' => [
        'client_id' => env('DISCOUNT_MANAGER_TEST_CLIENT_ID', ''),
        'client_secret' => env('DISCOUNT_MANAGER_TEST_CLIENT_SECRET', ''),
        'name' => 'Deco 折扣管理测试',
        'handle' => 'deco-discount-manager-test',
        'app_url' => 'https://testadmin.decomkt.com',
    ],
    'production' => [
        'client_id' => env('DISCOUNT_MANAGER_PRODUCTION_CLIENT_ID', ''),
        'client_secret' => env('DISCOUNT_MANAGER_PRODUCTION_CLIENT_SECRET', ''),
        'name' => 'Deco 折扣管理',
        'handle' => 'deco-discount-manager',
        'app_url' => 'https://admin.decomkt.com',
    ],
];

return [
    'environment' => $environment,
    'active' => $environments[$environment] ?? $environments['disabled'],
    'environments' => $environments,
    'required_scopes' => ['read_discounts', 'write_discounts', 'read_products'],
    'id_token_leeway_seconds' => 5,
];
