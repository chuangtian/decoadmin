<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface ReportItem {
    slug: string;
    name: string;
    description: string;
    category: string;
    category_label: string;
    icon: string;
    creator: string;
    kind: 'shopify_default' | 'shopify_custom';
    last_viewed_at: string | null;
    data_source: 'local' | 'shopifyql' | 'shopify_internal';
    available: boolean;
    executable: boolean;
    requires_scope: string | null;
    report_semantics: 'shopify_native' | 'decoadmin_custom' | 'shopify_internal';
    report_semantics_label: string;
    external_url: string;
    pinned: boolean;
}

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    reports: ReportItem[];
}>();

const search = ref('');
const category = ref('all');
const creator = ref('all');
const sort = ref<'catalog' | 'name' | 'category' | 'last_viewed'>('catalog');
const direction = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const perPage = 50;
const { formatDateTime } = useStoreDateTime();

const categories = computed(() => [
    { key: 'all', label: '全部报告', count: props.reports.length },
    ...Array.from(new Map(props.reports.map((report) => [report.category, report.category_label])))
        .map(([key, label]) => ({ key, label, count: props.reports.filter((report) => report.category === key).length })),
]);
const creators = computed(() => ['all', ...Array.from(new Set(props.reports.map((report) => report.creator)))]);
const filteredReports = computed(() => {
    const keyword = search.value.trim().toLocaleLowerCase('zh-CN');
    const rows = props.reports.filter((report) => {
        const categoryMatch = category.value === 'all' || report.category === category.value;
        const creatorMatch = creator.value === 'all' || report.creator === creator.value;
        const haystack = `${report.name} ${report.description} ${report.category_label} ${report.creator}`.toLocaleLowerCase('zh-CN');

        return categoryMatch && creatorMatch && (!keyword || haystack.includes(keyword));
    });

    const sortBy = sort.value;
    if (sortBy === 'catalog') return rows;
    return [...rows].sort((left, right) => {
        const leftValue = sortBy === 'last_viewed' ? left.last_viewed_at || '' : left[sortBy];
        const rightValue = sortBy === 'last_viewed' ? right.last_viewed_at || '' : right[sortBy];
        const result = String(leftValue).localeCompare(String(rightValue), 'zh-CN');

        return direction.value === 'asc' ? result : -result;
    });
});
const pageCount = computed(() => Math.max(1, Math.ceil(filteredReports.value.length / perPage)));
const paginatedReports = computed(() => filteredReports.value.slice((page.value - 1) * perPage, page.value * perPage));
const rangeStart = computed(() => filteredReports.value.length ? (page.value - 1) * perPage + 1 : 0);
const rangeEnd = computed(() => Math.min(page.value * perPage, filteredReports.value.length));

watch([search, category, creator, sort, direction], () => { page.value = 1; });
watch(pageCount, (count) => { if (page.value > count) page.value = count; });

const categoryClass: Record<string, string> = {
    acquisition: 'bg-teal-50 text-teal-700', profit: 'bg-lime-50 text-lime-700', customers: 'bg-violet-50 text-violet-700',
    inventory: 'bg-amber-50 text-amber-700', fraud: 'bg-red-50 text-red-700', performance: 'bg-orange-50 text-orange-700',
    marketing: 'bg-pink-50 text-pink-700', behavior: 'bg-fuchsia-50 text-fuchsia-700', orders: 'bg-indigo-50 text-indigo-700',
    finance: 'bg-rose-50 text-rose-700', sales: 'bg-emerald-50 text-emerald-700', retail_sales: 'bg-sky-50 text-sky-700',
};
const formatLastViewed = (value: string | null) => value ? formatDateTime(value) : '尚未查看';
const openReport = (report: ReportItem) => router.visit(`/reports/${encodeURIComponent(report.slug)}`);
const togglePin = (report: ReportItem) => router.put(`/reports/${encodeURIComponent(report.slug)}/pin`, { pinned: !report.pinned }, {
    preserveScroll: true,
    preserveState: true,
    only: ['reports'],
});
</script>

