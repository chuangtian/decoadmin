<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { MetaAdsCreativeItem } from './MetaAdsCreativeLibrary.vue';

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

function open(item: MetaAdsCreativeItem): void {
    selected.value = item;
}

function close(): void {
    selected.value = null;
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
                <h2 class="font-semibold text-slate-950">优质文案库</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">筛选所选账户与统计日期内有正文的广告，并按 ROAS 从高到低排列。</p>
            </div>
            <p class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">共 {{ integer(total) }} 条</p>
        </header>

        <div v-if="loading" class="space-y-4 p-5 sm:p-6">
            <div v-for="index in 6" :key="index" class="space-y-4 rounded-2xl border border-slate-100 bg-slate-50 p-5">
                <div class="flex justify-between gap-5"><div class="h-5 w-2/5 animate-pulse rounded bg-slate-200" /><div class="h-5 w-1/4 animate-pulse rounded bg-slate-200" /></div>
                <div class="space-y-2"><div class="h-3 w-full animate-pulse rounded bg-slate-200" /><div class="h-3 w-5/6 animate-pulse rounded bg-slate-200" /></div>
                <div class="h-4 w-2/5 animate-pulse rounded bg-slate-200" />
            </div>
        </div>

        <div v-else-if="items.length" class="space-y-4 p-5 sm:p-6">
            <article v-for="item in items" :key="item.id" class="rounded-2xl border border-slate-200 bg-white transition hover:border-blue-200 hover:shadow-md">
                <button type="button" class="block w-full p-5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-inset sm:p-6" :aria-label="`查看文案：${item.title}`" @click="open(item)">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <h3 class="min-w-0 line-clamp-1 font-semibold text-slate-900" :title="item.title">{{ item.title }}</h3>
                        <div class="flex shrink-0 flex-wrap gap-2 text-xs font-semibold text-slate-500">
                            <span class="max-w-44 truncate rounded-lg bg-slate-100 px-2.5 py-1.5" :title="item.account_name">{{ item.account_name }}</span>
                            <span class="max-w-72 truncate rounded-lg bg-slate-100 px-2.5 py-1.5" :title="item.campaign_name">{{ item.campaign_name }}</span>
                        </div>
                    </div>
                    <p class="mt-4 line-clamp-2 whitespace-pre-line text-sm leading-7 text-slate-600">{{ item.body }}</p>
                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-100 pt-4 text-sm">
                        <span class="font-semibold text-emerald-600">ROAS {{ multiple(item.roas) }}</span>
                        <span class="font-semibold text-blue-600">CTR {{ percentage(item.ctr) }}</span>
                        <span class="text-slate-500">花费 {{ money(item.spend) }}</span>
                        <span class="text-slate-500">购买 {{ purchases(item.purchases) }}</span>
                    </div>
                </button>
            </article>
        </div>

        <div v-else class="flex min-h-80 flex-col items-center justify-center px-6 py-12 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14.5"/><path d="M8 7h8M8 11h8M8 15h5"/><path d="M2 19.5h20"/></svg>
            </div>
            <p class="mt-4 font-semibold text-slate-800">所选时间范围暂无优质文案</p>
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
            <section role="dialog" aria-modal="true" aria-labelledby="copy-detail-title" class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                <header class="sticky top-0 z-10 flex items-start justify-between gap-5 border-b border-slate-100 bg-white/95 px-5 py-4 backdrop-blur sm:px-7">
                    <div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">广告文案详情</p><h2 id="copy-detail-title" class="mt-1 text-lg font-semibold text-slate-950">{{ selected.title }}</h2></div>
                    <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="关闭文案详情" @click="close"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
                </header>

                <div class="space-y-6 p-5 sm:p-7">
                    <p class="whitespace-pre-wrap rounded-2xl bg-slate-50 p-5 text-base leading-8 text-slate-700">{{ selected.body }}</p>

                    <dl class="grid gap-x-8 gap-y-5 border-t border-slate-100 pt-6 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-400">所在账户</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ selected.account_name }}</dd></div>
                        <div><dt class="text-slate-400">所在系列</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ selected.campaign_name }}</dd></div>
                        <div><dt class="text-slate-400">广告名称</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ selected.ad_name }}</dd></div>
                        <div><dt class="text-slate-400">素材格式</dt><dd class="mt-1 font-medium text-slate-800">{{ selected.format }}</dd></div>
                        <div><dt class="text-slate-400">ROAS</dt><dd class="mt-1 font-semibold text-emerald-600">{{ multiple(selected.roas) }}</dd></div>
                        <div><dt class="text-slate-400">CTR</dt><dd class="mt-1 font-semibold text-blue-600">{{ percentage(selected.ctr) }}</dd></div>
                        <div><dt class="text-slate-400">花费</dt><dd class="mt-1 font-medium text-slate-800">{{ money(selected.spend) }}</dd></div>
                        <div><dt class="text-slate-400">收入</dt><dd class="mt-1 font-semibold text-emerald-600">{{ money(selected.revenue) }}</dd></div>
                        <div><dt class="text-slate-400">点击数</dt><dd class="mt-1 font-medium text-slate-800">{{ integer(selected.clicks) }}</dd></div>
                        <div><dt class="text-slate-400">购买数</dt><dd class="mt-1 font-medium text-slate-800">{{ purchases(selected.purchases) }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>
    </Teleport>
</template>
