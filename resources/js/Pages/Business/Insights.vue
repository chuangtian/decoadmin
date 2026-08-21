<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DashboardComparisonPicker from '../../Components/Dashboard/DashboardComparisonPicker.vue';
import DashboardDateRangePicker from '../../Components/Dashboard/DashboardDateRangePicker.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

type ComparisonMode = 'none' | 'previous' | 'year' | 'year_weekday' | 'custom';
interface ComparisonMetric { current: number; baseline: number; change: number; change_percent: number | null }
interface SalesRow { id?: string; key?: string; name: string; orders: number; net_sales: number; total_sales: number; comparison: { orders: ComparisonMetric; net_sales: ComparisonMetric; total_sales: ComparisonMetric } | null }
interface StaffRow { id: string; name: string; orders: number; units: number; attributed_sales: number }
interface FunnelRow { key: string; label: string; sessions: number; rate: number; comparison: { sessions: ComparisonMetric; rate: ComparisonMetric } | null }
interface MetricComparisons { [key: string]: ComparisonMetric }

const props = defineProps<{
    store: { id: number; name: string; currency: string; timezone: string };
    insights: {
        period: { days: number; from: string; to: string; timezone: string };
        comparison: { mode: ComparisonMode; label: string; period: { days: number; from: string; to: string; timezone: string } | null };
        channels: SalesRow[];
        pos_locations: SalesRow[];
        pos_staff: StaffRow[];
        traffic: { sessions: number; page_views: number; product_view_sessions: number; converted_sessions: number; conversion_rate: number; comparison: MetricComparisons | null };
        search: { searches: number; sessions: number; converted_sessions: number; conversion_rate: number; top_queries: { query: string; searches: number; sessions: number | null }[]; comparison: MetricComparisons | null };
        funnel: FunnelRow[];
        coverage: { orders: number; channel_orders: number; pos_orders: number; pos_location_orders: number; pos_staff_line_items: number };
        integration: {
            order_sync_ready: boolean;
            reports_ready: boolean;
            report_scope_granted: boolean;
            report_errors: Record<string, string>;
            pixel_receiving: boolean;
            last_event_at: string | null;
            collector_endpoint: string;
            collector_endpoint_public: boolean;
            pixel_snippet: string;
            missing_order_scopes: string[];
            missing_report_scopes: string[];
            missing_app_pixel_scopes: string[];
            steps: string[];
        };
        data_source: {
            primary: 'shopifyql' | 'local';
            reports_available: boolean;
            report_errors: Record<string, string>;
            comparison_primary: 'shopifyql' | 'local' | null;
            storage: { persisted: boolean; source: 'database' | null; stale: boolean; pending?: boolean; refreshing?: boolean; fetched_at: string | null; expires_at: string | null };
            comparison_storage: { persisted: boolean; source: 'database' | null; stale: boolean; pending?: boolean; refreshing?: boolean; fetched_at: string | null; expires_at: string | null } | null;
        };
        privacy: { raw_ip_collected: boolean; raw_payload_stored: boolean; identifiers: string; search_query: string };
        generated_at: string;
    };
}>();

const copied = ref(false);
const loading = ref(false);
const { formatDateTime } = useStoreDateTime();
const money = (value: number) => new Intl.NumberFormat('zh-CN', {
    style: 'currency', currency: props.store.currency || 'USD', maximumFractionDigits: 2,
}).format(Number(value || 0));
const number = (value: number) => new Intl.NumberFormat('zh-CN').format(Number(value || 0));
const funnelMax = computed(() => Math.max(props.insights.funnel[0]?.sessions ?? 0, 1));
const channelCoverage = computed(() => props.insights.coverage.orders > 0
    ? Math.round(props.insights.coverage.channel_orders / props.insights.coverage.orders * 100)
    : 0);

const metricCards = computed(() => [
    { label: '访问 Session', value: number(props.insights.traffic.sessions), detail: '周期内去重会话', comparison: props.insights.traffic.comparison?.sessions ?? null },
    { label: '页面浏览', value: number(props.insights.traffic.page_views), detail: 'page_viewed 事件', comparison: props.insights.traffic.comparison?.page_views ?? null },
    { label: '搜索次数', value: number(props.insights.search.searches), detail: `${props.insights.search.sessions} 个搜索会话`, comparison: props.insights.search.comparison?.searches ?? null },
    { label: '购买转化率', value: `${props.insights.traffic.conversion_rate}%`, detail: `${props.insights.traffic.converted_sessions} 个完成购买会话`, comparison: props.insights.traffic.comparison?.conversion_rate ?? null },
]);

