<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type Summary = { revenue?: number; sessions?: number; clicks?: number; impressions?: number; ctr?: number; position?: number; ratio?: number; visible_clicks?: number; total_clicks?: number };
type Trend = { date: string; revenue?: number; sessions?: number; clicks?: number; impressions?: number; ctr?: number; position?: number };
type GscMetric = 'clicks' | 'impressions' | 'ctr' | 'position';
type DetailRow = {
    hash: string; label: string; clicks: number; impressions: number; ctr: number; position: number;
    previous: { clicks: number; impressions: number; ctr: number; position: number };
    difference: { clicks: number; impressions: number; ctr: number; position: number };
};
type GscModule = {
    label: string; description: string; current: Summary; previous: Summary; year: Summary;
    deltas: Record<string, number | null>; year_deltas: Record<string, number | null>;
    trend: Trend[]; previous_trend: Trend[]; queries: DetailRow[]; pages: DetailRow[];
};
type LandingPage = {
    key: string; page: string; channel: string; sessions: number; active_users: number; new_users: number;
    average_engagement_seconds: number; key_events: number; revenue: number; bounce_rate: number; event_rate: number;
};
type Overview = {
    filters: { date_from: string; date_to: string; comparison: string };
    comparison_period: { date_from: string; date_to: string }; year_period: { date_from: string; date_to: string };
    data_through: string | null; data_ready: boolean;
    ga4: {
        current: Summary; previous: Summary; year: Summary; deltas: Record<string, number | null>; year_deltas: Record<string, number | null>;
        trend: Trend[]; previous_trend: Trend[];
        channels: Array<{ channel: string; revenue: number; sessions: number; share: number }>;
        landing_pages: LandingPage[];
    };
    gsc: Record<string, GscModule>;
};
type SyncStatus = {
    uuid?: string | null; status: string; mode?: string | null; progress_percent: number; processed_rows: number;
    date_from?: string | null; date_to?: string | null; message?: string | null; last_completed_at?: string | null;
    total_shards?: number; completed_shards?: number; priority_ready?: boolean; priority_period?: string[] | null;
    last_completed_shard?: { completed_at?: string | null } | null;
};

const props = defineProps<{
    overview: Overview;
    syncStatus: SyncStatus;
    sourceStatus: { gsc: boolean; ga4: boolean; configured: boolean; missing: string[] };
    canSync: boolean;
}>();

const filters = ref({ ...props.overview.filters });
const status = ref({ ...props.syncStatus });
const syncing = ref(['queued', 'running'].includes(status.value.status));
const activeModule = ref('total');
const activeGscMetric = ref<GscMetric>('clicks');
const gscTrendOpen = ref(true);
const gscMetricGroup = ref<HTMLDivElement | null>(null);
const drillType = ref<'queries' | 'pages'>('queries');
const drillSearch = ref('');
const drillPage = ref(1);
const drillSort = ref<keyof DetailRow>('clicks');
const drillDirection = ref<'asc' | 'desc'>('desc');
const landingExpanded = ref(false);
const drillRows = ref<Record<string, DetailRow[]>>({});
const drillLoading = ref(false);
const landingPages = ref<LandingPage[]>(props.overview.ga4.landing_pages ?? []);
const landingLoading = ref(false);
const landingTotal = ref(landingPages.value.length);
let poller: ReturnType<typeof setInterval> | null = null;

