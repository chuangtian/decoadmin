<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { ApiClient } from './api';
import type { MirrorFailure, Overview, PageOption } from './types';

const props = defineProps<{
    api: ApiClient;
    overview: Overview;
    busy: boolean;
    run: (action: () => Promise<{ message: string }>, refresh?: () => Promise<void>) => Promise<void>;
    refresh: () => Promise<void>;
}>();

const emit = defineEmits<{
    (event: 'open-gallery', id: string): void;
    (event: 'open-settings'): void;
}>();

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

// 记住当前在跑哪个动作，好让对应按钮显示进行中文案。
// busy 是全局的（同一时刻只允许一个写操作），这里只补"是哪一个"。
const activeAction = ref<string | null>(null);
const perform = (name: string, action: () => Promise<{ message: string }>, refresh?: () => Promise<void>) => {
    activeAction.value = name;

    return props.run(action, refresh).finally(() => { activeAction.value = null; });
};

const selectPage = (option: PageOption) => perform(
    'select-page',
    () => props.api.post<{ message: string }>('/account/select-page', { page_id: option.page_id }),
    props.refresh,
);
const sync = () => perform('sync', () => props.api.post<{ message: string }>('/sync'), props.refresh);
const mirror = () => perform('mirror', () => props.api.post<{ message: string }>('/mirror'), props.refresh);

/**
 * 转存失败日志。
 *
 * 只在商家展开时才拉，避免每次刷新概览都带一串失败记录。
 */
const failures = ref<MirrorFailure[] | null>(null);
const failuresOpen = ref(false);
const loadingFailures = ref(false);

const loadFailures = async () => {
    loadingFailures.value = true;
    try {
        const data = await props.api.get<{ failures: MirrorFailure[] }>('/mirror-failures');
        failures.value = data.failures;
    } catch {
        failures.value = [];
    } finally {
        loadingFailures.value = false;
    }
};

const toggleFailures = () => {
    failuresOpen.value = !failuresOpen.value;
    if (failuresOpen.value && failures.value === null) void loadFailures();
};

const retryOne = (media: MirrorFailure) => perform(
    `retry-${media.id}`,
    () => props.api.post<{ message: string }>(`/media/${media.id}/retry-mirror`),
    async () => { await props.refresh(); await loadFailures(); },
);
const retryAll = () => perform(
    'retry-all',
    () => props.api.post<{ message: string }>('/mirror-failures/retry'),
    async () => { await props.refresh(); await loadFailures(); },
);
const storefrontSync = () => perform(
    'storefront-sync',
    () => props.api.post<{ message: string }>('/storefront-sync'),
    props.refresh,
);

// 主题编辑器里要填组标识，这里给一键复制，省得手抄。
const copiedHandle = ref<string | null>(null);
const copyHandle = async (handle: string) => {
    try {
        await navigator.clipboard.writeText(handle);
        copiedHandle.value = handle;
        window.setTimeout(() => {
            if (copiedHandle.value === handle) copiedHandle.value = null;
        }, 2000);
    } catch {
        // iframe 里剪贴板权限可能被拒，这时让商家手动选中复制。
        window.prompt('复制这个展示组标识：', handle);
    }
};

/**
 * 转存进度。
 *
 * 同步分两段：拉元数据很快，把视频转存到永久地址很慢。所以"进度"说的是转存那一段，
 * 数据直接来自 stats，不需要额外接口。
 */
const progress = computed(() => {
    const { total, ready, pending, processing, failed } = props.overview.stats;
    const outstanding = pending + processing;

    return {
        total,
        ready,
        outstanding,
        failed,
        // 失败的也算"处理完了"，否则有失败项时进度条永远停在 99%。
        percent: total > 0 ? Math.round(((ready + failed) / total) * 100) : 0,
        done: total > 0 && outstanding === 0,
    };
});

/**
 * 自动推进转存。
 *
 * 后端一次只转存一批（默认 10 条），媒体多时要推很多轮。让页面在打开期间自动推进，
 * 商家不用反复点按钮，进度条也才会真的动起来。
 *
 * 间隔 6 秒 = 10 次/分钟，低于 /mirror 的 12 次/分钟限流；出错就停，避免撞限流后继续打。
 */
