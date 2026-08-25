<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface TrendPoint {
    date: string;
    label: string;
    net_sales: number;
    gross_sales: number;
    total_sales: number;
    refunds: number;
    discounts: number;
    taxes: number;
    shipping: number;
    orders: number;
    average_order_value: number;
}

interface ComparisonMetric { current: number; baseline: number; change: number; change_percent: number | null }
interface RankingItem { product_id: number | null; name: string; vendor: string; product_type: string; units: number; net_sales: number }
interface InventoryItem { id: number; sku: string; available: number; units_sold: number; estimated_days_cover: number | null; risk: string }
interface AcquisitionInsight { key: string; label: string; detail: string; sessions: number; visitors: number; converted_sessions: number; conversion_rate: number }
interface DeviceInsight { key: string; label: string; sessions: number; share: number; pageviews: number; bounce_rate: number; conversion_rate: number }
interface LocationInsight { key: string; label: string; country: string; sessions: number; visitors: number; conversion_rate: number }
interface CustomerInsight { key: string; label: string; value: number }
interface PosLocationInsight { id: string; name: string; orders: number; net_sales: number; total_sales: number }
interface PosStaffInsight { id: string; name: string; orders: number; units: number; attributed_sales: number }
interface ReportInsight<T> { available: boolean; source: 'shopifyql'; items: T[]; error: string | null }
interface OperatingMetric { available: boolean; value: number | null; comparison: ComparisonMetric | null; trend: number[]; note: string }
interface BehaviorInsight {
    available: boolean;
    source: 'shopifyql';
    metrics: Record<string, { value: number; comparison: ComparisonMetric | null }>;
    trend: { date: string; sessions: number; conversion_rate: number; add_to_cart: number; checkout: number; completed_checkout: number }[];
    error: string | null;
}

const props = defineProps<{
    store: { id: number; name: string; currency: string; timezone: string };
    overview: {
        period: { days: number; from: string; to: string; timezone: string; include_test: boolean; include_cancelled: boolean };
        summary: Record<string, number>;
        comparisons: { previous: Record<string, ComparisonMetric>; year_over_year: Record<string, ComparisonMetric> };
        trend: TrendPoint[];
        comparison_trend: { previous: TrendPoint[] };
        sales_breakdown: { key: string; label: string; value: number }[];
        customers: { active: number; new: number; returning: number; repeat_customers: number; repeat_rate: number; average_lifetime_value: number | null };
        rankings: { products: RankingItem[]; vendors: { vendor: string; units: number; net_sales: number }[]; product_types: { product_type: string; units: number; net_sales: number }[] };
        inventory: { summary: { out_of_stock: number; low_stock: number; slow_moving: number }; items: InventoryItem[] };
        order_statuses: { financial: { status: string; total: number }[]; fulfillment: { status: string; total: number }[] };
        traffic: { available: boolean; reason_code: string; message: string };
        generated_at: string;
    };
    insights: {
        schema: 'analytics-overview-insights-v1';
        acquisition: ReportInsight<AcquisitionInsight>;
        devices: ReportInsight<DeviceInsight>;
        locations: ReportInsight<LocationInsight>;
        behavior: BehaviorInsight;
        customers: { available: boolean; source: 'local_sync'; items: CustomerInsight[]; repeat_rate: number; error: null };
        pos: { available: boolean; source: 'shopifyql' | 'local_sync'; locations: PosLocationInsight[]; staff: PosStaffInsight[]; error: string | null };
        integration: { report_scope_granted: boolean; shopifyql_available: boolean };
        generated_at: string;
    };
    performance: {
        schema: 'analytics-operating-metrics-v1';
        comparison: { mode: 'previous' | 'none'; label: string; period: { from: string; to: string } | null };
        advertising: { available: boolean; complete: boolean; available_channels: number; expected_channels: number; channels: { key: string; name: string; available: boolean; spend: number }[]; message: string };
        behavior: { available: boolean; source: 'shopifyql'; message: string };
        metrics: Record<string, OperatingMetric>;
        catalog: { sku_count: number; customer_count: number };
        generated_at: string;
    };
}>();

