<?php

$environment = env('MARKETING_APP_ENV', 'local');
$prefix = match ($environment) {
    'test' => 'MARKETING_TEST', 'production' => 'MARKETING_PRODUCTION', default => 'MARKETING_LOCAL'
};

return [
    'environment' => $environment,
    'reconciliation' => (bool) env('MARKETING_RECONCILIATION', false),
    'sync_scheduled' => (bool) env('MARKETING_SYNC_SCHEDULED', false),
    'scheduled' => (bool) env('MARKETING_SCHEDULED', false),
    'transport' => env('MARKETING_TRANSPORT', 'preview'),
    'resend_key' => env($prefix.'_RESEND_KEY', ''),
    'resend_webhook_secret' => env($prefix.'_RESEND_WEBHOOK_SECRET', ''),
    'from' => env($prefix.'_MAIL_FROM', ''),
    'recipients' => array_values(array_filter(array_map('trim', explode(',', env('MARKETING_TEST_RECIPIENTS', 'jiushizheyike@gmail.com'))))),
    'active' => [
        'handle' => 'deco-marketing-'.$environment,
        'client_id' => env($prefix.'_CLIENT_ID', ''),
        'client_secret' => env($prefix.'_CLIENT_SECRET', ''),
        'api_version' => '2026-07',
    ],
];
