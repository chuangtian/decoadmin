<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface ReportItem {
    slug: string;
    name: string;
    description: string;
    category: string;
    category_label: string;
    icon: string;
    creator: string;
    last_viewed_at: string | null;
    data_source: 'local' | 'shopifyql';
    available: boolean;
    requires_scope: string | null;
}

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    reports: ReportItem[];
}>();

const search = ref('');
const category = ref('all');
const categories = computed(() => [
    { key: 'all', label: '全部报告', count: props.reports.length },
    ...Array.from(new Map(props.reports.map((report) => [report.category, report.category_label])))
        .map(([key, label]) => ({ key, label, count: props.reports.filter((report) => report.category === key).length })),
]);
const filteredReports = computed(() => {
    const keyword = search.value.trim().toLocaleLowerCase('zh-CN');

    return props.reports.filter((report) => {
        const categoryMatch = category.value === 'all' || report.category === category.value;
        const searchMatch = !keyword || `${report.name} ${report.description} ${report.category_label}`.toLocaleLowerCase('zh-CN').includes(keyword);

        return categoryMatch && searchMatch;
    });
});

const categoryClass: Record<string, string> = {
    store: 'bg-cyan-50 text-cyan-700',
    orders: 'bg-indigo-50 text-indigo-700',
    finance: 'bg-rose-50 text-rose-700',
    sales: 'bg-emerald-50 text-emerald-700',
    products: 'bg-sky-50 text-sky-700',
    customers: 'bg-violet-50 text-violet-700',
    inventory: 'bg-amber-50 text-amber-700',
    acquisition: 'bg-teal-50 text-teal-700',
    behavior: 'bg-fuchsia-50 text-fuchsia-700',
    performance: 'bg-orange-50 text-orange-700',
    marketing: 'bg-pink-50 text-pink-700',
};

const formatLastViewed = (value: string | null): string => {
    if (!value) return '尚未查看';

    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
};
</script>

<template>
    <Head title="报告" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '报告' }]">
        <div class="mx-auto max-w-[1580px] space-y-5">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[.2em] text-emerald-700">Analytics Reports</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">报告</h1>
                    <p class="mt-2 text-sm text-slate-500">{{ store.name }} · 查看销售、商品、客户和库存报告，点击任意报告进入详细分析。</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500 shadow-sm">
                    当前共 <span class="font-semibold text-slate-950">{{ reports.length }}</span> 个报告
                </div>
            </header>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[1fr_auto] lg:items-center">
                    <label class="relative block">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input v-model="search" type="search" placeholder="搜索报告" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-12 pr-4 text-sm text-slate-900 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-50">
                    </label>
                    <div class="flex max-w-full gap-2 overflow-x-auto pb-1 lg:pb-0">
                        <button v-for="item in categories" :key="item.key" type="button" class="shrink-0 rounded-xl border px-3.5 py-3 text-sm font-semibold transition" :class="category === item.key ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'" @click="category = item.key">
                            {{ item.label }} <span class="ml-1 opacity-60">{{ item.count }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="filteredReports.length">
                    <div class="hidden grid-cols-[minmax(0,1.5fr)_180px_210px_180px_40px] gap-5 border-b border-slate-200 bg-slate-50/80 px-6 py-4 text-xs font-semibold uppercase tracking-[.12em] text-slate-500 lg:grid">
                        <span>名称</span><span>类别</span><span>上次查看时间</span><span>创建者</span><span></span>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <Link v-for="report in filteredReports" :key="report.slug" :href="`/reports/${report.slug}`" class="group grid gap-4 px-5 py-5 transition hover:bg-slate-50 lg:grid-cols-[minmax(0,1.5fr)_180px_210px_180px_40px] lg:items-center lg:gap-5 lg:px-6 lg:py-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-semibold text-slate-950 transition group-hover:text-emerald-700">{{ report.name }}</h2>
                                    <span v-if="report.data_source === 'shopifyql'" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">ShopifyQL</span>
                                    <span v-if="!report.available" class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">需重新授权</span>
                                </div>
                                <p class="mt-1 truncate text-sm text-slate-500">{{ report.description }}</p>
                            </div>
                            <div><span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold" :class="categoryClass[report.category] ?? 'bg-slate-100 text-slate-700'">{{ report.category_label }}</span></div>
                            <div class="text-sm text-slate-500"><span class="mr-2 text-xs font-semibold text-slate-400 lg:hidden">上次查看</span>{{ formatLastViewed(report.last_viewed_at) }}</div>
                            <div class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-50 text-xs font-bold text-emerald-700">D</span>
                                {{ report.creator }}
                            </div>
                            <svg class="h-5 w-5 text-slate-300 transition group-hover:translate-x-1 group-hover:text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </Link>
                    </div>
                </div>
                <div v-else class="grid min-h-64 place-items-center p-8 text-center">
                    <div><p class="font-semibold text-slate-800">没有找到匹配的报告</p><p class="mt-2 text-sm text-slate-500">试试其他关键词或报告类别。</p></div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