const page = usePage();
const filters = reactive({
    days: props.overview.period.days,
    date_from: props.overview.period.from,
    date_to: props.overview.period.to,
    include_test: props.overview.period.include_test,
    include_cancelled: props.overview.period.include_cancelled,
    comparison: props.performance.comparison.mode,
});
const showFilters = ref(false);
const loading = ref(false);
const { formatDateTime } = useStoreDateTime();
const selectedMetric = ref<keyof TrendPoint>('net_sales');
const isCustomPeriod = computed(() => {
    const query = String(page.url).split('?')[1] ?? '';
    const params = new URLSearchParams(query);

    return params.has('date_from') && params.has('date_to');
});

const money = (value: number) => new Intl.NumberFormat('zh-CN', {
    style: 'currency', currency: props.store.currency || 'USD', maximumFractionDigits: 2,
}).format(Number(value || 0));
const number = (value: number) => Number(value || 0).toLocaleString('zh-CN');
const changeText = (comparison: ComparisonMetric | null | undefined) => {
    const value = comparison?.change_percent;
    return value === null || value === undefined ? '暂无对比' : `${value >= 0 ? '↑' : '↓'} ${Math.abs(value)}%`;
};
const changeClass = (comparison: ComparisonMetric | null | undefined) => {
    const value = comparison?.change_percent;
    return value === null || value === undefined ? 'text-slate-400' : value >= 0 ? 'text-emerald-600' : 'text-rose-600';
};
const filterParams = () => ({
    days: filters.days,
    ...(filters.date_from && filters.date_to
        ? { date_from: filters.date_from, date_to: filters.date_to }
        : {}),
    include_test: filters.include_test ? 1 : 0,
    include_cancelled: filters.include_cancelled ? 1 : 0,
    comparison: filters.comparison,
});
const applyFilters = () => router.get('/analytics/overview', filterParams(), {
    preserveState: false,
    preserveScroll: true,
    replace: true,
    onStart: () => { loading.value = true; },
    onFinish: () => { loading.value = false; },
});
const preset = (days: number) => {
    filters.days = days;
    filters.date_from = '';
    filters.date_to = '';
    applyFilters();
};
const refresh = () => router.reload({
    only: ['overview', 'insights', 'performance'],
    onStart: () => { loading.value = true; },
    onFinish: () => { loading.value = false; },
});

const metricOptions: { key: keyof TrendPoint; label: string; money: boolean }[] = [
    { key: 'net_sales', label: '净销售额', money: true },
    { key: 'gross_sales', label: '毛销售额', money: true },
    { key: 'total_sales', label: '总销售额', money: true },
    { key: 'orders', label: '订单数', money: false },
    { key: 'average_order_value', label: '平均订单金额', money: true },
    { key: 'refunds', label: '退款金额', money: true },
    { key: 'discounts', label: '折扣金额', money: true },
    { key: 'taxes', label: '税费', money: true },
    { key: 'shipping', label: '运费', money: true },
];
const metricDefinition = computed(() => metricOptions.find((item) => item.key === selectedMetric.value) ?? metricOptions[0]);
const chartValues = computed(() => props.overview.trend.map((point) => Number(point[selectedMetric.value]) || 0));
const maxChart = computed(() => Math.max(...chartValues.value, 1));
const linePoints = computed(() => chartValues.value.map((value, index) => {
    const x = props.overview.trend.length <= 1 ? 0 : index * (100 / (props.overview.trend.length - 1));
    const y = 92 - (value / maxChart.value) * 76;
    return `${x},${y}`;
}).join(' '));
const productMax = computed(() => Math.max(...props.overview.rankings.products.slice(0, 7).map((item) => item.net_sales), 1));
const customerTotal = computed(() => Math.max(props.overview.customers.new + props.overview.customers.returning, 1));
const newCustomerDegrees = computed(() => `${(props.overview.customers.new / customerTotal.value) * 360}deg`);
const acquisitionMax = computed(() => Math.max(...props.insights.acquisition.items.map((item) => item.sessions), 1));
const locationMax = computed(() => Math.max(...props.insights.locations.items.map((item) => item.sessions), 1));