const autoAdvance = ref(true);
let mirrorTimer: number | undefined;

const stopAdvancing = () => {
    window.clearInterval(mirrorTimer);
    mirrorTimer = undefined;
};

const advanceOnce = async () => {
    // 有其它写操作在跑时跳过这一轮，避免和商家的点击抢同一个 busy 锁。
    if (props.busy) return;
    try {
        await props.api.post<{ message: string }>('/mirror');
        await props.refresh();
    } catch {
        // 失败原因交给商家手动点「推进转存」时呈现，这里只停掉自动推进。
        autoAdvance.value = false;
        stopAdvancing();
    }
};

const syncAutoAdvance = () => {
    const shouldRun = autoAdvance.value
        && props.overview.mirrorConfigured
        && progress.value.outstanding > 0;

    if (shouldRun && mirrorTimer === undefined) {
        mirrorTimer = window.setInterval(advanceOnce, 6000);
    } else if (!shouldRun && mirrorTimer !== undefined) {
        stopAdvancing();
    }
};

watch(
    () => [autoAdvance.value, props.overview.mirrorConfigured, progress.value.outstanding],
    syncAutoAdvance,
    { immediate: true },
);
onBeforeUnmount(stopAdvancing);

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
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Instagram 内容</h1>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <span
                        class="inline-flex shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
                        :class="statusBadge"
                    >
                        {{ statusLabel }}
                    </span>
                    <span
                        class="inline-flex shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
                        :class="appSessionMeta.tone"
                    >
                        Shopify {{ appSessionMeta.label }}
                    </span>
                </div>
            </div>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                连接 Instagram 专业账号，把视频与图片转存到永久地址，按展示组编排后自动同步到店铺前台。
            </p>
            <p class="mt-2 text-xs text-slate-400">{{ overview.store.shopify_domain }}</p>
        </section>

        <div v-if="nothingConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <p class="text-sm text-amber-800">
                还没有可用的授权方式。先去「应用配置」填上 Instagram 或 Facebook 应用凭证，再回到这里连接账号。
            </p>
            <button
                type="button"
                class="mt-3 shrink-0 whitespace-nowrap rounded-xl border border-amber-300 bg-white px-3.5 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
                @click="emit('open-settings')"
            >
                去应用配置
            </button>
        </div>

        <div v-if="!overview.mirrorConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <p class="text-sm text-amber-800">
                存储尚未配置，同步只会拉取元数据、不会转存文件。未转存的内容不会展示到前台，因为 Instagram 的原始链接会过期。
            </p>
            <button
                type="button"
                class="mt-3 shrink-0 whitespace-nowrap rounded-xl border border-amber-300 bg-white px-3.5 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
                @click="emit('open-settings')"
            >
                去配置存储
            </button>
        </div>

        <div v-if="overview.appSession.last_error" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <p class="font-semibold">最近一次与 Shopify 通信失败</p>
            <p class="mt-1 break-words">{{ overview.appSession.last_error }}</p>
            <p class="mt-1 text-xs text-rose-600">发生时间：{{ formatDate(overview.appSession.last_error_at) }}</p>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 items-start gap-4">
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
                        <dl v-if="account" class="mt-3 grid gap-x-6 gap-y-1 text-xs text-slate-500 sm:grid-cols-2 xl:grid-cols-3">
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
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <button
                        v-if="capabilities.connect && overview.providers.instagram_login && !usable"
                        type="button"
                        class="shrink-0 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                        @click="connect('instagram_login')"
                    >
                        连接 Instagram 账号
                    </button>
                    <button
                        v-if="capabilities.connect && overview.providers.facebook_login && !usable"
                        type="button"
                        class="shrink-0 whitespace-nowrap rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="connect('facebook_login')"
                    >
                        用 Facebook 主页连接
                    </button>
                    <button
                        v-if="capabilities.sync && usable"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                        @click="sync"
                    >
                        <span
                            v-if="activeAction === 'sync'"
                            class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white"
                            aria-hidden="true"
                        />
                        {{ activeAction === 'sync' ? '正在拉取内容…' : '同步内容' }}
                    </button>
                    <button
                        v-if="capabilities.sync && usable"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="mirror"
                    >
                        <span
                            v-if="activeAction === 'mirror'"
                            class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700"
                            aria-hidden="true"
                        />
                        {{ activeAction === 'mirror' ? '正在转存…' : '推进转存' }}
                    </button>

                    <button
                        v-if="capabilities.connect && account"
                        type="button"
                        class="shrink-0 whitespace-nowrap rounded-xl border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
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
                            class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="busy"
                            @click="selectPage(option)"
                        >
                            <span
                                v-if="activeAction === 'select-page'"
                                class="size-3 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                aria-hidden="true"
                            />
                            使用这个
                        </button>
                    </li>
                </ul>
            </div>
        </section>

        <section v-if="overview.stats.total > 0" class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">转存进度</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        <template v-if="progress.done">
                            全部 {{ progress.total }} 条内容已处理完，可以编排展示组并发布到前台。
                        </template>
                        <template v-else>
                            正在把视频与封面转存到永久地址。Instagram 的原始链接会过期，只有转存完成的内容才会发布到前台。
                        </template>
                    </p>
                </div>
                <p class="text-3xl font-semibold tabular-nums text-slate-950">{{ progress.percent }}%</p>
            </div>

            <div
                class="mt-4 h-2.5 w-full overflow-hidden rounded-full bg-slate-100"
                role="progressbar"
                :aria-valuenow="progress.percent"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="转存进度"
            >
                <div
                    class="h-full rounded-full transition-all duration-500"
                    :class="progress.failed > 0 ? 'bg-amber-500' : 'bg-emerald-500'"
                    :style="{ width: `${progress.percent}%` }"
                />
            </div>

            <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4 lg:grid-cols-5">
                <div class="min-w-0">
                    <dt class="truncate text-slate-500">已同步</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-slate-900">{{ progress.total }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="truncate text-slate-500">已转存</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-emerald-700">{{ progress.ready }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="truncate text-slate-500">待转存</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-amber-700">{{ progress.outstanding }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="truncate text-slate-500">失败</dt>
                    <dd
                        class="mt-0.5 text-xl font-semibold tabular-nums"
                        :class="progress.failed > 0 ? 'text-rose-700' : 'text-slate-400'"
                    >
                        {{ progress.failed }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="truncate text-slate-500">展示组</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-slate-900">{{ overview.stats.galleries }}</dd>
                </div>
            </dl>

            <div
                v-if="progress.outstanding > 0 && overview.mirrorConfigured"
                class="mt-5 flex flex-col gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <label class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-sm text-slate-600">
                    <input
                        v-model="autoAdvance"
                        type="checkbox"
                        class="size-4 shrink-0 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                    >
                    停留在本页时自动继续转存
                </label>
                <span
                    v-if="autoAdvance"
                    class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-sm font-medium text-emerald-700"
                >
                    <span class="size-2 shrink-0 animate-pulse rounded-full bg-emerald-500" />
                    进行中，剩余 {{ progress.outstanding }} 条
                </span>
                <span v-else class="text-sm text-slate-500">
                    已暂停。可点「推进转存」手动推进，或等后台每 10 分钟处理一批。
                </span>
            </div>

            <div v-if="progress.failed > 0" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                    <p class="text-sm font-semibold text-amber-900">有 {{ progress.failed }} 条转存失败</p>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="whitespace-nowrap rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-50"
                            :aria-expanded="failuresOpen"
                            @click="toggleFailures"
                        >
                            {{ failuresOpen ? '收起失败日志' : '查看失败日志' }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 disabled:opacity-50"
                            :disabled="busy"
                            @click="retryAll"
                        >
                            <span
                                v-if="activeAction === 'retry-all'"
                                class="size-3 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                aria-hidden="true"
                            />
                            全部重试
                        </button>
                    </div>
                </div>

                <div v-if="failuresOpen" class="mt-4">
                    <p v-if="loadingFailures" class="text-sm text-amber-800">正在读取失败日志…</p>

                    <p v-else-if="failures && failures.length === 0" class="text-sm text-amber-800">
                        暂无失败记录，可能刚刚已经重试成功。
                    </p>

                    <ul v-else-if="failures" class="space-y-3">
                        <li
                            v-for="item in failures"
                            :key="item.id"
                            class="flex gap-3 rounded-xl border border-amber-200 bg-white p-3"
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
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                        :disabled="busy"
                                        @click="retryOne(item)"
                                    >
                                        <span
                                            v-if="activeAction === `retry-${item.id}`"
                                            class="size-3 shrink-0 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700"
                                            aria-hidden="true"
                                        />
                                        重试这一条
                                    </button>
                                    <a
                                        :href="item.permalink"
                                        target="_blank"
                                        rel="noopener nofollow"
                                        class="shrink-0 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        查看原帖
                                    </a>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <h2 class="text-lg font-semibold text-slate-950">展示组</h2>
                    <form
                        v-if="capabilities.manageGallery"
                        class="flex shrink-0 items-center gap-2"
                        @submit.prevent="createGallery"
                    >
                        <input
                            v-model="newGalleryName"
                            type="text"
                            :maxlength="60"
                            placeholder="新展示组名称"
                            aria-label="新展示组名称"
                            class="w-44 min-w-0 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <button
                            type="submit"
                            class="shrink-0 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="busy || !newGalleryName.trim()"
                        >
                            新建
                        </button>
                    </form>
                </div>
                <p class="max-w-3xl text-sm leading-6 text-slate-500">
                    建好组、把内容挑进去就完成了，<span class="font-semibold text-slate-700">不需要额外发布</span>：内容一变就会自动同步到店铺前台。
                    之后去「在线商店 → 主题 → 自定义」添加本应用的区块，填入组标识即可展示。
                </p>
            </div>

            <p v-if="overview.galleries.length === 0" class="mt-6 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                还没有展示组。先同步内容，再建一个组把要展示的视频挑进去。
            </p>

            <template v-else>
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                <span>前台数据在内容变化时自动同步。如果店铺前台没跟上，可以手动同步一次。</span>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1 font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="busy"
                    @click="storefrontSync"
                >
                    <span
                        v-if="activeAction === 'storefront-sync'"
                        class="size-3 shrink-0 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700"
                        aria-hidden="true"
                    />
                    {{ activeAction === 'storefront-sync' ? '同步中…' : '手动同步前台' }}
                </button>
            </div>

            <ul class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="gallery in overview.galleries" :key="gallery.id" class="rounded-xl border border-slate-200 p-4">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ gallery.name }}</p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs text-slate-500">
                            <span class="whitespace-nowrap">{{ gallery.item_count }} 条内容</span>
                            <code class="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 font-mono">{{ gallery.handle }}</code>
                            <button
                                type="button"
                                class="shrink-0 whitespace-nowrap rounded border border-slate-300 px-1.5 py-0.5 font-semibold text-slate-600 hover:bg-slate-50"
                                @click="copyHandle(gallery.handle)"
                            >
                                {{ copiedHandle === gallery.handle ? '已复制' : '复制标识' }}
                            </button>
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

                    <div v-if="capabilities.manageGallery" class="mt-4 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="shrink-0 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                            @click="emit('open-gallery', gallery.id)"
                        >
                            编辑内容
                        </button>
                        <button
                            type="button"
                            class="shrink-0 whitespace-nowrap rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="busy"
                            @click="renameGallery(gallery.id, gallery.name)"
                        >
                            重命名
                        </button>
                        <button
                            type="button"
                            class="shrink-0 whitespace-nowrap rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                            :disabled="busy"
                            @click="deleteGallery(gallery.id, gallery.name)"
                        >
                            删除
                        </button>
                    </div>
                </li>
            </ul>
            </template>
        </section>
    </div>
</template>
