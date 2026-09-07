<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import DateRangeFilters from '../../Components/Analytics/DateRangeFilters.vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useDeferredReport, type ReportStorage } from '../../composables/useDeferredReport';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface Option { key: string; label: string; format?: string }
interface Header extends Option { format: string }

const props = defineProps<{
    store: { id: number; name: string; currency: string; timezone: string };
    report: {
        slug: string;
        name: string;
        description: string;
        category_label: string;
        metrics: Option[];
        dimensions: Option[];
        visualizations: string[];
        report_semantics: 'shopify_native' | 'decoadmin_custom' | 'shopify_internal';
        report_semantics_label: string;
        creator: string;
        kind: 'shopify_default' | 'shopify_custom';
        external_url: string;
        period: { days: number; from: string; to: string; include_test: boolean; include_cancelled: boolean };
        summary: Record<string, number>;
        comparisons: { previous: Record<string, { change_percent: number | null }> };
        headers: Header[];
        rows: Array<Record<string, string | number | null>>;
        selected: { metric: string; dimension: string; visualization: string };
        chart: { labels: string[]; values: number[] };
        integration: {
            source: 'local' | 'shopifyql' | 'shopify_internal';
            available: boolean;
            scope_granted: boolean | null;
            error: string | null;
            storage?: ReportStorage | null;
        };
        generated_at: string;
    };
    canExport: boolean;
}>();

const { refreshing, timedOut, retry } = useDeferredReport(() => ({
    key: `${props.store.id}:${props.report.slug}:${props.report.period.from}:${props.report.period.to}`,
    pending: props.report.integration.source === 'shopifyql' && !props.report.integration.available
        && Boolean(props.report.integration.storage?.pending || props.report.integration.storage?.refreshing),
}), ['report']);
const reportMessage = computed(() => refreshing.value ? '数据正在加载，完成后会自动显示。'
    : timedOut.value ? '加载时间较长，请重试。' : props.report.integration.error);

const controls = reactive({ ...props.report.selected });
const { formatDateTime } = useStoreDateTime();
const visualizationLabels: Record<string, string> = { line: '折线图', bar: '条形图', donut: '环形图', table: '数据表格' };
const riskLabels: Record<string, string> = { out_of_stock: '已缺货', low_stock: '低库存', slow_moving: '滞销风险', healthy: '正常' };
const selectedMetric = computed(() => props.report.metrics.find((item) => item.key === controls.metric) ?? props.report.metrics[0]);
const chartRows = computed(() => props.report.chart.labels.map((label, index) => ({ label, value: Number(props.report.chart.values[index] || 0) })).slice(0, 20));
const maxValue = computed(() => Math.max(...chartRows.value.map((item) => Math.abs(item.value)), 1));
const linePoints = computed(() => chartRows.value.map((item, index) => {
    const x = chartRows.value.length <= 1 ? 0 : index * (100 / (chartRows.value.length - 1));
    const y = 92 - (Math.abs(item.value) / maxValue.value) * 76;
    return `${x},${y}`;
}).join(' '));
const donutStops = computed(() => {
    const total = chartRows.value.reduce((sum, item) => sum + Math.max(0, item.value), 0) || 1;
    let cursor = 0;
    const colors = ['#10b981', '#38bdf8', '#8b5cf6', '#f59e0b', '#f43f5e'];
    return chartRows.value.slice(0, 5).map((item, index) => {
        const start = cursor;
        cursor += Math.max(0, item.value) / total * 360;
        return `${colors[index]} ${start}deg ${cursor}deg`;
    }).join(', ');
});
const filterExtra = computed(() => ({ ...controls }));

