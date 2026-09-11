<script setup lang="ts">
/**
 * Instagram Feed 后台页面，只读。
 *
 * 商家的操作全部在 Shopify App 内嵌页完成（内容管理 + 应用配置），这一页是给运营看
 * 运行状况的：Shopify 连接是否正常、素材转存到哪一步、有哪些展示组。
 * 刻意没有任何按钮或表单 —— 后端也没有对应的写路由。
 *
 * 字段结构来自 InstagramFeedPresenter，与 resources/js/embedded/instagram-feed/types.ts
 * 是同一份契约的子集，presenter 一改两边都要跟着改。
 */
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
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

interface MirrorFailure {
    id: string;
    media_type: string;
    caption: string | null;
    permalink: string;
    preview_url: string | null;
    posted_at: string | null;
    failed_at: string | null;
    reason: string;
    detail: string;
}

const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string; shopify_domain: string };
    environment: string;
    providers: { instagram_login: boolean; facebook_login: boolean };
    account: Account | null;
    stats: { total: number; ready: number; processing: number; pending: number; failed: number; galleries: number };
    galleries: Gallery[];
    mirrorConfigured: boolean;
    appSessionReady: boolean;
    appSession: AppSession;
    mirrorFailures: MirrorFailure[];
}>();

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '从未');

const usable = computed(() => props.account?.status === 'connected');
const needsPageSelection = computed(() => props.account?.status === 'needs_page_selection');
const nothingConfigured = computed(() => !props.providers.instagram_login && !props.providers.facebook_login);

