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
        'amazon_sync_enabled' => (bool) env('FEISHU_AMAZON_SYNC_ENABLED', true),
        'amazon_sync_time' => env('FEISHU_AMAZON_SYNC_TIME', '09:00'),
        'amazon_sync_timezone' => env('FEISHU_AMAZON_SYNC_TIMEZONE', 'Asia/Shanghai'),
    ],

];
