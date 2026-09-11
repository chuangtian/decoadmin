<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ApiError, pickProducts, type ApiClient } from './api';
import PostPreviewModal from './PostPreviewModal.vue';
import type { GalleryDetail, Media } from './types';

// 与后端 updateMediaProducts 的校验上限保持一致。
const MAX_LINKED_PRODUCTS = 20;

// 搜索防抖：媒体库上千条，每敲一个字就打一次接口没必要。
const SEARCH_DEBOUNCE_MS = 400;

const props = defineProps<{
    api: ApiClient;
    galleryId: string;
    busy: boolean;
    capabilities: { connect: boolean; sync: boolean; manageGallery: boolean; publish: boolean };
    run: (action: () => Promise<{ message: string }>, refresh?: () => Promise<void>) => Promise<void>;
}>();

const emit = defineEmits<{ (event: 'back'): void }>();

const detail = ref<GalleryDetail | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);

// 候选一侧的筛选条件。全部在服务端生效 —— 候选一次只回 200 条，
// 前端过滤那 200 条等于搜不到东西。
const filter = ref('all');
const search = ref('');
const from = ref('');
const to = ref('');
const refreshing = ref(false);

// 组内顺序在本地维护，保存时把完整顺序整串提交，服务端只认最终结果。
const order = ref<Media[]>([]);
const selected = ref<Set<string>>(new Set());

// 点击封面预览的帖子。
const preview = ref<Media | null>(null);

const load = async () => {
    refreshing.value = true;
    try {
        const query: Record<string, string> = { filter: filter.value };
        if (search.value.trim() !== '') query.search = search.value.trim();
        if (from.value !== '') query.from = from.value;
        if (to.value !== '') query.to = to.value;

        detail.value = await props.api.get<GalleryDetail>(`/galleries/${props.galleryId}`, query);
        loadError.value = null;
    } catch (error) {
        loadError.value = error instanceof ApiError ? error.message : '读取展示组失败，请稍后重试。';
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
};

let searchTimer: number | undefined;
const scheduleSearch = () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { void load(); }, SEARCH_DEBOUNCE_MS);
};

const applyDates = () => {
    // 起止填反了就地纠正，省得服务端回一个校验错误。
    if (from.value !== '' && to.value !== '' && from.value > to.value) {
        [from.value, to.value] = [to.value, from.value];
    }
    void load();
};

const filtersActive = computed(() => search.value.trim() !== '' || from.value !== '' || to.value !== '' || filter.value !== 'all');
const resetFilters = () => {
    filter.value = 'all';
    search.value = '';
    from.value = '';
    to.value = '';
    void load();
};

// 候选被截断时要让商家知道，否则会以为"就这么多"。
const truncated = computed(() => {
    const data = detail.value;

    return data ? data.matchedCount > data.candidates.length : false;
});

onMounted(load);
onBeforeUnmount(() => window.clearTimeout(searchTimer));
watch(() => detail.value?.members, value => { order.value = [...(value ?? [])]; }, { immediate: true });

const dirty = computed(() => {
    const current = order.value.map(item => item.id).join(',');
    const saved = (detail.value?.members ?? []).map(item => item.id).join(',');

    return current !== saved;
});

const selectedIds = computed(() => [...selected.value]);
const toggle = (id: string) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};

const filterLabel = (value: string) => ({ all: '全部', VIDEO: '视频', IMAGE: '图片' }[value] ?? value);
const applyFilter = (value: string) => {
    filter.value = value;
    void load();
};

const addSelected = () => {
    if (selectedIds.value.length === 0) return;
    void props.run(
        () => props.api.post<{ message: string }>(`/galleries/${props.galleryId}/items`, { media_ids: selectedIds.value }),
        async () => {
            selected.value = new Set();
            await load();
        },
    );
};
const removeItem = (media: Media) => props.run(
    () => props.api.del<{ message: string }>(`/galleries/${props.galleryId}/items`, { media_ids: [media.id] }),
    load,
);
const saveOrder = () => props.run(
    () => props.api.put<{ message: string }>(`/galleries/${props.galleryId}/order`, {
        media_ids: order.value.map(item => item.id),
    }),
    load,
);

