<?php

$environment = env('COMMUNITY_REVIEWS_ENVIRONMENT', 'local');
$prefix = 'COMMUNITY_REVIEWS_'.strtoupper($environment).'_';

return [
    'environment' => $environment,
    'active' => [
        'client_id' => env($prefix.'CLIENT_ID', ''),
        'client_secret' => env($prefix.'CLIENT_SECRET', ''),
        'proxy_path' => '/apps/community-reviews',
    ],
    'id_token_leeway_seconds' => 5,
];