const comparisonEnabled = computed(() => filters.comparison !== 'none');
const salesComparison = (key: string) => comparisonEnabled.value ? props.overview.comparisons.previous[key] ?? null : null;
const performanceMetric = (key: string): OperatingMetric => props.performance.metrics[key] ?? {
    available: false, value: null, comparison: null, trend: [], note: '暂不可用',
};
const formatOperating = (metric: OperatingMetric, format: 'money' | 'number' | 'percent' | 'ratio') => {
    if (!metric.available || metric.value === null) return '暂不可用';
    if (format === 'money') return money(metric.value);
    if (format === 'percent') return `${Number(metric.value).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}%`;
    if (format === 'ratio') return `${Number(metric.value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}×`;
    return number(metric.value);
};
const salesTrend = (key: keyof TrendPoint) => props.overview.trend.map((point) => Number(point[key]) || 0);
const cards = computed(() => {
    const adSpend = performanceMetric('ad_spend');
    const roi = performanceMetric('roi');
    const sessions = performanceMetric('sessions');
    const conversionRate = performanceMetric('conversion_rate');
    const addToCart = performanceMetric('add_to_cart');
    const checkout = performanceMetric('checkout');
    const addToCartCost = performanceMetric('add_to_cart_cost');
    const checkoutCost = performanceMetric('checkout_cost');

    return [
        { key: 'net_sales', label: '净销售额', value: money(props.overview.summary.net_sales), available: true, comparison: salesComparison('net_sales'), note: '扣除退款后的商品销售额', trend: salesTrend('net_sales'), color: '#0ea5e9' },
        { key: 'ad_spend', label: '广告花费', value: formatOperating(adSpend, 'money'), available: adSpend.available, comparison: comparisonEnabled.value ? adSpend.comparison : null, note: adSpend.note, trend: adSpend.trend, color: '#8b5cf6' },
        { key: 'roi', label: 'ROI', value: formatOperating(roi, 'ratio'), available: roi.available, comparison: comparisonEnabled.value ? roi.comparison : null, note: roi.note, trend: roi.trend, color: '#f59e0b' },
        { key: 'orders', label: '订单数', value: number(props.overview.summary.orders), available: true, comparison: salesComparison('orders'), note: '有效 Shopify 订单', trend: salesTrend('orders'), color: '#10b981' },
        { key: 'average_order_value', label: '平均订单金额', value: money(props.overview.summary.average_order_value), available: true, comparison: salesComparison('average_order_value'), note: 'Shopify 原生平均订单金额', trend: salesTrend('average_order_value'), color: '#14b8a6' },
        { key: 'sessions', label: '访问量', value: formatOperating(sessions, 'number'), available: sessions.available, comparison: comparisonEnabled.value ? sessions.comparison : null, note: sessions.note, trend: sessions.trend, color: '#6366f1' },
        { key: 'conversion_rate', label: '转化率', value: formatOperating(conversionRate, 'percent'), available: conversionRate.available, comparison: comparisonEnabled.value ? conversionRate.comparison : null, note: conversionRate.note, trend: conversionRate.trend, color: '#06b6d4' },
        { key: 'refunds', label: '退款金额', value: money(props.overview.summary.refunds), available: true, comparison: salesComparison('refunds'), note: '统计周期内退款', trend: salesTrend('refunds'), color: '#f43f5e' },
        { key: 'add_to_cart', label: '加购数', value: formatOperating(addToCart, 'number'), available: addToCart.available, comparison: comparisonEnabled.value ? addToCart.comparison : null, note: addToCart.note, trend: addToCart.trend, color: '#8b5cf6' },
        { key: 'checkout', label: '结账数', value: formatOperating(checkout, 'number'), available: checkout.available, comparison: comparisonEnabled.value ? checkout.comparison : null, note: checkout.note, trend: checkout.trend, color: '#ec4899' },
        { key: 'add_to_cart_cost', label: '单次加购成本', value: formatOperating(addToCartCost, 'money'), available: addToCartCost.available, comparison: comparisonEnabled.value ? addToCartCost.comparison : null, note: addToCartCost.note, trend: addToCartCost.trend, color: '#f97316' },
        { key: 'checkout_cost', label: '单次结账成本', value: formatOperating(checkoutCost, 'money'), available: checkoutCost.available, comparison: comparisonEnabled.value ? checkoutCost.comparison : null, note: checkoutCost.note, trend: checkoutCost.trend, color: '#eab308' },
    ];
});
const sparklinePoints = (values: number[]) => {
    if (!values.length) return '';
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = Math.max(max - min, 0.000001);

    return values.map((value, index) => {
        const x = values.length === 1 ? 50 : index * (100 / (values.length - 1));
        const y = max === min ? 50 : 88 - ((value - min) / range) * 76;
        return `${x},${y}`;
    }).join(' ');
};
const advertisingChannels = computed(() => props.performance.advertising.channels.filter((channel) => channel.available).map((channel) => channel.name));

