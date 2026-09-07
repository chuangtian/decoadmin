export interface MenuItem {
    name: string;
    route?: string;
    icon: string;
    section?: string;
    sectionDivider?: boolean;
    permission?: string;
    children?: MenuItem[];
    dynamicChildren?: 'applications';
    comingSoon?: boolean;
    hidden?: boolean;
}

export const menu: MenuItem[] = [
    {
        name: '工作台',
        icon: 'dashboard',
        section: '店铺运营',
        children: [
            { name: '概览', route: '/dashboard', icon: 'dashboard', permission: 'organization.view' },
            { name: '活动主题', route: '/campaign-themes', icon: 'campaign', permission: 'reports.view' },
        ],
    },
    {
        name: '业务中心',
        icon: 'business',
        children: [
            { name: '订单管理', route: '/orders', icon: 'orders', permission: 'orders.view' },
            { name: '商品管理', route: '/products', icon: 'products', permission: 'products.view' },
            { name: '客户管理', route: '/customers', icon: 'customers', permission: 'customers.view' },
            { name: '库存管理', route: '/inventory', icon: 'inventory', permission: 'inventory.view' },
            { name: '地点管理', route: '/locations', icon: 'stores', permission: 'inventory.view' },
            { name: '车型素材', route: '/model-assets', icon: 'products', permission: 'products.view' },
            { name: '折扣管理', route: '/discounts', icon: 'campaign', permission: 'discounts.view' },
        ],
    },
    {
        name: '应用中心',
        icon: 'app-center',
        // children 是平台内置应用的固定入口，dynamicChildren 追加当前店铺已安装的应用。
        // 两者按最终路由去重，所以内置应用装好之后不会出现两条。
        // 保留固定入口的原因：应用中心的动态条目要求 app_installations 有 active 记录，
        // 店铺还没装 App 时就没有任何入口，用户也就无从进去完成连接。
        dynamicChildren: 'applications',
        children: [
            { name: 'Instagram Feed', route: '/instagram-feed', icon: 'apps', permission: 'instagram_feed.view' },
            { name: '买家秀评价', route: '/community-reviews', icon: 'apps', permission: 'apps.view' },
        ],
    },
    {
        name: '数据分析',
        icon: 'analytics',
        children: [
            { name: '经营分析', route: '/analytics/overview', icon: 'analytics', permission: 'orders.view' },
            { name: '销售分析', route: '/analytics/sales', icon: 'analytics', permission: 'orders.view' },
            { name: '车型销量汇总', route: '/analytics/model-sales', icon: 'analytics', permission: 'reports.view' },
            { name: '渠道与转化', route: '/business/insights', icon: 'analytics', permission: 'reports.view' },
            { name: '报告', route: '/reports', icon: 'reports', permission: 'reports.view' },
            { name: '实时视图', route: '/analytics/live', icon: 'analytics', permission: 'orders.view' },
        ],
    },
    {
        name: '付费广告',
        icon: 'paid-advertising',
        children: [
            { name: '广告目标', route: '/paid-advertising/goals', icon: 'analytics', permission: 'reports.view' },
            { name: 'Facebook Ads', route: '/paid-advertising/facebook', icon: 'analytics', permission: 'reports.view' },
            { name: 'Google Ads', route: '/paid-advertising/google', icon: 'analytics', permission: 'reports.view' },
            { name: 'TikTok Ads', route: '/paid-advertising/tiktok', icon: 'analytics', permission: 'reports.view' },
            { name: 'Bing Ads', route: '/paid-advertising/bing', icon: 'analytics', permission: 'reports.view' },
            { name: 'Criteo', route: '/paid-advertising/criteo', icon: 'analytics', permission: 'reports.view' },
        ],
    },
    {
        name: '自然流量',
        icon: 'analytics',
        children: [
            { name: 'SEO / GEO', route: '/natural-traffic/seo-geo', icon: 'analytics', permission: 'reports.view' },
            { name: '品牌官媒', route: '/natural-traffic/brand-media', icon: 'analytics', permission: 'reports.view' },
            { name: '红人运营', route: '/natural-traffic/influencer-operations', icon: 'analytics', permission: 'reports.view' },
            { name: 'EDM 邮件', route: '/natural-traffic/edm-email', icon: 'analytics', permission: 'reports.view' },
            { name: '联盟营销', route: '/natural-traffic/affiliate-marketing', icon: 'analytics', permission: 'reports.view' },
        ],
    },
    {
        name: '舆情监控',
        icon: 'reports',
        children: [
            { name: '舆情总览', route: '/reputation/overview', icon: 'analytics', permission: 'reports.view' },
            { name: '风险同步', route: '/reputation/risks', icon: 'status', permission: 'reports.view' },
        ],
    },
    {
        name: '店铺设置',
        icon: 'settings',
        children: [
            { name: '店铺状态', route: '/store-settings/status', icon: 'status', permission: 'store.view' },
            // Instagram Feed 属于应用中心，入口挂在上面的「应用中心」分组，这里不重复挂。
            { name: '飞书设置', route: '/store-settings/feishu', icon: 'settings', permission: 'store.view' },
            { name: '邮箱设置', route: '/store-settings/mail', icon: 'settings', permission: 'store.view' },
            { name: '业务凭证', route: '/store-settings/credentials', icon: 'settings', permission: 'store.view' },
        ],
    },
    {
        name: '通知中心',
        route: '/notifications',
        icon: 'notifications',
        permission: 'alerts.view',
    },
    {
        name: 'Shopify',
        icon: 'shopify',
        section: '系统',
        sectionDivider: true,
        children: [
            { name: '店铺管理', route: '/stores', icon: 'stores', permission: 'store.view' },
            { name: '店铺对比', route: '/analytics/stores', icon: 'stores', permission: 'store.view' },
            { name: '应用管理', route: '/apps', icon: 'apps', permission: 'apps.view' },
            { name: '数据同步', route: '/sync', icon: 'sync', permission: 'sync.view' },
            { name: 'Webhook', route: '/webhooks', icon: 'webhooks', permission: 'webhooks.view' },
            { name: '异常告警', route: '/alerts', icon: 'status', permission: 'alerts.view' },
            { name: 'API 状态', route: '/system/api-status', icon: 'status', permission: 'system.health.view', comingSoon: true },
        ],
    },
    {
        name: '用户管理',
        route: '/users',
        icon: 'users',
        permission: 'users.view',
    },
    {
        name: '公司财务',
        route: '/finance',
        icon: 'finance',
        permission: 'finance.view',
    },
    {
        name: '协作管理',
        icon: 'collaboration',
        hidden: true,
        children: [
            { name: '用户管理', route: '/users', icon: 'users', permission: 'users.view' },
            { name: '团队成员', route: '/team', icon: 'team', permission: 'users.view', comingSoon: true },
        ],
    },
    {
        name: '系统管理',
        icon: 'system',
        children: [
            { name: 'Codex 插件授权', route: '/codex-tokens', icon: 'apps', permission: 'codex.tokens.view' },
            { name: '角色权限', route: '/roles', icon: 'roles', permission: 'roles.view' },
            { name: '审计日志', route: '/audit-logs', icon: 'audit', permission: 'audit.view' },
            { name: '系统状态', route: '/system/status', icon: 'status', permission: 'system.health.view' },
            { name: '飞书设置', route: '/settings/feishu', icon: 'settings', permission: 'system.settings.view' },
            { name: '邮箱设置', route: '/settings/mail', icon: 'settings', permission: 'system.settings.view' },
            { name: '系统设置', route: '/settings', icon: 'settings', permission: 'system.settings.view' },
        ],
    },
];
