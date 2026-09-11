export const brandProfileSections = [
    { key: 'overview', name: '总览', route: '/brand-profile/overview', icon: 'dashboard', description: '查看当前品牌的基本资料。' },
    { key: 'login-emails', name: '登录邮箱', route: '/brand-profile/login-emails', icon: 'mail', description: '集中查看品牌相关平台的登录邮箱资料。' },
    { key: 'seo-accounts', name: 'SEO账号密码', route: '/brand-profile/seo-accounts', icon: 'permissions', description: '集中查看品牌 SEO 平台的账号资料。' },
    { key: 'plugins', name: '插件', route: '/brand-profile/plugins', icon: 'apps', description: '集中查看品牌使用的插件资料。' },
    { key: 'business-licenses', name: '营业执照', route: '/brand-profile/business-licenses', icon: 'audit', description: '集中查看品牌主体的营业执照资料。' },
] as const;

export type BrandProfileSection = typeof brandProfileSections[number]['key'];