const moduleOptions = [
    { key: 'total', label: '总点击', accent: '#2563eb' },
    { key: 'brand', label: '品牌词', accent: '#0f766e' },
    { key: 'industry', label: '行业词', accent: '#7c3aed' },
    { key: 'blog', label: '博客', accent: '#ea580c' },
    { key: 'anonymous', label: '匿名化', accent: '#64748b' },
    { key: 'web', label: '网页', accent: '#0891b2' },
];
const currentModule = computed(() => props.overview.gsc[activeModule.value] ?? props.overview.gsc.total);
const latestSyncAt = computed(() => status.value.last_completed_shard?.completed_at ?? status.value.last_completed_at);
const comparisonLabel = computed(() => filters.value.comparison === 'year' ? '同比' : filters.value.comparison === 'none' ? '未对比' : '环比');
const comparisonPeriodLabel = computed(() => filters.value.comparison === 'year' ? '去年同期' : filters.value.comparison === 'none' ? '对比已关闭' : '上一周期');
const comparedDelta = (value: number | null | undefined): number | null => filters.value.comparison === 'none' ? null : value ?? null;
const metricCards = computed(() => [
    { label: 'SEO GMV', value: money(props.overview.ga4.current.revenue ?? 0), delta: comparedDelta(props.overview.ga4.deltas.revenue), source: 'GA4 · 3个自然渠道', color: 'text-emerald-700', tint: 'bg-emerald-50' },
    { label: '总点击', value: number(props.overview.gsc.total.current.clicks ?? 0), delta: comparedDelta(props.overview.gsc.total.deltas.clicks), source: 'GSC · 全站', color: 'text-blue-700', tint: 'bg-blue-50' },
    { label: '品牌词点击', value: number(props.overview.gsc.brand.current.clicks ?? 0), delta: comparedDelta(props.overview.gsc.brand.deltas.clicks), source: 'Query · 品牌正则', color: 'text-teal-700', tint: 'bg-teal-50' },
    { label: '行业词点击', value: number(props.overview.gsc.industry.current.clicks ?? 0), delta: comparedDelta(props.overview.gsc.industry.deltas.clicks), source: 'Query · 排除品牌', color: 'text-violet-700', tint: 'bg-violet-50' },
    { label: '博客点击', value: number(props.overview.gsc.blog.current.clicks ?? 0), delta: comparedDelta(props.overview.gsc.blog.deltas.clicks), source: 'Page · /blogs/', color: 'text-orange-700', tint: 'bg-orange-50' },
    { label: '匿名化查询', value: number(props.overview.gsc.anonymous.current.clicks ?? 0), delta: comparedDelta(props.overview.gsc.anonymous.deltas.clicks), source: `估算占比 ${percent(props.overview.gsc.anonymous.current.ratio ?? 0)}`, color: 'text-slate-700', tint: 'bg-slate-100' },
]);

const gaChart = computed(() => lineChart(props.overview.ga4.trend, props.overview.ga4.previous_trend, 'revenue'));
const gscMetricOptions: Array<{ key: GscMetric; label: string }> = [
    { key: 'clicks', label: '点击' },
    { key: 'impressions', label: '曝光' },
    { key: 'ctr', label: 'CTR' },
    { key: 'position', label: '平均排名' },
];
const gscMetricCards = computed(() => gscMetricOptions.map((metric) => ({
    ...metric,
    label: metric.key === 'clicks' ? currentModule.value.label : metric.label,
    value: formatGscMetric(currentModule.value.current[metric.key], metric.key),
    delta: comparedDelta(currentModule.value.deltas[metric.key]),
})));
const gscMetricLabel = computed(() => gscMetricCards.value.find((metric) => metric.key === activeGscMetric.value)!.label);
const gscChart = computed(() => lineChart(
    currentModule.value.trend,
    filters.value.comparison === 'none' ? [] : currentModule.value.previous_trend,
    activeGscMetric.value,
));
const gscHasTrend = computed(() => gscChart.value.current.length > 0 || gscChart.value.previous.length > 0);
const landingRows = computed(() => landingExpanded.value ? landingPages.value : landingPages.value.slice(0, 7));
const supportedDrills = computed(() => ({
    queries: !['blog', 'anonymous', 'web'].includes(activeModule.value),
    pages: true,
}));
const drillCacheKey = (module = activeModule.value, type = drillType.value): string => `${module}:${type}`;
const activeDrillRows = computed(() => drillRows.value[drillCacheKey()] ?? currentModule.value[drillType.value] ?? []);
const availableDrills = computed(() => ({
    queries: drillRows.value[drillCacheKey(activeModule.value, 'queries')]?.length ?? currentModule.value.queries?.length ?? 0,
    pages: drillRows.value[drillCacheKey(activeModule.value, 'pages')]?.length ?? currentModule.value.pages?.length ?? 0,
}));
const filteredDrillRows = computed(() => {
    const search = drillSearch.value.trim().toLowerCase();
    const rows = [...activeDrillRows.value].filter((row) => !search || row.label.toLowerCase().includes(search));
    rows.sort((a, b) => {
        const left = a[drillSort.value]; const right = b[drillSort.value];
        const order = typeof left === 'string' ? left.localeCompare(String(right)) : Number(left) - Number(right);
        return drillDirection.value === 'asc' ? order : -order;
    });
    return rows;
});
const pagedDrillRows = computed(() => filteredDrillRows.value.slice((drillPage.value - 1) * 10, drillPage.value * 10));
const drillPages = computed(() => Math.max(1, Math.ceil(filteredDrillRows.value.length / 10)));

