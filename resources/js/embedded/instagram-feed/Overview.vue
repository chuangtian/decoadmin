<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ApiClient } from './api';
import type { Overview, PageOption } from './types';

const props = defineProps<{
    api: ApiClient;
    overview: Overview;
    busy: boolean;
    run: (action: () => Promise<{ message: string }>, refresh?: () => Promise<void>) => Promise<void>;
    refresh: () => Promise<void>;
}>();

const emit = defineEmits<{ (event: 'open-gallery', id: string): void }>();

const newGalleryName = ref('');

const account = computed(() => props.overview.account);
const capabilities = computed(() => props.overview.capabilities);
const nothingConfigured = computed(() => !props.overview.providers.instagram_login && !props.overview.providers.facebook_login);
const usable = computed(() => account.value?.status === 'connected');
const needsPageSelection = computed(() => account.value?.status === 'needs_page_selection');

const statusBadge = computed(() => usable.value
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    : (needsPageSelection.value ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-100 text-slate-600 ring-slate-200'));
const statusLabel = computed(() => (usable.value ? '已连接' : (needsPageSelection.value ? '待选择账号' : '未连接')));

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '从未');

// 授权链接必须服务端签发（要写一次性 state），所以先取链接再开新窗口。
// iframe 内不能直接跳转顶层窗口，弹窗是这里唯一可行的方式。
const connect = (provider: 'instagram_login' | 'facebook_login') => props.run(async () => {
    const data = await props.api.post<{ authorize_url: string }>('/account/authorize', { provider });
    window.open(data.authorize_url, 'instagram-feed-auth', 'width=680,height=760');

    return { message: '已打开授权窗口，完成后回到这里即可。' };
});

const selectPage = (option: PageOption) => props.run(
    () => props.api.post<{ message: string }>('/account/select-page', { page_id: option.page_id }),
    props.refresh,
);
const sync = () => props.run(() => props.api.post<{ message: string }>('/sync'), props.refresh);
const mirror = () => props.run(() => props.api.post<{ message: string }>('/mirror'), props.refresh);
const publish = () => props.run(() => props.api.post<{ message: string }>('/publish'), props.refresh);

const disconnect = () => {
    if (!window.confirm('断开授权会同时删除已同步的内容和已转存的文件，确认继续？')) return;
    void props.run(() => props.api.del<{ message: string }>('/account'), props.refresh);
};

const createGallery = () => {
    const name = newGalleryName.value.trim();
    if (!name) return;
    void props.run(
        () => props.api.post<{ message: string }>('/galleries', { name }),
        async () => {
            newGalleryName.value = '';
            await props.refresh();
        },
    );
};
const renameGallery = (id: string, current: string) => {
    const name = window.prompt('输入新的展示组名称：', current);
    if (!name?.trim() || name.trim() === current) return;
    void props.run(() => props.api.put<{ message: string }>(`/galleries/${id}`, { name: name.trim() }), props.refresh);
};
const deleteGallery = (id: string, name: string) => {
    if (!window.confirm(`删除展示组「${name}」？组内内容不会被删除，但前台会立刻少掉这一组。`)) return;
    void props.run(() => props.api.del<{ message: string }>(`/galleries/${id}`), props.refresh);
};

// Shopify 连接状态。这里只展示、不提供授权按钮：会话由后端在请求时用 session token
// 自动建立，商家不需要做任何授权动作。
const appSessionMeta = computed(() => ({
    not_authorized: { label: '准备中', tone: 'bg-slate-100 text-slate-600 ring-slate-200' },
    connected: { label: '已连接', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    warning: { label: '需关注', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
    invalid: { label: '已失效', tone: 'bg-rose-50 text-rose-700 ring-rose-200' },
    disconnected: { label: '已卸载', tone: 'bg-slate-100 text-slate-600 ring-slate-200' },
}[props.overview.appSession.status]));
</script>

<template>
    <div class="space-y-5">
        <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Instagram 内容</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                    连接 Instagram 专业账号，把视频与图片转存到永久地址，按展示组编排后发布到店铺前台。
                </p>
                <p class="mt-2 text-xs text-slate-400">{{ overview.store.shopify_domain }}</p>
            </div>
            <div class="flex items-center gap-2 self-start">
                <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="statusBadge">{{ statusLabel }}</span>
                <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="appSessionMeta.tone">Shopify {{ appSessionMeta.label }}</span>
            </div>
        </section>

        <div v-if="nothingConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
            还没有可用的授权方式，请先在「配置」里补齐 Instagram 或 Facebook 应用凭证，再回到这里连接账号。
        </div>

        <div v-if="!overview.mirrorConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
            存储尚未配置，同步只会拉取元数据、不会转存文件。未转存的内容不会发布到前台，因为 Instagram 的原始链接会过期。
        </div>

        <div v-if="overview.appSession.last_error" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <p class="font-semibold">最近一次与 Shopify 通信失败</p>
            <p class="mt-1 break-words">{{ overview.appSession.last_error }}</p>
            <p class="mt-1 text-xs text-rose-600">发生时间：{{ formatDate(overview.appSession.last_error_at) }}</p>
        </div>

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
                        v-if="capabilities.connect && overview.providers.instagram_login && !usable"
                        type="button"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                        @click="connect('instagram_login')"
                    >
                        连接 Instagram 账号
                    </button>
                    <button
                        v-if="capabilities.connect && overview.providers.facebook_login && !usable"
                        type="button"
                        class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="connect('facebook_login')"
                    >
                        用 Facebook 主页连接
                    </button>
                    <button
                        v-if="capabilities.sync && usable"
                        type="button"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                        @click="sync"
                    >
                        同步内容
                    </button>
                    <button
                        v-if="capabilities.sync && usable"
                        type="button"
                        class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="mirror"
                    >
                        推进转存
                    </button>
                    <button
                        v-if="capabilities.publish && usable"
                        type="button"
                        class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"
                        :disabled="busy"
                        @click="publish"
                    >
                        发布到前台
                    </button>
                    <button
                        v-if="capabilities.connect && account"
                        type="button"
                        class="rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="disconnect"
                    >
                        断开授权
                    </button>
                </div>
            </div>

            <div v-if="needsPageSelection" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-800">选择要展示的 Instagram 账号</p>
                <p v-if="overview.pageOptionsError" class="mt-2 text-sm text-amber-700">{{ overview.pageOptionsError }}</p>
                <ul v-else class="mt-3 space-y-2">
                    <li
                        v-for="option in overview.pageOptions"
                        :key="option.page_id"
                        class="flex items-center justify-between gap-3 rounded-lg bg-white p-3 ring-1 ring-amber-200"
                    >
                        <div class="flex items-center gap-3">
                            <img
                                v-if="option.ig_avatar_url"
                                :src="option.ig_avatar_url"
                                :alt="option.ig_username ?? option.page_name"
                                class="size-8 rounded-full object-cover"
                                width="32"
                                height="32"
                            >
                            <div>
                                <p class="text-sm font-medium text-slate-900">@{{ option.ig_username ?? option.page_name }}</p>
                                <p class="text-xs text-slate-500">主页：{{ option.page_name }}</p>
                            </div>
                        </div>
                        <button
                            v-if="capabilities.connect"
                            type="button"
                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="busy"
                            @click="selectPage(option)"
                        >
                            使用这个
                        </button>
                    </li>
                </ul>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-sm font-semibold text-slate-600">已同步</p>
                <p class="mt-2 text-3xl font-semibold text-slate-950">{{ overview.stats.total }}</p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-sm font-semibold text-emerald-700">已转存</p>
                <p class="mt-2 text-3xl font-semibold text-emerald-950">{{ overview.stats.ready }}</p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <p class="text-sm font-semibold text-amber-700">待转存</p>
                <p class="mt-2 text-3xl font-semibold text-amber-950">{{ overview.stats.pending + overview.stats.processing }}</p>
            </div>
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5">
                <p class="text-sm font-semibold text-rose-700">转存失败</p>
                <p class="mt-2 text-3xl font-semibold text-rose-950">{{ overview.stats.failed }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-sm font-semibold text-slate-600">展示组</p>
                <p class="mt-2 text-3xl font-semibold text-slate-950">{{ overview.stats.galleries }}</p>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">展示组</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        每个组对应前台一个区块要展示的内容。主题编辑器里通过组标识指定展示哪一组，改名不会影响前台。
                    </p>
                </div>
                <form v-if="capabilities.manageGallery" class="flex gap-2" @submit.prevent="createGallery">
                    <input
                        v-model="newGalleryName"
                        type="text"
                        :maxlength="60"
                        placeholder="新展示组名称"
                        aria-label="新展示组名称"
                        class="w-48 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                    >
                    <button
                        type="submit"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy || !newGalleryName.trim()"
                    >
                        新建
                    </button>
                </form>
            </div>

            <p v-if="overview.galleries.length === 0" class="mt-6 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                还没有展示组。先同步内容，再建一个组把要展示的视频挑进去。
            </p>

            <ul v-else class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="gallery in overview.galleries" :key="gallery.id" class="rounded-xl border border-slate-200 p-4">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ gallery.name }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ gallery.item_count }} 条内容 · 标识 <code class="rounded bg-slate-100 px-1 py-0.5">{{ gallery.handle }}</code>
                        </p>
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

                    <div v-if="capabilities.manageGallery" class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                            @click="emit('open-gallery', gallery.id)"
                        >
                            编辑内容
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="busy"
                            @click="renameGallery(gallery.id, gallery.name)"
                        >
                            重命名
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                            :disabled="busy"
                            @click="deleteGallery(gallery.id, gallery.name)"
                        >
                            删除
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
