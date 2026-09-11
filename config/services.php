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

    'meta_ads' => [
        'api_base' => env('META_ADS_API_BASE', 'https://graph.facebook.com/v21.0'),
        'timeout' => (int) env('META_ADS_API_TIMEOUT', 30),
        'concurrency' => (int) env('META_ADS_API_CONCURRENCY', 4),
        'page_size' => (int) env('META_ADS_API_PAGE_SIZE', 500),
        'ad_set_page_size' => (int) env('META_ADS_API_AD_SET_PAGE_SIZE', 100),
        'ad_page_size' => (int) env('META_ADS_API_AD_PAGE_SIZE', 100),
        'insight_page_size' => (int) env('META_ADS_API_INSIGHT_PAGE_SIZE', 500),
        'insight_window_days' => (int) env('META_ADS_API_INSIGHT_WINDOW_DAYS', 31),
        'min_page_size' => (int) env('META_ADS_API_MIN_PAGE_SIZE', 25),
        'max_pages' => (int) env('META_ADS_API_MAX_PAGES', 2000),
        'retry_delays_ms' => [2000, 15000, 60000],
        'sync_enabled' => (bool) env('META_ADS_SYNC_ENABLED', true),
        'structure_sync_time' => env('META_ADS_STRUCTURE_SYNC_TIME', '02:35'),
        'structure_sync_timezone' => env('META_ADS_STRUCTURE_SYNC_TIMEZONE', 'UTC'),
        'history_months' => (int) env('META_ADS_HISTORY_MONTHS', 6),
        'priority_days' => (int) env('META_ADS_PRIORITY_DAYS', 7),
        'rolling_days' => (int) env('META_ADS_ROLLING_DAYS', 3),
        'initial_full_estimate_minutes' => (int) env('META_ADS_INITIAL_FULL_ESTIMATE_MINUTES', 45),
        'async_poll_interval_seconds' => (int) env('META_ADS_ASYNC_POLL_INTERVAL_SECONDS', 15),
        'async_recovery_window_days' => (int) env('META_ADS_ASYNC_RECOVERY_WINDOW_DAYS', 31),
        'async_min_window_days' => (int) env('META_ADS_ASYNC_MIN_WINDOW_DAYS', 7),
        'async_max_resubmissions' => (int) env('META_ADS_ASYNC_MAX_RESUBMISSIONS', 2),
        'job_timeout_seconds' => (int) env('META_ADS_SYNC_JOB_TIMEOUT', 1800),
        'account_refresh_hours' => (int) env('META_ADS_ACCOUNT_REFRESH_HOURS', 24),
        'usage_medium_threshold' => (int) env('META_ADS_USAGE_MEDIUM_THRESHOLD', 75),
        'usage_high_threshold' => (int) env('META_ADS_USAGE_HIGH_THRESHOLD', 90),
        'usage_medium_pause_seconds' => (int) env('META_ADS_USAGE_MEDIUM_PAUSE_SECONDS', 10),
        'usage_high_pause_seconds' => (int) env('META_ADS_USAGE_HIGH_PAUSE_SECONDS', 30),
        'usage_max_wait_seconds' => (int) env('META_ADS_USAGE_MAX_WAIT_SECONDS', 30),
        'rate_limit_pause_seconds' => (int) env('META_ADS_RATE_LIMIT_PAUSE_SECONDS', 60),
        'shard_timeout_seconds' => (int) env('META_ADS_SHARD_TIMEOUT', 900),
    ],

    'advertising_sync' => [
        'enabled' => (bool) env('ADVERTISING_CHANNEL_SYNC_ENABLED', true),
        'history_months' => (int) env('ADVERTISING_CHANNEL_HISTORY_MONTHS', 6),
        'priority_days' => (int) env('ADVERTISING_CHANNEL_PRIORITY_DAYS', 7),
        'rolling_days' => (int) env('ADVERTISING_CHANNEL_ROLLING_DAYS', 3),
        'google_reconcile_days' => (int) env('GOOGLE_ADS_RECONCILE_DAYS', 90),
        'chunk_days' => (int) env('ADVERTISING_CHANNEL_CHUNK_DAYS', 30),
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
        'campaign_sync_enabled' => (bool) env('FEISHU_CAMPAIGN_SYNC_ENABLED', true),
        'campaign_sync_time' => env('FEISHU_CAMPAIGN_SYNC_TIME', '04:10'),
        'campaign_sync_timezone' => env('FEISHU_CAMPAIGN_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'archive_max_tables' => (int) env('FEISHU_ARCHIVE_MAX_TABLES', 100),
        'archive_max_fields_per_table' => (int) env('FEISHU_ARCHIVE_MAX_FIELDS_PER_TABLE', 1000),
        'archive_max_records_per_table' => (int) env('FEISHU_ARCHIVE_MAX_RECORDS_PER_TABLE', 50000),
        'spreadsheet_archive_max_rows' => (int) env('FEISHU_SPREADSHEET_ARCHIVE_MAX_ROWS', 10000),
        'spreadsheet_archive_max_columns' => (int) env('FEISHU_SPREADSHEET_ARCHIVE_MAX_COLUMNS', 200),
        'natural_traffic_sync_enabled' => (bool) env('FEISHU_NATURAL_TRAFFIC_SYNC_ENABLED', true),
        'natural_traffic_sync_time' => env('FEISHU_NATURAL_TRAFFIC_SYNC_TIME', '04:25'),
        'natural_traffic_sync_timezone' => env('FEISHU_NATURAL_TRAFFIC_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'amazon_sync_enabled' => (bool) env('FEISHU_AMAZON_SYNC_ENABLED', true),
        'amazon_sync_time' => env('FEISHU_AMAZON_SYNC_TIME', '09:00'),
        'amazon_sync_timezone' => env('FEISHU_AMAZON_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'paid_advertising_goal_sync_enabled' => (bool) env('FEISHU_PAID_ADVERTISING_GOAL_SYNC_ENABLED', true),
        'paid_advertising_goal_sync_time' => env('FEISHU_PAID_ADVERTISING_GOAL_SYNC_TIME', '03:40'),
        'paid_advertising_goal_sync_timezone' => env('FEISHU_PAID_ADVERTISING_GOAL_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'paid_advertising_goal_max_fields' => (int) env('FEISHU_PAID_ADVERTISING_GOAL_MAX_FIELDS', 1000),
        'paid_advertising_goal_max_records' => (int) env('FEISHU_PAID_ADVERTISING_GOAL_MAX_RECORDS', 5000),
        'paid_advertising_goal_max_tables' => (int) env('FEISHU_PAID_ADVERTISING_GOAL_MAX_TABLES', 100),
        'mf_daily_report_sync_enabled' => (bool) env('FEISHU_MF_DAILY_REPORT_SYNC_ENABLED', true),
        'mf_daily_report_sync_time' => env('FEISHU_MF_DAILY_REPORT_SYNC_TIME', '15:30'),
        'mf_daily_report_sync_timezone' => env('FEISHU_MF_DAILY_REPORT_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'daily_report_font_regular' => env('FEISHU_DAILY_REPORT_FONT_REGULAR', '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'),
        'daily_report_font_bold' => env('FEISHU_DAILY_REPORT_FONT_BOLD', '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'),
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
        'default_site_url' => env('GSC_SITE_URL', 'https://macfoxbike.com/'),
        'sync_enabled' => (bool) env('SEO_ANALYTICS_SYNC_ENABLED', true),
        'sync_time' => env('SEO_ANALYTICS_SYNC_TIME', '04:40'),
        'sync_timezone' => env('SEO_ANALYTICS_SYNC_TIMEZONE', 'America/Los_Angeles'),
        'backfill_months' => (int) env('SEO_ANALYTICS_BACKFILL_MONTHS', 16),
        'overlap_days' => (int) env('SEO_ANALYTICS_OVERLAP_DAYS', 7),
        'data_delay_days' => (int) env('SEO_ANALYTICS_DATA_DELAY_DAYS', 2),
        'detail_row_limit' => (int) env('SEO_ANALYTICS_DETAIL_ROW_LIMIT', 25000),
    ],

    'google_analytics' => [
        'default_property_id' => env('GA4_PROPERTY_ID', '354777547'),
        'channel_dimension' => env('GA4_SEO_CHANNEL_DIMENSION', 'sessionCustomChannelGroup:14917938318'),
    ],

    'youtube_analytics' => [
        'redirect_uri' => env('YOUTUBE_ANALYTICS_REDIRECT_URI'),
        'state_ttl_minutes' => (int) env('YOUTUBE_ANALYTICS_OAUTH_STATE_TTL_MINUTES', 10),
        'max_videos' => (int) env('YOUTUBE_ANALYTICS_MAX_VIDEOS', 200),
    ],

];
