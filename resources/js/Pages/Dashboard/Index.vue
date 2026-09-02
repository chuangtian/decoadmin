<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DashboardDateRangePicker from '../../Components/Dashboard/DashboardDateRangePicker.vue';
import DashboardMetricChart from '../../Components/Dashboard/DashboardMetricChart.vue';
import AppIcon from '../../Components/Layout/AppIcon.vue';
import ShopifyConnectionStatus from '../../Components/Shopify/ShopifyConnectionStatus.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { ShopifyConnectionStatus as ConnectionStatus } from '../../types';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface DashboardData {
    store: { id: number; name: string; shopify_domain: string; currency: string } | null;
    summary: {
        orders: number;
        orders_today: number;
        sales: number;
        sales_today: number;
        products: number;
        customers: number;
        inventory_available: number;
    };
    operations: {
        connection_status: ConnectionStatus | 'pending';
        last_sync_at: string | null;
        open_alerts: number;
        failed_sync_jobs_24h: number;
        failed_webhooks_24h: number;
    };
    sales_trend: Array<{ date: string; label: string; amount: number; orders: number }>;
    recent_orders: Array<{
        id: number;
        order_number: string;
        email: string | null;
        financial_status: string | null;
        total_price: string;
        currency: string;
        processed_at: string | null;
    }>;
    analytics_30d: {
        summary: { sales: string; orders: number; average_order_value: string };
        trend: Array<{ date: string; label: string; sales: number; orders: number }>;
        top_products: Array<{ product_id: number | null; title: string; units: number; revenue: number }>;
        low_stock: Array<{ id: number; sku: string; available: number }>;
    };
    analytics: {
        period: { days: number; from: string; to: string; timezone: string; include_test: boolean; include_cancelled: boolean };
        comparison: { mode: 'none' | 'previous' | 'year' | 'custom'; label: string; period: { days: number; from: string; to: string; timezone: string } | null };
        summary: Record<string, number | string>;
        comparisons: { previous: Record<string, { change_percent: number | null }> };
        trend: Array<{ date: string; label: string; sales: number; orders: number; [key: string]: string | number }>;
        comparison_trend: { previous: Array<{ date: string; label: string; sales: number; orders: number; [key: string]: string | number }> };
        data_source?: { pending?: boolean; comparison_pending?: boolean; notice?: string | null };
    };
    metric_definitions: Array<{ key: string; label: string; format: 'currency' | 'number'; description: string }>;
    store_comparison: { stores: Array<{ id: number; name: string; currency: string; orders: number; sales: number }> };
}

const props = defineProps<{ dashboard: DashboardData }>();
const defaultMetrics = ['total_sales', 'orders', 'average_order_value', 'refunds'];
const legacyDefaultMetrics = ['net_sales', 'orders', 'average_order_value', 'refunds'];
const selectedMetrics = ref<string[]>([...defaultMetrics]);
const activeSlot = ref(0);
const pickerSlot = ref<number | null>(null);
const metricSearch = ref('');

const storageKey = computed(() => `dashboard_metric_slots:${props.dashboard.store?.id ?? 'none'}`);
const currentDataPending = computed(() => Boolean(props.dashboard.analytics.data_source?.pending));
const comparisonDataPending = computed(() => Boolean(props.dashboard.analytics.data_source?.comparison_pending));
const analyticsPending = computed(() => currentDataPending.value || comparisonDataPending.value);
const selectedMetric = computed(() => props.dashboard.metric_definitions.find((item) => item.key === selectedMetrics.value[activeSlot.value]) ?? props.dashboard.metric_definitions[0]);
const filteredMetrics = computed(() => {
    const needle = metricSearch.value.trim().toLowerCase();
    return props.dashboard.metric_definitions.filter((item) => !needle || `${item.label} ${item.description} ${item.key}`.toLowerCase().includes(needle));
});
const toggleMetricPicker = (index: number) => {
    activeSlot.value = index;
    pickerSlot.value = pickerSlot.value === index ? null : index;
    metricSearch.value = '';
};

onMounted(() => {
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey.value) || '[]');
        const allowed = new Set(props.dashboard.metric_definitions.map((item) => item.key));
        if (Array.isArray(saved) && saved.length === 4 && saved.every((key) => allowed.has(key))) {
            const isLegacyDefault = saved.every((key, index) => key === legacyDefaultMetrics[index]);
            selectedMetrics.value = isLegacyDefault ? [...defaultMetrics] : saved;
            if (isLegacyDefault) localStorage.setItem(storageKey.value, JSON.stringify(defaultMetrics));
        }
    } catch {
        selectedMetrics.value = [...defaultMetrics];
    }
    scheduleAnalyticsRefresh();
});