<template>
    <Head title="报告" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '报告' }]">
        <div class="mx-auto max-w-[1580px] space-y-5">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div><p class="text-xs font-semibold uppercase tracking-[.2em] text-emerald-700">Shopify Reports</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">报告</h1><p class="mt-2 text-sm text-slate-500">{{ store.name }} · 已同步 Shopify 完整报告目录；公开接口支持的报告可在此直接分析。</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500 shadow-sm">当前共 <span class="font-semibold text-slate-950">{{ reports.length }}</span> 个报告</div>
            </header>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="space-y-3 border-b border-slate-100 p-4">
                    <div class="grid gap-3 xl:grid-cols-[minmax(280px,1fr)_200px_190px_auto]">
                        <label class="relative block"><svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input v-model="search" type="search" placeholder="搜索报告、类别或创建者" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm text-slate-900 outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-50"></label>
                        <select v-model="creator" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700"><option value="all">全部创建者</option><option v-for="item in creators.filter((item) => item !== 'all')" :key="item" :value="item">{{ item }}</option></select>
                        <select v-model="sort" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700"><option value="catalog">Shopify 目录顺序</option><option value="name">按名称</option><option value="category">按类别</option><option value="last_viewed">按上次查看</option></select>
                        <button type="button" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600" @click="direction = direction === 'asc' ? 'desc' : 'asc'">{{ direction === 'asc' ? '升序 ↑' : '降序 ↓' }}</button>
                    </div>
                    <div class="flex max-w-full gap-2 overflow-x-auto pb-1"><button v-for="item in categories" :key="item.key" type="button" class="shrink-0 rounded-xl border px-3.5 py-2.5 text-sm font-semibold transition" :class="category === item.key ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'" @click="category = item.key">{{ item.label }} <span class="ml-1 opacity-60">{{ item.count }}</span></button></div>
                </div>

                <div v-if="paginatedReports.length">
                    <div class="hidden grid-cols-[44px_minmax(0,1.5fr)_160px_190px_170px_36px] gap-4 border-b border-slate-200 bg-slate-50/80 px-6 py-4 text-xs font-semibold uppercase tracking-[.12em] text-slate-500 lg:grid"><span></span><span>名称</span><span>类别</span><span>上次查看时间</span><span>创建者</span><span></span></div>
                    <div class="divide-y divide-slate-100">
                        <div v-for="report in paginatedReports" :key="report.slug" role="link" tabindex="0" class="group grid cursor-pointer gap-3 px-5 py-5 transition hover:bg-slate-50 lg:grid-cols-[44px_minmax(0,1.5fr)_160px_190px_170px_36px] lg:items-center lg:gap-4 lg:px-6 lg:py-4" @click="openReport(report)" @keydown.enter="openReport(report)">
                            <button type="button" class="grid h-9 w-9 place-items-center rounded-xl text-lg transition" :class="report.pinned ? 'bg-amber-50 text-amber-500' : 'bg-slate-50 text-slate-300 hover:text-amber-500'" :aria-label="report.pinned ? '取消置顶' : '置顶报告'" @click.stop="togglePin(report)">{{ report.pinned ? '★' : '☆' }}</button>
                            <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950 group-hover:text-emerald-700">{{ report.name }}</h2><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="report.report_semantics === 'shopify_native' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ report.report_semantics_label }}</span><span v-if="report.executable && !report.available" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">需重新授权</span></div><p class="mt-1 truncate text-sm text-slate-500">{{ report.description }}</p></div>
                            <div><span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold" :class="categoryClass[report.category] ?? 'bg-slate-100 text-slate-700'">{{ report.category_label }}</span></div>
                            <div class="text-sm text-slate-500"><span class="mr-2 text-xs font-semibold text-slate-400 lg:hidden">上次查看</span>{{ formatLastViewed(report.last_viewed_at) }}</div>
                            <div class="flex items-center gap-2 text-sm font-medium text-slate-700"><span class="grid h-8 w-8 place-items-center rounded-lg text-xs font-bold" :class="report.creator === 'Shopify' ? 'bg-emerald-50 text-emerald-700' : 'bg-violet-50 text-violet-700'">{{ report.creator === 'Shopify' ? 'S' : report.creator.slice(0, 1).toUpperCase() }}</span><span class="truncate">{{ report.creator }}</span></div>
                            <svg class="h-5 w-5 text-slate-300 transition group-hover:translate-x-1 group-hover:text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </div>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 px-5 py-4 text-sm text-slate-500"><span>{{ rangeStart }}–{{ rangeEnd }} / {{ filteredReports.length }}</span><div class="flex gap-2"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-40" :disabled="page <= 1" @click="page--">上一页</button><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-40" :disabled="page >= pageCount" @click="page++">下一页</button></div></div>
                </div>
                <div v-else class="grid min-h-64 place-items-center p-8 text-center"><div><p class="font-semibold text-slate-800">没有找到匹配的报告</p><p class="mt-2 text-sm text-slate-500">试试其他关键词、类别或创建者。</p></div></div>
            </section>
        </div>
    </AppLayout>
</template>
