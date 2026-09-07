<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type TargetSheet = { name: string; columns: string[]; rows: Array<Record<string, unknown>> };
type TargetProgress = { key: string; label: string; actual: number | null; target: number | null; progress: number | null; format: 'number' | 'percent'; status: 'unconfigured' | 'complete' | 'attention' | 'behind' };
type EdmDashboard = NaturalTrafficDashboardBase & {
    trend_history?: Array<Record<string, unknown>>; trends: Array<Record<string, unknown>>; sequences: Array<Record<string, unknown>>;
    top_flows: Array<Record<string, unknown>>;
    sequence_columns: string[]; sequence_rows: Array<Record<string, unknown>>;
    segments: Array<{ key: string; label: string; count: number; description: string; average_lifetime_value?: number }>;
    segment_summary: { customers: number; average_lifetime_value: number }; target_progress: TargetProgress[]; target_sheets: TargetSheet[];
};
const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: EdmDashboard; configured: boolean; canSync: boolean }>();
const activeTab = ref('overview');
const trendScope = ref('history');
const visibleTrends = computed(() => trendScope.value === 'history' ? (props.dashboard.trend_history ?? props.dashboard.trends) : props.dashboard.trends);
const targetSheet = ref(props.dashboard.target_sheets[0]?.name ?? '');
const activeTarget = computed(() => props.dashboard.target_sheets.find((sheet) => sheet.name === targetSheet.value) ?? props.dashboard.target_sheets[0]);
const sequenceRowsWithShare = computed(() => props.dashboard.sequences.map((row) => ({
    ...row,
    revenue_share: Number(props.dashboard.kpis[0]?.value ?? 0) > 0 ? Number(row.revenue ?? 0) / Number(props.dashboard.kpis[0].value) * 100 : 0,
})));
function compact(value: unknown): string { return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value ?? 0)); }
function money(value: unknown): string { return new Intl.NumberFormat('en-US', { style: 'currency', currency: props.store.currency, maximumFractionDigits: 2 }).format(Number(value ?? 0)); }
function targetValue(value: number | null, format: TargetProgress['format']): string { return value === null ? '未配置' : format === 'percent' ? `${value.toFixed(2)}%` : new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value); }
function targetStatus(item: TargetProgress): string { return item.status === 'unconfigured' ? '源表未填写实际值或目标' : item.status === 'complete' ? '已达成' : item.status === 'attention' ? '接近目标' : '待追进'; }
function targetStatusClass(item: TargetProgress): string { return item.status === 'complete' ? 'text-emerald-700 bg-emerald-50' : item.status === 'attention' ? 'text-amber-700 bg-amber-50' : item.status === 'behind' ? 'text-rose-700 bg-rose-50' : 'text-slate-500 bg-slate-100'; }
</script>

