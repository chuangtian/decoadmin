<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type AffiliateDashboard = NaturalTrafficDashboardBase & {
    affiliate_options: string[]; weekly: Array<Record<string, unknown>>; partners: Array<Record<string, unknown>>;
    columns: string[]; rows: Array<Record<string, unknown>>;
};
defineProps<{ store: { id: number; name: string; currency: string }; dashboard: AffiliateDashboard; configured: boolean; canSync: boolean }>();
const activeTab = ref('overview');
</script>

<template>
    <Head title="联盟营销" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '联盟营销' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/affiliate-marketing" refresh-path="/natural-traffic/affiliate-marketing/refresh" :active-tab="activeTab" accent="amber" select-label="联盟伙伴" select-param="affiliate" :select-value="dashboard.filters.affiliate || ''" :select-options="dashboard.affiliate_options" @tab="activeTab = $event" />
            <NaturalTrafficEmptyState v-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <NaturalTrafficKpiGrid :kpis="dashboard.kpis" :currency="store.currency" :columns="3" />
                <div class="grid gap-6 xl:grid-cols-2">
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">GMV 趋势（按周）</h2><p class="mt-1 mb-5 text-xs text-slate-500">同一周的不同联盟自动合并</p><NaturalTrafficTrendChart :points="dashboard.weekly" x-key="date" interactive :currency="store.currency" :series="[{ key: 'gmv', label: 'GMV', format: 'currency', color: '#f59e0b' }]" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">Clicks 与 GMV 趋势</h2><p class="mt-1 mb-5 text-xs text-slate-500">点击规模与产出效率同步观察</p><NaturalTrafficTrendChart :points="dashboard.weekly" x-key="date" interactive :currency="store.currency" :series="[{ key: 'clicks', label: 'Clicks', format: 'integer', color: '#10b981' }, { key: 'gmv', label: 'GMV', format: 'currency', color: '#7c3aed' }]" /></section>
                </div>
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(380px,.9fr)]">
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">每周关键指标</h2><NaturalTrafficDataTable :columns="['week', 'date', 'clicks', 'gmv', 'gmv_per_click']" :rows="dashboard.weekly" :labels="{ week: '周', date: '开始日期', clicks: '点击数', gmv: 'GMV', gmv_per_click: 'GMV / Click' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">联盟伙伴贡献</h2><NaturalTrafficDataTable :columns="['name', 'weeks', 'clicks', 'gmv', 'gmv_per_click']" :rows="dashboard.partners" :labels="{ name: '联盟伙伴', weeks: '周数', clicks: '点击数', gmv: 'GMV', gmv_per_click: 'GMV / Click' }" /></section>
                </div>
                <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">完整联盟源数据</h2><p class="mt-1 text-xs text-slate-500">保留当前项目数据库内全部非敏感业务字段</p></div><NaturalTrafficDataTable :columns="['table_name', ...dashboard.columns]" :rows="dashboard.rows" :labels="{ table_name: '来源表' }" /></section>
            </template>
        </div>
    </AppLayout>
</template>
