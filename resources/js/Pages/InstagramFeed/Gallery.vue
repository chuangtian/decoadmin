<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface LinkedProduct {
    id: string;
    title: string;
    handle: string;
    image_url: string | null;
    image_alt: string | null;
}

interface Media {
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

const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string };
    gallery: { id: string; name: string; handle: string };
    members: Media[];
    candidates: Media[];
    totalCount: number;
    filter: string;
    filters: string[];
    productError: string | null;
    permissions: { publish: boolean };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/instagram-feed`;
const galleryUrl = `${baseUrl}/galleries/${props.gallery.id}`;

// 组内顺序在本地维护，保存时把完整顺序整串提交，服务端只认最终结果。
const order = ref<Media[]>([...props.members]);
watch(() => props.members, (value) => { order.value = [...value]; });
const dirty = computed(() => order.value.map(item => item.id).join(',') !== props.members.map(item => item.id).join(','));

const selected = ref<Set<string>>(new Set());
const toggle = (id: string) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};
const selectedIds = computed(() => [...selected.value]);

const filterLabel = (value: string) => ({ all: '全部', VIDEO: '视频', IMAGE: '图片' }[value] ?? value);
const applyFilter = (value: string) => router.get(galleryUrl, { filter: value }, { preserveScroll: true, preserveState: true });

const addSelected = () => {
    if (selectedIds.value.length === 0) return;
    router.post(`${galleryUrl}/items`, { media_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = new Set(); },
    });
};
const removeItem = (media: Media) => router.delete(`${galleryUrl}/items`, {
    data: { media_ids: [media.id] },
    preserveScroll: true,
});
const saveOrder = () => router.put(`${galleryUrl}/order`, { media_ids: order.value.map(item => item.id) }, { preserveScroll: true });
const publish = () => router.post(`${baseUrl}/publish`, {}, { preserveScroll: true });

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

const editProducts = (media: Media) => {
    const current = media.products.map(product => product.id).join('\n');
    const input = window.prompt(
        '每行一个商品 GID，例如 gid://shopify/Product/123456。留空表示清除关联。',
        current,
    );
    if (input === null) return;
    const productIds = input.split(/[\n,]/).map(value => value.trim()).filter(Boolean);
    router.put(`${baseUrl}/media/${media.id}/products`, { product_ids: productIds }, { preserveScroll: true });
};
const retryMirror = (media: Media) => router.post(`${baseUrl}/media/${media.id}/retry-mirror`, {}, { preserveScroll: true });

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
</script>

<template>
    <Head :title="`${gallery.name} · Instagram Feed`" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: 'Instagram Feed', href: baseUrl }, { label: gallery.name }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ organization.name }} · {{ store.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">{{ gallery.name }}</h1>
                    <p class="mt-2 text-sm text-slate-500">
                        组标识 <code class="rounded bg-slate-100 px-1.5 py-0.5">{{ gallery.handle }}</code>，主题编辑器里用它指定要展示的组。
                        媒体库共 {{ totalCount }} 条。
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 self-start">
                    <Link :href="baseUrl" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        返回概览
                    </Link>
                    <button
                        type="button"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-40"
                        :disabled="!dirty"
                        @click="saveOrder"
                    >
                        保存顺序
                    </button>
                    <button
                        v-if="permissions.publish"
                        type="button"
                        class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100"
                        @click="publish"
                    >
                        发布到前台
                    </button>
                </div>
            </section>

            <div v-if="productError" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                {{ productError }}
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-slate-950">未加入（{{ candidates.length }}）</h2>
                        <div class="flex gap-1">
                            <button
                                v-for="value in filters"
                                :key="value"
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1"
                                :class="value === filter ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'"
                                @click="applyFilter(value)"
                            >
                                {{ filterLabel(value) }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <p class="text-sm text-slate-500">勾选后加入，加入顺序即勾选顺序。</p>
                        <button
                            type="button"
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-40"
                            :disabled="selectedIds.length === 0"
                            @click="addSelected"
                        >
                            加入 {{ selectedIds.length || '' }}
                        </button>
                    </div>

                    <p v-if="candidates.length === 0" class="mt-5 rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                        当前筛选下没有可加入的内容。
                    </p>

                    <ul v-else class="mt-5 grid max-h-[32rem] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                        <li v-for="media in candidates" :key="media.id">
                            <label class="flex cursor-pointer gap-3 rounded-xl border p-3 transition" :class="selected.has(media.id) ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:border-slate-300'">
                                <input
                                    type="checkbox"
                                    class="mt-1 size-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                    :checked="selected.has(media.id)"
                                    @change="toggle(media.id)"
                                >
                                <div class="size-16 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                    <img v-if="media.preview_url" :src="media.preview_url" alt="" class="size-full object-cover" loading="lazy">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{{ typeLabel(media) }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="mirrorBadge(media.mirror_status)">{{ mirrorLabel(media.mirror_status) }}</span>
                                    </div>
                                    <p class="mt-1.5 line-clamp-2 text-xs text-slate-500">{{ media.caption || '无文案' }}</p>
                                </div>
                            </label>
                        </li>
                    </ul>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="text-lg font-semibold text-slate-950">组内（{{ order.length }}）</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        拖拽或用上下按钮调整前台顺序，改完点「保存顺序」。只有已转存的内容会发布到前台。
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
                                <button type="button" class="rounded border border-slate-300 px-1.5 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-30" :disabled="index === 0" aria-label="上移" @click="move(index, -1)">↑</button>
                                <button type="button" class="rounded border border-slate-300 px-1.5 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-30" :disabled="index === order.length - 1" aria-label="下移" @click="move(index, 1)">↓</button>
                            </div>
                            <div class="size-16 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                <img v-if="media.preview_url" :src="media.preview_url" alt="" class="size-full object-cover" loading="lazy">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{{ typeLabel(media) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="mirrorBadge(media.mirror_status)">{{ mirrorLabel(media.mirror_status) }}</span>
                                    <a :href="media.permalink" target="_blank" rel="noopener nofollow" class="text-[11px] font-semibold text-slate-500 underline hover:text-slate-700">原帖</a>
                                </div>
                                <p class="mt-1.5 line-clamp-2 text-xs text-slate-500">{{ media.caption || '无文案' }}</p>
                                <p v-if="media.mirror_error" class="mt-1 line-clamp-2 text-xs text-rose-600">{{ media.mirror_error }}</p>
                                <p v-if="media.products.length" class="mt-1.5 text-xs text-slate-600">
                                    关联商品：{{ media.products.map(product => product.title).join('、') }}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <button type="button" class="rounded-lg border border-slate-300 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50" @click="editProducts(media)">关联商品</button>
                                    <button v-if="media.mirror_status === 'failed'" type="button" class="rounded-lg border border-amber-300 px-2.5 py-1 text-[11px] font-semibold text-amber-700 hover:bg-amber-50" @click="retryMirror(media)">重试转存</button>
                                    <button type="button" class="rounded-lg border border-rose-300 px-2.5 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50" @click="removeItem(media)">移出</button>
                                </div>
                            </div>
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
