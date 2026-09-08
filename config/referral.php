<?php

$environment = match ((string) env('APP_ENV', 'local')) {
    'production' => 'production',
    'staging', 'test', 'testing' => 'test',
    default => 'local',
};

$environments = [];
foreach (['local', 'test', 'production'] as $name) {
    $prefix = 'REFERRAL_'.strtoupper($name);
    $environments[$name] = [
        'client_id' => env($prefix.'_CLIENT_ID'),
        'client_secret' => env($prefix.'_CLIENT_SECRET'),
        'handle' => 'deco-referral-'.$name,
    ];
}

return [
    'risk' => ['order_velocity_limit' => 10, 'same_source_order_limit' => 3, 'click_burst_limit' => 60],
    'environment' => $environment,
    'active' => $environments[$environment],
    'required_scopes' => ['read_orders', 'read_customers', 'read_products', 'write_discounts'],
    'id_token_leeway_seconds' => 5,
];
