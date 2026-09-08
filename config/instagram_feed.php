<?php

$environment = (string) env('INSTAGRAM_FEED_ENVIRONMENT', match ((string) env('APP_ENV', 'local')) {
    'production' => 'production',
    'staging', 'test', 'testing' => 'test',
    default => 'local',
});

$environments = [
    'local' => [
        'client_id' => 'd3446448682d2950aa75cea4a399d50f',
        'name' => 'Instagram Feed (local)',
        'handle' => 'deco-instagram-feed-local',
        'app_url' => 'https://wendy-interim-classic-segment.trycloudflare.com',
        'client_secret' => env('INSTAGRAM_FEED_LOCAL_CLIENT_SECRET', env('INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET')),
    ],
    // 测试与生产必须是 Dev Dashboard 里各自独立的 Shopify App。
    // client_id 留空表示尚未创建，配置校验会拒绝在该环境执行 Shopify 调用。
    'test' => [
        'client_id' => env('INSTAGRAM_FEED_TEST_CLIENT_ID', ''),
        'name' => 'Instagram Feed (test)',
        'handle' => 'deco-instagram-feed-test',
        'app_url' => 'https://testadmin.decomkt.com',
        'client_secret' => env('INSTAGRAM_FEED_TEST_CLIENT_SECRET', env('INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET')),
    ],
    'production' => [
        'client_id' => env('INSTAGRAM_FEED_PRODUCTION_CLIENT_ID', ''),
        'name' => 'Instagram Feed',
        'handle' => 'deco-instagram-feed',
        'app_url' => 'https://admin.decomkt.com',
        'client_secret' => env('INSTAGRAM_FEED_PRODUCTION_CLIENT_SECRET', env('INSTAGRAM_FEED_SHOPIFY_CLIENT_SECRET')),
    ],
];

$active = $environments[$environment] ?? $environments['local'];
$appUrl = rtrim((string) $active['app_url'], '/');

return [
    'environment' => $environment,
    'active' => $active,
    'environments' => $environments,

    // Shopify App 安装时必须授予的权限。read_x 可被 write_x 覆盖。
    'required_scopes' => [
        'read_products',
    ],

    'id_token_leeway_seconds' => 5,

    // 前台数据交付：写进 AppInstallation 的 app-data metafield，
    // Theme App Extension 用 app.metafields.instagram_videos.feed.value 直接读，
    // 不依赖 DecoAdmin 在线。
    'metafield' => [
        'namespace' => 'instagram_videos',
        'key' => 'feed',
        // Shopify 单个 metafield 值上限 64KB，这里留足余量。
        'max_items_per_gallery' => 50,
        'max_caption_length' => 300,
    ],

    // OAuth state 有效期。Meta 回调没有 DecoAdmin 会话，靠签名 state 还原店铺。
    'oauth_state_ttl_seconds' => (int) env('INSTAGRAM_FEED_OAUTH_STATE_TTL', 900),

    // Shopify App 自身授权（授权码模式）的 state 有效期，单位分钟。
    // 与 Meta 授权是两条独立链路：这条走 oauth_states 表 + httpOnly cookie。
    'oauth_state_ttl_minutes' => (int) env('INSTAGRAM_FEED_SHOPIFY_STATE_TTL_MINUTES', 10),

    // Instagram API with Instagram Login（Basic Display API 已于 2024-12 下线）
    'instagram' => [
        'app_id' => env('INSTAGRAM_FEED_INSTAGRAM_APP_ID'),
        'app_secret' => env('INSTAGRAM_FEED_INSTAGRAM_APP_SECRET'),
        'graph_version' => env('INSTAGRAM_FEED_INSTAGRAM_GRAPH_VERSION', 'v25.0'),
        // 必须与 Meta 后台 OAuth redirect URI 完全一致，包括结尾斜杠。
        'redirect_uri' => env('INSTAGRAM_FEED_INSTAGRAM_REDIRECT_URI', $appUrl.'/instagram-feed/oauth/instagram/callback'),
        'scopes' => ['instagram_business_basic'],
        // 长效 token 剩余有效期少于这个天数就续期。
        'refresh_when_days_left' => (int) env('INSTAGRAM_FEED_REFRESH_WHEN_DAYS_LEFT', 10),
    ],

    // Instagram API with Facebook Login（多绑一层 Facebook 主页）
    'facebook' => [
        'app_id' => env('INSTAGRAM_FEED_FACEBOOK_APP_ID'),
        'app_secret' => env('INSTAGRAM_FEED_FACEBOOK_APP_SECRET'),
        'graph_version' => env('INSTAGRAM_FEED_FACEBOOK_GRAPH_VERSION', 'v25.0'),
        'redirect_uri' => env('INSTAGRAM_FEED_FACEBOOK_REDIRECT_URI', $appUrl.'/instagram-feed/oauth/facebook/callback'),
        // 配置化登录（Facebook Login for Business）填了 config_id 后不再传 scope。
        'login_config_id' => env('INSTAGRAM_FEED_FACEBOOK_LOGIN_CONFIG_ID'),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INSTAGRAM_FEED_FACEBOOK_SCOPES', 'instagram_basic,pages_show_list')),
        ))),
    ],

    'media' => [
        // Instagram 不返回总数，只能按游标翻到底；这是防游标异常死循环的兜底。
        'fetch_all_items' => (int) env('INSTAGRAM_FEED_FETCH_ALL_ITEMS', 2000),
        // Instagram media 端点单页最多 50 条。
        'page_size' => 50,
    ],

    // Cloudflare R2：IG CDN 链接带签名会过期，同步后把视频与封面转存到 R2，
    // 对外用绑定在桶上的自定义域名给永久地址。
    'r2' => [
        'account_id' => env('INSTAGRAM_FEED_R2_ACCOUNT_ID'),
        'access_key_id' => env('INSTAGRAM_FEED_R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('INSTAGRAM_FEED_R2_SECRET_ACCESS_KEY'),
        'bucket' => env('INSTAGRAM_FEED_R2_BUCKET'),
        'public_base_url' => rtrim((string) env('INSTAGRAM_FEED_R2_PUBLIC_BASE_URL', ''), '/'),
        'region' => 'auto',
    ],

    'mirror' => [
        // 单次转存条数。转存是先读进内存再上传，串行处理，不能开太大。
        'batch_size' => (int) env('INSTAGRAM_FEED_MIRROR_BATCH_SIZE', 10),
        // 单个文件上限，超过直接跳过，避免打爆 PHP 内存。
        'max_object_bytes' => (int) env('INSTAGRAM_FEED_MIRROR_MAX_OBJECT_BYTES', 200 * 1024 * 1024),
        // 进程中断会把记录留在 processing，超过这个秒数视为残留并重试。
        'stale_processing_seconds' => (int) env('INSTAGRAM_FEED_MIRROR_STALE_SECONDS', 300),
        // 转存对象内容不会变，可以按 immutable 长缓存。
        'cache_control' => 'public, max-age=31536000, immutable',
    ],

    'gallery' => [
        'max_name_length' => 60,
        // handle 用随机短哈希而非组名派生，改名不影响主题里的引用。
        'handle_bytes' => 4,
    ],
];
