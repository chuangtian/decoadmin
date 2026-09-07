<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
    details: KolRow[]; excluded_details: KolRow[]; detail_columns: string[]; viral_columns: string[];
};
const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: KolDashboard; configured: boolean; canSync: boolean; canManage: boolean }>();
const labels = { influencer: '红人', platform: '平台', type: '合作类型', fee: '合作价格', date: '发布日期', average_views: '均播', views: '浏览', likes: '赞', comments: '评', clicks: '点击', engagement_rate: '互动率 %', link: '合作链接' };
const visibility = ref('visible');
const saving = ref(false);
const operationError = ref('');
const deleteTarget = ref<Record<string, unknown> | null>(null);
const deleteDialog = ref<HTMLDialogElement | null>(null);
const notice = ref('');
const visibilityOptions = computed(() => [
    { key: 'visible', label: '可见帖子', count: props.dashboard.details.length },
    { key: 'hidden', label: '已隐藏帖子', count: props.dashboard.excluded_details.filter(row => row.status === 'hidden').length },
    { key: 'deleted', label: '回收站', count: props.dashboard.excluded_details.filter(row => row.status === 'deleted').length },
]);
function requestDelete(row: Record<string, unknown>): void {
    if (saving.value) return;
    operationError.value = '';
    deleteTarget.value = row;
    deleteDialog.value?.showModal();
}
function closeDelete(): void {
    if (saving.value) return;
    deleteDialog.value?.close();
    deleteTarget.value = null;
}
function confirmDelete(): void {
    if (deleteTarget.value) setRecordState(deleteTarget.value, 'deleted');
}
const managedRows = computed(() => visibility.value === 'visible' ? props.dashboard.details : props.dashboard.excluded_details.filter((row) => row.status === visibility.value));
function setRecordState(row: Record<string, unknown>, status: string): void {
    if (saving.value) return;
    saving.value = true;
    operationError.value = '';
    notice.value = '';
    router.put('/natural-traffic/influencer-operations/records/state', {
        source_table_key: String(row.source_table_key), source_record_id: String(row.record_id), status,
    }, { preserveScroll: true, preserveState: true,
        onSuccess: () => {
            if (status === 'deleted') { deleteDialog.value?.close(); deleteTarget.value = null; }
            notice.value = status === 'hidden' ? '已隐藏，可在「已隐藏帖子」中查看并恢复。' : status === 'deleted' ? '已移入回收站，可随时恢复。' : '已恢复，可在「可见帖子」中查看。';
        },
        onError: () => { operationError.value = '操作失败，请刷新页面后重试。'; },
        onFinish: () => { saving.value = false; },
    });
}
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
                    <div class="grid gap-4">
                        <section class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-4 flex flex-wrap items-center gap-4">
                                <h2 class="text-[16px] leading-6 font-black text-slate-950">合作数据明细</h2>
                                <div class="flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1" role="group" aria-label="合作记录显示状态">
                                    <button v-for="option in visibilityOptions" :key="option.key" type="button" :aria-pressed="visibility === option.key" class="min-h-10 rounded-lg px-4 text-[14px] leading-5 font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" :class="visibility === option.key ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-200' : 'text-slate-600 hover:bg-white hover:text-slate-900'" @click="visibility = option.key; notice = ''; operationError = ''">{{ option.label }} <span class="ml-1 tabular-nums">{{ option.count }}</span></button>
                                </div>
                                <span class="text-[14px] leading-5 text-slate-500">当前筛选 · {{ managedRows.length }} 条</span>
                            </div>
                            <p class="mb-4 text-[14px] leading-5 leading-6 text-slate-500">隐藏和删除的记录不参与统计及对比，可在对应列表恢复。仅管理后台记录，不影响飞书源记录或平台视频。</p>
                            <p v-if="notice" role="status" class="mb-4 rounded-xl bg-blue-50 px-4 py-3 text-[14px] leading-5 text-blue-700">{{ notice }}</p>
                            <p v-if="operationError && !deleteTarget" role="alert" class="mb-3 text-[14px] leading-5 text-red-600">{{ operationError }}</p>
                            <NaturalTrafficDataTable :key="visibility" :columns="dashboard.detail_columns" :rows="managedRows" :labels="labels" :page-size="8" readable :empty-text="visibility === 'hidden' ? '当前筛选范围内没有已隐藏帖子' : visibility === 'deleted' ? '当前筛选范围内回收站为空' : '当前筛选范围内没有可见帖子'">
                                <template v-if="canManage" #actions="{ row }"><div class="flex items-center gap-2">
                                    <button v-if="row.status === 'visible'" :disabled="saving" class="min-h-10 rounded-lg px-2 text-[14px] leading-5 font-semibold text-blue-600 hover:bg-blue-50 disabled:opacity-40" @click="setRecordState(row, 'hidden')">隐藏</button>
                                    <button v-else :disabled="saving" class="min-h-10 rounded-lg px-2 text-[14px] leading-5 font-semibold text-blue-600 hover:bg-blue-50 disabled:opacity-40" @click="setRecordState(row, 'visible')">恢复</button>
                                    <button v-if="row.status !== 'deleted'" :disabled="saving" class="min-h-10 rounded-lg px-2 text-[14px] leading-5 font-semibold text-red-600 hover:bg-red-50 disabled:opacity-40" @click="requestDelete(row)">删除</button>
                                </div></template>
                            </NaturalTrafficDataTable>
                        </section>
                    <section v-if="dashboard.model_summary.length" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><h2 class="mb-3 text-[16px] leading-6 font-black text-slate-950">年度车型汇总</h2><p class="mb-4 text-[14px] leading-6 text-slate-500">独立年度汇总表数据，不随合作记录隐藏或删除调整。</p><NaturalTrafficDataTable :columns="['model', 'influencers', 'views', 'views_per_influencer']" :rows="dashboard.model_summary" :labels="{ model: '车型', influencers: '合作红人数', views: '曝光量', views_per_influencer: '人均曝光' }" :page-size="8" readable /></section>
                </div>
                <section v-if="dashboard.viral_content.length" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><h2 class="mb-3 text-[16px] leading-6 font-black text-slate-950">爆款内容统计</h2><p class="mb-4 text-[14px] leading-6 text-slate-500">独立爆款统计表中的非 AI 数据，按发布日期从新到旧</p><NaturalTrafficDataTable :columns="dashboard.viral_columns" :rows="dashboard.viral_content" :labels="labels" :page-size="8" readable /></section>
            </template>
        </div>
        <Teleport to="body">
            <dialog ref="deleteDialog" aria-labelledby="delete-record-title" aria-describedby="delete-record-description" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 bg-white p-6 text-slate-900 shadow-2xl backdrop:bg-slate-950/50" @cancel.prevent="closeDelete" @click.self="closeDelete">
                <h2 id="delete-record-title" class="text-[16px] leading-6 font-bold">是否删除该条数据？</h2>
                <p v-if="deleteTarget" class="mt-3 rounded-xl bg-slate-50 p-3 text-[14px] leading-5 font-medium break-words">{{ deleteTarget.influencer || '合作记录' }}<span v-if="deleteTarget.date" class="ml-2 font-normal text-slate-500">{{ deleteTarget.date }}</span></p>
                <p id="delete-record-description" class="mt-3 text-[14px] leading-5 leading-6 text-slate-600">删除后将移入回收站，不再参与统计和对比，可在回收站恢复。</p>
                <p v-if="operationError" role="alert" class="mt-3 text-[14px] leading-5 text-red-600">{{ operationError }}</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" autofocus :disabled="saving" class="h-11 rounded-xl border border-slate-200 px-5 text-[14px] leading-5 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="closeDelete">取消</button>
                    <button type="button" :disabled="saving" class="h-11 rounded-xl bg-red-600 px-5 text-[14px] leading-5 font-semibold text-white hover:bg-red-700 disabled:opacity-50" @click="confirmDelete">{{ saving ? '删除中…' : '确认删除' }}</button>
                </div>
            </dialog>
        </Teleport>
    </AppLayout>
</template>
