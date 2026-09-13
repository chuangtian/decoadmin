<?php

$environment = env('DECO_REVIEWS_ENVIRONMENT', 'local');
$prefix = 'DECO_REVIEWS_'.strtoupper($environment).'_';

return [
    'environment' => $environment,
    'active' => ['client_id' => env($prefix.'CLIENT_ID', ''), 'client_secret' => env($prefix.'CLIENT_SECRET', ''), 'proxy_path' => '/apps/deco-reviews'],
    // Fail closed until sender configuration and consent policy have been verified per environment.
    'delivery_enabled' => (bool) env($prefix.'DELIVERY_ENABLED', false),
    'automation_stores' => array_filter(explode(',', (string) env($prefix.'AUTOMATION_STORES', ''))),
    'recipient_allowlist' => array_filter(explode(',', (string) env($prefix.'RECIPIENT_ALLOWLIST', ''))),
    'video' => [
        'ffprobe_binary' => env('DECO_REVIEWS_FFPROBE_BINARY', '/usr/bin/ffprobe'),
        'probe_timeout_seconds' => 10,
        'max_duration_seconds' => 120,
        'max_width' => 3840,
        'max_height' => 2160,
    ],
    'id_token_leeway_seconds' => 5,
    'defaults' => [
        'enabled' => false, 'organic_collection_enabled' => false, 'auto_publish_days' => 14, 'invites_enabled' => false,
        'auto_invites_enabled' => false, 'auto_invites_since' => null, 'reminders_enabled' => false,
        'reminder_subject' => 'A reminder to share your experience',
        'reminder_body' => 'How was {product}? We would love your honest feedback. All ratings are welcome.',
        'email_button_label' => 'Write a review', 'email_accent' => '#7C3AED',
        'domestic_delay_days' => 14, 'international_delay_days' => 21, 'reminder_days' => 7,
        'star_color' => '#EBBF20', 'corner_style' => 'rounded', 'display_name' => 'initials',
        'show_verified' => true, 'show_incentive' => true, 'layout' => 'grid', 'page_size' => 12,
        'heading' => 'Customer reviews', 'reply_to' => '', 'subject' => 'How was your purchase?',
        'email_body' => 'Please share your honest experience with {product}. All ratings are welcome.', 'marketing_only' => true,
    ],
];
