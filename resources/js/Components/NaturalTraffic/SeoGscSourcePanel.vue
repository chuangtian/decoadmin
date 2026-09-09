<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

type Summary = { clicks: number; impressions: number; ctr: number; position: number };
type Trend = Summary & { date: string };
type DetailRow = Summary & {
    hash: string; label: string; previous?: Summary; difference?: Summary; status?: 'new' | 'existing';
};
type Segment = {
    label: string; description: string; current: Summary; previous: Summary; deltas: Record<string, number | null>;
    trend: Trend[]; queries: DetailRow[]; pages: DetailRow[];
};
type SourceData = {
    filters: { date_from: string; date_to: string; comparison: string; segment: string };
    comparison_period: { date_from: string; date_to: string };
    data_through: string | null; generated_at: string; data_ready: boolean;
    segment_key: string; segment: Segment; segments: Record<string, Segment>; segment_totals: Record<string, Summary>;
};
type Pagination = { current_page: number; per_page: number; total: number; last_page: number; from: number; to: number };

const props = defineProps<{ data: SourceData }>();
const filters = ref({ ...props.data.filters });
const activeSegment = ref(props.data.segment_key);
const detailType = ref<'queries' | 'pages'>(props.data.segment_key === 'blog' ? 'pages' : 'queries');
const search = ref('');
const sortKey = ref<'clicks' | 'impressions' | 'ctr' | 'position' | 'label'>('clicks');
const sortDirection = ref<'asc' | 'desc'>('desc');
const metric = ref<keyof Summary>('clicks');
const grain = ref<'day' | 'week' | 'month'>('day');
const searchType = ref('web');
const dimension = ref('');
const sourceSummary = ref<Summary | null>(null);
const sourceTrend = ref<Trend[] | null>(null);
const page = ref(1);
const perPage = ref(50);
const detailRows = ref<DetailRow[]>([]);
const pagination = ref<Pagination>({ current_page: 1, per_page: 50, total: 0, last_page: 1, from: 0, to: 0 });
const loading = ref(false);
const detailError = ref('');
let requestId = 0;
let searchTimer: ReturnType<typeof setTimeout> | null = null;

const segmentOptions = [
    { key: 'total', label: '全站', color: 'bg-blue-500' },
    { key: 'brand', label: '品牌词', color: 'bg-teal-500' },
    { key: 'industry', label: '行业词', color: 'bg-violet-500' },
    { key: 'blog', label: '博客页', color: 'bg-orange-500' },
];
const metricOptions: Array<{ key: keyof Summary; label: string }> = [
    { key: 'clicks', label: '点击' }, { key: 'impressions', label: '曝光' }, { key: 'ctr', label: 'CTR' }, { key: 'position', label: '排名' },
];
const segment = computed(() => props.data.segments[activeSegment.value] ?? props.data.segment);
const currentSummary = computed(() => sourceSummary.value ?? segment.value.current);
const currentTrend = computed(() => sourceTrend.value ?? segment.value.trend);
const detailLabel = computed(() => ({ country: '国家/地区', device: '设备', search_appearance: '搜索结果呈现' }[dimension.value] ?? (detailType.value === 'queries' ? '搜索词' : '页面')));
const groupedTrend = computed<Trend[]>(() => {
    if (grain.value === 'day') return currentTrend.value ?? [];
    const groups = new Map<string, Trend[]>();
    for (const item of currentTrend.value ?? []) {
        const date = new Date(`${item.date}T00:00:00Z`);
        let key = item.date.slice(0, 7);
        if (grain.value === 'week') {
            const day = date.getUTCDay() || 7;
            date.setUTCDate(date.getUTCDate() - day + 1);
            key = date.toISOString().slice(0, 10);
        }
        groups.set(key, [...(groups.get(key) ?? []), item]);
    }
    return [...groups.entries()].map(([date, rows]) => {
        const clicks = rows.reduce((sum, row) => sum + row.clicks, 0);
        const impressions = rows.reduce((sum, row) => sum + row.impressions, 0);
        const weightedPosition = rows.reduce((sum, row) => sum + row.position * row.impressions, 0);
        return { date, clicks, impressions, ctr: impressions ? clicks / impressions * 100 : 0, position: impressions ? weightedPosition / impressions : 0 };
    });
});
const chart = computed(() => {
    const items = groupedTrend.value;
    const width = 820; const height = 220; const padX = 24; const padY = 24;
    const values = items.map((item) => Number(item[metric.value]));
    const max = Math.max(1, ...values);
    const min = metric.value === 'position' ? Math.min(...values, 0) : 0;
    const points = items.map((item, index) => {
        const value = Number(item[metric.value]);
        const ratio = max === min ? 0.5 : (value - min) / (max - min);
        return { ...item, value, x: items.length <= 1 ? width / 2 : padX + index * ((width - padX * 2) / (items.length - 1)), y: height - padY - ratio * (height - padY * 2) };
    });
    const line = points.map((point) => `${point.x},${point.y}`).join(' ');
    return { points, line, area: points.length ? `${padX},${height - padY} ${line} ${points.at(-1)?.x},${height - padY}` : '' };
});
const metricCards = computed(() => [
    { key: 'clicks', label: '点击', value: number(currentSummary.value.clicks), delta: searchType.value === 'web' ? segment.value.deltas.clicks : null, note: 'Search Console Clicks' },
    { key: 'impressions', label: '曝光', value: number(currentSummary.value.impressions), delta: searchType.value === 'web' ? segment.value.deltas.impressions : null, note: 'Search Console Impressions' },
    { key: 'ctr', label: 'CTR', value: percent(currentSummary.value.ctr), delta: searchType.value === 'web' ? segment.value.deltas.ctr : null, note: '点击 ÷ 曝光' },
    { key: 'position', label: '平均排名', value: currentSummary.value.position.toFixed(2), delta: searchType.value === 'web' ? segment.value.deltas.position : null, note: '按曝光加权' },
]);