<template>
    <Head title="EDM 邮件" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: 'EDM 邮件' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/edm-email" refresh-path="/natural-traffic/edm-email/refresh" :active-tab="activeTab" accent="emerald" large-filters @tab="activeTab = $event" />
            <NaturalTrafficEmptyState v-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <template v-if="activeTab === 'overview'">
                    <NaturalTrafficKpiGrid :kpis="dashboard.kpis" :currency="store.currency" :columns="6" />
                    <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-sm text-slate-500">趋势范围：{{ trendScope === 'history' ? '截至所选结束日期的最近 20 周；其他指标仍按顶部日期筛选' : '当前筛选日期范围' }}</p><select v-model="trendScope" aria-label="趋势展示范围" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="history">最近 20 周</option><option value="selected">当前筛选范围</option></select></div>
                    <div class="grid gap-6">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">Email Revenue Trend</h2><p class="mt-1 mb-5 text-xs text-slate-500">按周汇总邮件营收</p><NaturalTrafficTrendChart :points="visibleTrends" x-key="label" interactive weekly-labels fill-area :height="320" value-format="currency" :currency="store.currency" :series="[{ key: 'revenue', label: '邮件营收', color: '#059669' }]" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">核心比率趋势</h2><p class="mt-1 mb-5 text-xs text-slate-500">打开率、点击率、转化率和退订率</p><NaturalTrafficTrendChart :points="visibleTrends" x-key="label" interactive weekly-labels :height="320" value-format="percent" :series="[{ key: 'open_rate', label: '打开率', color: '#2563eb' }, { key: 'click_rate', label: '点击率', color: '#7c3aed' }, { key: 'conversion_rate', label: '转化率', color: '#10b981' }, { key: 'unsubscribe_rate', label: '退订率', color: '#ef4444' }]" /></section>
                    </div>
                    <div class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="mb-5"><h2 class="text-lg font-black text-slate-950">Top Revenue Flows</h2><p class="mt-1 text-xs text-slate-500">按序列收入排序，并显示筛选期收入贡献</p></div>
                            <div class="space-y-4"><article v-for="(flow, index) in dashboard.top_flows" :key="String(flow.name)" class="rounded-2xl bg-slate-50 p-4"><div class="flex items-center justify-between gap-4"><div class="min-w-0"><p class="truncate text-sm font-black text-slate-900">{{ index + 1 }}. {{ flow.name }}</p><p class="mt-1 text-xs text-slate-500">{{ flow.records }} 条记录 · 打开率 {{ Number(flow.open_rate ?? 0).toFixed(2) }}%</p></div><strong class="whitespace-nowrap tabular-nums text-emerald-700">{{ money(flow.revenue) }}</strong></div><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, Number(flow.revenue_share ?? 0))}%` }"></div></div><p class="mt-1.5 text-right text-[11px] font-bold text-slate-400">贡献 {{ Number(flow.revenue_share ?? 0).toFixed(2) }}%</p></article><p v-if="!dashboard.top_flows.length" class="py-12 text-center text-sm text-slate-400">当前筛选暂无序列收入</p></div>
                        </section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="mb-5"><h2 class="text-lg font-black text-slate-950">Audience Health</h2><p class="mt-1 text-xs text-slate-500">只展示当前项目数据库的聚合分层，不返回客户个人信息</p></div>
                            <div class="grid gap-3 sm:grid-cols-2"><article v-for="segment in dashboard.segments" :key="segment.key" class="rounded-2xl border border-slate-100 bg-slate-50 p-4"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-black text-slate-800">{{ segment.label }}</p><p class="mt-1 text-xs leading-5 text-slate-500">{{ segment.description }}</p></div><strong class="tabular-nums text-emerald-700">{{ compact(segment.count) }}</strong></div></article></div>
                            <div class="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-900"><strong>{{ compact(dashboard.segment_summary.customers) }}</strong> 位数据库客户 · 平均终身消费 <strong>{{ money(dashboard.segment_summary.average_lifetime_value) }}</strong></div>
                        </section>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">Flow Performance</h2><p class="mt-1 text-xs text-slate-500">序列收入、核心比率与收入贡献的完整汇总</p></div><NaturalTrafficDataTable :columns="['name', 'records', 'revenue', 'revenue_share', 'open_rate', 'click_rate', 'conversion_rate', 'unsubscribe_rate', 'latest_date']" :rows="sequenceRowsWithShare" :labels="{ name: '序列', records: '记录数', revenue: '营收', revenue_share: '收入贡献 %', open_rate: '打开率 %', click_rate: '点击率 %', conversion_rate: '转化率 %', unsubscribe_rate: '退订率 %', latest_date: '最近日期' }" /></section>
                </template>
                <template v-else-if="activeTab === 'sequences'">
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">序列表现汇总</h2><NaturalTrafficDataTable :columns="['name', 'records', 'revenue', 'open_rate', 'click_rate', 'conversion_rate', 'unsubscribe_rate', 'subscribers', 'latest_date']" :rows="dashboard.sequences" :labels="{ name: '序列', records: '记录数', revenue: '营收', open_rate: '打开率 %', click_rate: '点击率 %', conversion_rate: '转化率 %', unsubscribe_rate: '退订率 %', subscribers: '订阅者', latest_date: '最近日期' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">序列完整字段</h2><p class="mt-1 text-xs text-slate-500">当前项目数据库中的全部非敏感序列表现字段</p></div><NaturalTrafficDataTable :columns="['table_name', ...dashboard.sequence_columns]" :rows="dashboard.sequence_rows" :labels="{ table_name: '来源表' }" /></section>
                </template>
                <template v-else-if="activeTab === 'segments'">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><article v-for="segment in dashboard.segments" :key="segment.key" class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-black text-emerald-700">{{ segment.label }}</p><p class="mt-3 text-3xl font-black tabular-nums text-slate-950">{{ compact(segment.count) }}</p><p class="mt-3 text-xs leading-5 text-slate-500">{{ segment.description }}</p></article></div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">分层口径</h2><p class="mt-2 text-sm leading-6 text-slate-600">用户分层只使用当前项目已同步的 Shopify 客户汇总，不返回姓名、邮箱或电话。当前客户 {{ dashboard.segment_summary.customers.toLocaleString() }}，平均终身消费 {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: store.currency }).format(dashboard.segment_summary.average_lifetime_value) }}。</p></section>
                </template>
                <template v-else>
                    <div v-if="dashboard.target_progress.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <article v-for="item in dashboard.target_progress" :key="item.key" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-2"><p class="text-xs font-black text-slate-600">{{ item.label }}</p><span class="rounded-full px-2.5 py-1 text-[10px] font-black" :class="targetStatusClass(item)">{{ targetStatus(item) }}</span></div>
                            <p class="mt-4 text-2xl font-black tabular-nums text-slate-950">{{ targetValue(item.actual, item.format) }}</p><p class="mt-1 text-xs text-slate-500">目标 {{ targetValue(item.target, item.format) }}</p>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, Math.max(0, item.progress ?? 0))}%` }"></div></div><p class="mt-1.5 text-right text-[11px] font-bold text-slate-400">{{ item.progress === null ? '等待源表填写' : `${item.progress.toFixed(1)}%` }}</p>
                        </article>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-lg font-black text-slate-950">EDM 目标看板</h2><p class="mt-1 text-xs text-slate-500">完整展示当前项目已同步的各目标工作表</p></div><select v-if="dashboard.target_sheets.length" v-model="targetSheet" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-700"><option v-for="sheet in dashboard.target_sheets" :key="sheet.name" :value="sheet.name">{{ sheet.name }}（{{ sheet.rows.length }}）</option></select></div>
                        <NaturalTrafficDataTable v-if="activeTarget" :columns="['table_name', ...activeTarget.columns]" :rows="activeTarget.rows" :labels="{ table_name: '来源表' }" />
                        <p v-else class="py-14 text-center text-sm font-semibold text-slate-400">目标数据源暂无工作表记录</p>
                    </section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