function comparisonFilters() {
    const filters: Record<string, string> = { comparison: props.insights.comparison.mode };
    if (props.insights.comparison.mode === 'custom' && props.insights.comparison.period) {
        filters.comparison_date_from = props.insights.comparison.period.from;
        filters.comparison_date_to = props.insights.comparison.period.to;
    }
    return filters;
}

function visit(filters: Record<string, string | number | undefined>) {
    loading.value = true;
    router.get('/business/insights', filters, {
        preserveState: false,
        preserveScroll: true,
        replace: true,
        onFinish: () => { loading.value = false; },
    });
}

const applyPeriod = (filters: { days: number; date_from: string; date_to: string }) => visit({ ...filters, ...comparisonFilters() });
const applyQuickRange = (days: number) => visit({ days, ...comparisonFilters() });
const applyComparison = (filters: { comparison: ComparisonMode; comparison_date_from?: string; comparison_date_to?: string }) => visit({
    days: props.insights.period.days,
    date_from: props.insights.period.from,
    date_to: props.insights.period.to,
    ...filters,
});

function trendClass(metric: ComparisonMetric | null | undefined) {
    if (metric?.change_percent === null || metric?.change_percent === undefined || metric.change_percent === 0) return 'text-slate-400';
    return metric.change_percent > 0 ? 'text-emerald-600' : 'text-rose-600';
}

function trendText(metric: ComparisonMetric | null | undefined) {
    if (!metric || metric.change_percent === null) return '暂无可比基数';
    if (metric.change_percent === 0) return '— 0%';
    return `${metric.change_percent > 0 ? '↑' : '↓'} ${Math.abs(metric.change_percent).toFixed(2).replace(/\.00$/, '')}%`;
}

async function copyPixel() {
    await navigator.clipboard.writeText(props.insights.integration.pixel_snippet);
    copied.value = true;
    window.setTimeout(() => { copied.value = false; }, 1800);
}
</script>