const retryMirror = (media: Media) => props.run(
    () => props.api.post<{ message: string }>(`/media/${media.id}/retry-mirror`),
    load,
);

const move = (index: number, delta: number) => {
    const target = index + delta;
    if (target < 0 || target >= order.value.length) return;
    const next = [...order.value];
    [next[index], next[target]] = [next[target], next[index]];
    order.value = next;
};

// 拖拽排序：只记住起点，落点在 dragover 时实时换位，松手即得最终顺序。
const draggingIndex = ref<number | null>(null);
const onDragStart = (index: number) => { draggingIndex.value = index; };
const onDragOver = (index: number) => {
    if (draggingIndex.value === null || draggingIndex.value === index) return;
    const next = [...order.value];
    const [moved] = next.splice(draggingIndex.value, 1);
    next.splice(index, 0, moved);
    order.value = next;
    draggingIndex.value = index;
};
const onDragEnd = () => { draggingIndex.value = null; };

// 关联商品走 Shopify 原生选择器：搜索和分页由 Shopify 负责，我们只提交它回传的 GID。
// 选择器在 run() 里打开，这样"没装 App Bridge"之类的异常能走统一的错误提示。
const editProducts = (media: Media) => props.run(async () => {
    const picked = await pickProducts(
        media.products.map(product => product.id),
        MAX_LINKED_PRODUCTS,
    );
    // 取消：不提交、也不提示。
    if (picked === null) return { message: '' };

    return props.api.put<{ message: string }>(`/media/${media.id}/products`, {
        product_ids: picked.map(product => product.id),
    });
}, load);

const clearProducts = (media: Media) => props.run(
    () => props.api.put<{ message: string }>(`/media/${media.id}/products`, { product_ids: [] }),
    load,
);

const mirrorBadge = (status: string) => ({
    ready: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    processing: 'bg-sky-50 text-sky-700 ring-sky-200',
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
}[status] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
const mirrorLabel = (status: string) => ({ ready: '已转存', processing: '转存中', pending: '待转存', failed: '转存失败' }[status] ?? status);
const typeLabel = (media: Media) => {
    if (media.media_type === 'VIDEO') return media.media_product_type === 'REELS' ? 'Reels' : '视频';

    return media.media_type === 'CAROUSEL_ALBUM' ? '多图' : '图片';
};

// 列表里只需要到天，完整时间在预览弹窗里给。
const postedDate = (value: string | null) => (value
    ? new Date(value).toLocaleDateString('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit' })
    : '');
</script>

