<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

export interface MetaAdsCreativeItem {
    id: string;
    ad_id: string;
    creative_id: string;
    account_name: string;
    campaign_id: string | null;
    campaign_name: string;
    ad_name: string;
    title: string;
    body: string | null;
    format: string;
    image_url: string | null;
    thumbnail_url: string | null;
    spend: number;
    revenue: number;
    purchases: number;
    impressions: number;
    clicks: number;
    roas: number | null;
    ctr: number | null;
}

const props = defineProps<{
    items: MetaAdsCreativeItem[];
    loading: boolean;
    page: number;
    lastPage: number;
    total: number;
    currency: string;
}>();

const emit = defineEmits<{ pageChange: [page: number] }>();
const selected = ref<MetaAdsCreativeItem | null>(null);
const brokenThumbnails = ref<Record<string, boolean>>({});
const modalImageBroken = ref(false);

function imageUrl(item: MetaAdsCreativeItem): string | null {
    const value = item.image_url || item.thumbnail_url;
    return value && /^https?:\/\//i.test(value) ? value : null;
}

function open(item: MetaAdsCreativeItem): void {
    selected.value = item;
    modalImageBroken.value = false;
}

function close(): void {
    selected.value = null;
    modalImageBroken.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && selected.value) close();
}

function money(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: /^[A-Z]{3}$/.test(props.currency) ? props.currency : 'USD',
        maximumFractionDigits: 2,
    }).format(value);
}

function integer(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function purchases(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value);
}

