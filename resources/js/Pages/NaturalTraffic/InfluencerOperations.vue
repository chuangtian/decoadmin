<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
import NaturalTrafficScatterPlot from '../../Components/NaturalTraffic/NaturalTrafficScatterPlot.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type KolRow = Record<string, unknown>;
type KolDashboard = NaturalTrafficDashboardBase & {
    trends: Array<Record<string, unknown>>; top_influencers: Array<Record<string, unknown>>; platforms: Array<Record<string, unknown>>;
    scatter: Array<Record<string, unknown>>; model_summary: Array<Record<string, unknown>>; viral_content: KolRow[];
    details: KolRow[]; detail_columns: string[]; viral_columns: string[];
};
const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: KolDashboard; configured: boolean; canSync: boolean }>();
const labels = { influencer: '红人', platform: '平台', type: '合作类型', fee: '合作价格', date: '发布日期', average_views: '均播', views: '浏览', likes: '赞', comments: '评', clicks: '点击', engagement_rate: '互动率 %', link: '合作链接' };
const topMax = computed(() => Math.max(1, ...props.dashboard.top_influencers.map((row) => Number(row.views ?? 0))));
function compact(value: unknown): string { return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value ?? 0)); }
</script>

<template>
    <Head title="红人运营" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '红人运营' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-4">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/influencer-operations" refresh-path="/natural-traffic/influencer-operations/refresh" active-tab="tracking" accent="violet" compact />
            <NaturalTrafficEmptyState v-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <NaturalTrafficKpiGrid :kpis="dashboard.kpis" :currency="store.currency" :columns="dashboard.kpis.length >= 6 ? 6 : 5" compact />
                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(420px,.9fr)]">
                    <section class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="text-base font-black text-slate-950">发布日期表现趋势</h2><p class="mt-0.5 mb-2 text-xs text-slate-500">浏览、点赞、评论和平均互动率</p><NaturalTrafficTrendChart :height="190" :points="dashboard.trends" :series="[{ key: 'views', label: '浏览', color: '#7c3aed' }, { key: 'likes', label: '点赞', color: '#ec4899' }, { key: 'comments', label: '评论', color: '#10b981' }, { key: 'engagement_rate', label: '互动率', color: '#f59e0b' }]" /></section>
                    <section class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="text-base font-black text-slate-950">红人浏览 TOP 10</h2><p class="mt-0.5 text-xs text-slate-500">同一红人合并统计</p><div class="mt-3 grid gap-x-5 gap-y-2 2xl:grid-cols-2"><div v-for="(row, index) in dashboard.top_influencers" :key="String(row.name)" class="grid grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-2"><span class="text-[11px] font-black text-slate-400">{{ index + 1 }}</span><div><div class="flex justify-between gap-2 text-xs"><strong class="truncate text-slate-800">{{ row.name }}</strong><span class="text-[11px] text-slate-400">{{ row.platform }}</span></div><div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-violet-500" :style="{ width: `${Math.max(2, Number(row.views ?? 0) / topMax * 100)}%` }"></div></div></div><strong class="text-xs tabular-nums text-slate-800">{{ compact(row.views) }}</strong></div><p v-if="!dashboard.top_influencers.length" class="py-8 text-center text-sm text-slate-400">暂无排行数据</p></div></section>
                </div>
                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,.7fr)]">
                    <section class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="text-base font-black text-slate-950">浏览量与互动率分布</h2><p class="mt-0.5 mb-2 text-xs text-slate-500">每个点是一条合作记录；横轴使用对数刻度</p><NaturalTrafficScatterPlot :height="220" :points="dashboard.scatter" /></section>
                    <section class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="text-base font-black text-slate-950">平台结构</h2><div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2"><article v-for="row in dashboard.platforms" :key="String(row.platform)" class="rounded-xl bg-slate-50 p-3"><div class="flex items-center justify-between text-sm"><strong>{{ row.platform }}</strong><span class="font-black tabular-nums">{{ compact(row.views) }}</span></div><p class="mt-1 text-[11px] text-slate-500">{{ row.influencers }} 位红人 · {{ row.collaborations }} 次合作 · ER {{ Number(row.engagement_rate ?? 0).toFixed(2) }}%</p></article></div></section>
                </div>
                <div class="grid gap-4" :class="dashboard.model_summary.length ? 'xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,.75fr)]' : ''">
                    <section class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="mb-3 text-base font-black text-slate-950">合作数据明细</h2><NaturalTrafficDataTable :columns="dashboard.detail_columns" :rows="dashboard.details" :labels="labels" :page-size="8" compact /></section>
                    <section v-if="dashboard.model_summary.length" class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="mb-3 text-base font-black text-slate-950">年度车型汇总</h2><NaturalTrafficDataTable :columns="['model', 'influencers', 'views', 'views_per_influencer']" :rows="dashboard.model_summary" :labels="{ model: '车型', influencers: '合作红人数', views: '曝光量', views_per_influencer: '人均曝光' }" :page-size="8" compact /></section>
                </div>
                <section v-if="dashboard.viral_content.length" class="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm"><h2 class="text-base font-black text-slate-950">爆款内容统计</h2><p class="mt-0.5 mb-3 text-xs text-slate-500">独立爆款统计表中的非 AI 数据，按发布日期从新到旧</p><NaturalTrafficDataTable :columns="dashboard.viral_columns" :rows="dashboard.viral_content" :labels="labels" :page-size="8" compact /></section>
            </template>
        </div>
    </AppLayout>
</template>
