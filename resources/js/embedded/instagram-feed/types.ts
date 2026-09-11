// 与后端 InstagramFeedPresenter 的输出一一对应。
// 这里的结构必须与 resources/js/Pages/InstagramFeed 下的后台页面保持一致：
// 两边读的是同一个 presenter，字段一改要同时改。

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
    video_url: string | null;
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
    productError: string | null;
}