function multiple(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(2)}×`;
}

function percentage(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(2)}%`;
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-live="polite">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
            <div>
                <h2 class="font-semibold text-slate-950">优秀广告素材</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">按所选账户和统计日期筛选，并按 ROAS 从高到低排列。</p>
            </div>
            <p class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">共 {{ integer(total) }} 条</p>
        </header>

        <div v-if="loading" class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
            <div v-for="index in 6" :key="index" class="overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                <div class="aspect-[16/9] animate-pulse bg-slate-200" />
                <div class="space-y-3 p-4"><div class="h-4 w-4/5 animate-pulse rounded bg-slate-200" /><div class="h-3 w-2/5 animate-pulse rounded bg-slate-200" /><div class="h-3 w-3/5 animate-pulse rounded bg-slate-200" /></div>
            </div>
        </div>

        <div v-else-if="items.length" class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
            <article v-for="item in items" :key="item.id" class="group overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-lg">
                <button type="button" class="block w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-inset" :aria-label="`查看素材：${item.title}`" @click="open(item)">
                    <div class="relative aspect-[16/9] overflow-hidden bg-slate-100">
                        <img
                            v-if="imageUrl(item) && !brokenThumbnails[item.id]"
                            :src="imageUrl(item) || undefined"
                            :alt="item.title"
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                            loading="lazy"
                            decoding="async"
                            referrerpolicy="no-referrer"
                            @error="brokenThumbnails[item.id] = true"
                        />
                        <div v-else class="flex h-full items-center justify-center bg-gradient-to-br from-slate-100 to-slate-200 text-sm font-medium text-slate-400">素材预览暂不可用</div>
                        <span class="absolute left-3 top-3 rounded-full bg-slate-950/75 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur">{{ item.format }}</span>
                    </div>
                    <div class="p-4">
                        <h3 class="line-clamp-1 font-semibold text-slate-900" :title="item.title">{{ item.title }}</h3>
                        <p class="mt-1 line-clamp-1 text-xs text-slate-400" :title="item.ad_name">{{ item.ad_name }}</p>
                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm font-semibold">
                            <span class="text-emerald-600">ROAS {{ multiple(item.roas) }}</span>
                            <span class="text-blue-600">CTR {{ percentage(item.ctr) }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">花费 {{ money(item.spend) }} · 购买 {{ purchases(item.purchases) }}</p>
                    </div>
                </button>
            </article>
        </div>

        <div v-else class="flex min-h-80 flex-col items-center justify-center px-6 py-12 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m21 15-5-5L5 20"/></svg>
            </div>
            <p class="mt-4 font-semibold text-slate-800">所选时间范围暂无优秀素材</p>
            <p class="mt-1 text-sm text-slate-500">可调整广告账户或统计日期后重试。</p>
        </div>

        <footer v-if="lastPage > 1" class="flex items-center justify-center gap-3 border-t border-slate-100 px-5 py-4">
            <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || page <= 1" @click="emit('pageChange', page - 1)">上一页</button>
            <span class="text-sm font-medium text-slate-500">{{ page }} / {{ lastPage }}</span>
            <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || page >= lastPage" @click="emit('pageChange', page + 1)">下一页</button>
        </footer>
    </section>

    <Teleport to="body">
        <div v-if="selected" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm sm:p-6" @click.self="close">
            <section role="dialog" aria-modal="true" aria-labelledby="creative-detail-title" class="max-h-[94vh] w-full max-w-5xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                <header class="sticky top-0 z-10 flex items-start justify-between gap-5 border-b border-slate-100 bg-white/95 px-5 py-4 backdrop-blur sm:px-7">
                    <div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">广告素材详情</p><h2 id="creative-detail-title" class="mt-1 truncate text-lg font-semibold text-slate-950">{{ selected.title }}</h2></div>
                    <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="关闭素材详情" @click="close"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
                </header>

                <div class="space-y-6 p-5 sm:p-7">
                    <div class="overflow-hidden rounded-2xl bg-slate-100">
                        <img v-if="imageUrl(selected) && !modalImageBroken" :src="imageUrl(selected) || undefined" :alt="selected.title" class="mx-auto max-h-[58vh] w-full object-contain" decoding="async" referrerpolicy="no-referrer" @error="modalImageBroken = true" />
                        <div v-else class="flex aspect-[16/9] items-center justify-center text-sm font-medium text-slate-400">素材预览暂不可用</div>
                    </div>

                    <dl class="grid gap-x-8 gap-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-400">广告账户</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ selected.account_name }}</dd></div>
                        <div><dt class="text-slate-400">广告系列</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ selected.campaign_name }}</dd></div>
                        <div><dt class="text-slate-400">广告名称</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ selected.ad_name }}</dd></div>
                        <div><dt class="text-slate-400">素材格式</dt><dd class="mt-1 font-medium text-slate-800">{{ selected.format }}</dd></div>
                    </dl>

                    <div>
                        <h3 class="font-semibold text-slate-900">广告文案</h3>
                        <p class="mt-2 whitespace-pre-wrap rounded-2xl bg-slate-50 p-5 text-sm leading-7 text-slate-600">{{ selected.body || '暂无广告文案' }}</p>
                    </div>

                    <div>
                        <h3 class="font-semibold text-slate-900">投放数据</h3>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">花费</dt><dd class="mt-2 font-semibold text-slate-900">{{ money(selected.spend) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">ROAS</dt><dd class="mt-2 font-semibold text-emerald-600">{{ multiple(selected.roas) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">CTR</dt><dd class="mt-2 font-semibold text-blue-600">{{ percentage(selected.ctr) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">购买数</dt><dd class="mt-2 font-semibold text-slate-900">{{ purchases(selected.purchases) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">曝光量</dt><dd class="mt-2 font-semibold text-slate-900">{{ integer(selected.impressions) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">点击量</dt><dd class="mt-2 font-semibold text-slate-900">{{ integer(selected.clicks) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">营收</dt><dd class="mt-2 font-semibold text-emerald-600">{{ money(selected.revenue) }}</dd></div>
                            <div class="rounded-2xl border border-slate-200 p-4"><dt class="text-xs text-slate-400">广告 ID</dt><dd class="mt-2 break-all font-mono text-xs font-semibold text-slate-700">{{ selected.ad_id }}</dd></div>
                        </dl>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>
