<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { RequestPayload } from '@inertiajs/core';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppCredentialsPanel from '../../Components/InstagramFeed/AppCredentialsPanel.vue';
import type { AppCredentials } from '../../Components/InstagramFeed/AppCredentialsPanel.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Account {
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

interface PageOption {
    page_id: string;
    page_name: string;
    ig_user_id: string;
    ig_username: string | null;
    ig_avatar_url: string | null;
}

interface Gallery {
    id: string;
    name: string;
    handle: string;
    item_count: number;
    previews: string[];
}

interface AppSession {
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

const props = defineProps<{
    organization: { id: number; name: string };
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
    permissions: { connect: boolean; sync: boolean; manageGallery: boolean; publish: boolean };
    credentials: AppCredentials | null;
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/instagram-feed`;

// 页签只影响本页展示，用查询参数记住当前位置，刷新和分享链接都能回到同一处。
type Tab = 'content' | 'credentials';
const initialTab = new URLSearchParams(window.location.search).get('tab');
const tab = ref<Tab>(initialTab === 'credentials' && props.credentials ? 'credentials' : 'content');
const switchTab = (next: Tab) => {
    tab.value = next;
    const url = new URL(window.location.href);
    next === 'content' ? url.searchParams.delete('tab') : url.searchParams.set('tab', next);
    window.history.replaceState({}, '', url);
};
const busy = ref(false);
const nothingConfigured = computed(() => !props.providers.instagram_login && !props.providers.facebook_login);
const usable = computed(() => props.account?.status === 'connected');
const needsPageSelection = computed(() => props.account?.status === 'needs_page_selection');

// 授权在新窗口完成，回调页 postMessage 通知这里刷新数据。
const onAuthMessage = (event: MessageEvent) => {
    if (event.origin !== window.location.origin) return;
    if (event.data?.type !== 'instagram-feed-auth' || !event.data.ok) return;
    router.reload({ only: ['account', 'pageOptions', 'pageOptionsError', 'stats'] });
};
onMounted(() => window.addEventListener('message', onAuthMessage));
onBeforeUnmount(() => window.removeEventListener('message', onAuthMessage));

const csrfToken = () => {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

// 授权链接必须服务端签发（要写一次性 state），所以先取链接再开新窗口。
const connect = async (provider: 'instagram_login' | 'facebook_login') => {
    busy.value = true;
    try {
        const response = await fetch(`${baseUrl}/connect`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ provider }),
        });
        const payload = await response.json().catch(() => null);
        const authorizeUrl = payload?.data?.authorize_url;
        if (!response.ok || typeof authorizeUrl !== 'string') {
            window.alert(payload?.error?.message ?? '无法发起授权，请稍后重试。');
            return;
        }
        window.open(authorizeUrl, 'instagram-feed-auth', 'width=680,height=760');
    } catch {
        window.alert('无法发起授权，请检查网络后重试。');
    } finally {
        busy.value = false;
    }
};

const post = (path: string, data: RequestPayload = {}) =>
    router.post(`${baseUrl}${path}`, data, { preserveScroll: true });

const selectPage = (option: PageOption) => post('/select-page', { page_id: option.page_id });
const sync = () => post('/sync');
const mirror = () => post('/mirror');
const publish = () => post('/publish');
const disconnect = () => {
    if (!window.confirm('断开授权会同时删除已同步的内容和已转存的文件，确认继续？')) return;
    router.delete(`${baseUrl}/account`, { preserveScroll: true });
};

const galleryForm = useForm({ name: '' });
const createGallery = () => galleryForm.post(`${baseUrl}/galleries`, {
    preserveScroll: true,
    onSuccess: () => galleryForm.reset(),
});
const renameGallery = (gallery: Gallery) => {
    const name = window.prompt('输入新的展示组名称：', gallery.name);
    if (!name?.trim() || name.trim() === gallery.name) return;
    router.put(`${baseUrl}/galleries/${gallery.id}`, { name: name.trim() }, { preserveScroll: true });
};
const deleteGallery = (gallery: Gallery) => {
    if (!window.confirm(`删除展示组「${gallery.name}」？组内内容不会被删除，但前台会立刻少掉这一组。`)) return;
    router.delete(`${baseUrl}/galleries/${gallery.id}`, { preserveScroll: true });
};

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '从未');

// Shopify App 自身的授权状态。与下面的 Meta 账号授权是两条独立链路：
// 这条决定能不能把内容发布到店铺前台。
const appSessionMeta = computed(() => ({
    not_authorized: { label: '未授权', tone: 'bg-slate-100 text-slate-600 ring-slate-200', hint: '还没有完成 Shopify 授权，发布到前台不可用。' },
    connected: { label: '已授权', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200', hint: '授权正常，可以发布到店铺前台。' },
    warning: { label: '需关注', tone: 'bg-amber-50 text-amber-700 ring-amber-200', hint: '最近一次调用 Shopify 失败，可先点「检查连接」重试。' },
    invalid: { label: '已失效', tone: 'bg-rose-50 text-rose-700 ring-rose-200', hint: '授权已失效，需要重新授权。' },
    disconnected: { label: '已卸载', tone: 'bg-slate-100 text-slate-600 ring-slate-200', hint: '应用已从该店铺卸载，重新授权即可恢复。' },
}[props.appSession.status]));

const authorizeShopify = () => post('/shopify-authorize');
const verifyShopify = () => post('/shopify-verify');
const statusBadge = computed(() => usable.value
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    : (needsPageSelection.value ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-100 text-slate-600 ring-slate-200'));
const statusLabel = computed(() => usable.value ? '已连接' : (needsPageSelection.value ? '待选择账号' : '未连接'));
</script>

<template>
    <Head title="Instagram Feed" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: 'Instagram Feed' }]">
        <div class="mx-auto max-w-7xl space-y-7">
            <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ organization.name }} · {{ store.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Instagram Feed</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                        连接 Instagram 专业账号，把视频与图片同步到 Cloudflare R2 取得永久地址，按展示组编排后发布到店铺前台。
                    </p>
                </div>
                <div class="flex items-center gap-2 self-start">
                    <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="statusBadge">{{ statusLabel }}</span>
                    <span class="inline-flex rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ environment }}</span>
                </div>
            </section>

            <nav v-if="credentials" class="flex gap-1 border-b border-slate-200" aria-label="Instagram Feed 页签">
                <button
                    type="button"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                    :class="tab === 'content' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    :aria-current="tab === 'content' ? 'page' : undefined"
                    @click="switchTab('content')"
                >
                    内容管理
                </button>
                <button
                    type="button"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                    :class="tab === 'credentials' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    :aria-current="tab === 'credentials' ? 'page' : undefined"
                    @click="switchTab('credentials')"
                >
                    应用配置
                </button>
            </nav>

            <template v-if="tab === 'content'">
            <div v-if="nothingConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
                两种 Meta 授权方式都还没配置。
                <template v-if="credentials">请先在「应用配置」页签补齐 Instagram 或 Facebook 应用凭证，再回到这里连接账号。</template>
                <template v-else>请联系拥有系统设置权限的成员补齐 Instagram 或 Facebook 应用凭证，再回到这里连接账号。</template>
            </div>

            <div v-if="!mirrorConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
                Cloudflare R2 尚未配置，同步只会拉取元数据、不会转存文件。未转存的内容不会发布到前台，因为 Instagram 的原始链接会过期。
                <template v-if="credentials">配置入口在「应用配置」页签。</template>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-950">Shopify 应用授权</h2>
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="appSessionMeta.tone">{{ appSessionMeta.label }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ appSessionMeta.hint }}</p>
                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-xs text-slate-500 sm:grid-cols-2">
                            <div><dt class="inline">授权时间：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(appSession.installed_at) }}</dd></div>
                            <div><dt class="inline">最近校验：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(appSession.last_verified_at) }}</dd></div>
                            <div><dt class="inline">最近检测：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(appSession.last_api_check) }}</dd></div>
                            <div><dt class="inline">最近发布：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(appSession.last_published_at) }}</dd></div>
                            <div v-if="appSession.granted_scopes.length" class="sm:col-span-2">
                                <dt class="inline">已授予权限：</dt><dd class="inline font-medium text-slate-700">{{ appSession.granted_scopes.join('、') }}</dd>
                            </div>
                            <div v-if="appSession.uninstalled_at"><dt class="inline">卸载时间：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(appSession.uninstalled_at) }}</dd></div>
                        </dl>
                    </div>
                    <div v-if="permissions.connect" class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                            @click="authorizeShopify"
                        >
                            {{ appSession.status === 'connected' ? '重新授权' : '授权 Shopify' }}
                        </button>
                        <button
                            v-if="appSession.status !== 'not_authorized'"
                            type="button"
                            class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            @click="verifyShopify"
                        >
                            检查连接
                        </button>
                    </div>
                </div>

                <div v-if="appSession.last_error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    <p class="font-semibold">最近一次失败原因</p>
                    <p class="mt-1 break-words">{{ appSession.last_error }}</p>
                    <p class="mt-1 text-xs text-rose-600">发生时间：{{ formatDate(appSession.last_error_at) }}</p>
                </div>

                <div v-if="appSession.environment && !appSession.environment_matches" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    这条授权记录属于 <span class="font-semibold">{{ appSession.environment }}</span> 环境，当前后端运行在 <span class="font-semibold">{{ environment }}</span>，需要在当前环境重新授权。
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-4">
                        <img
                            v-if="account?.profile_picture_url"
                            :src="account.profile_picture_url"
                            :alt="account.username ?? 'Instagram'"
                            class="size-12 rounded-full object-cover ring-1 ring-slate-200"
                            width="48"
                            height="48"
                        >
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">
                                {{ account?.username ? `@${account.username}` : '尚未连接账号' }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                <template v-if="account">
                                    {{ account.provider_label }}
                                    <template v-if="account.page_name"> · 主页「{{ account.page_name }}」</template>
                                    <template v-if="account.account_type"> · {{ account.account_type }}</template>
                                </template>
                                <template v-else>连接后即可同步该账号公开发布的视频与图片。</template>
                            </p>
                            <dl v-if="account" class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                                <div><dt class="inline">上次同步：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(account.last_synced_at) }}</dd></div>
                                <div><dt class="inline">上次发布：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(account.last_published_at) }}</dd></div>
                                <div v-if="account.token_expires_at"><dt class="inline">令牌到期：</dt><dd class="inline font-medium text-slate-700">{{ formatDate(account.token_expires_at) }}</dd></div>
                            </dl>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="permissions.connect && providers.instagram_login && !usable"
                            type="button"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="busy"
                            @click="connect('instagram_login')"
                        >
                            连接 Instagram 账号
                        </button>
                        <button
                            v-if="permissions.connect && providers.facebook_login && !usable"
                            type="button"
                            class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="busy"
                            @click="connect('facebook_login')"
                        >
                            用 Facebook 主页连接
                        </button>
                        <button
                            v-if="permissions.sync && usable"
                            type="button"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                            @click="sync"
                        >
                            同步内容
                        </button>
                        <button
                            v-if="permissions.sync && usable"
                            type="button"
                            class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            @click="mirror"
                        >
                            推进转存
                        </button>
                        <button
                            v-if="permissions.publish && usable"
                            type="button"
                            class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100"
                            @click="publish"
                        >
                            发布到前台
                        </button>
                        <button
                            v-if="permissions.connect && account"
                            type="button"
                            class="rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50"
                            @click="disconnect"
                        >
                            断开授权
                        </button>
                    </div>
                </div>

                <div v-if="needsPageSelection" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-800">选择要展示的 Instagram 账号</p>
                    <p v-if="pageOptionsError" class="mt-2 text-sm text-amber-700">{{ pageOptionsError }}</p>
                    <ul v-else class="mt-3 space-y-2">
                        <li v-for="option in pageOptions" :key="option.page_id" class="flex items-center justify-between gap-3 rounded-lg bg-white p-3 ring-1 ring-amber-200">
                            <div class="flex items-center gap-3">
                                <img v-if="option.ig_avatar_url" :src="option.ig_avatar_url" :alt="option.ig_username ?? option.page_name" class="size-8 rounded-full object-cover" width="32" height="32">
                                <div>
                                    <p class="text-sm font-medium text-slate-900">@{{ option.ig_username ?? option.page_name }}</p>
                                    <p class="text-xs text-slate-500">主页：{{ option.page_name }}</p>
                                </div>
                            </div>
                            <button
                                v-if="permissions.connect"
                                type="button"
                                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                                @click="selectPage(option)"
                            >
                                使用这个
                            </button>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm font-semibold text-slate-600">已同步</p><p class="mt-2 text-3xl font-semibold text-slate-950">{{ stats.total }}</p></div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><p class="text-sm font-semibold text-emerald-700">已转存</p><p class="mt-2 text-3xl font-semibold text-emerald-950">{{ stats.ready }}</p></div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5"><p class="text-sm font-semibold text-amber-700">待转存</p><p class="mt-2 text-3xl font-semibold text-amber-950">{{ stats.pending + stats.processing }}</p></div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5"><p class="text-sm font-semibold text-rose-700">转存失败</p><p class="mt-2 text-3xl font-semibold text-rose-950">{{ stats.failed }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm font-semibold text-slate-600">展示组</p><p class="mt-2 text-3xl font-semibold text-slate-950">{{ stats.galleries }}</p></div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">展示组</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            每个组对应前台一个区块要展示的内容。主题编辑器里通过组标识指定展示哪一组，改名不会影响前台。
                        </p>
                    </div>
                    <form v-if="permissions.manageGallery" class="flex gap-2" @submit.prevent="createGallery">
                        <input
                            v-model="galleryForm.name"
                            type="text"
                            :maxlength="60"
                            placeholder="新展示组名称"
                            aria-label="新展示组名称"
                            class="w-48 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <button
                            type="submit"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="galleryForm.processing || !galleryForm.name.trim()"
                        >
                            新建
                        </button>
                    </form>
                </div>

                <p v-if="galleries.length === 0" class="mt-6 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                    还没有展示组。先同步内容，再建一个组把要展示的视频挑进去。
                </p>

                <ul v-else class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="gallery in galleries" :key="gallery.id" class="rounded-xl border border-slate-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ gallery.name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ gallery.item_count }} 条内容 · 标识 <code class="rounded bg-slate-100 px-1 py-0.5">{{ gallery.handle }}</code></p>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-1.5">
                            <div
                                v-for="(preview, index) in gallery.previews"
                                :key="index"
                                class="size-14 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200"
                            >
                                <img :src="preview" alt="" class="size-full object-cover" loading="lazy">
                            </div>
                            <div v-if="gallery.previews.length === 0" class="flex h-14 flex-1 items-center rounded-lg bg-slate-50 px-3 text-xs text-slate-400">
                                组内还没有已转存的内容
                            </div>
                        </div>

                        <div v-if="permissions.manageGallery" class="mt-4 flex flex-wrap gap-2">
                            <Link
                                :href="`${baseUrl}/galleries/${gallery.id}`"
                                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                                编辑内容
                            </Link>
                            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="renameGallery(gallery)">
                                重命名
                            </button>
                            <button type="button" class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50" @click="deleteGallery(gallery)">
                                删除
                            </button>
                        </div>
                    </li>
                </ul>
            </section>
            </template>

            <AppCredentialsPanel v-else-if="credentials" :credentials="credentials" :environment="environment" />
        </div>
    </AppLayout>
</template>