const metricDefinition = (key: string) => props.dashboard.metric_definitions.find((item) => item.key === key) ?? props.dashboard.metric_definitions[0];
const formatMetric = (key: string, value: string | number) => metricDefinition(key)?.format === 'currency' ? money(value) : number(Number(value || 0));
const comparisonEnabled = computed(() => props.dashboard.analytics.comparison.mode !== 'none');
const comparison = (key: string) => comparisonEnabled.value ? (props.dashboard.analytics.comparisons.previous[key]?.change_percent ?? null) : null;
const comparisonLabel = (key: string) => comparisonDataPending.value
    ? '数据准备中…'
    : comparison(key) === null ? '暂无对比' : `${comparison(key)! > 0 ? '+' : ''}${comparison(key)!.toFixed(1)}%`;
const rangeText = (points: Array<{ date: string }>, fallback: string) => points.length
    ? `${shortDate(points[0].date, true)}–${shortDate(points[points.length - 1].date, points[0].date.slice(0, 4) !== points[points.length - 1].date.slice(0, 4))}`
    : fallback;
const shortDate = (value: string, includeYear = false) => {
    const [year, month, day] = value.split('-');
    return `${includeYear ? `${Number(year)}年` : ''}${Number(month)}月${Number(day)}日`;
};

const chooseMetric = (key: string) => {
    if (pickerSlot.value === null) return;
    selectedMetrics.value[pickerSlot.value] = key;
    activeSlot.value = pickerSlot.value;
    selectedMetrics.value = [...selectedMetrics.value];
    localStorage.setItem(storageKey.value, JSON.stringify(selectedMetrics.value));
    pickerSlot.value = null;
    metricSearch.value = '';
};