// Shopify 连接状态。安装与会话都由 Shopify 侧驱动（托管安装 + session token
// 换 offline token），后台不提供人工授权入口，这里只做状态呈现与排障线索。
const appSessionMeta = computed(() => ({
    not_authorized: { label: '未连接', tone: 'bg-slate-100 text-slate-600 ring-slate-200', hint: '商家还没有在 Shopify 后台打开过本应用，会话尚未建立。' },
    connected: { label: '已连接', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200', hint: '连接正常，展示组内容会自动同步到店铺前台。' },
    warning: { label: '需关注', tone: 'bg-amber-50 text-amber-700 ring-amber-200', hint: '最近一次调用 Shopify 失败，商家下次打开应用会自动重试。' },
    invalid: { label: '已失效', tone: 'bg-rose-50 text-rose-700 ring-rose-200', hint: '凭证已失效，商家重新打开应用即可自动重建会话。' },
    disconnected: { label: '已卸载', tone: 'bg-slate-100 text-slate-600 ring-slate-200', hint: '应用已从该店铺卸载，重新安装即可恢复。' },
}[props.appSession.status]));

const statusBadge = computed(() => (usable.value
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    : (needsPageSelection.value ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-100 text-slate-600 ring-slate-200')));
const statusLabel = computed(() => (usable.value ? '已连接' : (needsPageSelection.value ? '待选择账号' : '未连接')));

const outstanding = computed(() => props.stats.pending + props.stats.processing);
</script>

<template>
    <Head title="Instagram Feed" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: 'Instagram Feed' }]">
        <div class="mx-auto max-w-7xl space-y-7">
            <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-emerald-700">{{ organization.name }} · {{ store.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Instagram Feed</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                        本页只读。账号连接、内容同步、转存推进、展示组编排和应用配置都由商家在 Shopify 后台的应用里完成。
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
                    <span
                        class="inline-flex shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
                        :class="statusBadge"
                    >
                        {{ statusLabel }}
                    </span>
                    <span class="inline-flex shrink-0 whitespace-nowrap rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                        {{ environment }}
                    </span>
                </div>
            </section>

            <div v-if="nothingConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-800">
                本店铺还没有可用的 Meta 授权方式。商家需要在 Shopify 应用的「应用配置」页签里填好 Instagram 或 Facebook 应用凭证，才能连接账号。
            </div>

            <div v-if="!mirrorConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-800">
                本店铺还没有配置 Cloudflare R2，同步只会拉取元数据、不会转存文件。未转存的内容不会展示到前台，因为 Instagram 的原始链接会过期。
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h2 class="text-lg font-semibold text-slate-950">Shopify 连接状态</h2>
                    <span
                        class="inline-flex shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1"
                        :class="appSessionMeta.tone"
                    >
                        {{ appSessionMeta.label }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ appSessionMeta.hint }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ store.shopify_domain }}</p>

                <dl class="mt-4 grid gap-x-6 gap-y-1.5 text-xs text-slate-500 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="min-w-0">
                        <dt class="inline">首次连接：</dt>
                        <dd class="inline font-medium text-slate-700">{{ formatDate(appSession.installed_at) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="inline">最近校验：</dt>
                        <dd class="inline font-medium text-slate-700">{{ formatDate(appSession.last_verified_at) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="inline">最近检测：</dt>
                        <dd class="inline font-medium text-slate-700">{{ formatDate(appSession.last_api_check) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="inline">最近同步到前台：</dt>
                        <dd class="inline font-medium text-slate-700">{{ formatDate(appSession.last_published_at) }}</dd>
                    </div>
                    <div v-if="appSession.granted_scopes.length" class="min-w-0 sm:col-span-2 xl:col-span-4">
                        <dt class="inline">已授予权限：</dt>
                        <dd class="inline font-medium text-slate-700">{{ appSession.granted_scopes.join('、') }}</dd>
                    </div>
                    <div v-if="appSession.uninstalled_at" class="min-w-0">
                        <dt class="inline">卸载时间：</dt>
                        <dd class="inline font-medium text-slate-700">{{ formatDate(appSession.uninstalled_at) }}</dd>
                    </div>
                </dl>

                <div v-if="appSession.last_error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    <p class="font-semibold">最近一次失败原因</p>
                    <p class="mt-1 break-words">{{ appSession.last_error }}</p>
                    <p class="mt-1 text-xs text-rose-600">发生时间：{{ formatDate(appSession.last_error_at) }}</p>
                </div>

                <div
                    v-if="appSession.environment && !appSession.environment_matches"
                    class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800"
                >
                    这条连接记录属于 <span class="font-semibold">{{ appSession.environment }}</span> 环境，当前后端运行在
                    <span class="font-semibold">{{ environment }}</span>，商家在当前环境打开一次应用即会自动重建。
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-slate-950">Instagram 账号</h2>
                <div class="mt-4 flex items-start gap-4">
                    <img
                        v-if="account?.profile_picture_url"
                        :src="account.profile_picture_url"
                        :alt="account.username ?? 'Instagram'"
                        class="size-12 shrink-0 rounded-full object-cover ring-1 ring-slate-200"
                        width="48"
                        height="48"
                    >
                    <div class="min-w-0">
                        <p class="text-base font-semibold text-slate-950">
                            {{ account?.username ? `@${account.username}` : '尚未连接账号' }}
                        </p>
                        <p class="mt-1 text-sm text-slate-500">
                            <template v-if="account">
                                {{ account.provider_label }}
                                <template v-if="account.page_name"> · 主页「{{ account.page_name }}」</template>
                                <template v-if="account.account_type"> · {{ account.account_type }}</template>
                            </template>
                            <template v-else>商家在 Shopify 应用里连接账号后，这里会显示账号信息。</template>
                        </p>
                        <dl v-if="account" class="mt-3 grid gap-x-6 gap-y-1.5 text-xs text-slate-500 sm:grid-cols-2 xl:grid-cols-3">
                            <div class="min-w-0">
                                <dt class="inline">上次同步：</dt>
                                <dd class="inline font-medium text-slate-700">{{ formatDate(account.last_synced_at) }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="inline">上次同步到前台：</dt>
                                <dd class="inline font-medium text-slate-700">{{ formatDate(account.last_published_at) }}</dd>
                            </div>
                            <div v-if="account.token_expires_at" class="min-w-0">
                                <dt class="inline">令牌到期：</dt>
                                <dd class="inline font-medium text-slate-700">{{ formatDate(account.token_expires_at) }}</dd>
                            </div>
                        </dl>
                        <p v-if="needsPageSelection" class="mt-3 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-800">
                            该账号关联了多个 Facebook 主页，商家需要在 Shopify 应用里选定要展示的那一个。
                        </p>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-slate-950">转存素材</h2>
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <dt class="truncate text-sm font-semibold text-slate-600">已同步</dt>
                        <dd class="mt-2 text-3xl font-semibold tabular-nums text-slate-950">{{ stats.total }}</dd>
                    </div>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                        <dt class="truncate text-sm font-semibold text-emerald-700">已转存</dt>
                        <dd class="mt-2 text-3xl font-semibold tabular-nums text-emerald-950">{{ stats.ready }}</dd>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                        <dt class="truncate text-sm font-semibold text-amber-700">待转存</dt>
                        <dd class="mt-2 text-3xl font-semibold tabular-nums text-amber-950">{{ outstanding }}</dd>
                    </div>
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5">
                        <dt class="truncate text-sm font-semibold text-rose-700">转存失败</dt>
                        <dd class="mt-2 text-3xl font-semibold tabular-nums text-rose-950">{{ stats.failed }}</dd>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <dt class="truncate text-sm font-semibold text-slate-600">展示组</dt>
                        <dd class="mt-2 text-3xl font-semibold tabular-nums text-slate-950">{{ stats.galleries }}</dd>
                    </div>
                </dl>

                <div v-if="mirrorFailures.length" class="mt-5 rounded-2xl border border-amber-200 bg-white p-6">
                    <h3 class="text-base font-semibold text-slate-950">最近的转存失败（{{ mirrorFailures.length }} 条）</h3>
                    <p class="mt-1 text-sm text-slate-500">重试入口在 Shopify 应用里，这里只列原因供排查。</p>
                    <ul class="mt-4 space-y-3">
                        <li
                            v-for="item in mirrorFailures"
                            :key="item.id"
                            class="flex gap-3 rounded-xl border border-slate-200 p-3"
                        >
                            <div class="size-14 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                <img v-if="item.preview_url" :src="item.preview_url" alt="" class="size-full object-cover" loading="lazy">
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-900">{{ item.reason }}</p>
                                <p v-if="item.detail" class="mt-1 break-words text-xs text-slate-500">{{ item.detail }}</p>
                                <p class="mt-1 text-xs text-slate-400">
                                    失败时间 {{ formatDate(item.failed_at) }}
                                    <template v-if="item.caption"> · {{ item.caption }}</template>
                                </p>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-slate-950">展示区</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                    每个展示组对应前台一个区块的内容。商家在主题编辑器里用组标识指定展示哪一组，改名不影响前台。
                </p>

                <p v-if="galleries.length === 0" class="mt-5 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                    还没有展示组。商家在 Shopify 应用里同步内容后即可创建。
                </p>

                <ul v-else class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="gallery in galleries" :key="gallery.id" class="rounded-xl border border-slate-200 p-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ gallery.name }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs text-slate-500">
                                <span class="whitespace-nowrap">{{ gallery.item_count }} 条内容</span>
                                <code class="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 font-mono">{{ gallery.handle }}</code>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-1.5">
                            <div
                                v-for="(preview, index) in gallery.previews"
                                :key="index"
                                class="size-14 shrink-0 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200"
                            >
                                <img :src="preview" alt="" class="size-full object-cover" loading="lazy">
                            </div>
                            <div
                                v-if="gallery.previews.length === 0"
                                class="flex h-14 flex-1 items-center rounded-lg bg-slate-50 px-3 text-xs text-slate-400"
                            >
                                组内还没有已转存的内容
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
