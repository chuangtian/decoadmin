<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DateRangeFilters from '../../Components/Analytics/DateRangeFilters.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useDeferredReport, type ReportStorage } from '../../composables/useDeferredReport';

interface Metrics {
    net_items_sold: number;
    total_sales: number;
    gross_sales: number;
}

interface Trend {
    key: 'new' | 'hot' | 'up' | 'stable' | 'sliding' | 'down';
    label: string;
    direction: 'up' | 'down' | 'flat';
    percent: number | null;
}

interface Variant {
    id: string;
    shopify_variant_id: string | null;
    title: string;
    sku: string;
    current: Metrics;
    previous: Metrics;
    changes: { net_items_sold: Trend; total_sales: Trend };
    trend: Trend;
}

interface Model {
    id: string;
    shopify_product_id: string | null;
    title: string;
    variant_count: number;
    current: Metrics;
    previous: Metrics;
    changes: { net_items_sold: Trend; total_sales: Trend };
    trend: Trend;
    variants: Variant[];
}

const props = defineProps<{
    store: { id: number; name: string; currency: string; timezone: string };
    report: {
        schema: string;
        period: { days: number; from: string; to: string; timezone: string; include_test: boolean; include_cancelled: boolean; comparison_from: string; comparison_to: string };
        summary: { models: number; variants: number; net_items_sold: number; total_sales: number; gross_sales: number };
        models: Model[];
        integration: { source: string; available: boolean; complete: boolean; scope_granted: boolean; error: string | null; storage?: ReportStorage | null };
    };
}>();

const { refreshing, timedOut, retry } = useDeferredReport(() => ({
    key: `${props.store.id}:${props.report.period.from}:${props.report.period.to}`,
    pending: !props.report.integration.available && Boolean(props.report.integration.storage?.pending || props.report.integration.storage?.refreshing),
}), ['report']);
const reportMessage = computed(() => refreshing.value ? '数据正在加载，完成后会自动显示。'
    : timedOut.value ? '加载时间较长，请重试。' : props.report.integration.error);

const expanded = ref<Set<string>>(new Set(props.report.models.map((model) => model.id)));
watch(() => props.report.models, (models) => {
    expanded.value = new Set(models.map((model) => model.id));
});

const allExpanded = computed(() => props.report.models.length > 0 && expanded.value.size === props.report.models.length);
const toggle = (id: string) => {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
};
const toggleAll = () => {
    expanded.value = allExpanded.value ? new Set() : new Set(props.report.models.map((model) => model.id));
};

const money = (value: number | string) => new Intl.NumberFormat('zh-CN', {
    style: 'currency',
    currency: props.store.currency,
    maximumFractionDigits: 2,
}).format(Number(value || 0));
const integer = (value: number) => Number(value || 0).toLocaleString('zh-CN');
const directionSymbol = (direction: Trend['direction']) => direction === 'up' ? '↑' : direction === 'down' ? '↓' : '—';
const changePercent = (change: Trend) => change.percent === null
    ? `${directionSymbol(change.direction)} 上期无销量`
    : `${directionSymbol(change.direction)} ${Math.abs(change.percent).toFixed(1)}%`;
const trendClass = (trend: Trend) => ({
    hot: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    up: 'bg-teal-50 text-teal-700 ring-teal-200',
    stable: 'bg-slate-100 text-slate-600 ring-slate-200',
    sliding: 'bg-amber-50 text-amber-700 ring-amber-200',
    down: 'bg-rose-50 text-rose-700 ring-rose-200',
    new: 'bg-violet-50 text-violet-700 ring-violet-200',
}[trend.key]);
const changeTextClass = (change: Trend) => ({
    hot: 'text-emerald-600',
    up: 'text-teal-600',
    stable: 'text-slate-500',
    sliding: 'text-amber-600',
    down: 'text-rose-600',
    new: 'text-violet-600',
}[change.key]);

const cards = computed(() => [
    { label: '在售车型', value: integer(props.report.summary.models), note: `${integer(props.report.summary.variants)} 个款式` },
    { label: '净销量', value: integer(props.report.summary.net_items_sold), note: '已计入退货与冲销' },
    { label: '总销售额', value: money(props.report.summary.total_sales), note: '含税费、运费及其他费用' },
    { label: '毛销售额', value: money(props.report.summary.gross_sales), note: '折扣与退货前商品销售额' },
]);
</script>

