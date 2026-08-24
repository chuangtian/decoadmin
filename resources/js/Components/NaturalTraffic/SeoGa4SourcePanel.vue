<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

type MetricKey = 'revenue' | 'sessions' | 'key_events' | 'average_engagement_seconds' | 'bounce_rate';
type Summary = Record<MetricKey, number>;
type Trend = Summary & { date: string };
type LandingPage = {
    key: string; page: string; page_type: string; channel: string; sessions: number; active_users: number; new_users: number;
    average_engagement_seconds: number; key_events: number; revenue: number; bounce_rate: number; event_rate: number;
    previous?: Record<string, number>; change_percent?: Record<string, number | null>; status?: 'new' | 'existing';
};
type SourceData = {
    filters: { date_from: string; date_to: string; comparison: string };
    comparison_period: { date_from: string; date_to: string };
    data_through: string | null; generated_at: string; data_ready: boolean;
    current: Summary; previous: Summary; deltas: Record<string, number | null>;
    trend: Trend[];
    channels: Array<{ channel: string; revenue: number; sessions: number; share: number }>;
};
type Pagination = { current_page: number; per_page: number; total: number; last_page: number; from: number; to: number };
type Totals = { revenue: number; sessions: number; active_users: number; new_users: number; key_events: number };

const props = defineProps<{ data: SourceData }>();
const filters = ref({ ...props.data.filters });
const channel = ref('all');
const pageType = ref('all');
const search = ref('');
const sortKey = ref<'revenue' | 'sessions' | 'active_users' | 'new_users' | 'key_events' | 'bounce_rate' | 'average_engagement_seconds' | 'page'>('revenue');
const sortDirection = ref<'asc' | 'desc'>('desc');
const metric = ref<MetricKey>('revenue');
const page = ref(1);
const perPage = ref(30);
const rows = ref<LandingPage[]>([]);
const totals = ref<Totals>({ revenue: 0, sessions: 0, active_users: 0, new_users: 0, key_events: 0 });
const pagination = ref<Pagination>({ current_page: 1, per_page: 30, total: 0, last_page: 1, from: 0, to: 0 });
const loading = ref(false);
const detailError = ref('');
const savedRanges = ref<Array<{ name: string; date_from: string; date_to: string; comparison: string }>>([]);
let requestId = 0;
let searchTimer: ReturnType<typeof setTimeout> | null = null;

const revenuePerSession = computed(() => props.data.current.sessions ? props.data.current.revenue / props.data.current.sessions : 0);
const metricOptions: Array<{ key: MetricKey; label: string; color: string }> = [
    { key: 'revenue', label: '收入', color: '#059669' }, { key: 'sessions', label: 'Sessions', color: '#2563eb' },
    { key: 'key_events', label: '关键事件', color: '#7c3aed' }, { key: 'average_engagement_seconds', label: '平均互动', color: '#ea580c' },
    { key: 'bounce_rate', label: '跳出率', color: '#e11d48' },
];
const chartColor = computed(() => metricOptions.find((item) => item.key === metric.value)?.color ?? '#059669');
const chart = computed(() => {
    const items = props.data.trend ?? [];
    const width = 820; const height = 220; const padX = 24; const padY = 24;
    const max = Math.max(1, ...items.map((item) => Number(item[metric.value] ?? 0)));
    const points = items.map((item, index) => ({ ...item, value: Number(item[metric.value] ?? 0), x: items.length <= 1 ? width / 2 : padX + index * ((width - padX * 2) / (items.length - 1)), y: height - padY - (Number(item[metric.value] ?? 0) / max) * (height - padY * 2) }));
    const line = points.map((point) => `${point.x},${point.y}`).join(' ');
    return { points, line, area: points.length ? `${padX},${height - padY} ${line} ${points.at(-1)?.x},${height - padY}` : '' };
});

watch([channel, pageType, sortKey, sortDirection, perPage], () => { page.value = 1; void loadDetails(); });
watch(search, () => { if (searchTimer) clearTimeout(searchTimer); searchTimer = setTimeout(() => { page.value = 1; void loadDetails(); }, 300); });
onMounted(() => {
    try { savedRanges.value = JSON.parse(localStorage.getItem('seo-ga4-saved-ranges') || '[]'); } catch { savedRanges.value = []; }
    void loadDetails();
});

