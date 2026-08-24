<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
import NaturalTrafficScatterPlot from '../../Components/NaturalTraffic/NaturalTrafficScatterPlot.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type KolRow = Record<string, unknown> & { source_fields?: Record<string, unknown> };
type KolDashboard = NaturalTrafficDashboardBase & {
    trends: Array<Record<string, unknown>>; top_influencers: Array<Record<string, unknown>>; platforms: Array<Record<string, unknown>>;
    scatter: Array<Record<string, unknown>>; model_summary: Array<Record<string, unknown>>; viral_content: KolRow[];
    details: KolRow[]; resources: Array<Record<string, unknown>>; columns: string[];
};
const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: KolDashboard; configured: boolean; canSync: boolean }>();
const activeTab = ref('tracking');
const normalizedColumns = ['influencer', 'platform', 'type', 'fee', 'date', 'average_views', 'views', 'likes', 'comments', 'clicks', 'conversion_rate', 'engagement_rate', 'link', 'commission_link', 'copy'];
const labels = { influencer: '红人', platform: '平台', type: '合作类型', fee: '合作价格', date: '发布日期', average_views: '均播', views: '浏览', likes: '赞', comments: '评', clicks: '点击', conversion_rate: '转化率 %', engagement_rate: '互动率 %', link: '合作链接', commission_link: '佣金链接', copy: '文案' };
const detailRows = computed(() => props.dashboard.details.map((row) => ({ ...row, ...(row.source_fields ?? {}) })));
const fieldExists = (names: string[]) => props.dashboard.columns.some((column) => names.some((name) => column.toLowerCase() === name.toLowerCase()));
const detailColumns = computed(() => normalizedColumns.filter((column) => {
    if (column === 'clicks') return props.dashboard.kpis.some((kpi) => kpi.key === 'clicks');
    if (column === 'conversion_rate') return fieldExists(['转化率', 'conversion rate']);
    if (column === 'commission_link') return fieldExists(['佣金链接']);
    if (column === 'copy') return fieldExists(['文案', '内容']);
    return true;
}));
const rawColumns = computed(() => ['table_name', ...props.dashboard.columns]);
const topMax = computed(() => Math.max(1, ...props.dashboard.top_influencers.map((row) => Number(row.views ?? 0))));
function compact(value: unknown): string { return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value ?? 0)); }
</script>

<template>
    <Head title="红人运营" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '红人运营' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/influencer-operations" refresh-path="/natural-traffic/influencer-operations/refresh" :active-tab="activeTab" accent="violet" @tab="activeTab = $event" />
            <NaturalTrafficEmptyState v-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <NaturalTrafficKpiGrid :kpis="dashboard.kpis" :currency="store.currency" :columns="dashboard.kpis.length >= 6 ? 6 : 5" />
                <template v-if="activeTab === 'tracking'">
                    <div class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">发布日期表现趋势</h2><p class="mt-1 mb-5 text-xs text-slate-500">浏览、点赞、评论和平均互动率</p><NaturalTrafficTrendChart :points="dashboard.trends" :series="[{ key: 'views', label: '浏览', color: '#7c3aed' }, { key: 'likes', label: '点赞', color: '#ec4899' }, { key: 'comments', label: '评论', color: '#10b981' }, { key: 'engagement_rate', label: '互动率', color: '#f59e0b' }]" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">红人浏览 TOP 10</h2><p class="mt-1 text-xs text-slate-500">同一红人合并统计</p><div class="mt-5 space-y-3"><div v-for="(row, index) in dashboard.top_influencers" :key="String(row.name)" class="grid grid-cols-[28px_minmax(0,1fr)_auto] items-center gap-3"><span class="text-xs font-black text-slate-400">{{ index + 1 }}</span><div><div class="flex justify-between gap-3 text-sm"><strong class="truncate text-slate-800">{{ row.name }}</strong><span class="text-xs text-slate-400">{{ row.platform }}</span></div><div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-violet-500" :style="{ width: `${Math.max(2, Number(row.views ?? 0) / topMax * 100)}%` }"></div></div></div><strong class="text-sm tabular-nums text-slate-800">{{ compact(row.views) }}</strong></div><p v-if="!dashboard.top_influencers.length" class="py-12 text-center text-sm text-slate-400">暂无排行数据</p></div></section>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">浏览量与互动率分布</h2><p class="mt-1 mb-5 text-xs text-slate-500">每个点是一条合作记录；横轴使用对数刻度，避免头部内容压缩其他记录</p><NaturalTrafficScatterPlot :points="dashboard.scatter" /></section>
                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,.9fr)]">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">合作数据明细</h2><NaturalTrafficDataTable :columns="detailColumns" :rows="detailRows" :labels="labels" /></section>
                        <div class="space-y-6">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">平台结构</h2><div class="mt-5 space-y-3"><article v-for="row in dashboard.platforms" :key="String(row.platform)" class="rounded-2xl bg-slate-50 p-4"><div class="flex items-center justify-between"><strong>{{ row.platform }}</strong><span class="font-black tabular-nums">{{ compact(row.views) }}</span></div><p class="mt-2 text-xs text-slate-500">{{ row.influencers }} 位红人 · {{ row.collaborations }} 次合作 · ER {{ Number(row.engagement_rate ?? 0).toFixed(2) }}%</p></article></div></section>
                            <section v-if="dashboard.model_summary.length" class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">年度车型汇总</h2><NaturalTrafficDataTable :columns="['model', 'influencers', 'views', 'views_per_influencer']" :rows="dashboard.model_summary" :labels="{ model: '车型', influencers: '合作红人数', views: '曝光量', views_per_influencer: '人均曝光' }" /></section>
                        </div>
                    </div>
                    <section v-if="dashboard.viral_content.length" class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">爆款内容统计</h2><p class="mt-1 mb-5 text-xs text-slate-500">独立爆款统计表中的非 AI 数据</p><NaturalTrafficDataTable :columns="detailColumns" :rows="dashboard.viral_content" :labels="labels" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">筛选期完整源字段</h2><p class="mt-1 text-xs text-slate-500">业务明细不重复列；这里单独保留当前项目数据库中已同步的全部非敏感原字段</p></div><NaturalTrafficDataTable :columns="rawColumns" :rows="detailRows" :labels="{ table_name: '来源表' }" /></section>
                </template>
                <template v-else>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">红人资源库</h2><p class="mt-1 text-xs text-slate-500">按红人和平台聚合历史合作，不提供删除或隐藏操作</p></div><NaturalTrafficDataTable :columns="['influencer', 'platform', 'type', 'collaborations', 'average_views', 'fee', 'engagement_rate', 'views', 'link']" :rows="dashboard.resources" :labels="{ influencer: '红人', platform: '平台', type: '类型', collaborations: '合作次数', average_views: '均播', fee: '费用', engagement_rate: '互动率 %', views: '累计浏览', link: '链接' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">筛选期合作资源明细</h2><p class="mt-1 text-xs text-slate-500">保留本期逐条合作、费用、表现与链接，便于从聚合结果追溯</p></div><NaturalTrafficDataTable :columns="detailColumns" :rows="detailRows" :labels="labels" /></section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