watch([detailType, activeSegment, searchType, dimension, sortKey, sortDirection, perPage], () => { page.value = 1; void loadDetails(); });
watch(searchType, (value) => { if (value !== 'web') dimension.value = ''; });
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { page.value = 1; void loadDetails(); }, 300);
});
onMounted(() => { void loadDetails(); });

async function loadDetails(): Promise<void> {
    const currentRequest = ++requestId;
    loading.value = true;
    detailError.value = '';
    const params = new URLSearchParams({
        source: 'gsc', ...filters.value, segment: activeSegment.value, detail_type: detailType.value,
        page: String(page.value), per_page: String(perPage.value), search: search.value,
        sort: sortKey.value, direction: sortDirection.value, search_type: searchType.value, dimension: dimension.value,
    });
    try {
        const response = await fetch(`/natural-traffic/seo-geo/source-details?${params.toString()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`明细读取失败（${response.status}）`);
        const payload = await response.json();
        if (currentRequest !== requestId) return;
        detailRows.value = payload.rows ?? [];
        pagination.value = payload.pagination ?? pagination.value;
        sourceSummary.value = payload.summary ?? null;
        sourceTrend.value = payload.trend ?? null;
    } catch (error) {
        if (currentRequest === requestId) detailError.value = error instanceof Error ? error.message : '明细读取失败';
    } finally {
        if (currentRequest === requestId) loading.value = false;
    }
}
function applyFilters(): void {
    router.get('/natural-traffic/seo-geo', { tab: 'gsc', ...filters.value, segment: activeSegment.value }, { preserveScroll: true });
}
function quickRange(days: number): void {
    const end = new Date(`${props.data.data_through || filters.value.date_to}T00:00:00Z`);
    const start = new Date(end); start.setUTCDate(start.getUTCDate() - days + 1);
    filters.value.date_from = start.toISOString().slice(0, 10);
    filters.value.date_to = end.toISOString().slice(0, 10);
    applyFilters();
}
function changeSegment(key: string): void {
    if (key === activeSegment.value) return;
    activeSegment.value = key;
    sourceSummary.value = null;
    sourceTrend.value = null;
    detailType.value = key === 'blog' ? 'pages' : 'queries';
    search.value = '';
}
function toggleSort(key: typeof sortKey.value): void {
    if (sortKey.value === key) sortDirection.value = sortDirection.value === 'desc' ? 'asc' : 'desc';
    else { sortKey.value = key; sortDirection.value = key === 'position' || key === 'label' ? 'asc' : 'desc'; }
}
function goToPage(value: number): void { page.value = Math.max(1, Math.min(pagination.value.last_page, value)); void loadDetails(); }
function number(value: number): string { return new Intl.NumberFormat('en-US').format(Math.round(value)); }
function percent(value: number): string { return `${Number(value).toFixed(2)}%`; }
function signed(value: number, suffix = ''): string { return `${value > 0 ? '+' : ''}${Number(value).toFixed(suffix ? 2 : 0)}${suffix}`; }
function metricValue(row: Trend): string { return metric.value === 'ctr' ? percent(row.ctr) : metric.value === 'position' ? row.position.toFixed(2) : number(row[metric.value]); }
function deltaClass(value: number | null | undefined, reverse = false): string {
    if (value == null || value === 0) return 'text-slate-400';
    const positive = reverse ? value < 0 : value > 0;
    return positive ? 'text-emerald-600' : 'text-rose-600';
}
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
                            <span class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-black uppercase tracking-[0.16em] text-white">GSC 本地快照</span>
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>本地数据库已就绪</span>
                        </div>
                        <h2 class="mt-2.5 text-xl font-black tracking-tight text-slate-950">搜索表现数据仓</h2>
                        <p class="mt-1 text-sm text-slate-500">查询、网页与分段指标均从当前店铺的本地归档读取。</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-500">
                        <span class="h-2 w-2 rounded-full bg-blue-500"></span>页面不请求 Google API
                    </div>
                </div>
            </div>

            <div class="px-5 py-5 lg:px-6">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(240px,1.1fr)_minmax(135px,.72fr)_minmax(135px,.72fr)_minmax(120px,.58fr)_minmax(96px,auto)] xl:items-end">
                    <fieldset class="min-w-0">
                        <legend class="mb-1.5 text-xs font-black uppercase tracking-[0.12em] text-slate-400">快捷范围</legend>
                        <div class="grid h-11 grid-cols-4 rounded-xl bg-blue-50 p-1">
                            <button v-for="preset in [{ days: 1, label: '24小时' }, { days: 7, label: '7天' }, { days: 28, label: '28天' }, { days: 90, label: '3个月' }]" :key="preset.days" type="button" class="rounded-lg px-2 text-xs font-black transition" :class="isQuickRangeActive(preset.days) ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-blue-700'" @click="quickRange(preset.days)">{{ preset.label }}</button>
                        </div>
                    </fieldset>
                    <label for="gsc-date-from" class="text-xs font-black uppercase tracking-[0.12em] text-slate-400">开始日期<input id="gsc-date-from" v-model="filters.date_from" name="gsc_date_from" type="date" class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold tracking-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"></label>
                    <label for="gsc-date-to" class="text-xs font-black uppercase tracking-[0.12em] text-slate-400">结束日期<input id="gsc-date-to" v-model="filters.date_to" name="gsc_date_to" type="date" class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold tracking-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"></label>
                    <label for="gsc-comparison" class="text-xs font-black uppercase tracking-[0.12em] text-slate-400">对比<select id="gsc-comparison" v-model="filters.comparison" name="gsc_comparison" class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold tracking-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"><option value="previous">上一周期</option><option value="year">去年同期</option><option value="none">不对比</option></select></label>
                    <button type="button" class="h-11 min-w-24 whitespace-nowrap rounded-xl bg-slate-950 px-4 text-sm font-black text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200" @click="applyFilters">应用范围</button>
                </div>

                <div class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold text-slate-500">
                        <span><strong class="text-slate-700">数据截至</strong> {{ data.data_through || '等待同步' }}</span>
                        <span class="hidden h-3 w-px bg-slate-200 sm:block"></span>
                        <span><strong class="text-slate-700">{{ comparisonLabel() }}</strong> {{ data.comparison_period.date_from }} — {{ data.comparison_period.date_to }}</span>
                        <span class="hidden h-3 w-px bg-slate-200 sm:block"></span>
                        <span>全量明细 · 服务端分页</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label for="gsc-search-type" class="flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-xs font-bold text-slate-500">搜索类型<select id="gsc-search-type" v-model="searchType" name="gsc_search_type" class="bg-transparent font-black text-slate-800 outline-none"><option value="web">网络</option><option value="image">图片</option><option value="video">视频</option><option value="news">新闻</option></select></label>
                        <label for="gsc-dimension" class="flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-xs font-bold text-slate-500">高级维度<select id="gsc-dimension" v-model="dimension" name="gsc_dimension" class="bg-transparent font-black text-slate-800 outline-none"><option value="">Query / Page</option><option value="country">国家/地区</option><option value="device">设备</option><option value="search_appearance">搜索结果呈现</option></select></label>
                    </div>
                </div>
            </div>
        </section>

        <section class="flex gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
            <button v-for="option in segmentOptions" :key="option.key" class="flex min-w-fit items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-black transition" :class="activeSegment === option.key ? 'bg-slate-950 text-white' : 'text-slate-500 hover:bg-slate-50'" @click="changeSegment(option.key)">
                <span class="h-2 w-2 rounded-full" :class="option.color"></span>{{ option.label }}
            </button>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <button v-for="card in metricCards" :key="card.label" class="rounded-2xl border bg-white p-5 text-left shadow-sm transition" :class="metric === card.key ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200 hover:border-blue-200'" @click="metric = card.key as keyof Summary">
                <div class="flex items-start justify-between gap-3"><p class="text-xs font-black uppercase tracking-wider text-slate-400">{{ card.label }}</p><span class="text-xs font-bold" :class="deltaClass(card.delta, card.label === '平均排名')">{{ card.delta == null || filters.comparison === 'none' ? '—' : signed(card.delta, '%') }}</span></div>
                <p class="mt-3 text-3xl font-black tabular-nums text-slate-950">{{ card.value }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ card.note }}</p>
            </button>
        </section>

        <section class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(280px,0.8fr)]">
            <article class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4"><div><h3 class="text-lg font-black text-slate-950">{{ segmentOptions.find((item) => item.key === activeSegment)?.label }}{{ metricOptions.find((item) => item.key === metric)?.label }}趋势</h3><p class="mt-1 text-xs text-slate-400">本地 {{ grain === 'day' ? '按日' : grain === 'week' ? '按周' : '按月' }}聚合</p></div><div class="flex gap-2"><select v-model="metric" name="gsc_trend_metric" class="h-9 rounded-lg border border-slate-200 px-3 text-xs font-bold"><option v-for="item in metricOptions" :key="item.key" :value="item.key">{{ item.label }}</option></select><select v-model="grain" name="gsc_trend_grain" class="h-9 rounded-lg border border-slate-200 px-3 text-xs font-bold"><option value="day">按日</option><option value="week">按周</option><option value="month">按月</option></select></div></div>
                <div class="mt-6 overflow-x-auto">
                    <svg v-readable-chart class="min-w-[620px]" viewBox="0 0 820 260" role="img" :aria-label="`${segmentOptions.find((item) => item.key === activeSegment)?.label}${metricOptions.find((item) => item.key === metric)?.label}趋势`">
                        <defs><linearGradient id="gscArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2563eb" stop-opacity=".26"/><stop offset="1" stop-color="#2563eb" stop-opacity="0"/></linearGradient></defs>
                        <line v-for="row in 5" :key="row" x1="24" x2="796" :y1="24 + (row - 1) * 43" :y2="24 + (row - 1) * 43" stroke="#e2e8f0" stroke-dasharray="5 7"/>
                        <polygon v-if="chart.points.length" :points="chart.area" fill="url(#gscArea)"/>
                        <polyline v-if="chart.points.length" :points="chart.line" fill="none" stroke="#2563eb" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                        <g v-for="point in chart.points" :key="point.date"><circle :cx="point.x" :cy="point.y" r="4.5" fill="white" stroke="#2563eb" stroke-width="3"><title>{{ point.date }} · {{ metricValue(point) }}</title></circle></g>
                        <text v-for="(point, index) in chart.points" v-show="index === 0 || index === chart.points.length - 1 || chart.points.length <= 8" :key="`label-${point.date}`" :x="point.x" y="250" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text>
                    </svg>
                </div>
            </article>
            <article class="rounded-[24px] border border-slate-200 bg-slate-950 p-6 text-white shadow-sm">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-300">Segment ledger</p>
                <h3 class="mt-3 text-xl font-black">分段点击结构</h3>
                <div class="mt-6 space-y-5">
                    <button v-for="option in segmentOptions" :key="option.key" class="block w-full text-left" @click="changeSegment(option.key)">
                        <div class="flex items-center justify-between text-sm"><span class="font-bold text-slate-300">{{ option.label }}</span><strong>{{ number(data.segment_totals[option.key].clicks) }}</strong></div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-800"><span class="block h-full rounded-full" :class="option.color" :style="{ width: `${Math.min(100, data.segment_totals.total.clicks ? data.segment_totals[option.key].clicks / data.segment_totals.total.clicks * 100 : 0)}%` }"></span></div>
                    </button>
                </div>
                <p class="mt-7 border-t border-slate-800 pt-5 text-xs leading-relaxed text-slate-400">品牌词和行业词来自本地 Query 分段；博客来自包含 /blogs/ 的 Page 分段。</p>
            </article>
        </section>

        <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between"><div><h3 class="text-lg font-black text-slate-950">日期明细</h3><p class="mt-1 text-xs text-slate-400">与趋势使用同一组本地归档数据</p></div><span class="text-xs font-bold text-slate-400">{{ groupedTrend.length }} 个时间点</span></div>
            <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-slate-200"><table class="min-w-full text-left text-sm"><thead class="sticky top-0 bg-slate-50 text-xs font-black text-slate-400"><tr><th class="px-4 py-3">日期</th><th class="px-4 py-3">点击</th><th class="px-4 py-3">曝光</th><th class="px-4 py-3">CTR</th><th class="px-4 py-3">排名</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="row in groupedTrend" :key="row.date"><td class="px-4 py-3 font-bold text-slate-800">{{ row.date }}</td><td class="px-4 py-3">{{ number(row.clicks) }}</td><td class="px-4 py-3">{{ number(row.impressions) }}</td><td class="px-4 py-3">{{ percent(row.ctr) }}</td><td class="px-4 py-3">{{ row.position.toFixed(2) }}</td></tr></tbody></table></div>
        </section>

        <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div><h3 class="text-lg font-black text-slate-950">{{ segment.label }}明细</h3><p class="mt-1 text-xs text-slate-400">前端筛选与排序，不重新请求外部接口</p></div>
                <div class="flex flex-wrap gap-2">
                    <div v-if="!dimension" class="inline-flex rounded-xl bg-slate-100 p-1"><button v-if="activeSegment !== 'blog'" class="rounded-lg px-4 py-2 text-xs font-black" :class="detailType === 'queries' ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'" @click="detailType = 'queries'">Query</button><button class="rounded-lg px-4 py-2 text-xs font-black" :class="detailType === 'pages' ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'" @click="detailType = 'pages'">Page</button></div>
                    <input v-model="search" type="search" :placeholder="`搜索${detailLabel}…`" class="h-11 min-w-[240px] rounded-xl border border-slate-200 px-4 text-sm outline-none focus:border-blue-500">
                    <select v-model="perPage" class="h-11 rounded-xl border border-slate-200 px-3 text-sm font-bold"><option :value="25">25条</option><option :value="50">50条</option><option :value="100">100条</option></select>
                </div>
            </div>
            <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-black uppercase tracking-wider text-slate-400"><tr><th class="min-w-[300px] px-4 py-3">{{ detailLabel }}</th><th v-for="column in [{ key: 'clicks', label: '点击' }, { key: 'impressions', label: '曝光' }, { key: 'ctr', label: 'CTR' }, { key: 'position', label: '排名' }]" :key="column.key" class="cursor-pointer whitespace-nowrap px-4 py-3" @click="toggleSort(column.key as typeof sortKey)">{{ column.label }} <span v-if="sortKey === column.key">{{ sortDirection === 'desc' ? '↓' : '↑' }}</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in detailRows" :key="row.hash" class="hover:bg-blue-50/40"><td class="max-w-[480px] truncate px-4 py-3.5 font-semibold text-slate-800" :title="row.label"><span v-if="row.status === 'new'" class="mr-2 rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-black text-emerald-700">NEW</span>{{ row.label }}</td><td class="px-4 py-3.5 font-black tabular-nums text-slate-900">{{ number(row.clicks) }} <small v-if="row.difference" :class="deltaClass(row.difference.clicks)" class="ml-1">{{ signed(row.difference.clicks) }}</small></td><td class="px-4 py-3.5 tabular-nums text-slate-600">{{ number(row.impressions) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-600">{{ percent(row.ctr) }}</td><td class="px-4 py-3.5 tabular-nums text-slate-600">{{ row.position.toFixed(2) }}</td></tr>
                        <tr v-if="loading"><td colspan="5" class="px-4 py-12 text-center text-blue-600">正在读取本地明细…</td></tr><tr v-else-if="detailError"><td colspan="5" class="px-4 py-12 text-center text-rose-600">{{ detailError }}</td></tr><tr v-else-if="!detailRows.length"><td colspan="5" class="px-4 py-12 text-center text-slate-400">当前筛选没有匹配数据</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex items-center justify-between text-xs text-slate-400"><span>第 {{ pagination.from }}–{{ pagination.to }} 条，共 {{ number(pagination.total) }} 条本地记录</span><div class="flex items-center gap-3"><button :disabled="page <= 1 || loading" class="rounded-lg border border-slate-200 px-3 py-2 font-bold text-slate-600 disabled:opacity-30" @click="goToPage(page - 1)">上一页</button><strong class="text-slate-700">{{ page }} / {{ pagination.last_page }}</strong><button :disabled="page >= pagination.last_page || loading" class="rounded-lg border border-slate-200 px-3 py-2 font-bold text-slate-600 disabled:opacity-30" @click="goToPage(page + 1)">下一页</button></div></div>
        </section>
    </div>
</template>