watch(() => props.syncStatus, (value) => {
    status.value = { ...value };
    syncing.value = ['queued', 'running'].includes(value.status);
    if (syncing.value) startPolling();
}, { deep: true });
watch(activeModule, () => {
    drillSearch.value = ''; drillPage.value = 1;
    drillType.value = supportedDrills.value.queries ? 'queries' : 'pages';
    void loadDrillRows();
});
watch(drillType, () => { drillPage.value = 1; void loadDrillRows(); });
watch(drillSearch, () => { drillPage.value = 1; });

function applyFilters(): void {
    router.get('/natural-traffic/seo-geo', { tab: 'overview', ...filters.value }, { preserveScroll: true, preserveState: true });
}

function queueSync(): void {
    if (!props.canSync || !props.sourceStatus.configured || syncing.value) return;
    syncing.value = true;
    router.post('/natural-traffic/seo-geo/overview/refresh', {}, {
        preserveScroll: true,
        onSuccess: () => {
            status.value = { ...props.syncStatus };
            startPolling();
        },
        onError: () => { syncing.value = false; },
    });
}

function startPolling(): void {
    if (poller || !status.value.uuid || !['queued', 'running'].includes(status.value.status)) return;
    poller = setInterval(async () => {
        try {
            const response = await fetch(`/natural-traffic/seo-geo/overview/sync-status/${status.value.uuid}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const wasPriorityReady = Boolean(status.value.priority_ready);
            const nextStatus: SyncStatus = await response.json();
            const priorityBecameReady = !wasPriorityReady && Boolean(nextStatus.priority_ready);
            status.value = nextStatus;
            syncing.value = ['queued', 'running'].includes(status.value.status);
            if (priorityBecameReady) {
                applyFilters();
                return;
            }
            if (!syncing.value) {
                stopPolling();
                if (status.value.status === 'completed') applyFilters();
            }
        } catch { /* 下一轮重试 */ }
    }, 3000);
}

function stopPolling(): void { if (poller) clearInterval(poller); poller = null; }
onMounted(() => {
    startPolling();
    void Promise.all([loadDrillRows(), loadLandingPages()]);
});
onBeforeUnmount(() => stopPolling());

async function loadDrillRows(): Promise<void> {
    const module = activeModule.value;
    const type = drillType.value;
    if (!supportedDrills.value[type]) return;
    const key = drillCacheKey(module, type);
    if (drillRows.value[key]) return;
    drillLoading.value = true;
    const sourceSegment = ['anonymous', 'web'].includes(module) ? 'total' : module;
    const params = new URLSearchParams({
        source: 'gsc', ...filters.value, segment: sourceSegment, detail_type: type,
        page: '1', per_page: '50', sort: 'clicks', direction: 'desc', search_type: 'web', dimension: '',
    });
    try {
        const response = await fetch(`/natural-traffic/seo-geo/source-details?${params.toString()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        let rows: DetailRow[] = payload.rows ?? [];
        if (module === 'anonymous') {
            const currentRatio = Number(props.overview.gsc.anonymous.current.ratio ?? 0) / 100;
            const previousRatio = Number(props.overview.gsc.anonymous.previous.ratio ?? 0) / 100;
            rows = rows.map((row) => {
                const clicks = Math.round(row.clicks * currentRatio);
                const previousClicks = Math.round(row.previous.clicks * previousRatio);
                return {
                    ...row, clicks, impressions: 0, ctr: 0, position: 0,
                    previous: { ...row.previous, clicks: previousClicks, impressions: 0, ctr: 0, position: 0 },
                    difference: { clicks: clicks - previousClicks, impressions: 0, ctr: 0, position: 0 },
                };
            });
        }
        drillRows.value = { ...drillRows.value, [key]: rows };
    } finally {
        drillLoading.value = false;
    }
}

async function loadLandingPages(): Promise<void> {
    if (landingPages.value.length) return;
    landingLoading.value = true;
    const params = new URLSearchParams({ source: 'ga4', ...filters.value, page: '1', per_page: '30', sort: 'revenue', direction: 'desc' });
    try {
        const response = await fetch(`/natural-traffic/seo-geo/source-details?${params.toString()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        landingPages.value = payload.rows ?? [];
        landingTotal.value = Number(payload.pagination?.total ?? landingPages.value.length);
    } finally {
        landingLoading.value = false;
    }
}

function changeSort(key: keyof DetailRow): void {
    if (drillSort.value === key) drillDirection.value = drillDirection.value === 'asc' ? 'desc' : 'asc';
    else { drillSort.value = key; drillDirection.value = key === 'label' ? 'asc' : 'desc'; }
}

function openGscTrend(metric: GscMetric): void {
    activeGscMetric.value = metric;
    gscTrendOpen.value = true;
}

function closeGscTrend(): void {
    gscTrendOpen.value = false;
    gscMetricGroup.value?.querySelector<HTMLButtonElement>(`[data-gsc-metric="${activeGscMetric.value}"]`)?.focus();
}

function lineChart(current: Trend[], previous: Trend[], key: 'revenue' | GscMetric) {
    const width = 920, height = 300, left = 58, right = 22, top = 24, bottom = 42;
    const plotWidth = width - left - right, plotHeight = height - top - bottom;
    const max = Math.max(1, ...current.map((row) => Number(row[key] ?? 0)), ...previous.map((row) => Number(row[key] ?? 0))) * 1.12;
    const length = Math.max(current.length, previous.length, 1);
    const x = (index: number) => length > 1 ? left + index * plotWidth / (length - 1) : left + plotWidth / 2;
    const y = (value: number) => top + plotHeight - value / max * plotHeight;
    return {
        width, height, left, right, top, bottom, plotWidth, plotHeight, max,
        current: current.map((row, index) => ({ ...row, x: x(index), y: y(Number(row[key] ?? 0)), value: Number(row[key] ?? 0), index })),
        previous: previous.map((row, index) => ({ ...row, x: x(index), y: y(Number(row[key] ?? 0)), value: Number(row[key] ?? 0), index })),
        currentLine: current.map((row, index) => `${x(index)},${y(Number(row[key] ?? 0))}`).join(' '),
        previousLine: previous.map((row, index) => `${x(index)},${y(Number(row[key] ?? 0))}`).join(' '),
        ticks: [0, 1, 2, 3, 4].map((index) => ({ y: top + plotHeight - index / 4 * plotHeight, value: max * index / 4 })),
    };
}

function number(value: number): string { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value); }
function formatGscMetric(value: number | undefined, key: GscMetric, axis = false): string {
    if (value === undefined) return '—';
    if (key === 'ctr') return `${value.toFixed(2)}%`;
    if (key === 'position') return value.toFixed(2);
    return axis ? compact(value) : number(value);
}
function gscDeltaColor(key: GscMetric, value: number | null): string {
    if (value === null || value === 0) return 'text-slate-400';
    const improved = key === 'position' ? value < 0 : value > 0;
    return improved ? 'text-emerald-300' : 'text-rose-300';
}
function money(value: number): string { return `$${new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value)}`; }
function percent(value: number): string { return `${value.toFixed(1)}%`; }
function delta(value: number | null | undefined): string { return value === null || value === undefined ? '—' : `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`; }
function dateTime(value: string | null | undefined): string {
    if (!value) return '尚未完成同步';
    return new Intl.DateTimeFormat('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value));
}
function duration(seconds: number): string { const minutes = Math.floor(seconds / 60); const rest = Math.round(seconds % 60); return minutes ? `${minutes}m ${rest}s` : `${rest}s`; }
function compact(value: number): string { return value >= 1000 ? `${(value / 1000).toFixed(value >= 10000 ? 0 : 1)}k` : number(Math.round(value)); }
function difference(value: number, suffix = ''): string { return `${value > 0 ? '+' : ''}${value.toFixed(suffix ? 2 : 0)}${suffix}`; }
</script>

<template>
    <div class="space-y-6">
        <section class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm lg:p-6">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-700">↗</span>
                        <div>
                            <h2 class="text-lg font-black text-slate-950">日期与对比</h2>
                            <p class="text-xs text-slate-500">页面只查询本地数据库，不会实时请求 Google 接口</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <label class="text-xs font-bold text-slate-500">开始日期
                        <input v-model="filters.date_from" type="date" class="mt-1 block h-11 rounded-xl border border-slate-200 px-3 text-sm text-slate-800 outline-none focus:border-blue-500">
                    </label>
                    <label class="text-xs font-bold text-slate-500">结束日期
                        <input v-model="filters.date_to" type="date" class="mt-1 block h-11 rounded-xl border border-slate-200 px-3 text-sm text-slate-800 outline-none focus:border-blue-500">
                    </label>
                    <label class="text-xs font-bold text-slate-500">对比方式
                        <select v-model="filters.comparison" class="mt-1 block h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500">
                            <option value="previous">上一周期</option><option value="year">去年同期</option><option value="none">不对比</option>
                        </select>
                    </label>
                    <button class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-black text-white hover:bg-blue-700" @click="applyFilters">应用</button>
                </div>
            </div>
            <div class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 text-xs sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap gap-2 text-slate-500">
                    <span class="rounded-full bg-slate-100 px-3 py-1.5">数据截至 {{ overview.data_through || '等待首次同步' }}</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1.5">{{ comparisonPeriodLabel }}<template v-if="filters.comparison !== 'none'"> {{ overview.comparison_period.date_from }} — {{ overview.comparison_period.date_to }}</template></span>
                    <span class="rounded-full bg-slate-100 px-3 py-1.5">最近同步 {{ dateTime(latestSyncAt) }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="syncing" class="font-bold text-blue-700">{{ status.priority_ready ? '最近7天已就绪，历史回填' : '优先同步最近7天' }} {{ status.progress_percent }}%<template v-if="status.total_shards"> · {{ status.completed_shards }}/{{ status.total_shards }} 分片</template> · {{ number(status.processed_rows) }} 行</span>
                    <span v-else-if="status.status === 'failed'" class="font-bold text-rose-600" :title="status.message || ''">上次同步失败</span>
                    <button v-if="canSync" :disabled="syncing || !sourceStatus.configured" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 font-black text-blue-700 disabled:cursor-not-allowed disabled:opacity-40" @click="queueSync">{{ syncing ? '同步进行中' : '后台刷新数据' }}</button>
                </div>
            </div>
        </section>

        <div v-if="!sourceStatus.configured" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            GA4 / GSC 配置不完整。现有本地数据仍可查看；补齐店铺数据源后才能提交后台同步任务。
        </div>
        <div v-if="!overview.data_ready" class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-900">
            本地数据库暂无综合看板数据。点击“后台刷新数据”后任务会在队列运行，当前页面无需等待。
        </div>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <article v-for="card in metricCards" :key="card.label" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between"><p class="text-xs font-black uppercase tracking-wider text-slate-400">{{ card.label }}</p><span class="h-2.5 w-2.5 rounded-full" :class="card.tint"></span></div>
                <p class="mt-4 text-3xl font-black tracking-tight tabular-nums" :class="card.color">{{ card.value }}</p>
                <div class="mt-3 flex items-center justify-between gap-2 text-xs"><span :class="(card.delta ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="font-black">{{ delta(card.delta) }}</span><span class="truncate text-slate-400">{{ card.source }}</span></div>
            </article>
        </section>

        <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-600">GA4 revenue</p><h2 class="mt-1 text-xl font-black text-slate-950">SEO GMV 与自然渠道</h2><p class="mt-1 text-sm text-slate-500">Organic Search + Organic Shopping + AI assistants</p></div>
                <div class="text-right"><p class="text-3xl font-black tabular-nums text-slate-950">{{ money(overview.ga4.current.revenue ?? 0) }}</p><p class="text-xs text-slate-500">{{ number(overview.ga4.current.sessions ?? 0) }} Sessions</p></div>
            </div>
            <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div class="overflow-x-auto">
                    <svg :viewBox="`0 0 ${gaChart.width} ${gaChart.height}`" class="min-w-[720px] w-full" role="img" aria-label="SEO GMV趋势">
                        <g v-for="tick in gaChart.ticks" :key="tick.y"><line :x1="gaChart.left" :x2="gaChart.width-gaChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5"/><text :x="gaChart.left-8" :y="tick.y+4" text-anchor="end" fill="#94a3b8" font-size="11">{{ compact(tick.value) }}</text></g>
                        <polyline v-if="filters.comparison !== 'none'" :points="gaChart.previousLine" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-dasharray="7 6"/>
                        <polyline :points="gaChart.currentLine" fill="none" stroke="#10b981" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <g v-for="point in gaChart.current" :key="point.date"><circle :cx="point.x" :cy="point.y" r="4" fill="#10b981"><title>{{ point.date }} · {{ money(point.value) }}</title></circle><text v-if="point.index===0 || point.index===gaChart.current.length-1" :x="point.x" :y="gaChart.height-12" text-anchor="middle" fill="#64748b" font-size="11">{{ point.date.slice(5) }}</text></g>
                    </svg>
                </div>
                <div class="space-y-3">
                    <div v-for="channel in overview.ga4.channels" :key="channel.channel" class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                        <div class="flex items-center justify-between"><span class="text-sm font-black text-slate-800">{{ channel.channel }}</span><span class="text-xs font-bold text-slate-500">{{ percent(channel.share) }}</span></div>
                        <div class="mt-3 h-2 rounded-full bg-white"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, channel.share)}%` }"></div></div>
                        <div class="mt-2 flex justify-between text-xs text-slate-500"><span>{{ money(channel.revenue) }}</span><span>{{ number(channel.sessions) }} sessions</span></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between"><div><h2 class="text-xl font-black text-slate-950">GMV 着陆页明细</h2><p class="mt-1 text-sm text-slate-500">按总收入降序，首屏完成后从本地数据库加载</p></div><span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">{{ landingLoading ? '读取中…' : `${landingTotal} 个组合` }}</span></div>
            <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-[1280px] w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3">着陆页</th><th class="px-4 py-3">渠道</th><th class="px-4 py-3 text-right">会话</th><th class="px-4 py-3 text-right">活跃用户</th><th class="px-4 py-3 text-right">新用户</th><th class="px-4 py-3 text-right">平均互动</th><th class="px-4 py-3 text-right">关键事件</th><th class="px-4 py-3 text-right">收入</th><th class="px-4 py-3 text-right">跳出率</th><th class="px-4 py-3 text-right">事件率</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="row in landingRows" :key="row.key" class="hover:bg-slate-50"><td class="max-w-[360px] truncate px-4 py-3 font-semibold text-slate-800" :title="row.page">{{ row.page }}</td><td class="px-4 py-3"><span class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ row.channel }}</span></td><td class="px-4 py-3 text-right tabular-nums">{{ number(row.sessions) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number(row.active_users) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number(row.new_users) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ duration(row.average_engagement_seconds) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number(row.key_events) }}</td><td class="px-4 py-3 text-right font-black tabular-nums text-emerald-700">{{ money(row.revenue) }}</td><td class="px-4 py-3 text-right">{{ percent(row.bounce_rate) }}</td><td class="px-4 py-3 text-right">{{ percent(row.event_rate) }}</td></tr><tr v-if="landingLoading && !landingRows.length"><td colspan="10" class="px-4 py-10 text-center text-slate-400">正在读取着陆页明细…</td></tr></tbody>
                </table>
            </div>
            <button v-if="landingPages.length > 7" class="mt-4 text-sm font-black text-blue-700" @click="landingExpanded=!landingExpanded">{{ landingExpanded ? '收起明细' : `展开已加载的 ${landingPages.length} 条` }}</button>
        </section>

        <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Search Console</p><h2 class="mt-1 text-xl font-black text-slate-950">搜索表现与页面下钻</h2></div><div class="flex flex-wrap gap-2 rounded-2xl bg-slate-100 p-1.5"><button v-for="option in moduleOptions" :key="option.key" class="rounded-xl px-4 py-2 text-xs font-black transition" :class="activeModule===option.key ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500'" @click="activeModule=option.key">{{ option.label }}</button></div></div>
            <div class="mt-6 grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
                <div ref="gscMetricGroup" class="rounded-2xl bg-slate-950 p-3 text-white" role="group" aria-label="搜索表现指标">
                    <div class="grid grid-cols-1 auto-rows-fr gap-2">
                        <button
                            v-for="metric in gscMetricCards"
                            :key="metric.key"
                            type="button"
                            :data-gsc-metric="metric.key"
                            :aria-pressed="gscTrendOpen && activeGscMetric === metric.key"
                            :aria-expanded="gscTrendOpen && activeGscMetric === metric.key"
                            aria-controls="gsc-metric-trend"
                            class="group min-w-0 rounded-xl border p-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950"
                            :class="[
                                gscTrendOpen && activeGscMetric === metric.key
                                    ? 'border-blue-400 bg-blue-500/20'
                                    : 'border-transparent bg-white/5 hover:border-white/20 hover:bg-white/10',
                            ]"
                            @click="openGscTrend(metric.key)"
                        >
                            <span class="flex items-center justify-between gap-2 text-xs font-semibold text-slate-300">
                                {{ metric.label }}
                                <svg class="h-3.5 w-3.5 shrink-0 transition" :class="gscTrendOpen && activeGscMetric === metric.key ? 'text-blue-300' : 'text-slate-500 group-hover:text-white'" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 11.5 6 7.5 9 10.5 14 4.5M9.5 4.5H14V9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="mt-2 block text-xl font-black tracking-tight tabular-nums">{{ metric.value }}</span>
                            <span class="mt-2 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs">
                                <span class="text-slate-400">{{ comparisonLabel }}</span>
                                <span class="font-bold tabular-nums" :class="gscDeltaColor(metric.key, metric.delta)" :title="metric.delta === null && filters.comparison !== 'none' ? '对比期为 0 或没有可用数据，无法计算变化百分比' : undefined">{{ delta(metric.delta) }}</span>
                            </span>
                        </button>
                    </div>
                    <p class="px-3 pt-3 text-xs leading-5 text-slate-400">{{ currentModule.description }}</p>
                </div>
                <div id="gsc-metric-trend" class="min-w-0">
                    <Transition
                        mode="out-in"
                        enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
                        enter-from-class="translate-x-3 opacity-0"
                        enter-to-class="translate-x-0 opacity-100"
                        leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
                        leave-from-class="translate-x-0 opacity-100"
                        leave-to-class="translate-x-3 opacity-0"
                    >
                        <section v-if="gscTrendOpen" :key="activeGscMetric" class="h-full rounded-2xl border border-blue-100 bg-blue-50/20 p-4" :aria-label="`${gscMetricLabel}趋势详情`" @keydown.esc.stop.prevent="closeGscTrend">
                            <div class="flex items-start justify-between gap-3">
                                <div aria-live="polite">
                                    <h3 class="font-black text-slate-900">{{ gscMetricLabel }}趋势</h3>
                                    <p class="mt-1 text-xs text-slate-500">{{ overview.filters.date_from }} — {{ overview.filters.date_to }}<span v-if="activeGscMetric === 'position'" class="ml-2">数值越小，排名越靠前</span></p>
                                </div>
                                <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" aria-label="收起趋势图" @click="closeGscTrend">
                                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m4 4 8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                            <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
                                <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 rounded bg-blue-600"></span>本期</span>
                                <span v-if="filters.comparison !== 'none'" class="inline-flex items-center gap-2"><span class="w-5 border-t-2 border-dashed border-slate-400"></span>{{ comparisonPeriodLabel }} · {{ overview.comparison_period.date_from }} — {{ overview.comparison_period.date_to }}</span>
                            </div>
                            <div v-if="gscHasTrend" class="mt-2 overflow-x-auto">
                                <svg :viewBox="`0 0 ${gscChart.width} ${gscChart.height}`" class="min-w-[540px] w-full" role="img" :aria-label="`${gscMetricLabel}趋势`">
                                    <g v-for="tick in gscChart.ticks" :key="tick.y">
                                        <line :x1="gscChart.left" :x2="gscChart.width-gscChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5"/>
                                        <text :x="gscChart.left-8" :y="tick.y+4" text-anchor="end" fill="#94a3b8" font-size="11">{{ formatGscMetric(tick.value, activeGscMetric, true) }}</text>
                                    </g>
                                    <polyline v-if="filters.comparison !== 'none'" :points="gscChart.previousLine" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-dasharray="7 6"/>
                                    <polyline :points="gscChart.currentLine" fill="none" stroke="#2563eb" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <g v-for="point in gscChart.previous" :key="point.date">
                                        <circle :cx="point.x" :cy="point.y" r="3" fill="#94a3b8"><title>{{ comparisonPeriodLabel }} · {{ point.date }} · {{ formatGscMetric(point.value, activeGscMetric) }}</title></circle>
                                    </g>
                                    <g v-for="point in gscChart.current" :key="point.date">
                                        <circle :cx="point.x" :cy="point.y" r="4" fill="#2563eb"><title>本期 · {{ point.date }} · {{ formatGscMetric(point.value, activeGscMetric) }}</title></circle>
                                        <text v-if="point.index===0 || point.index===gscChart.current.length-1" :x="point.x" :y="gscChart.height-12" text-anchor="middle" fill="#64748b" font-size="11">{{ point.date.slice(5) }}</text>
                                    </g>
                                </svg>
                            </div>
                            <div v-else class="flex min-h-60 items-center justify-center text-sm text-slate-400">当前日期暂无{{ gscMetricLabel }}趋势数据</div>
                        </section>
                        <div v-else key="closed" class="flex h-full min-h-72 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 px-6 text-center">
                            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-blue-500 shadow-sm">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 4v16h16M7 14l4-4 4 3 5-7" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <p class="mt-4 text-sm font-semibold text-slate-700">点击指标数字，展开趋势图</p>
                            <p class="mt-1.5 text-xs leading-5 text-slate-400">查看点击、曝光、CTR 或平均排名的变化</p>
                        </div>
                    </Transition>
                </div>
            </div>

            <div class="mt-7 border-t border-slate-100 pt-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div class="flex gap-2"><button :disabled="!supportedDrills.queries" class="rounded-xl px-4 py-2 text-xs font-black disabled:opacity-30" :class="drillType==='queries' ? 'bg-blue-600 text-white':'bg-slate-100 text-slate-600'" @click="drillType='queries'">热门搜索 {{ availableDrills.queries }}</button><button :disabled="!supportedDrills.pages" class="rounded-xl px-4 py-2 text-xs font-black disabled:opacity-30" :class="drillType==='pages' ? 'bg-blue-600 text-white':'bg-slate-100 text-slate-600'" @click="drillType='pages'">网页下钻 {{ availableDrills.pages }}</button></div><input v-model="drillSearch" type="search" :placeholder="drillType==='queries' ? '搜索关键词…':'搜索 URL…'" class="h-10 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-blue-500 lg:w-80"></div>
                <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200"><table class="min-w-[1100px] w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="cursor-pointer px-4 py-3" @click="changeSort('label')">{{ drillType==='queries' ? '搜索词':'网页' }}</th><th class="cursor-pointer px-4 py-3 text-right" @click="changeSort('clicks')">点击</th><th class="px-4 py-3 text-right">点击差值</th><th class="cursor-pointer px-4 py-3 text-right" @click="changeSort('impressions')">曝光</th><th class="px-4 py-3 text-right">曝光差值</th><th class="cursor-pointer px-4 py-3 text-right" @click="changeSort('ctr')">CTR</th><th class="px-4 py-3 text-right">CTR差值</th><th class="cursor-pointer px-4 py-3 text-right" @click="changeSort('position')">排名</th><th class="px-4 py-3 text-right">排名差值</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="row in pagedDrillRows" :key="row.hash" class="hover:bg-slate-50"><td class="max-w-[420px] truncate px-4 py-3 font-semibold text-slate-800" :title="row.label">{{ row.label }}</td><td class="px-4 py-3 text-right tabular-nums"><strong>{{ number(row.clicks) }}</strong><span class="ml-1 text-xs text-slate-400">vs {{ number(row.previous.clicks) }}</span></td><td class="px-4 py-3 text-right" :class="row.difference.clicks>=0?'text-emerald-600':'text-rose-600'">{{ difference(row.difference.clicks) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number(row.impressions) }} <span class="text-xs text-slate-400">vs {{ number(row.previous.impressions) }}</span></td><td class="px-4 py-3 text-right" :class="row.difference.impressions>=0?'text-emerald-600':'text-rose-600'">{{ difference(row.difference.impressions) }}</td><td class="px-4 py-3 text-right">{{ percent(row.ctr) }}</td><td class="px-4 py-3 text-right">{{ difference(row.difference.ctr, '%') }}</td><td class="px-4 py-3 text-right">{{ row.position.toFixed(1) }}</td><td class="px-4 py-3 text-right">{{ difference(row.difference.position, '') }}</td></tr><tr v-if="drillLoading && !pagedDrillRows.length"><td colspan="9" class="px-4 py-10 text-center text-slate-400">正在读取本地明细…</td></tr><tr v-else-if="!pagedDrillRows.length"><td colspan="9" class="px-4 py-10 text-center text-slate-400">当前筛选下暂无本地明细</td></tr></tbody></table></div>
                <div v-if="drillPages>1" class="mt-4 flex items-center justify-between text-xs text-slate-500"><span>共 {{ filteredDrillRows.length }} 条</span><div class="flex items-center gap-2"><button :disabled="drillPage<=1" class="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-30" @click="drillPage--">上一页</button><span>{{ drillPage }} / {{ drillPages }}</span><button :disabled="drillPage>=drillPages" class="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-30" @click="drillPage++">下一页</button></div></div>
            </div>
        </section>
    </div>
</template>
