<?php

return [
    'client_id' => env('SHOPIFY_CLIENT_ID'),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    'requested_scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SHOPIFY_REQUESTED_SCOPES', 'read_products,read_inventory,read_orders,read_customers,read_locations')),
    ))),
    'app_name' => env('SHOPIFY_APP_NAME', 'Shopify Commerce Hub'),
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'shopify-commerce-hub'),
    'app_url' => env('SHOPIFY_APP_URL', env('APP_URL', 'http://localhost:8000')),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI'),
    'state_ttl_minutes' => (int) env('SHOPIFY_OAUTH_STATE_TTL', 10),
];