<template>
    <p v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
        正在加载展示组…
    </p>

    <div v-else-if="loadError" class="rounded-2xl border border-rose-200 bg-white p-6">
        <p class="text-sm text-slate-600">{{ loadError }}</p>
        <button
            type="button"
            class="mt-4 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            @click="emit('back')"
        >
            返回概览
        </button>
    </div>

    <div v-else-if="detail" class="space-y-5">
        <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ detail.gallery.name }}</h1>
                <p class="mt-2 text-sm text-slate-500">
                    组标识 <code class="rounded bg-slate-100 px-1.5 py-0.5">{{ detail.gallery.handle }}</code>，主题编辑器里用它指定要展示的组。
                    媒体库共 {{ detail.totalCount }} 条。
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
                <button
                    type="button"
                    class="shrink-0 whitespace-nowrap rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="emit('back')"
                >
                    返回概览
                </button>
                <button
                    type="button"
                    class="shrink-0 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-40"
                    :disabled="!dirty || busy"
                    @click="saveOrder"
                >
                    保存顺序
                </button>
            </div>
        </section>

        <div v-if="detail.productError" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            {{ detail.productError }}
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <h2 class="text-lg font-semibold text-slate-950">
                        未加入（{{ detail.matchedCount }}）
                    </h2>
                    <div class="flex shrink-0 gap-1">
                        <button
                            v-for="value in detail.filters"
                            :key="value"
                            type="button"
                            class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold ring-1"
                            :class="value === detail.filter ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'"
                            @click="applyFilter(value)"
                        >
                            {{ filterLabel(value) }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <label class="block">
                        <span class="sr-only">搜索文案</span>
                        <input
                            v-model="search"
                            type="search"
                            :maxlength="100"
                            placeholder="搜索文案关键词"
                            class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                            @input="scheduleSearch"
                            @keydown.enter.prevent="load"
                        >
                    </label>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex shrink-0 items-center gap-1.5 text-xs text-slate-500">
                            <span class="whitespace-nowrap">发布日期</span>
                            <input
                                v-model="from"
                                type="date"
                                aria-label="起始日期"
                                class="w-36 shrink-0 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                                @change="applyDates"
                            >
                        </label>
                        <span class="shrink-0 text-xs text-slate-400">至</span>
                        <input
                            v-model="to"
                            type="date"
                            aria-label="结束日期"
                            class="w-36 shrink-0 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                            @change="applyDates"
                        >
                        <button
                            v-if="filtersActive"
                            type="button"
                            class="shrink-0 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                            @click="resetFilters"
                        >
                            清除筛选
                        </button>
                        <span v-if="refreshing" class="shrink-0 whitespace-nowrap text-xs text-slate-400">正在筛选…</span>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <p class="text-sm text-slate-500">勾选后加入，加入顺序即勾选顺序。点封面可预览帖子。</p>
                    <button
                        type="button"
                        class="shrink-0 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-40"
                        :disabled="selectedIds.length === 0 || busy"
                        @click="addSelected"
                    >
                        加入 {{ selectedIds.length || '' }}
                    </button>
                </div>

                <p v-if="truncated" class="mt-3 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-800">
                    符合条件的有 {{ detail.matchedCount }} 条，这里按发布时间从新到旧只显示前
                    {{ detail.candidateLimit }} 条。用搜索或日期范围继续收窄可以看到更早的内容。
                </p>

                <p v-if="detail.candidates.length === 0" class="mt-5 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                    当前筛选下没有可加入的内容。
                </p>

                <ul v-else class="mt-5 grid max-h-[32rem] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                    <li
                        v-for="media in detail.candidates"
                        :key="media.id"
                        class="flex gap-3 rounded-xl border p-3 transition"
                        :class="selected.has(media.id) ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:border-slate-300'"
                    >
                        <label class="flex min-w-0 flex-1 cursor-pointer gap-3">
                            <input
                                type="checkbox"
                                class="mt-1 size-4 shrink-0 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                :checked="selected.has(media.id)"
                                @change="toggle(media.id)"
                            >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ typeLabel(media) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1" :class="mirrorBadge(media.mirror_status)">{{ mirrorLabel(media.mirror_status) }}</span>
                                </div>
                                <p class="mt-1 text-xs tabular-nums text-slate-400">{{ postedDate(media.posted_at) }}</p>
                                <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ media.caption || '无文案' }}</p>
                            </div>
                        </label>
                        <!-- 封面单独做成按钮：点它是预览帖子，点卡片其余部分才是勾选。 -->
                        <button
                            type="button"
                            class="group relative size-16 shrink-0 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-transparent hover:ring-slate-900"
                            :aria-label="`预览帖子${media.caption ? '：' + media.caption.slice(0, 40) : ''}`"
                            @click="preview = media"
                        >
                            <img v-if="media.preview_url" :src="media.preview_url" alt="" class="size-full object-cover" loading="lazy">
                            <span class="absolute inset-0 hidden items-center justify-center bg-slate-950/45 text-xs font-semibold text-white group-hover:flex">
                                预览
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-slate-950">组内（{{ order.length }}）</h2>
                <p class="mt-1 text-sm text-slate-500">
                    拖拽或用上下按钮调整前台顺序，改完点「保存顺序」，保存后会自动同步到店铺前台。
                    只有已转存完成的内容会出现在前台。
                </p>

                <p v-if="order.length === 0" class="mt-5 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                    组内还没有内容，从左侧勾选加入。
                </p>

                <ol v-else class="mt-5 max-h-[32rem] space-y-3 overflow-y-auto pr-1">
                    <li
                        v-for="(media, index) in order"
                        :key="media.id"
                        draggable="true"
                        class="flex gap-3 rounded-xl border border-slate-200 p-3"
                        :class="draggingIndex === index ? 'opacity-60' : ''"
                        @dragstart="onDragStart(index)"
                        @dragover.prevent="onDragOver(index)"
                        @dragend="onDragEnd"
                    >
                        <div class="flex flex-col items-center gap-1">
                            <span class="text-xs font-semibold text-slate-400">{{ index + 1 }}</span>
                            <button
                                type="button"
                                class="rounded border border-slate-300 px-1.5 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-30"
                                :disabled="index === 0"
                                aria-label="上移"
                                @click="move(index, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                class="rounded border border-slate-300 px-1.5 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-30"
                                :disabled="index === order.length - 1"
                                aria-label="下移"
                                @click="move(index, 1)"
                            >
                                ↓
                            </button>
                        </div>
                        <button
                            type="button"
                            class="group relative size-16 shrink-0 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-transparent hover:ring-slate-900"
                            :aria-label="`预览帖子${media.caption ? '：' + media.caption.slice(0, 40) : ''}`"
                            @click="preview = media"
                        >
                            <img v-if="media.preview_url" :src="media.preview_url" alt="" class="size-full object-cover" loading="lazy">
                            <span class="absolute inset-0 hidden items-center justify-center bg-slate-950/45 text-xs font-semibold text-white group-hover:flex">
                                预览
                            </span>
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ typeLabel(media) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1" :class="mirrorBadge(media.mirror_status)">{{ mirrorLabel(media.mirror_status) }}</span>
                                <span class="whitespace-nowrap text-xs tabular-nums text-slate-400">{{ postedDate(media.posted_at) }}</span>
                                <a :href="media.permalink" target="_blank" rel="noopener nofollow" class="text-xs font-semibold text-slate-500 underline hover:text-slate-700">原帖</a>
                            </div>
                            <p class="mt-1.5 line-clamp-2 text-xs text-slate-500">{{ media.caption || '无文案' }}</p>
                            <p v-if="media.mirror_error" class="mt-1 line-clamp-2 text-xs text-rose-600">{{ media.mirror_error }}</p>
                            <ul v-if="media.products.length" class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <li
                                    v-for="product in media.products"
                                    :key="product.id"
                                    class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-full bg-slate-100 py-0.5 pe-2 ps-0.5 text-xs text-slate-700"
                                >
                                    <span class="size-5 shrink-0 overflow-hidden rounded-full bg-white ring-1 ring-slate-200">
                                        <img
                                            v-if="product.image_url"
                                            :src="product.image_url"
                                            :alt="product.image_alt ?? ''"
                                            class="size-full object-cover"
                                            loading="lazy"
                                        >
                                    </span>
                                    <span class="truncate">{{ product.title }}</span>
                                </li>
                            </ul>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    class="shrink-0 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                                    :disabled="busy"
                                    @click="editProducts(media)"
                                >
                                    {{ media.products.length ? '修改关联商品' : '关联商品' }}
                                </button>
                                <button
                                    v-if="media.products.length"
                                    type="button"
                                    class="shrink-0 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                                    :disabled="busy"
                                    @click="clearProducts(media)"
                                >
                                    清除关联
                                </button>
                                <button
                                    v-if="media.mirror_status === 'failed'"
                                    type="button"
                                    class="shrink-0 whitespace-nowrap rounded-lg border border-amber-300 px-2.5 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                                    :disabled="busy"
                                    @click="retryMirror(media)"
                                >
                                    重试转存
                                </button>
                                <button
                                    type="button"
                                    class="shrink-0 whitespace-nowrap rounded-lg border border-rose-300 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                                    :disabled="busy"
                                    @click="removeItem(media)"
                                >
                                    移出
                                </button>
                            </div>
                        </div>
                    </li>
                </ol>
            </section>
        </div>

        <PostPreviewModal :media="preview" @close="preview = null" />
    </div>
</template>
