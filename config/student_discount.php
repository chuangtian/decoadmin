<?php

$environment = (string) env('STUDENT_DISCOUNT_ENVIRONMENT', match ((string) env('APP_ENV', 'local')) {
    'production' => 'production',
    'staging', 'test', 'testing' => 'test',
    default => 'local',
});

$environments = [
    'local' => [
        'client_id' => '89f4215fdf2d0ffa9be3c7586fe50234',
        'name' => 'Deco-学生优惠-local',
        'handle' => 'deco-student-discount-local',
        'proxy_path' => '/apps/deco-student-local',
        'app_url' => 'https://wendy-interim-classic-segment.trycloudflare.com',
        'client_secret' => env('STUDENT_DISCOUNT_LOCAL_CLIENT_SECRET', env('STUDENT_DISCOUNT_SHOPIFY_CLIENT_SECRET')),
    ],
    'test' => [
        'client_id' => '179bfe6a970b5daabc4f6344bcd2733b',
        'name' => 'Deco-学生优惠-test',
        'handle' => 'deco-student-discount-test',
        'proxy_path' => '/apps/deco-student-test',
        'app_url' => 'https://testadmin.decomkt.com',
        'client_secret' => env('STUDENT_DISCOUNT_TEST_CLIENT_SECRET', env('STUDENT_DISCOUNT_SHOPIFY_CLIENT_SECRET')),
    ],
    'production' => [
        'client_id' => '030f66008921f9d200ac057ef03fa163',
        'name' => 'Deco-学生优惠',
        'handle' => 'deco-student-discount',
        'proxy_path' => '/apps/deco-student-discount',
        'app_url' => 'https://admin.decomkt.com',
        'client_secret' => env('STUDENT_DISCOUNT_PRODUCTION_CLIENT_SECRET', env('STUDENT_DISCOUNT_SHOPIFY_CLIENT_SECRET')),
    ],
];

return [
    'environment' => $environment,
    'active' => $environments[$environment] ?? $environments['local'],
    'environments' => $environments,
    'required_scopes' => [
        'read_discounts',
        'write_discounts',
        'read_products',
        'write_app_proxy',
    ],
    'app_proxy_target' => '/api/shopify-app/student-discounts/proxy',
    'id_token_leeway_seconds' => 5,
];
