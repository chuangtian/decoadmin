<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'feishu_table' => [
        'app_id' => env('FEISHU_APP_ID'),
        'app_secret' => env('FEISHU_APP_SECRET'),
        'base_url' => env('FEISHU_API_BASE_URL', 'https://open.feishu.cn/open-apis'),
        'timeout' => (int) env('FEISHU_API_TIMEOUT', 20),
        'max_pages' => (int) env('FEISHU_API_MAX_PAGES', 200),
        'max_attachment_bytes' => (int) env('FEISHU_API_MAX_ATTACHMENT_BYTES', 52428800),
        'campaign_image_max_dimension' => (int) env('FEISHU_CAMPAIGN_IMAGE_MAX_DIMENSION', 1920),
        'campaign_image_max_pixels' => (int) env('FEISHU_CAMPAIGN_IMAGE_MAX_PIXELS', 80000000),
        'campaign_image_webp_quality' => (int) env('FEISHU_CAMPAIGN_IMAGE_WEBP_QUALITY', 82),
        'amazon_sync_enabled' => (bool) env('FEISHU_AMAZON_SYNC_ENABLED', true),
        'amazon_sync_time' => env('FEISHU_AMAZON_SYNC_TIME', '09:00'),
        'amazon_sync_timezone' => env('FEISHU_AMAZON_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'paid_advertising_goal_sync_enabled' => (bool) env('FEISHU_PAID_ADVERTISING_GOAL_SYNC_ENABLED', true),
        'paid_advertising_goal_sync_time' => env('FEISHU_PAID_ADVERTISING_GOAL_SYNC_TIME', '03:40'),
        'paid_advertising_goal_sync_timezone' => env('FEISHU_PAID_ADVERTISING_GOAL_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'paid_advertising_goal_max_fields' => (int) env('FEISHU_PAID_ADVERTISING_GOAL_MAX_FIELDS', 1000),
        'paid_advertising_goal_max_records' => (int) env('FEISHU_PAID_ADVERTISING_GOAL_MAX_RECORDS', 5000),
    ],

    'google_ads' => [
        'redirect_uri' => env('GOOGLE_ADS_REDIRECT_URI'),
        'state_ttl_minutes' => (int) env('GOOGLE_ADS_OAUTH_STATE_TTL_MINUTES', 10),
    ],

    'bing_ads' => [
        'redirect_uri' => env('BING_ADS_REDIRECT_URI'),
        'state_ttl_minutes' => (int) env('BING_ADS_OAUTH_STATE_TTL_MINUTES', 10),
    ],

    'google_search_console' => [
        'redirect_uri' => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI'),
        'state_ttl_minutes' => (int) env('GOOGLE_SEARCH_CONSOLE_OAUTH_STATE_TTL_MINUTES', 10),
    ],

    'youtube_analytics' => [
        'redirect_uri' => env('YOUTUBE_ANALYTICS_REDIRECT_URI'),
        'state_ttl_minutes' => (int) env('YOUTUBE_ANALYTICS_OAUTH_STATE_TTL_MINUTES', 10),
    ],

];
