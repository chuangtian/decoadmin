<?php

$environment = env('DECO_REVIEWS_ENVIRONMENT', 'local');
$prefix = 'DECO_REVIEWS_'.strtoupper($environment).'_';

return [
    'environment' => $environment,
    'active' => ['client_id' => env($prefix.'CLIENT_ID', ''), 'client_secret' => env($prefix.'CLIENT_SECRET', ''), 'proxy_path' => '/apps/deco-reviews'],
    // Fail closed until sender configuration and consent policy have been verified per environment.
    'delivery_enabled' => (bool) env($prefix.'DELIVERY_ENABLED', false),
    'reward_writes_enabled' => (bool) env($prefix.'REWARD_WRITES_ENABLED', false),
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
        'media_reminders_enabled' => false, 'media_reminder_days' => 7,
        'media_reminder_subject' => 'Show us how you use {product}',
        'media_reminder_body' => 'Photos and videos help other customers. You can still share any honest rating, with or without media.',
        'email_button_label' => 'Write a review', 'email_accent' => '#7C3AED',
        'product_thank_you_enabled' => false, 'product_thank_you_subject' => 'Thank you for reviewing {product}',
        'product_thank_you_body' => 'Thank you, {author}. Your {rating}-star review has been received and will follow the store review policy.',
        'store_thank_you_enabled' => false, 'store_thank_you_subject' => 'Thank you for reviewing {store}',
        'store_thank_you_body' => 'Thank you, {author}. Your {rating}-star store review has been received and will follow the store review policy.',
        'reply_notification_enabled' => false, 'reply_notification_subject' => '{store} replied to your review',
        'reply_notification_body' => "The store posted this public reply to your review of {product}:\n\n{reply}",
        'rewards_enabled' => false, 'photo_reward_enabled' => false, 'video_reward_enabled' => false,
        'reward_discount_kind' => 'percentage', 'reward_value' => 10, 'reward_currency' => 'USD', 'reward_expiration_days' => 30,
        'reward_issued_subject' => 'Your review reward from {store}',
        'reward_issued_body' => 'Thank you, {author}. Your {value} reward for reviewing {product} is ready.',
        'reward_reminder_enabled' => false, 'reward_reminder_days' => 14,
        'reward_reminder_subject' => 'Your review reward expires soon',
        'reward_reminder_body' => 'A reminder that your {value} reward for reviewing {product} expires on {expires}.',
        'domestic_delay_days' => 14, 'international_delay_days' => 21, 'reminder_days' => 7,
        'star_color' => '#EBBF20', 'corner_style' => 'rounded', 'display_name' => 'initials',
        'show_verified' => true, 'show_incentive' => true, 'layout' => 'grid', 'page_size' => 12,
        'heading' => 'Customer reviews', 'reply_to' => '', 'subject' => 'How was your purchase?',
        'email_body' => 'Please share your honest experience with {product}. All ratings are welcome.', 'marketing_only' => true,
    ],
];