async function loadDetails(): Promise<void> {
    const currentRequest = ++requestId;
    loading.value = true; detailError.value = '';
    const params = new URLSearchParams({ source: 'ga4', ...filters.value, page: String(page.value), per_page: String(perPage.value), search: search.value, sort: sortKey.value, direction: sortDirection.value });
    if (channel.value !== 'all') params.set('channel', channel.value);
    if (pageType.value !== 'all') params.set('page_type', pageType.value);
    try {
        const response = await fetch(`/natural-traffic/seo-geo/source-details?${params.toString()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`明细读取失败（${response.status}）`);
        const payload = await response.json();
        if (currentRequest !== requestId) return;
        rows.value = payload.rows ?? []; totals.value = payload.totals ?? totals.value; pagination.value = payload.pagination ?? pagination.value;
    } catch (error) {
        if (currentRequest === requestId) detailError.value = error instanceof Error ? error.message : '明细读取失败';
    } finally { if (currentRequest === requestId) loading.value = false; }
}
function applyFilters(): void { router.get('/natural-traffic/seo-geo', { tab: 'ga4', ...filters.value }, { preserveScroll: true, preserveState: true }); }
function quickRange(days: number): void {
    const end = new Date(`${props.data.data_through || filters.value.date_to}T00:00:00Z`); const start = new Date(end); start.setUTCDate(start.getUTCDate() - days + 1);
    filters.value.date_from = start.toISOString().slice(0, 10); filters.value.date_to = end.toISOString().slice(0, 10); applyFilters();
}
function saveRange(): void {
    const name = `${filters.value.date_from}～${filters.value.date_to}`;
    savedRanges.value = [{ name, ...filters.value }, ...savedRanges.value.filter((item) => item.name !== name)].slice(0, 6);
    localStorage.setItem('seo-ga4-saved-ranges', JSON.stringify(savedRanges.value));
}
function restoreRange(event: Event): void {
    const item = savedRanges.value[Number((event.target as HTMLSelectElement).value)];
    if (item) { filters.value = { date_from: item.date_from, date_to: item.date_to, comparison: item.comparison }; applyFilters(); }
}
function toggleSort(key: typeof sortKey.value): void {
    if (sortKey.value === key) sortDirection.value = sortDirection.value === 'desc' ? 'asc' : 'desc'; else { sortKey.value = key; sortDirection.value = key === 'page' ? 'asc' : 'desc'; }
}
function goToPage(value: number): void { page.value = Math.max(1, Math.min(pagination.value.last_page, value)); void loadDetails(); }
function number(value: number, digits = 0): string { return new Intl.NumberFormat('en-US', { maximumFractionDigits: digits }).format(value); }
function money(value: number): string { return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value); }
function percent(value: number): string { return `${Number(value).toFixed(2)}%`; }
function duration(value: number): string { const seconds = Math.round(value); return seconds >= 60 ? `${Math.floor(seconds / 60)}m ${seconds % 60}s` : `${seconds}s`; }
function metricValue(value: number): string { return metric.value === 'revenue' ? money(value) : metric.value === 'bounce_rate' ? percent(value) : metric.value === 'average_engagement_seconds' ? duration(value) : number(value, 1); }
function delta(value: number | null | undefined): string { return value == null || filters.value.comparison === 'none' ? '—' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`; }
function deltaClass(value: number | null | undefined, reverse = false): string { if (value == null || value === 0) return 'text-slate-400'; return (reverse ? value < 0 : value > 0) ? 'text-emerald-600' : 'text-rose-600'; }
function comparisonLabel(): string { return filters.value.comparison === 'year' ? '同比' : filters.value.comparison === 'none' ? '未对比' : '环比'; }
function isQuickRangeActive(days: number): boolean {
    const end = new Date(`${filters.value.date_to}T00:00:00Z`);
    const start = new Date(end);
    start.setUTCDate(start.getUTCDate() - days + 1);
    return filters.value.date_from === start.toISOString().slice(0, 10);
}
</script>

<template>
    <div class="space-y-5">
        <section class="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-5 lg:px-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-md bg-emerald-600 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-white">GA4 本地快照</span>
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>本地数据库已就绪</span>
                        </div>
                        <h2 class="mt-2.5 text-xl font-black tracking-tight text-slate-950">自然渠道收入数据仓</h2>
                        <p class="mt-1 text-sm text-slate-500">收入、会话与着陆页表现均从当前店铺的本地 GA4 日快照聚合。</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-500">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>页面不请求 Google API
                    </div>
                </div>
            </div>

            <div class="px-5 py-5 lg:px-6">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(240px,1.1fr)_minmax(135px,.72fr)_minmax(135px,.72fr)_minmax(120px,.58fr)_minmax(96px,auto)] xl:items-end">
                    <fieldset class="min-w-0">
                        <legend class="mb-1.5 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">快捷范围</legend>
                        <div class="grid h-11 grid-cols-4 rounded-xl bg-emerald-50 p-1">
                            <button v-for="preset in [{ days: 7, label: '7天' }, { days: 14, label: '14天' }, { days: 28, label: '28天' }, { days: 90, label: '3个月' }]" :key="preset.days" type="button" class="rounded-lg px-2 text-xs font-black transition" :class="isQuickRangeActive(preset.days) ? 'bg-white text-emerald-700 shadow-sm' : 'text-slate-500 hover:text-emerald-700'" @click="quickRange(preset.days)">{{ preset.label }}</button>
                        </div>
                    </fieldset>
                    <label for="ga4-date-from" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">开始日期<input id="ga4-date-from" v-model="filters.date_from" name="ga4_date_from" type="date" class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold tracking-normal text-slate-800 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"></label>
                    <label for="ga4-date-to" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">结束日期<input id="ga4-date-to" v-model="filters.date_to" name="ga4_date_to" type="date" class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold tracking-normal text-slate-800 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"></label>
                    <label for="ga4-comparison" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">对比<select id="ga4-comparison" v-model="filters.comparison" name="ga4_comparison" class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold tracking-normal text-slate-800 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"><option value="previous">上一周期</option><option value="year">去年同期</option><option value="none">不对比</option></select></label>
                    <button type="button" class="h-11 min-w-24 whitespace-nowrap rounded-xl bg-slate-950 px-4 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-200" @click="applyFilters">应用范围</button>
                </div>

                <div class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold text-slate-500">
                        <span><strong class="text-slate-700">数据截至</strong> {{ data.data_through || '等待同步' }}</span>
                        <span class="hidden h-3 w-px bg-slate-200 sm:block"></span>
                        <span><strong class="text-slate-700">{{ comparisonLabel() }}</strong> {{ data.comparison_period.date_from }} — {{ data.comparison_period.date_to }}</span>
                        <span class="hidden h-3 w-px bg-slate-200 sm:block"></span>
                        <span>全量着陆页 · 服务端分页</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <select v-if="savedRanges.length" name="ga4_saved_ranges" class="h-9 rounded-lg border border-slate-200 bg-slate-50 px-3 text-xs font-bold text-slate-700 outline-none" aria-label="历史查询" @change="restoreRange"><option value="">历史查询</option><option v-for="(item, index) in savedRanges" :key="item.name" :value="index">{{ item.name }}</option></select>
                        <button type="button" class="h-9 rounded-lg border border-emerald-200 bg-emerald-50 px-4 text-xs font-black text-emerald-700 transition hover:bg-emerald-100" @click="saveRange">保存当前条件</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex justify-between"><p class="text-xs font-black uppercase tracking-wider text-slate-400">自然渠道收入</p><span class="text-xs font-bold" :class="deltaClass(data.deltas.revenue)">{{ delta(data.deltas.revenue) }}</span></div><p class="mt-3 text-3xl font-black tabular-nums text-emerald-700">{{ money(data.current.revenue) }}</p><p class="mt-1 text-xs text-slate-400">totalRevenue 汇总</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex justify-between"><p class="text-xs font-black uppercase tracking-wider text-slate-400">Sessions</p><span class="text-xs font-bold" :class="deltaClass(data.deltas.sessions)">{{ delta(data.deltas.sessions) }}</span></div><p class="mt-3 text-3xl font-black tabular-nums text-blue-700">{{ number(data.current.sessions) }}</p><p class="mt-1 text-xs text-slate-400">自然渠道会话</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex justify-between"><p class="text-xs font-black uppercase tracking-wider text-slate-400">平均互动时长</p><span class="text-xs font-bold" :class="deltaClass(data.deltas.average_engagement_seconds)">{{ delta(data.deltas.average_engagement_seconds) }}</span></div><p class="mt-3 text-3xl font-black tabular-nums text-orange-600">{{ duration(data.current.average_engagement_seconds) }}</p><p class="mt-1 text-xs text-slate-400">互动时长 ÷ Sessions</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex justify-between"><p class="text-xs font-black uppercase tracking-wider text-slate-400">关键事件</p><span class="text-xs font-bold" :class="deltaClass(data.deltas.key_events)">{{ delta(data.deltas.key_events) }}</span></div><p class="mt-3 text-3xl font-black tabular-nums text-violet-700">{{ number(data.current.key_events, 1) }}</p><p class="mt-1 text-xs text-slate-400">keyEvents 汇总</p></article>
            <article class="rounded-2xl border border-slate-200 bg-slate-950 p-5 text-white shadow-sm"><p class="text-xs font-black uppercase tracking-wider text-slate-500">每会话收入</p><p class="mt-3 text-3xl font-black tabular-nums">{{ money(revenuePerSession) }}</p><p class="mt-1 text-xs text-slate-400">收入 ÷ Sessions · {{ data.channels.length }} 渠道</p></article>
        </section>

        <section class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,0.8fr)]">
            <article class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4"><div><h3 class="text-lg font-black text-slate-950">自然渠道多指标趋势</h3><p class="mt-1 text-xs text-slate-400">Organic Search + Organic Shopping + AI assistants</p></div><select v-model="metric" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700"><option v-for="item in metricOptions" :key="item.key" :value="item.key">{{ item.label }}</option></select></div>
                <div class="mt-6 overflow-x-auto"><svg class="min-w-[620px]" viewBox="0 0 820 260" role="img" aria-label="GA4自然渠道趋势"><defs><linearGradient id="gaArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" :stop-color="chartColor" stop-opacity=".28"/><stop offset="1" :stop-color="chartColor" stop-opacity="0"/></linearGradient></defs><line v-for="row in 5" :key="row" x1="24" x2="796" :y1="24 + (row - 1) * 43" :y2="24 + (row - 1) * 43" stroke="#e2e8f0" stroke-dasharray="5 7"/><polygon v-if="chart.points.length" :points="chart.area" fill="url(#gaArea)"/><polyline v-if="chart.points.length" :points="chart.line" fill="none" :stroke="chartColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><g v-for="point in chart.points" :key="point.date"><circle :cx="point.x" :cy="point.y" r="4.5" fill="white" :stroke="chartColor" stroke-width="3"><title>{{ point.date }} · {{ metricValue(point.value) }}</title></circle></g><text v-for="(point, index) in chart.points" v-show="index === 0 || index === chart.points.length - 1 || chart.points.length <= 8" :key="`label-${point.date}`" :x="point.x" y="250" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text></svg></div>
            </article>
            <article class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Channel mix</p><h3 class="mt-3 text-xl font-black text-slate-950">渠道收入构成</h3><div class="mt-6 space-y-5"><div v-for="(item, index) in data.channels" :key="item.channel"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-black text-slate-800">{{ item.channel }}</p><p class="mt-0.5 text-xs text-slate-400">{{ number(item.sessions) }} sessions</p></div><div class="text-right"><strong class="text-sm text-slate-900">{{ money(item.revenue) }}</strong><p class="text-xs text-slate-400">{{ item.share.toFixed(1) }}%</p></div></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full" :class="index === 0 ? 'bg-emerald-500' : index === 1 ? 'bg-blue-500' : 'bg-violet-500'" :style="{ width: `${Math.min(100, item.share)}%` }"></span></div></div></div></article>
        </section>

        <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"><div><h3 class="text-lg font-black text-slate-950">着陆页表现明细</h3><p class="mt-1 text-xs text-slate-400">本地聚合、服务端筛选分页；不会重新请求 GA4</p></div><div class="flex flex-wrap gap-2"><select v-model="channel" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700"><option value="all">全部渠道</option><option v-for="item in data.channels" :key="item.channel" :value="item.channel">{{ item.channel }}</option></select><select v-model="pageType" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700"><option value="all">全部页面类型</option><option value="home">首页</option><option value="product">产品页</option><option value="collection">分类页</option><option value="blog">博客页</option><option value="page">内容页</option><option value="other">其他</option></select><input v-model="search" type="search" placeholder="搜索着陆页…" class="h-11 min-w-[230px] rounded-xl border border-slate-200 px-4 text-sm"><select v-model="perPage" class="h-11 rounded-xl border border-slate-200 px-3 text-sm font-bold"><option :value="30">30条</option><option :value="50">50条</option><option :value="100">100条</option></select></div></div>
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900">GA4 可能对低流量组合应用阈值；按日快照再次汇总的活跃用户等非可加指标，与 GA4 区间报告通常会有约 1%–3% 的自然差异。</div>
            <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs font-black uppercase tracking-wider text-slate-400"><tr><th class="min-w-[300px] cursor-pointer px-4 py-3" @click="toggleSort('page')">着陆页</th><th class="whitespace-nowrap px-4 py-3">类型 / 渠道</th><th v-for="column in [{ key: 'revenue', label: '收入' }, { key: 'sessions', label: '会话' }, { key: 'active_users', label: '活跃用户' }, { key: 'new_users', label: '新用户' }, { key: 'key_events', label: '关键事件' }, { key: 'bounce_rate', label: '跳出率' }, { key: 'average_engagement_seconds', label: '平均互动' }]" :key="column.key" class="cursor-pointer whitespace-nowrap px-4 py-3" @click="toggleSort(column.key as typeof sortKey)">{{ column.label }} <span v-if="sortKey === column.key">{{ sortDirection === 'desc' ? '↓' : '↑' }}</span></th><th class="whitespace-nowrap px-4 py-3">事件率</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="row in rows" :key="row.key" class="hover:bg-emerald-50/40"><td class="max-w-[460px] truncate px-4 py-3.5 font-semibold text-slate-800" :title="row.page"><span v-if="row.status === 'new'" class="mr-2 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-black text-emerald-700">NEW</span>{{ row.page }}</td><td class="whitespace-nowrap px-4 py-3.5"><span class="rounded-lg bg-blue-50 px-2 py-1 text-[10px] font-bold text-blue-700">{{ row.page_type }}</span><span class="ml-1 rounded-lg bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ row.channel }}</span></td><td class="px-4 py-3.5 font-black tabular-nums text-emerald-700">{{ money(row.revenue) }}<small v-if="row.change_percent" class="ml-1" :class="deltaClass(row.change_percent.revenue)">{{ delta(row.change_percent.revenue) }}</small></td><td class="px-4 py-3.5 tabular-nums text-slate-700">{{ number(row.sessions) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-700">{{ number(row.active_users) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-700">{{ number(row.new_users) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-700">{{ number(row.key_events, 1) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-700">{{ percent(row.bounce_rate) }}</td><td class="px-4 py-3.5 whitespace-nowrap tabular-nums text-slate-700">{{ duration(row.average_engagement_seconds) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-700">{{ percent(row.event_rate) }}</td></tr><tr v-if="loading"><td colspan="10" class="px-4 py-12 text-center text-emerald-600">正在读取本地明细…</td></tr><tr v-else-if="detailError"><td colspan="10" class="px-4 py-12 text-center text-rose-600">{{ detailError }}</td></tr><tr v-else-if="!rows.length"><td colspan="10" class="px-4 py-12 text-center text-slate-400">当前筛选没有匹配数据</td></tr></tbody><tfoot class="bg-slate-950 font-black text-white"><tr><td class="px-4 py-3">筛选合计</td><td class="px-4 py-3">{{ number(pagination.total) }} 组合</td><td class="px-4 py-3">{{ money(totals.revenue) }}</td><td class="px-4 py-3">{{ number(totals.sessions) }}</td><td class="px-4 py-3">{{ number(totals.active_users) }}</td><td class="px-4 py-3">{{ number(totals.new_users) }}</td><td class="px-4 py-3">{{ number(totals.key_events, 1) }}</td><td colspan="3"></td></tr></tfoot></table></div>
            <div class="mt-4 flex items-center justify-between text-xs text-slate-400"><span>第 {{ pagination.from }}–{{ pagination.to }} 条，共 {{ number(pagination.total) }} 个本地组合</span><div class="flex items-center gap-3"><button :disabled="page <= 1 || loading" class="rounded-lg border border-slate-200 px-3 py-2 font-bold text-slate-600 disabled:opacity-30" @click="goToPage(page - 1)">上一页</button><strong class="text-slate-700">{{ page }} / {{ pagination.last_page }}</strong><button :disabled="page >= pagination.last_page || loading" class="rounded-lg border border-slate-200 px-3 py-2 font-bold text-slate-600 disabled:opacity-30" @click="goToPage(page + 1)">下一页</button></div></div>
        </section>
    </div>
</template>