const format = (value: unknown, type = 'text') => {
    if (value === null || value === undefined || value === '') return '—';
    if (type === 'currency') return new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.store.currency, maximumFractionDigits: 2 }).format(Number(value));
    if (type === 'number') return Number(value).toLocaleString('zh-CN');
    if (type === 'decimal') return Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
    if (type === 'seconds') return `${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 })} 秒`;
    if (type === 'milliseconds') return `${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 })} ms`;
    if (type === 'percent') return `${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}%`;
    if (type === 'status') return riskLabels[String(value)] ?? String(value);
    return String(value);
};
const applyControls = () => router.get(`/reports/${props.report.slug}`, {
    date_from: props.report.period.from,
    date_to: props.report.period.to,
    include_test: props.report.period.include_test ? 1 : 0,
    include_cancelled: props.report.period.include_cancelled ? 1 : 0,
    ...controls,
}, { preserveState: false, preserveScroll: true, replace: true });
const exportUrl = (type: string) => {
    const query = new URLSearchParams({
        date_from: props.report.period.from, date_to: props.report.period.to,
        include_test: props.report.period.include_test ? '1' : '0',
        include_cancelled: props.report.period.include_cancelled ? '1' : '0',
        ...controls,
    });
    return `/reports/${props.report.slug}/export/${type}?${query.toString()}`;
};
</script>