<template>
    <Head title="渠道与转化" />
    <AppLayout :breadcrumbs="[{ label: '数据分析', href: '/analytics/overview' }, { label: '渠道与转化' }]">
        <div class="mx-auto max-w-[1600px] space-y-5">
            <header class="flex flex-col gap-4 border-b border-slate-200 pb-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-semibold tracking-tight text-slate-950">渠道与转化</h1>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ store.name }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">销售渠道、访问 Session、搜索行为和购买漏斗</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2" :class="loading ? 'pointer-events-none opacity-60' : ''">
                    <button v-for="days in [7, 30, 90]" :key="days" type="button" class="rounded-2xl border px-4 py-3 text-sm font-semibold shadow-sm transition" :class="insights.period.days === days ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'" @click="applyQuickRange(days)">{{ days }} 天</button>
                    <DashboardDateRangePicker :period="insights.period" @apply="applyPeriod" />
                    <DashboardComparisonPicker :comparison="insights.comparison" :current-period="insights.period" @apply="applyComparison" />
                </div>
            </header>

            <section class="grid gap-3 lg:grid-cols-3">
                <article class="flex items-start gap-4 rounded-2xl border bg-white p-5 shadow-sm" :class="insights.integration.order_sync_ready ? 'border-emerald-200' : 'border-amber-200'">
                    <span class="mt-0.5 h-3 w-3 rounded-full" :class="insights.integration.order_sync_ready ? 'bg-emerald-500' : 'bg-amber-500'" />
                    <div><h2 class="font-semibold text-slate-900">订单渠道与 POS 同步</h2><p class="mt-1 text-sm text-slate-500">{{ insights.integration.order_sync_ready ? `已具备权限；当前周期渠道覆盖 ${channelCoverage}%` : `缺少 ${insights.integration.missing_order_scopes.join(', ')}` }}</p></div>
                </article>
                <article class="flex items-start gap-4 rounded-2xl border bg-white p-5 shadow-sm" :class="insights.integration.reports_ready ? 'border-emerald-200' : 'border-amber-200'">
                    <span class="mt-0.5 h-3 w-3 rounded-full" :class="insights.integration.reports_ready ? 'bg-emerald-500' : 'bg-amber-500'" />
                    <div><h2 class="font-semibold text-slate-900">Shopify 原生报表</h2><p class="mt-1 text-sm text-slate-500">{{ insights.integration.reports_ready ? 'read_reports 已生效，当前数据优先使用 ShopifyQL 口径' : (insights.integration.report_scope_granted ? '权限已存在，但部分报表查询失败，失败项已使用本地数据' : '缺少 read_reports，请重新授权后同步 Shopify 原生统计') }}</p><p v-if="insights.data_source.storage.persisted" class="mt-1 text-xs font-semibold" :class="insights.data_source.storage.stale ? 'text-amber-600' : 'text-emerald-600'">{{ insights.data_source.storage.pending ? 'Shopify 数据正在后台加载，当前先显示本地同步数据' : (insights.data_source.storage.stale ? '正在使用数据库中的最近一次快照' : '数据已保存到本地数据库') }}<span v-if="insights.data_source.storage.fetched_at && !insights.data_source.storage.pending" class="font-normal text-slate-400"> · {{ formatDateTime(insights.data_source.storage.fetched_at) }}</span></p></div>
                </article>
                <article class="flex items-start gap-4 rounded-2xl border bg-white p-5 shadow-sm" :class="insights.integration.pixel_receiving ? 'border-emerald-200' : 'border-amber-200'">
                    <span class="mt-0.5 h-3 w-3 rounded-full" :class="insights.integration.pixel_receiving ? 'bg-emerald-500' : 'bg-amber-500'" />
                    <div><h2 class="font-semibold text-slate-900">店面客户事件（备用）</h2><p class="mt-1 text-sm text-slate-500">{{ insights.integration.pixel_receiving ? `正在接收，最近事件 ${formatDateTime(insights.integration.last_event_at)}` : (insights.integration.reports_ready ? '原生报表已可用，无需额外 Pixel 也能显示 Session、搜索和漏斗' : '尚未收到事件，可按页面底部步骤接入 Shopify Customer Events') }}</p></div>
                </article>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="card in metricCards" :key="card.label" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold text-slate-500">{{ card.label }}</p>
                    <div class="mt-3 flex flex-wrap items-end gap-x-3 gap-y-1"><p class="text-3xl font-semibold text-slate-950">{{ card.value }}</p><span v-if="insights.comparison.mode !== 'none'" class="pb-1 text-sm font-semibold" :class="trendClass(card.comparison)">{{ trendText(card.comparison) }}</span></div>
                    <p class="mt-2 text-xs text-slate-400">{{ card.detail }} · {{ insights.comparison.label }}</p>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-950">销售渠道</h2><p class="mt-1 text-sm text-slate-500">来源应用、Online Store、POS 或第三方渠道 · {{ insights.data_source.primary === 'shopifyql' ? 'ShopifyQL 口径' : '本地订单口径' }}</p></div>
                    <div v-if="insights.channels.length" class="overflow-x-auto"><table class="w-full min-w-[720px] text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-6 py-3">渠道</th><th class="px-4 py-3">订单</th><th class="px-4 py-3">净销售额</th><th class="px-6 py-3 text-right">总销售额</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="row in insights.channels" :key="`${row.key}-${row.name}`"><td class="px-6 py-4 font-semibold text-slate-900">{{ row.name }}</td><td class="px-4 py-4"><strong class="block text-slate-800">{{ number(row.orders) }}</strong><span v-if="insights.comparison.mode !== 'none'" class="mt-1 block text-xs font-semibold" :class="trendClass(row.comparison?.orders)">{{ trendText(row.comparison?.orders) }}</span></td><td class="px-4 py-4"><strong class="block text-slate-800">{{ money(row.net_sales) }}</strong><span v-if="insights.comparison.mode !== 'none'" class="mt-1 block text-xs font-semibold" :class="trendClass(row.comparison?.net_sales)">{{ trendText(row.comparison?.net_sales) }}</span></td><td class="px-6 py-4 text-right"><strong class="block text-slate-900">{{ money(row.total_sales) }}</strong><span v-if="insights.comparison.mode !== 'none'" class="mt-1 block text-xs font-semibold" :class="trendClass(row.comparison?.total_sales)">{{ trendText(row.comparison?.total_sales) }}</span></td></tr></tbody></table></div>
                    <p v-else class="px-6 py-12 text-center text-sm text-slate-400">重新同步订单后显示销售渠道。</p>
                </article>

                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="font-semibold text-slate-950">转化漏斗</h2><p class="mt-1 text-sm text-slate-500">按去重 Session 统计，每个阶段相对全部访问的比例</p>
                    <div class="mt-6 space-y-4"><div v-for="stage in insights.funnel" :key="stage.key"><div class="mb-2 flex items-center justify-between gap-4 text-sm"><span class="font-semibold text-slate-700">{{ stage.label }}</span><span class="flex flex-wrap items-center justify-end gap-2 text-slate-500"><span>{{ number(stage.sessions) }} · {{ stage.rate }}%</span><strong v-if="insights.comparison.mode !== 'none'" class="text-xs" :class="trendClass(stage.comparison?.sessions)">{{ trendText(stage.comparison?.sessions) }}</strong></span></div><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-sky-500" :style="{ width: `${Math.max(2, stage.sessions / funnelMax * 100)}%` }" /></div></div></div>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-950">搜索行为</h2><p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500"><span>搜索会话购买转化率 {{ insights.search.conversion_rate }}%</span><strong v-if="insights.comparison.mode !== 'none'" class="text-xs" :class="trendClass(insights.search.comparison?.conversion_rate)">{{ trendText(insights.search.comparison?.conversion_rate) }}</strong></p></div><span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700">搜索词已脱敏</span></div>
                    <div v-if="insights.search.top_queries.length" class="divide-y divide-slate-100"><div v-for="row in insights.search.top_queries" :key="row.query" class="grid grid-cols-[minmax(0,1fr)_90px_90px] gap-3 px-6 py-4 text-sm"><strong class="truncate text-slate-900">{{ row.query }}</strong><span class="text-slate-500">{{ row.searches }} 次</span><span class="text-right text-slate-500">{{ row.sessions === null ? 'Shopify' : `${row.sessions} 会话` }}</span></div></div>
                    <p v-else class="px-6 py-12 text-center text-sm text-slate-400">接入 Customer Events 后显示站内搜索词与搜索转化。</p>
                </article>

                <article class="rounded-3xl border border-slate-200 bg-slate-950 p-6 text-white shadow-sm">
                    <h2 class="font-semibold">数据覆盖</h2><p class="mt-1 text-sm text-slate-400">用于判断“数据为什么比 Shopify 少”</p>
                    <div class="mt-6 space-y-3 text-sm"><div v-for="item in [
                        ['周期订单',insights.coverage.orders],['带销售渠道',insights.coverage.channel_orders],['POS 订单',insights.coverage.pos_orders],['带 POS 地点',insights.coverage.pos_location_orders],['带员工归属行项目',insights.coverage.pos_staff_line_items],
                    ]" :key="String(item[0])" class="flex justify-between border-b border-white/10 pb-3"><span class="text-slate-300">{{ item[0] }}</span><strong>{{ number(Number(item[1])) }}</strong></div></div>
                    <p class="mt-5 text-xs leading-5 text-slate-500">隐私：不采集原始 IP，不保存原始事件载荷；访客和 Session 标识仅保存 HMAC-SHA256 哈希。</p>
                </article>
            </section>

            <section v-if="insights.integration.steps.length" class="rounded-3xl border border-amber-200 bg-amber-50/60 p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="font-semibold text-amber-950">还缺这些接入步骤</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-amber-900"><li v-for="step in insights.integration.steps" :key="step">{{ step }}</li></ol></div><span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-amber-800">需要店铺管理员</span></div>
                <div class="mt-5 rounded-2xl border border-amber-200 bg-white p-4"><div class="flex items-center justify-between gap-3"><div><h3 class="text-sm font-semibold text-slate-900">Shopify 自定义 Pixel 代码</h3><p class="mt-1 text-xs text-slate-500">接收端：{{ insights.integration.collector_endpoint }}</p></div><button type="button" class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white" @click="copyPixel">{{ copied ? '已复制' : '复制代码' }}</button></div><textarea readonly :value="insights.integration.pixel_snippet" class="mt-4 h-64 w-full resize-y rounded-xl border border-slate-200 bg-slate-950 p-4 font-mono text-xs leading-5 text-emerald-300" /></div>
            </section>
        </div>
    </AppLayout>
</template>