const statusLabel: Record<string, string> = {
    paid: '已付款', pending: '待付款', refunded: '已退款', partially_refunded: '部分退款',
    fulfilled: '已发货', partial: '部分发货', unfulfilled: '未发货', unknown: '未设置',
};
const riskLabel: Record<string, string> = { out_of_stock: '已缺货', low_stock: '低库存', slow_moving: '滞销风险', healthy: '健康' };
</script>

<template>
    <Head title="经营分析" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '经营分析' }]">
        <div class="mx-auto max-w-[1600px] space-y-5">
            <header class="flex flex-col gap-4 border-b border-slate-200 pb-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-3xl font-semibold tracking-tight text-slate-950">经营分析</h1>
                        <span class="flex items-center gap-1.5 text-xs font-medium text-slate-400"><i class="h-2 w-2 rounded-full" :class="loading ? 'animate-pulse bg-amber-400' : 'bg-sky-400'" />{{ loading ? '更新中…' : `更新于 ${formatDateTime(overview.generated_at)}` }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">{{ store.name }} · {{ store.timezone }} · {{ store.currency }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button v-for="days in [1, 7, 30, 90]" :key="days" type="button" :disabled="loading" class="rounded-xl border px-3.5 py-2 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60" :class="overview.period.days === days && !isCustomPeriod ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'" @click="preset(days)">{{ days === 1 ? '今天' : `${days} 天` }}</button>
                    <button type="button" :disabled="loading" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-wait disabled:opacity-60" @click="showFilters = !showFilters">{{ overview.period.from }} — {{ overview.period.to }}</button>
                    <select v-model="filters.comparison" :disabled="loading" class="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 disabled:cursor-wait disabled:opacity-60" @change="applyFilters">
                        <option value="previous">对比上一等长周期</option>
                        <option value="none">不对比</option>
                    </select>
                    <button type="button" :disabled="loading" class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60" @click="refresh">刷新</button>
                </div>
            </header>

            <form v-if="showFilters" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-[1fr_1fr_auto_auto_auto] xl:items-end" @submit.prevent="applyFilters">
                <label class="text-xs font-semibold text-slate-500">开始日期<input v-model="filters.date_from" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label>
                <label class="text-xs font-semibold text-slate-500">结束日期<input v-model="filters.date_to" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label>
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-600"><input v-model="filters.include_test" type="checkbox" class="rounded border-slate-300 text-emerald-600">测试订单</label>
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-600"><input v-model="filters.include_cancelled" type="checkbox" class="rounded border-slate-300 text-emerald-600">取消订单</label>
                <button class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white">应用</button>
            </form>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="card in cards" :key="card.key" class="relative min-h-44 overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-500">{{ card.label }}</p>
                        <div class="mt-6 flex items-end justify-between gap-4">
                            <p class="min-w-0 text-2xl font-semibold tracking-tight" :class="card.available ? 'text-slate-950' : 'text-slate-400'">{{ card.value }}</p>
                            <svg v-if="card.trend.length" viewBox="0 0 100 100" preserveAspectRatio="none" class="h-12 w-24 shrink-0 overflow-visible opacity-80"><polyline :points="sparklinePoints(card.trend)" fill="none" :stroke="card.color" stroke-width="3" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </div>
                        <div class="mt-4 flex items-start justify-between gap-3 text-xs"><span class="shrink-0 font-semibold" :class="changeClass(card.comparison)">{{ comparisonEnabled ? `环比 ${changeText(card.comparison)}` : '未启用对比' }}</span><span class="line-clamp-2 text-right leading-5 text-slate-400">{{ card.note }}</span></div>
                    </article>
            </section>

            <div class="flex flex-wrap gap-x-5 gap-y-2 rounded-2xl border border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500">
                <span>商品 SKU {{ number(performance.catalog.sku_count) }}</span>
                <span>客户总数 {{ number(performance.catalog.customer_count) }}</span>
                <span>广告花费渠道：{{ advertisingChannels.length ? advertisingChannels.join(' + ') : '暂无已同步渠道' }}</span>
                <span :class="performance.advertising.complete ? 'text-emerald-700' : 'text-amber-700'">{{ performance.advertising.message }}</span>
                <span>访问、加购与结账：{{ performance.behavior.available ? 'ShopifyQL' : '暂不可用' }}</span>
            </div>

            <section class="grid gap-5 xl:grid-cols-[1.35fr_.85fr]">
                <article class="relative min-h-[430px] overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div><p class="text-xs font-semibold uppercase tracking-[.18em] text-slate-400">{{ overview.period.days }} 天趋势</p><h2 class="mt-2 text-xl font-semibold text-slate-950">{{ metricDefinition.label }}随时间变化</h2><p class="mt-2 text-3xl font-semibold text-slate-950">{{ metricDefinition.money ? money(overview.summary[String(selectedMetric)] ?? 0) : number(overview.summary[String(selectedMetric)] ?? 0) }}</p></div>
                        <select v-model="selectedMetric" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700"><option v-for="option in metricOptions" :key="option.key" :value="option.key">{{ option.label }}</option></select>
                    </div>
                    <div class="mt-8 h-64 border-b border-l border-slate-200 bg-[linear-gradient(to_bottom,transparent_24%,#e2e8f0_25%,transparent_26%,transparent_49%,#e2e8f0_50%,transparent_51%,transparent_74%,#e2e8f0_75%,transparent_76%)] p-2">
                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-full w-full overflow-visible"><polyline :points="linePoints" fill="none" stroke="#0ea5e9" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </div>
                    <div class="mt-3 flex justify-between text-[11px] text-slate-400"><span>{{ overview.trend[0]?.label }}</span><span>{{ overview.trend[Math.floor(overview.trend.length / 2)]?.label }}</span><span>{{ overview.trend.at(-1)?.label }}</span></div>
                </article>

                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-950">总销售额细分</h2><p class="mt-1 text-sm text-slate-500">与 Shopify 财务口径分项展示</p></div>
                    <div class="divide-y divide-slate-100 px-6">
                        <div v-for="item in overview.sales_breakdown" :key="item.key" class="flex items-center justify-between py-4 text-sm"><span class="font-medium text-slate-600">{{ item.label }}</span><strong :class="item.value < 0 ? 'text-rose-600' : 'text-slate-950'">{{ money(item.value) }}</strong></div>
                    </div>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-3">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-950">按商品统计的销售额</h2><p class="mt-1 text-sm text-slate-500">Shopify 原生商品销售口径，按净销售额排序</p></div><Link href="/analytics/sales" class="text-sm font-semibold text-emerald-700">详细分析</Link></div>
                    <div v-if="overview.rankings.products.length" class="space-y-5 p-6">
                        <div v-for="product in overview.rankings.products.slice(0, 7)" :key="`${product.product_id}-${product.name}`">
                            <div class="mb-2 flex items-center justify-between gap-4 text-sm"><span class="min-w-0 truncate font-medium text-slate-700">{{ product.name }}</span><strong class="shrink-0 text-slate-950">{{ money(product.net_sales) }}</strong></div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-sky-400" :style="{ width: `${Math.max(3, product.net_sales / productMax * 100)}%` }" /></div>
                        </div>
                    </div>
                    <div v-else class="grid min-h-64 place-items-center p-8 text-center"><div><p class="font-semibold text-slate-700">此日期范围内无商品销售</p><p class="mt-2 text-sm text-slate-400">Shopify 原生报告返回数据后将展示排行。</p></div></div>
                </article>

                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="font-semibold text-slate-950">客户构成</h2><p class="mt-1 text-sm text-slate-500">新客户与回头客户</p>
                    <div class="mx-auto mt-8 grid h-52 w-52 place-items-center rounded-full" :style="{ background: `conic-gradient(#10b981 0 ${newCustomerDegrees}, #e2e8f0 ${newCustomerDegrees} 360deg)` }"><div class="grid h-36 w-36 place-items-center rounded-full bg-white text-center"><div><p class="text-3xl font-semibold text-slate-950">{{ overview.customers.active }}</p><p class="text-xs text-slate-400">活跃客户</p></div></div></div>
                    <div class="mt-7 grid grid-cols-2 gap-3 text-center"><div class="rounded-2xl bg-emerald-50 p-3"><p class="text-xs text-emerald-700">新客户</p><p class="mt-1 text-xl font-semibold text-slate-950">{{ overview.customers.new }}</p></div><div class="rounded-2xl bg-slate-100 p-3"><p class="text-xs text-slate-500">回头客户</p><p class="mt-1 text-xl font-semibold text-slate-950">{{ overview.customers.returning }}</p></div></div>
                    <p class="mt-4 text-center text-sm text-slate-500">复购率 {{ overview.customers.repeat_rate }}% · 客户价值 {{ overview.customers.average_lifetime_value === null ? '请查看客户价值报告' : money(overview.customers.average_lifetime_value) }}</p>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-3">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="font-semibold text-slate-950">订单状态</h2><p class="mt-1 text-sm text-slate-500">付款与发货处理进度</p>
                    <div class="mt-6 space-y-3"><div v-for="item in overview.order_statuses.financial" :key="`f-${item.status}`" class="flex justify-between rounded-xl bg-slate-50 px-4 py-3 text-sm"><span>{{ statusLabel[item.status] ?? item.status }}</span><strong>{{ item.total }}</strong></div></div>
                    <div class="mt-4 space-y-3"><div v-for="item in overview.order_statuses.fulfillment" :key="`u-${item.status}`" class="flex justify-between rounded-xl bg-blue-50/60 px-4 py-3 text-sm"><span>{{ statusLabel[item.status] ?? item.status }}</span><strong>{{ item.total }}</strong></div></div>
                </article>
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm xl:col-span-2">
                    <div class="flex items-start justify-between"><div><h2 class="font-semibold text-slate-950">库存与售罄风险</h2><p class="mt-1 text-sm text-slate-500">结合当前可售库存和周期销量</p></div><Link href="/inventory" class="text-sm font-semibold text-emerald-700">查看库存</Link></div>
                    <div class="mt-6 grid gap-3 sm:grid-cols-3"><div v-for="item in [['已缺货',overview.inventory.summary.out_of_stock],['低库存',overview.inventory.summary.low_stock],['滞销风险',overview.inventory.summary.slow_moving]]" :key="String(item[0])" class="rounded-2xl bg-slate-50 p-4"><p class="text-xs text-slate-500">{{ item[0] }}</p><p class="mt-2 text-2xl font-semibold text-slate-950">{{ item[1] }}</p></div></div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2"><div v-for="item in overview.inventory.items.slice(0, 6)" :key="item.id" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm"><div><p class="font-mono font-semibold text-slate-700">{{ item.sku }}</p><p class="mt-1 text-xs text-slate-400">可用 {{ item.available }} · 售出 {{ item.units_sold }}</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="item.risk === 'out_of_stock' ? 'bg-rose-50 text-rose-700' : item.risk === 'low_stock' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'">{{ riskLabel[item.risk] }}</span></div></div>
                </article>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="font-semibold text-slate-950">访问与转化分析</h2><p class="mt-1 text-sm text-slate-500">ShopifyQL 原生报表与已同步订单数据；不生成模拟数据。</p></div>
                    <span class="w-fit rounded-full px-3 py-1 text-xs font-semibold" :class="insights.integration.shopifyql_available ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ insights.integration.shopifyql_available ? 'ShopifyQL 已接入' : (insights.integration.report_scope_granted ? 'ShopifyQL 暂不可用' : '需重新授权 read_reports') }}</span>
                </div>
                <div class="grid md:grid-cols-2">
                    <article class="min-h-80 border-b border-slate-100 p-6 md:border-r">
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-800">访问来源</h3><p class="mt-1 text-sm text-slate-400">推荐人与访问转化</p></div><span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="insights.acquisition.available ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ insights.acquisition.available ? 'ShopifyQL' : '待授权' }}</span></div>
                        <div v-if="insights.acquisition.available && insights.acquisition.items.length" class="mt-7 space-y-4">
                            <div v-for="item in insights.acquisition.items.slice(0, 5)" :key="item.key">
                                <div class="mb-1.5 flex items-center justify-between gap-3 text-sm"><div class="min-w-0"><p class="truncate font-semibold text-slate-700">{{ item.label }}</p><p class="truncate text-xs text-slate-400">{{ item.detail }} · 转化 {{ item.conversion_rate }}%</p></div><strong class="shrink-0 text-slate-950">{{ number(item.sessions) }}</strong></div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-sky-400" :style="{ width: `${Math.max(3, item.sessions / acquisitionMax * 100)}%` }" /></div>
                            </div>
                        </div>
                        <div v-else class="mt-8 grid min-h-40 place-items-center rounded-2xl bg-slate-50 p-6 text-center"><p class="max-w-xs text-sm leading-6 text-slate-500">{{ insights.acquisition.available ? '此日期范围内没有访问来源数据。' : (insights.acquisition.error || '重新授权 read_reports 后显示访问来源。') }}</p></div>
                    </article>

                    <article class="min-h-80 border-b border-slate-100 p-6 md:border-r">
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-800">设备类型</h3><p class="mt-1 text-sm text-slate-400">桌面、移动设备和平板占比</p></div><span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="insights.devices.available ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ insights.devices.available ? 'ShopifyQL' : '待授权' }}</span></div>
                        <div v-if="insights.devices.available && insights.devices.items.length" class="mt-7 space-y-4">
                            <div v-for="item in insights.devices.items.slice(0, 5)" :key="item.key" class="rounded-2xl bg-slate-50 p-4">
                                <div class="flex items-center justify-between"><strong class="text-sm text-slate-800">{{ item.label }}</strong><span class="text-lg font-semibold text-slate-950">{{ item.share }}%</span></div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-white"><div class="h-full rounded-full bg-emerald-400" :style="{ width: `${item.share}%` }" /></div>
                                <p class="mt-2 text-xs text-slate-400">{{ number(item.sessions) }} 次访问 · 跳出率 {{ item.bounce_rate }}%</p>
                            </div>
                        </div>
                        <div v-else class="mt-8 grid min-h-40 place-items-center rounded-2xl bg-slate-50 p-6 text-center"><p class="max-w-xs text-sm leading-6 text-slate-500">{{ insights.devices.available ? '此日期范围内没有设备数据。' : (insights.devices.error || '重新授权 read_reports 后显示设备分布。') }}</p></div>
                    </article>

                    <article class="min-h-80 border-b border-slate-100 p-6 md:border-r">
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-800">访问地点</h3><p class="mt-1 text-sm text-slate-400">国家和地区分布</p></div><span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="insights.locations.available ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ insights.locations.available ? 'ShopifyQL' : '待授权' }}</span></div>
                        <div v-if="insights.locations.available && insights.locations.items.length" class="mt-7 space-y-4">
                            <div v-for="item in insights.locations.items.slice(0, 5)" :key="item.key">
                                <div class="mb-1.5 flex items-center justify-between gap-3 text-sm"><div class="min-w-0"><p class="truncate font-semibold text-slate-700">{{ item.label }}</p><p class="truncate text-xs text-slate-400">{{ item.country }} · {{ item.visitors }} 位访客</p></div><strong class="shrink-0 text-slate-950">{{ number(item.sessions) }}</strong></div>
                                <div class="h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-violet-400" :style="{ width: `${Math.max(3, item.sessions / locationMax * 100)}%` }" /></div>
                            </div>
                        </div>
                        <div v-else class="mt-8 grid min-h-40 place-items-center rounded-2xl bg-slate-50 p-6 text-center"><p class="max-w-xs text-sm leading-6 text-slate-500">{{ insights.locations.available ? '此日期范围内没有地点数据。' : (insights.locations.error || '重新授权 read_reports 后显示访问地点。') }}</p></div>
                    </article>

                    <article class="min-h-80 border-b border-slate-100 p-6 md:border-r">
                    <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-800">客户群组</h3><p class="mt-1 text-sm text-slate-400">新客户与回头客户</p></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">ShopifyQL</span></div>
                        <div class="mt-8 grid grid-cols-3 gap-3">
                            <div v-for="item in insights.customers.items" :key="item.key" class="rounded-2xl bg-slate-50 p-4 text-center"><p class="text-xs text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold text-slate-950">{{ number(item.value) }}</p></div>
                        </div>
                        <div class="mt-5 rounded-2xl bg-emerald-50 p-5"><div class="flex items-center justify-between"><span class="text-sm font-semibold text-emerald-800">回头客率</span><strong class="text-2xl text-emerald-900">{{ insights.customers.repeat_rate }}%</strong></div><p class="mt-2 text-xs leading-5 text-emerald-700">采用 Shopify 原生客户口径。</p></div>
                    </article>

                </div>
            </section>
        </div>
    </AppLayout>
</template>
