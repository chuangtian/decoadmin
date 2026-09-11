// 与后端 InstagramFeedPresenter 的输出一一对应。
// DecoAdmin 后台的 InstagramFeed 页面只读同一个 presenter 的一个子集（连接状态、
// 转存统计、展示组），所以 presenter 字段一改，这里和后台页面都要跟着改。

export interface Account {
    provider: string;
    provider_label: string;
    status: 'connected' | 'needs_page_selection';
    username: string | null;
    account_type: string | null;
    profile_picture_url: string | null;
    page_name: string | null;
    token_expires_at: string | null;
    last_synced_at: string | null;
    last_published_at: string | null;
}

export interface PageOption {
    page_id: string;
    page_name: string;
    ig_user_id: string;
    ig_username: string | null;
    ig_avatar_url: string | null;
}

export interface Gallery {
    id: string;
    name: string;
    handle: string;
    item_count: number;
    previews: string[];
}

export interface AppSession {
    status: 'not_authorized' | 'connected' | 'warning' | 'invalid' | 'disconnected';
    usable: boolean;
    environment: string | null;
    environment_matches: boolean;
    app_installation_id: string | null;
    granted_scopes: string[];
    installed_at: string | null;
    uninstalled_at: string | null;
    last_verified_at: string | null;
    last_api_check: string | null;
    last_published_at: string | null;
    last_error: string | null;
    last_error_at: string | null;
}

export interface Overview {
    store: { id: number; name: string; shopify_domain: string };
    environment: string;
    providers: { instagram_login: boolean; facebook_login: boolean };
    account: Account | null;
    pageOptions: PageOption[];
    pageOptionsError: string | null;
    stats: { total: number; ready: number; processing: number; pending: number; failed: number; galleries: number };
    galleries: Gallery[];
    mirrorConfigured: boolean;
    appSessionReady: boolean;
    appSession: AppSession;
    capabilities: { connect: boolean; sync: boolean; manageGallery: boolean; publish: boolean };
}

export interface LinkedProduct {
    id: string;
    title: string;
    handle: string;
    image_url: string | null;
    image_alt: string | null;
}

export interface Media {
    id: string;
    media_type: string;
    media_product_type: string | null;
    caption: string | null;
    permalink: string;
    preview_url: string | null;
    /** Instagram 官方 embed 地址，点击封面时嵌进弹窗。脏数据时为 null。 */
    embed_url: string | null;
    mirror_status: 'pending' | 'processing' | 'ready' | 'failed';
    mirror_error: string | null;
    posted_at: string | null;
    like_count: number | null;
    comments_count: number | null;
    products: LinkedProduct[];
}

export interface MirrorFailure {
    id: string;
    media_type: string;
    caption: string | null;
    permalink: string;
    preview_url: string | null;
    posted_at: string | null;
    failed_at: string | null;
    /** 归纳出的可行动说明 */
    reason: string;
    /** 脱敏后的原始信息，排查用 */
    detail: string;
}

export interface GalleryDetail {
    gallery: { id: string; name: string; handle: string };
    members: Media[];
    candidates: Media[];
    totalCount: number;
    filter: string;
    filters: string[];
    /** 服务端实际生效的筛选条件，用来回填输入框。 */
    search: string;
    from: string | null;
    to: string | null;
    /** 满足筛选条件的总条数；大于 candidateLimit 说明候选被截断了。 */
    matchedCount: number;
    candidateLimit: number;
    productError: string | null;
}

// 「应用配置」页签。与后端 InstagramFeedStoreCredentials::forFrontend() 一一对应。
// 密钥字段永远回空字符串，是否已配置只看 *_configured。

export interface MetaSettings {
    instagram_app_id: string;
    instagram_app_secret: string;
    facebook_app_id: string;
    facebook_app_secret: string;
    facebook_login_config_id: string;
    instagram_app_secret_configured: boolean;
    facebook_app_secret_configured: boolean;
}

export interface R2Settings {
    account_id: string;
    access_key_id: string;
    secret_access_key: string;
    bucket: string;
    public_base_url: string;
    secret_access_key_configured: boolean;
}

export interface StoreSettings {
    meta: MetaSettings;
    r2: R2Settings;
    /** 环境级回调地址，所有店铺共用，要原样填进各自的 Meta 应用。 */
    callbacks: { instagram: string; facebook: string };
    /** store = 本店铺自己配的；platform = 在用 DecoAdmin 的平台默认值。 */
    source: { meta: 'store' | 'platform'; r2: 'store' | 'platform' };
}