<template>
    <Head :title="report.name" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '报告', href: '/reports' }, { label: report.name }]">
        <div class="mx-auto max-w-[1600px] space-y-5">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-5 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <Link href="/reports" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-emerald-700">← 返回报告目录</Link>
                    <div class="mt-4 flex flex-wrap items-center gap-3"><h1 class="text-3xl font-semibold tracking-tight text-slate-950">{{ report.name }}</h1><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ report.category_label }}</span><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="report.report_semantics === 'shopify_native' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'">{{ report.report_semantics_label }}</span></div>
                    <p class="mt-2 text-sm text-slate-500">{{ report.description }} · {{ store.name }} · {{ store.timezone }}</p>
                </div>
                <div class="flex gap-2"><a v-if="report.integration.source === 'shopify_internal'" :href="report.external_url" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm">在 Shopify 后台打开 ↗</a><template v-else-if="canExport && report.integration.available"><a :href="exportUrl('csv')" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm">导出 CSV</a><a :href="exportUrl('excel')" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm">导出 Excel</a></template></div>
            </header>

            <DateRangeFilters v-if="report.integration.source !== 'shopify_internal'" :action="`/reports/${report.slug}`" :period="report.period" :extra="filterExtra" />

            <section v-if="report.integration.source === 'shopify_internal'" class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">S</div>
                <h2 class="mt-5 text-xl font-semibold text-slate-950">此报告由 Shopify 后台提供</h2>
                <p class="mx-auto mt-2 max-w-2xl text-sm leading-7 text-slate-500">{{ report.integration.error }} 目录、类别、创建者、收藏和最近查看已在 DecoAdmin 同步；数据口径不会用本地近似算法替代。</p>
                <a :href="report.external_url" target="_blank" rel="noopener noreferrer" class="mt-6 inline-flex rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white shadow-sm">查看 Shopify 原报告 ↗</a>
            </section>

            <section v-if="report.integration.source === 'shopifyql' && !report.integration.available" class="rounded-3xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 shadow-sm">
                <p class="font-semibold">{{ refreshing ? '正在加载 Shopify 报告' : 'Shopify 原生报告暂不可用' }}</p>
                <p class="mt-1 text-amber-800">{{ reportMessage || '请检查 Shopify 连接，并重新授权 read_reports 权限。' }}</p>
            </section>

            <button v-if="timedOut" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold" @click="retry">重试加载</button>

            <section v-if="report.integration.source !== 'shopify_internal'" class="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-3">
                <label class="text-xs font-semibold text-slate-500">指标<select v-model="controls.metric" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800" @change="applyControls"><option v-for="item in report.metrics" :key="item.key" :value="item.key">{{ item.label }}</option></select></label>
                <label class="text-xs font-semibold text-slate-500">维度<select v-model="controls.dimension" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800" @change="applyControls"><option v-for="item in report.dimensions" :key="item.key" :value="item.key">{{ item.label }}</option></select></label>
                <label class="text-xs font-semibold text-slate-500">展示方式<select v-model="controls.visualization" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800" @change="applyControls"><option v-for="item in report.visualizations" :key="item" :value="item">{{ visualizationLabels[item] }}</option></select></label>
            </section>

            <section v-if="report.rows.length && controls.visualization !== 'table'" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.18em] text-slate-400">数据可视化</p><h2 class="mt-2 text-xl font-semibold text-slate-950">{{ selectedMetric.label }}</h2></div><p class="text-xs text-slate-400">更新于 {{ formatDateTime(report.generated_at) }}</p></div>

                <div v-if="controls.visualization === 'line'" class="mt-8">
                    <div class="h-72 border-b border-l border-slate-200 bg-[linear-gradient(to_bottom,transparent_24%,#e2e8f0_25%,transparent_26%,transparent_49%,#e2e8f0_50%,transparent_51%,transparent_74%,#e2e8f0_75%,transparent_76%)] p-3"><svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-full w-full overflow-visible"><polyline :points="linePoints" fill="none" stroke="#0ea5e9" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" /></svg></div>
                    <div class="mt-3 flex justify-between text-xs text-slate-400"><span>{{ chartRows[0]?.label }}</span><span>{{ chartRows[Math.floor(chartRows.length / 2)]?.label }}</span><span>{{ chartRows.at(-1)?.label }}</span></div>
                </div>

                <div v-else-if="controls.visualization === 'bar'" class="mt-8 space-y-4">
                    <div v-for="row in chartRows.slice(0, 12)" :key="row.label" class="grid gap-2 sm:grid-cols-[minmax(140px,280px)_1fr_auto] sm:items-center">
                        <p class="truncate text-sm font-medium text-slate-600">{{ row.label }}</p><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-sky-400" :style="{ width: `${Math.max(row.value ? 3 : 0, Math.abs(row.value) / maxValue * 100)}%` }" /></div><strong class="text-sm text-slate-900">{{ format(row.value, selectedMetric.format) }}</strong>
                    </div>
                </div>

                <div v-else class="mt-8 grid items-center gap-8 lg:grid-cols-[320px_1fr]">
                    <div class="mx-auto grid h-64 w-64 place-items-center rounded-full" :style="{ background: `conic-gradient(${donutStops || '#e2e8f0 0deg 360deg'})` }"><div class="grid h-40 w-40 place-items-center rounded-full bg-white text-center"><div><p class="text-3xl font-semibold text-slate-950">{{ format(chartRows.reduce((sum,row) => sum + row.value, 0), selectedMetric.format) }}</p><p class="mt-1 text-xs text-slate-400">合计</p></div></div></div>
                    <div class="space-y-3"><div v-for="(row,index) in chartRows.slice(0,5)" :key="row.label" class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 text-sm"><span class="flex min-w-0 items-center gap-2"><i class="h-2.5 w-2.5 shrink-0 rounded-full" :class="['bg-emerald-500','bg-sky-400','bg-violet-500','bg-amber-500','bg-rose-500'][index]"/><span class="truncate text-slate-600">{{ row.label }}</span></span><strong>{{ format(row.value, selectedMetric.format) }}</strong></div></div>
                </div>
            </section>

            <section v-if="report.rows.length" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-semibold text-slate-950">报告明细</h2><p class="mt-1 text-sm text-slate-500">共 {{ report.rows.length }} 条结果，按当前店铺和统计周期生成。</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th v-for="header in report.headers" :key="header.key" class="whitespace-nowrap px-5 py-4">{{ header.label }}</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="(row,index) in report.rows" :key="index" class="hover:bg-slate-50"><td v-for="header in report.headers" :key="header.key" class="whitespace-nowrap px-5 py-4 text-slate-700">{{ format(row[header.key], header.format) }}</td></tr></tbody></table></div>
            </section>
            <EmptyState v-else-if="report.integration.source !== 'shopify_internal'" :title="refreshing ? '正在加载报告' : report.integration.available ? '当前报告暂无数据' : '当前报告暂不可用'" :description="reportMessage || (report.integration.source === 'shopifyql' ? '调整统计周期，或确认 Shopify 原生报告中已有数据。' : '调整统计周期，或先同步当前店铺的 Shopify 数据。')" icon="reports" />
        </div>
    </AppLayout>
</template>