<template>
    <Head title="车型销量汇总" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '车型销量汇总' }]">
        <div class="mx-auto max-w-[1500px] space-y-6">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">商品表现</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">车型销量汇总</h1>
                    <p class="mt-2 text-sm text-slate-500">{{ store.name }} · 仅显示 Shopify 当前在售车型 · 按 {{ report.period.timezone }} 统计</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500 shadow-sm">
                    对比期：<span class="font-semibold text-slate-800">{{ report.period.comparison_from }} 至 {{ report.period.comparison_to }}</span>
                </div>
            </header>

            <DateRangeFilters action="/analytics/model-sales" :period="report.period" :show-order-options="false" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="card in cards" :key="card.label" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold text-slate-500">{{ card.label }}</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ !report.integration.available ? '—' : card.value }}</p>
                    <p class="mt-2 text-xs text-slate-400">{{ card.note }}</p>
                </article>
            </section>

            <section v-if="!report.integration.complete" class="rounded-2xl border px-5 py-4 text-sm" :class="refreshing ? 'border-sky-200 bg-sky-50 text-sky-800' : report.integration.available ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-rose-200 bg-rose-50 text-rose-800'">
                <p class="font-semibold">{{ refreshing ? '正在加载 Shopify 报表' : report.integration.available ? '部分统计暂不可用' : 'Shopify 报表暂不可用' }}</p>
                <p class="mt-1 opacity-80">{{ reportMessage || (report.integration.scope_granted ? '请稍后刷新重试。' : '请确认店铺已授予 read_reports 权限。') }}</p>
            </section>

            <button v-if="timedOut" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold" @click="retry">重试加载</button>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-5 lg:flex-row lg:items-center lg:justify-between lg:px-7">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">在售车型表现</h2>
                        <p class="mt-1 text-sm text-slate-500">趋势按当前区间与等长上一周期的总销售额计算。</p>
                    </div>
                    <button v-if="report.models.length" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-emerald-300 hover:text-emerald-700" @click="toggleAll">
                        {{ allExpanded ? '全部收起' : '全部展开' }}
                    </button>
                </div>

                <div class="flex flex-wrap gap-2 border-b border-slate-100 bg-slate-50/70 px-5 py-3 text-xs font-semibold lg:px-7">
                    <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-700">热销：增长超过 10%</span>
                    <span class="rounded-full bg-teal-50 px-3 py-1.5 text-teal-700">上升：增长 0–10%</span>
                    <span class="rounded-full bg-amber-50 px-3 py-1.5 text-amber-700">下滑：下降不超过 20%</span>
                    <span class="rounded-full bg-rose-50 px-3 py-1.5 text-rose-700">下降：下降超过 20%</span>
                    <span class="rounded-full bg-violet-50 px-3 py-1.5 text-violet-700">新品：上期无销售</span>
                </div>

                <div v-if="report.models.length" class="hidden overflow-x-auto lg:block">
                    <table class="w-full table-fixed text-left">
                        <colgroup>
                            <col style="width: 36%">
                            <col style="width: 14%">
                            <col style="width: 18%">
                            <col style="width: 18%">
                            <col style="width: 14%">
                        </colgroup>
                        <thead class="bg-white text-xs font-semibold uppercase tracking-wide text-slate-400">
                            <tr><th class="px-7 py-4">车型 / 款式</th><th class="px-5 py-4 text-right">净销量</th><th class="px-5 py-4 text-right">总销售额</th><th class="px-5 py-4 text-right">毛销售额</th><th class="px-7 py-4 text-right">趋势</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="model in report.models" :key="model.id">
                                <tr class="bg-slate-50/80">
                                    <td class="px-7 py-5">
                                        <button type="button" class="flex w-full min-w-0 items-center gap-3 text-left" :aria-expanded="expanded.has(model.id)" @click="toggle(model.id)">
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 transition" :class="expanded.has(model.id) ? 'rotate-90 text-emerald-700' : ''">›</span>
                                            <span class="min-w-0"><strong class="block truncate text-base text-slate-950" :title="model.title">{{ model.title }}</strong><span class="mt-1 block text-xs text-slate-400">{{ model.variant_count }} 个款式</span></span>
                                        </button>
                                    </td>
                                    <td class="px-5 py-5 text-right"><strong class="text-base text-slate-950">{{ integer(model.current.net_items_sold) }}</strong><p class="mt-1 text-xs font-semibold" :class="changeTextClass(model.changes.net_items_sold)">{{ changePercent(model.changes.net_items_sold) }}</p></td>
                                    <td class="px-5 py-5 text-right"><strong class="text-base text-emerald-700">{{ money(model.current.total_sales) }}</strong><p class="mt-1 text-xs font-semibold" :class="changeTextClass(model.changes.total_sales)">{{ changePercent(model.changes.total_sales) }}</p></td>
                                    <td class="px-5 py-5 text-right"><span class="font-semibold text-slate-800">{{ money(model.current.gross_sales) }}</span></td>
                                    <td class="px-7 py-5 text-right"><span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset" :class="trendClass(model.trend)">{{ model.trend.label }} {{ directionSymbol(model.trend.direction) }}</span></td>
                                </tr>
                                <tr v-for="variant in (expanded.has(model.id) ? model.variants : [])" :key="variant.id" class="hover:bg-slate-50/60">
                                    <td class="px-7 py-4"><div class="flex min-w-0 items-center gap-3 pl-11"><span class="h-2 w-2 shrink-0 rounded-full bg-emerald-400"></span><div class="min-w-0"><p class="truncate font-medium text-slate-800" :title="variant.title">{{ variant.title }}</p><p v-if="variant.sku" class="mt-0.5 truncate font-mono text-xs text-slate-400" :title="variant.sku">SKU {{ variant.sku }}</p></div></div></td>
                                    <td class="px-5 py-4 text-right text-sm text-slate-700">{{ integer(variant.current.net_items_sold) }}<p class="mt-1 text-xs font-semibold" :class="changeTextClass(variant.changes.net_items_sold)">{{ changePercent(variant.changes.net_items_sold) }}</p></td>
                                    <td class="px-5 py-4 text-right text-sm font-medium text-slate-800">{{ money(variant.current.total_sales) }}<p class="mt-1 text-xs font-semibold" :class="changeTextClass(variant.changes.total_sales)">{{ changePercent(variant.changes.total_sales) }}</p></td>
                                    <td class="px-5 py-4 text-right text-sm text-slate-600">{{ money(variant.current.gross_sales) }}</td>
                                    <td class="px-7 py-4 text-right"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="trendClass(variant.trend)">{{ variant.trend.label }} {{ directionSymbol(variant.trend.direction) }}</span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-if="report.models.length" class="divide-y divide-slate-100 lg:hidden">
                    <article v-for="model in report.models" :key="model.id">
                        <button type="button" class="w-full px-5 py-5 text-left" :aria-expanded="expanded.has(model.id)" @click="toggle(model.id)">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="truncate font-semibold text-slate-950" :title="model.title">{{ model.title }}</h3><p class="mt-1 text-xs text-slate-400">{{ model.variant_count }} 个款式</p></div><span class="text-xl text-slate-400" :class="expanded.has(model.id) ? 'rotate-90' : ''">›</span></div>
                            <div class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><p class="text-xs text-slate-400">净销量</p><p class="mt-1 font-semibold text-slate-900">{{ integer(model.current.net_items_sold) }}</p><p class="mt-1 text-xs font-semibold" :class="changeTextClass(model.changes.net_items_sold)">{{ changePercent(model.changes.net_items_sold) }}</p></div><div><p class="text-xs text-slate-400">总销售额</p><p class="mt-1 font-semibold text-emerald-700">{{ money(model.current.total_sales) }}</p><p class="mt-1 text-xs font-semibold" :class="changeTextClass(model.changes.total_sales)">{{ changePercent(model.changes.total_sales) }}</p></div><div class="col-span-2"><p class="text-xs text-slate-400">毛销售额</p><p class="mt-1 font-semibold text-slate-900">{{ money(model.current.gross_sales) }}</p></div></div>
                            <span class="mt-4 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="trendClass(model.trend)">{{ model.trend.label }} {{ directionSymbol(model.trend.direction) }}</span>
                        </button>
                        <div v-if="expanded.has(model.id)" class="divide-y divide-slate-100 border-t border-slate-100 bg-slate-50/70 px-5">
                            <div v-for="variant in model.variants" :key="variant.id" class="py-4"><div class="flex items-center justify-between gap-3"><div class="min-w-0"><p class="truncate font-medium text-slate-800" :title="variant.title">{{ variant.title }}</p><p v-if="variant.sku" class="mt-1 truncate font-mono text-xs text-slate-400" :title="variant.sku">{{ variant.sku }}</p></div><span class="shrink-0 text-sm font-semibold text-slate-900">{{ integer(variant.current.net_items_sold) }} 件</span></div><div class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><p class="text-xs text-slate-400">净销量变化</p><p class="mt-1 text-xs font-semibold" :class="changeTextClass(variant.changes.net_items_sold)">{{ changePercent(variant.changes.net_items_sold) }}</p></div><div><p class="text-xs text-slate-400">总销售额</p><p class="mt-1 text-emerald-700">{{ money(variant.current.total_sales) }}</p><p class="mt-1 text-xs font-semibold" :class="changeTextClass(variant.changes.total_sales)">{{ changePercent(variant.changes.total_sales) }}</p></div><div class="col-span-2"><p class="text-xs text-slate-400">毛销售额</p><p class="mt-1 text-slate-700">{{ money(variant.current.gross_sales) }}</p></div></div></div>
                        </div>
                    </article>
                </div>

                <div v-if="!report.models.length" class="px-6 py-20 text-center"><div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-2xl">⌁</div><h3 class="mt-4 font-semibold text-slate-900">{{ refreshing ? '正在加载车型销量' : report.integration.available ? '当前区间暂无在售车型销量' : '车型销量暂不可用' }}</h3><p class="mt-2 text-sm text-slate-500">{{ reportMessage || '调整顶部时间范围，或确认 Shopify 报表连接状态。' }}</p></div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 text-sm leading-6 text-slate-600 shadow-sm">
                <h2 class="font-semibold text-slate-900">统计口径</h2>
                <p class="mt-2">净销量采用 Shopify 的 net_items_sold，已扣除退货及冲销数量；总销售额包含税费、运费、关税及其他费用；毛销售额为折扣和退货前的商品销售额。列表仅包含当前状态为 Active，且在当前或对比周期内产生过销售记录的商品。</p>
            </section>
        </div>
    </AppLayout>
</template>