const applyPeriod = (filters: { days: number; date_from: string; date_to: string; comparison: 'none' | 'previous' | 'year' | 'custom'; comparison_date_from?: string; comparison_date_to?: string }) => router.get('/dashboard', filters, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

let analyticsRefreshTimer: number | null = null;
let analyticsRefreshAttempts = 0;
const analyticsRefreshLimit = 40;
const scheduleAnalyticsRefresh = () => {
    if (analyticsRefreshTimer !== null) window.clearTimeout(analyticsRefreshTimer);
    analyticsRefreshTimer = null;
    if (!analyticsPending.value || analyticsRefreshAttempts >= analyticsRefreshLimit) return;

    analyticsRefreshTimer = window.setTimeout(() => {
        analyticsRefreshAttempts += 1;
        router.reload({
            only: ['dashboard'],
            onFinish: scheduleAnalyticsRefresh,
        });
    }, 1500);
};

watch(analyticsPending, (pending) => {
    if (!pending) analyticsRefreshAttempts = 0;
    scheduleAnalyticsRefresh();
});
onBeforeUnmount(() => {
    if (analyticsRefreshTimer !== null) window.clearTimeout(analyticsRefreshTimer);
});

const money = (value: number | string, currency?: string) => new Intl.NumberFormat('zh-CN', {
    style: 'currency',
    currency: currency || props.dashboard.store?.currency || 'USD',
}).format(Number(value || 0));

const number = (value: number) => new Intl.NumberFormat('zh-CN').format(value);

const { formatDateTime: storeDateTime } = useStoreDateTime();
const dateTime = (value: string | null) => value ? storeDateTime(value) : '暂无记录';

const financialLabel = (status: string | null) => ({
    paid: '已付款', pending: '待付款', refunded: '已退款', partially_refunded: '部分退款', voided: '已作废', authorized: '已授权',
}[status || ''] || status || '未知');
</script>

<template>
    <Head title="工作台" />
    <AppLayout :breadcrumbs="[{ label: '工作台' }]">
        <section v-if="!dashboard.store" class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <AppIcon name="stores" :size="34" class="mx-auto text-slate-300" />
            <h2 class="mt-4 text-lg font-semibold text-slate-900">请先选择店铺</h2>
            <p class="mt-2 text-sm text-slate-500">选择有权限的店铺后，这里会显示该店铺的真实运营数据。</p>
        </section>

        <template v-else>
            <section class="relative rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ dashboard.store.name }}</p><h1 class="mt-1 text-xl font-semibold text-slate-950">经营数据概览</h1></div>
                    <DashboardDateRangePicker :period="dashboard.analytics.period" :comparison="dashboard.analytics.comparison" @apply="applyPeriod" />
                </div>
                <div class="grid divide-y divide-slate-100 lg:grid-cols-4 lg:divide-x lg:divide-y-0">
                    <article v-for="(key,index) in selectedMetrics" :key="`${index}-${key}`" role="button" tabindex="0" class="group relative min-h-32 cursor-pointer p-5 text-left transition hover:bg-slate-50" :class="activeSlot === index ? 'bg-emerald-50/50' : ''" @click="activeSlot = index" @keydown.enter="activeSlot = index">
                        <span class="flex items-start justify-between gap-3"><span class="text-sm font-semibold text-slate-500">{{ metricDefinition(key)?.label }}</span><button type="button" class="grid h-8 w-8 place-items-center rounded-xl text-slate-400 opacity-70 transition hover:bg-white hover:text-emerald-700 group-hover:opacity-100" :aria-label="`更换${metricDefinition(key)?.label}`" @click.stop="toggleMetricPicker(index)">✎</button></span>
                        <strong class="mt-4 block font-semibold tracking-tight" :class="currentDataPending ? 'text-base text-slate-400' : 'text-2xl text-slate-950'">{{ currentDataPending ? '数据准备中…' : formatMetric(key, dashboard.analytics.summary[key] ?? 0) }}</strong>
                        <span class="mt-2 inline-flex items-center gap-1 text-xs font-semibold" :class="comparison(key) === null ? 'text-slate-400' : comparison(key)! >= 0 ? 'text-emerald-700' : 'text-rose-600'">{{ comparisonEnabled ? '环比' : '对比' }} {{ comparisonEnabled ? comparisonLabel(key) : '未开启' }}</span>
                        <div v-if="pickerSlot === index" class="absolute top-[-6rem] z-40 w-[min(460px,calc(100vw_-_2rem))] cursor-default rounded-3xl border border-slate-200 bg-white p-5 text-left shadow-2xl shadow-slate-900/20" :class="index < 2 ? 'left-[calc(100%+0.75rem)]' : 'right-[calc(100%+0.75rem)]'" @click.stop>
                            <div class="flex shrink-0 items-center justify-between"><div><p class="text-base font-semibold text-slate-900">选择指标</p><p class="mt-1 text-sm text-slate-400">第 {{ index + 1 }} 个指标</p></div><button type="button" class="grid h-10 w-10 place-items-center rounded-xl text-slate-400 hover:bg-slate-100" @click="pickerSlot = null">×</button></div>
                            <input v-model="metricSearch" type="search" placeholder="搜索指标" class="mt-5 w-full shrink-0 rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            <div class="mt-4 max-h-80 space-y-1 overflow-y-auto pr-1">
                                <button v-for="metric in filteredMetrics" :key="metric.key" type="button" class="flex w-full items-center justify-between rounded-2xl px-4 py-3 text-left transition hover:bg-slate-50" @click="chooseMetric(metric.key)"><span><strong class="block text-sm text-slate-800">{{ metric.label }}</strong><small class="mt-1 block text-xs text-slate-400">{{ metric.description }}</small></span><span v-if="selectedMetrics[index] === metric.key" class="text-emerald-600">✓</span></button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <div v-if="analyticsPending" class="mt-4 flex items-center gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-700">
                <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-sky-500" />
                {{ currentDataPending ? '统计数据正在准备，完成后页面会自动刷新。' : '对比数据正在准备，完成后页面会自动刷新。' }}
            </div>

            <DashboardMetricChart v-if="!currentDataPending" class="mt-5" :current="dashboard.analytics.trend" :previous="dashboard.analytics.comparison_trend.previous" :metric="selectedMetric.key" :label="selectedMetric.label" :format="selectedMetric.format" :currency="dashboard.store.currency" :value="dashboard.analytics.summary[selectedMetric.key] ?? 0" :current-range="rangeText(dashboard.analytics.trend, '当前周期')" :previous-range="rangeText(dashboard.analytics.comparison_trend.previous, dashboard.analytics.comparison.label)" />
            <article v-else class="mt-5 grid min-h-80 place-items-center rounded-3xl border border-slate-200 bg-white text-sm font-medium text-slate-400 shadow-sm">统计数据准备中…</article>

            <section class="mt-5 grid gap-5 xl:grid-cols-2">
                <article class="rounded-3xl bg-[#0d1828] p-6 text-white shadow-xl shadow-slate-900/8">
                    <p class="text-xs font-semibold tracking-wider text-slate-500">运营状态</p>
                    <div class="mt-5 flex items-center justify-between border-b border-white/10 pb-5">
                        <span class="text-sm text-slate-300">Shopify 连接</span>
                        <ShopifyConnectionStatus :status="dashboard.operations.connection_status" />
                    </div>
                    <div class="divide-y divide-white/10">
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">可用库存</span><strong>{{ number(dashboard.summary.inventory_available) }}</strong></div>
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">未处理告警</span><Link href="/alerts" class="font-semibold text-amber-300">{{ dashboard.operations.open_alerts }}</Link></div>
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">24 小时同步失败</span><strong :class="dashboard.operations.failed_sync_jobs_24h ? 'text-rose-300' : 'text-emerald-300'">{{ dashboard.operations.failed_sync_jobs_24h }}</strong></div>
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">24 小时 Webhook 失败</span><strong :class="dashboard.operations.failed_webhooks_24h ? 'text-rose-300' : 'text-emerald-300'">{{ dashboard.operations.failed_webhooks_24h }}</strong></div>
                    </div>
                    <p class="mt-4 text-xs text-slate-500">最近同步：{{ dateTime(dashboard.operations.last_sync_at) }}</p>
                </article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">热销商品</h2><p class="mt-1 text-xs text-slate-400">最近 30 天</p></div><Link href="/analytics/sales" class="text-sm font-semibold text-emerald-700">查看全部</Link></div>
                    <div v-if="dashboard.analytics_30d.top_products.length" class="divide-y divide-slate-100"><div v-for="(product,index) in dashboard.analytics_30d.top_products.slice(0,5)" :key="`${product.product_id}-${product.title}`" class="flex items-center gap-4 px-6 py-4"><span class="grid h-8 w-8 place-items-center rounded-xl bg-emerald-50 text-xs font-semibold text-emerald-700">{{index+1}}</span><div class="min-w-0 flex-1"><Link v-if="product.product_id" :href="`/products/${product.product_id}`" class="block truncate font-semibold text-slate-900">{{product.title}}</Link><p v-else class="truncate font-semibold text-slate-900">{{product.title}}</p></div><span class="text-sm font-semibold text-slate-600">{{product.units}} 件</span></div></div>
                    <p v-else class="px-6 py-10 text-center text-sm text-slate-400">暂无商品销量数据。</p>
                </article>
            </section>

            <section class="mt-5 grid gap-5 xl:grid-cols-2">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">库存预警</h2><p class="mt-1 text-xs text-slate-400">可用库存不高于 10</p></div><Link href="/inventory" class="text-sm font-semibold text-emerald-700">库存管理</Link></div><div v-if="dashboard.analytics_30d.low_stock.length" class="divide-y divide-slate-100"><div v-for="item in dashboard.analytics_30d.low_stock.slice(0,5)" :key="item.id" class="flex items-center justify-between px-6 py-4"><Link :href="`/inventory/${item.id}`" class="font-mono text-sm font-semibold text-slate-900">{{item.sku}}</Link><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="item.available<=0?'bg-rose-50 text-rose-700':'bg-amber-50 text-amber-700'">{{item.available}} 可用</span></div></div><p v-else class="px-6 py-10 text-center text-sm text-slate-400">当前库存充足。</p></article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">店铺对比</h2><p class="mt-1 text-xs text-slate-400">授权店铺 30 天销售额</p></div><Link href="/analytics/stores" class="text-sm font-semibold text-emerald-700">详细对比</Link></div><div class="divide-y divide-slate-100"><div v-for="store in dashboard.store_comparison.stores.slice(0,5)" :key="store.id" class="flex items-center justify-between px-6 py-4"><div><p class="font-semibold text-slate-900">{{store.name}}</p><p class="mt-1 text-xs text-slate-400">{{store.orders}} 单</p></div><strong>{{money(store.sales,store.currency)}}</strong></div></div></article>
            </section>

            <section class="mt-5 rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-900">最近订单</h2><Link href="/orders" class="text-sm font-semibold text-emerald-700">全部订单</Link></div>
                <div v-if="dashboard.recent_orders.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500"><tr><th class="px-6 py-3">订单</th><th class="px-6 py-3">客户</th><th class="px-6 py-3">付款状态</th><th class="px-6 py-3">金额</th><th class="px-6 py-3">时间</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="order in dashboard.recent_orders" :key="order.id"><td class="px-6 py-4"><Link :href="`/orders/${order.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ order.order_number }}</Link></td><td class="px-6 py-4 text-slate-500">{{ order.email || '未提供' }}</td><td class="px-6 py-4 text-slate-600">{{ financialLabel(order.financial_status) }}</td><td class="px-6 py-4 font-semibold text-slate-900">{{ money(order.total_price, order.currency) }}</td><td class="px-6 py-4 text-slate-500">{{ dateTime(order.processed_at) }}</td></tr></tbody>
                    </table>
                </div>
                <p v-else class="px-6 py-10 text-center text-sm text-slate-400">当前店铺暂时没有已同步订单。</p>
            </section>
        </template>
    </AppLayout>
</template>
