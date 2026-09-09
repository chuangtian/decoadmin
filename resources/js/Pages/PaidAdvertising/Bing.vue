<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../composables/useToast';

type ChannelState = 'not_configured' | 'pending' | 'syncing' | 'backfilling' | 'completed' | 'failed' | 'partial_failed';
type ChannelStatus = {
    schema: 'advertising-channel-sync-status-v1'; channel: 'bing'; label: string; configured: boolean;
    settings_url: string; state: ChannelState; data_ready: boolean; progress_percent: number;
    last_metric_date: string | null; message: string | null;
};
type Summary = {
    spend: number; revenue: number; roas: number; conversions: number; impressions: number; clicks: number;
    ctr: number; cpc: number; cpa: number; add_to_cart: number; checkout: number;
    add_to_cart_cost: number; checkout_cost: number;
};
type TrendPoint = {
    date: string; spend: number; revenue: number; roas: number; impressions: number; clicks: number;
    ctr: number; cpc: number; conversions: number; add_to_cart: number; checkout: number; cpa: number;
};
type Campaign = {
    id: string; name: string; status: string | null; type: string | null; spend: number; revenue: number;
    roas: number; impressions: number; clicks: number; ctr: number; cpc: number; conversions: number; share: number;
};
type Overview = {
    schema: 'bing-ads-overview-v1';
    accounts: Array<{ id: string; name: string; currency: string; timezone: string | null }>;
    filters: { account: string | null; date_from: string; date_to: string };
    comparison_period: { date_from: string; date_to: string };
    currency: string; current: Summary; previous: Summary; trend: TrendPoint[]; previous_trend: TrendPoint[]; campaigns: Campaign[];
    deltas: Record<keyof Summary, number | null>;
};
type MetricCard = {
    key: Exclude<keyof Summary, 'ctr'>; label: string; format: 'money' | 'number' | 'ratio';
    color: string; tint: string; note: (summary: Summary) => string;
};

const props = defineProps<{
    store: { id: number; name: string };
    channelStatus: ChannelStatus;
    bingOverview: Overview | null;
    canSync: boolean;
}>();

const status = ref(props.channelStatus);
const overview = ref(props.bingOverview);
const filters = ref({
    account: props.bingOverview?.filters.account ?? '',
    date_from: props.bingOverview?.filters.date_from ?? '',
    date_to: props.bingOverview?.filters.date_to ?? '',
});
const activeTab = ref('overview');
const comparisonEnabled = ref(true);
const loading = ref(false);
const syncing = ref(false);
const hoveredTrend = ref<number | null>(null);
const hoveredFunnel = ref<number | null>(null);
const hoveredCtr = ref<number | null>(null);
const hoveredCampaign = ref<number | null>(null);
const hoveredCampaignRoas = ref<number | null>(null);
const dailySearch = ref('');
const dailySortKey = ref<keyof TrendPoint>('date');
const dailySortDirection = ref<'asc' | 'desc'>('asc');
const dailyPage = ref(1);
const campaignSearch = ref('');
const campaignPage = ref(1);
const toast = useToast();
let poller: ReturnType<typeof setInterval> | null = null;

const tabs = [
    { key: 'overview', label: '总览', icon: '▥' },
    { key: 'trend', label: '趋势分析', icon: '↗' },
    { key: 'campaigns', label: '广告系列', icon: '▤' },
];
const dailyColumns: Array<{ key: keyof TrendPoint; label: string }> = [
    { key: 'date', label: '日期' }, { key: 'spend', label: '花费' }, { key: 'revenue', label: '广告收入' },
    { key: 'roas', label: 'ROAS' }, { key: 'impressions', label: '曝光' }, { key: 'clicks', label: '点击' },
    { key: 'ctr', label: 'CTR' }, { key: 'cpc', label: 'CPC' }, { key: 'conversions', label: '购买' },
    { key: 'add_to_cart', label: '加购' }, { key: 'checkout', label: '结账' }, { key: 'cpa', label: 'CPA' },
];
const cards: MetricCard[] = [
    { key: 'spend', label: '广告花费', format: 'money', color: '#0ea5e9', tint: '#f0f9ff', note: () => `${filters.value.date_from} — ${filters.value.date_to}` },
    { key: 'revenue', label: '广告收入', format: 'money', color: '#10b981', tint: '#ecfdf5', note: () => '所选时间内 Revenue 汇总' },
    { key: 'roas', label: 'ROAS', format: 'ratio', color: '#14b8a6', tint: '#f0fdfa', note: () => '总收入 ÷ 总花费' },
    { key: 'conversions', label: '购买数', format: 'number', color: '#16a34a', tint: '#f0fdf4', note: () => '购买成功 / purchase 目标' },
    { key: 'impressions', label: '曝光量', format: 'number', color: '#3b82f6', tint: '#eff6ff', note: () => '每日 Impressions 汇总' },
    { key: 'clicks', label: '点击数', format: 'number', color: '#eab308', tint: '#fefce8', note: summary => `CTR ${formatPercent(summary.ctr)}` },
    { key: 'cpc', label: '平均 CPC', format: 'money', color: '#f97316', tint: '#fff7ed', note: () => '总花费 ÷ 总点击数' },
    { key: 'cpa', label: 'CPA', format: 'money', color: '#ea580c', tint: '#fff7ed', note: () => '总花费 ÷ 购买数' },
    { key: 'add_to_cart', label: '加购数', format: 'number', color: '#8b5cf6', tint: '#f5f3ff', note: () => '匹配 add to cart / 加购目标' },
    { key: 'checkout', label: '结账数', format: 'number', color: '#22c55e', tint: '#f0fdf4', note: () => '匹配 checkout / 结账目标' },
    { key: 'add_to_cart_cost', label: '单次加购成本', format: 'money', color: '#a855f7', tint: '#faf5ff', note: () => '总花费 ÷ 加购数' },
    { key: 'checkout_cost', label: '单次结账成本', format: 'money', color: '#f59e0b', tint: '#fffbeb', note: () => '总花费 ÷ 结账数' },
];
const busy = computed(() => loading.value || syncing.value || ['pending', 'syncing', 'backfilling'].includes(status.value.state));
const selectedAccount = computed(() => overview.value?.accounts.find(account => account.id === filters.value.account));
const hasMetrics = computed(() => overview.value ? Object.values(overview.value.current).some(value => value > 0) : false);
const trendChart = computed(() => {
    const width = 780, height = 330, left = 64, right = 62, top = 48, bottom = 50;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const current = overview.value?.trend ?? [];
    const previous = comparisonEnabled.value ? overview.value?.previous_trend ?? [] : [];
    const amountMax = Math.max(1, ...current.map(point => point.spend), ...previous.map(point => point.spend)) * 1.12;
    const roasMax = Math.max(1, ...current.map(point => point.roas), ...previous.map(point => point.roas)) * 1.12;
    const step = current.length ? plotWidth / current.length : plotWidth;
    const barWidth = Math.max(5, Math.min(18, step * (comparisonEnabled.value ? 0.24 : 0.42)));
    const labelEvery = Math.max(1, Math.ceil(current.length / 9));
    const points = current.map((point, index) => {
        const previousPoint = previous[index];
        const x = left + step * (index + 0.5);
        return {
            ...point,
            index,
            showLabel: index % labelEvery === 0 || index === current.length - 1,
            previous: previousPoint,
            x,
            currentBarY: top + plotHeight - (point.spend / amountMax) * plotHeight,
            previousBarY: top + plotHeight - ((previousPoint?.spend ?? 0) / amountMax) * plotHeight,
            currentRoasY: top + plotHeight - (point.roas / roasMax) * plotHeight,
            previousRoasY: top + plotHeight - ((previousPoint?.roas ?? 0) / roasMax) * plotHeight,
        };
    });
    return {
        width, height, left, right, top, bottom, plotWidth, plotHeight, step, barWidth, points,
        currentLine: points.map(point => `${point.x},${point.currentRoasY}`).join(' '),
        previousLine: points.filter(point => point.previous).map(point => `${point.x},${point.previousRoasY}`).join(' '),
        ticks: [0, 1, 2, 3, 4].map(index => ({
            y: top + plotHeight - (index / 4) * plotHeight,
            amount: amountMax * index / 4,
            roas: roasMax * index / 4,
        })),
    };
});
const activeTrend = computed(() => hoveredTrend.value === null ? null : trendChart.value.points[hoveredTrend.value] ?? null);
const funnelChart = computed(() => {
    const summary = overview.value?.current;
    const values = summary ? [summary.impressions, summary.clicks, summary.add_to_cart, summary.checkout, summary.conversions] : [];
    const definitions = [
        { key: 'impressions', label: '曝光', color: '#0ea5e9' },
        { key: 'clicks', label: '点击', color: '#0284c7' },
        { key: 'add_to_cart', label: '加购', color: '#8b5cf6' },
        { key: 'checkout', label: '结账', color: '#f59e0b' },
        { key: 'conversions', label: '购买', color: '#10b981' },
    ];
    const max = Math.max(1, ...values);
    const widthFor = (value: number) => value <= 0 ? 62 : 62 + (Math.log1p(value) / Math.log1p(max)) * 428;
    const center = 300, startY = 28, stageHeight = 54;
    return definitions.map((stage, index) => {
        const value = values[index] ?? 0;
        const topWidth = widthFor(value);
        const bottomWidth = index === definitions.length - 1 ? 24 : widthFor(values[index + 1] ?? 0);
        const y = startY + index * stageHeight;
        return {
            ...stage, value, index, y,
            path: `M ${center - topWidth / 2} ${y} L ${center + topWidth / 2} ${y} L ${center + bottomWidth / 2} ${y + stageHeight} L ${center - bottomWidth / 2} ${y + stageHeight} Z`,
        };
    });
});
const activeFunnel = computed(() => hoveredFunnel.value === null ? null : funnelChart.value[hoveredFunnel.value] ?? null);
const ctrChart = computed(() => {
    const width = 760, height = 300, left = 58, right = 22, top = 28, bottom = 46;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const rows = overview.value?.trend ?? [];
    const max = Math.max(0.5, ...rows.map(row => row.ctr)) * 1.16;
    const step = rows.length > 1 ? plotWidth / (rows.length - 1) : plotWidth;
    const labelEvery = Math.max(1, Math.ceil(rows.length / 9));
    const points = rows.map((row, index) => ({
        ...row,
        index,
        x: rows.length > 1 ? left + step * index : left + plotWidth / 2,
        y: top + plotHeight - (row.ctr / max) * plotHeight,
        showLabel: index % labelEvery === 0 || index === rows.length - 1,
    }));
    const baseline = top + plotHeight;
    return {
        width, height, left, right, top, bottom, plotWidth, plotHeight, step, points, max, baseline,
        line: points.map(point => `${point.x},${point.y}`).join(' '),
        area: points.length ? `${left},${baseline} ${points.map(point => `${point.x},${point.y}`).join(' ')} ${points.at(-1)?.x ?? left},${baseline}` : '',
        ticks: [0, 1, 2, 3, 4].map(index => ({ y: baseline - (index / 4) * plotHeight, value: max * index / 4 })),
    };
});
const activeCtr = computed(() => hoveredCtr.value === null ? null : ctrChart.value.points[hoveredCtr.value] ?? null);

function polar(center: number, radius: number, angle: number) {
    return { x: center + radius * Math.cos(angle), y: center + radius * Math.sin(angle) };
}
const campaignChartRows = computed(() => {
    return (overview.value?.campaigns ?? []).slice(0, 6);
});
const campaignPie = computed(() => {
    const rows = campaignChartRows.value;
    const colors = ['#06b6d4', '#0284c7', '#2563eb', '#8b5cf6', '#10b981', '#f59e0b'];
    const center = 150, radius = 108;
    const total = rows.reduce((sum, row) => sum + row.spend, 0);
    let cursor = -Math.PI / 2;
    return rows.map((row, index) => {
        const share = total > 0 ? row.spend / total * 100 : 0;
        const angle = (share / 100) * Math.PI * 2;
        const start = cursor;
        const end = cursor + angle;
        cursor = end;
        const a = polar(center, radius, start);
        const b = polar(center, radius, end);
        return {
            ...row, index, share, color: colors[index % colors.length],
            path: rows.length === 1 ? '' : `M ${center} ${center} L ${a.x} ${a.y} A ${radius} ${radius} 0 ${angle > Math.PI ? 1 : 0} 1 ${b.x} ${b.y} Z`,
        };
    });
});
const activeCampaign = computed(() => hoveredCampaign.value === null ? null : campaignPie.value[hoveredCampaign.value] ?? null);
const campaignRoasChart = computed(() => {
    const rows = campaignChartRows.value;
    const width = 620, left = 160, right = 55, top = 32, bottom = 34;
    const rowHeight = 52;
    const height = Math.max(300, top + bottom + rows.length * rowHeight);
    const plotWidth = width - left - right;
    const max = Math.max(1, ...rows.map(row => row.roas)) * 1.12;
    const contentTop = top + Math.max(0, (height - top - bottom - rows.length * rowHeight) / 2);
    return {
        width, height, left, right, top, bottom, plotWidth, max,
        rows: rows.map((row, index) => ({
            ...row,
            index,
            y: contentTop + index * rowHeight + 8,
            height: 26,
            width: row.roas / max * plotWidth,
            shortName: row.name.length > 21 ? `${row.name.slice(0, 21)}…` : row.name,
        })),
        ticks: [0, 1, 2, 3, 4, 5].map(index => ({
            x: left + plotWidth * index / 5,
            value: max * index / 5,
        })),
    };
});
const activeCampaignRoas = computed(() => hoveredCampaignRoas.value === null ? null : campaignRoasChart.value.rows[hoveredCampaignRoas.value] ?? null);
const filteredCampaignRows = computed(() => {
    const search = campaignSearch.value.trim().toLowerCase();
    return search === ''
        ? [...(overview.value?.campaigns ?? [])]
        : (overview.value?.campaigns ?? []).filter(row => row.name.toLowerCase().includes(search));
});
const campaignPageSize = 15;
const campaignPageCount = computed(() => Math.max(1, Math.ceil(filteredCampaignRows.value.length / campaignPageSize)));
const paginatedCampaignRows = computed(() => filteredCampaignRows.value.slice((campaignPage.value - 1) * campaignPageSize, campaignPage.value * campaignPageSize));
const filteredDailyRows = computed(() => {
    const search = dailySearch.value.trim().toLowerCase();
    const rows = search === '' ? [...(overview.value?.trend ?? [])] : (overview.value?.trend ?? []).filter(row => row.date.toLowerCase().includes(search));
    const key = dailySortKey.value;
    const direction = dailySortDirection.value === 'asc' ? 1 : -1;
    return rows.sort((a, b) => {
        const first = a[key];
        const second = b[key];
        if (typeof first === 'string' && typeof second === 'string') return first.localeCompare(second) * direction;
        return (Number(first) - Number(second)) * direction;
    });
});
const dailyPageSize = 14;
const dailyPageCount = computed(() => Math.max(1, Math.ceil(filteredDailyRows.value.length / dailyPageSize)));
const paginatedDailyRows = computed(() => filteredDailyRows.value.slice((dailyPage.value - 1) * dailyPageSize, dailyPage.value * dailyPageSize));

function format(value: number, kind: MetricCard['format']) {
    if (kind === 'money') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency', currency: overview.value?.currency || 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(value || 0);
    }
    if (kind === 'ratio') return `${Number(value || 0).toFixed(2)}×`;
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value || 0);
}
function formatPercent(value: number) {
    return `${Number(value || 0).toFixed(2)}%`;
}
function compactNumber(value: number) {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value || 0);
}
function selectTab(key: string) {
    activeTab.value = key;
    const url = new URL(window.location.href);
    if (key === 'overview') url.searchParams.delete('tab');
    else url.searchParams.set('tab', key);
    window.history.replaceState({}, '', url);
}
function sortDaily(key: keyof TrendPoint) {
    if (dailySortKey.value === key) dailySortDirection.value = dailySortDirection.value === 'asc' ? 'desc' : 'asc';
    else {
        dailySortKey.value = key;
        dailySortDirection.value = key === 'date' ? 'asc' : 'desc';
    }
    dailyPage.value = 1;
}
function dailySortIcon(key: keyof TrendPoint) {
    if (dailySortKey.value !== key) return '↕';
    return dailySortDirection.value === 'asc' ? '↑' : '↓';
}
function publicAccountName(name: string) {
    return name.replace(/^default\|/i, '').trim();
}
function accountLabel(account: { id: string; name: string }) {
    const name = publicAccountName(account.name);
    return name.includes(account.id) ? name : `${name} (${account.id})`;
}
function delta(card: MetricCard) {
    return overview.value?.deltas[card.key] ?? null;
}
function deltaText(card: MetricCard) {
    const value = delta(card);
    return value === null ? '上期为 0' : `${value >= 0 ? '↑' : '↓'} ${Math.abs(value).toFixed(1)}%`;
}
function previousText(card: MetricCard) {
    return overview.value ? format(overview.value.previous[card.key], card.format) : '—';
}
function queryParams() {
    const params = new URLSearchParams();
    if (filters.value.account) params.set('account', filters.value.account);
    params.set('date_from', filters.value.date_from);
    params.set('date_to', filters.value.date_to);
    return params;
}
async function loadData(showSuccess = false) {
    if (!filters.value.date_from || !filters.value.date_to || loading.value) return;
    loading.value = true;
    try {
        const response = await fetch(`/paid-advertising/bing/data?${queryParams()}`, { headers: { Accept: 'application/json' } });
        const payload = await response.json().catch(() => null) as { data?: Overview; message?: string } | null;
        if (!response.ok || !payload?.data) throw new Error(payload?.message || 'Bing Ads 数据读取失败。');
        overview.value = payload.data;
        filters.value.account = payload.data.filters.account ?? '';
        dailyPage.value = 1;
        campaignPage.value = 1;
        if (showSuccess) toast.success('Bing Ads 本地数据已刷新。');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Bing Ads 数据读取失败。');
    } finally {
        loading.value = false;
    }
}
async function refreshStatus() {
    const response = await fetch('/paid-advertising/bing/status', { headers: { Accept: 'application/json' } });
    if (!response.ok) return;
    status.value = (await response.json()).data;
    if (!['pending', 'syncing', 'backfilling'].includes(status.value.state) && syncing.value) {
        syncing.value = false;
        await loadData();
        toast.success('Microsoft Ads 数据同步完成。');
    }
}
async function syncData() {
    if (!props.canSync || busy.value) return;
    syncing.value = true;
    try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/paid-advertising/bing/sync', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        const payload = await response.json().catch(() => null) as { message?: string; status?: ChannelStatus } | null;
        if (!response.ok) throw new Error(payload?.message || 'Microsoft Ads 同步任务提交失败。');
        if (payload?.status) status.value = payload.status;
        toast.success(payload?.message || 'Microsoft Ads 同步任务已提交。');
    } catch (error) {
        syncing.value = false;
        toast.error(error instanceof Error ? error.message : 'Microsoft Ads 同步任务提交失败。');
    }
}

onMounted(() => {
    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    if (requestedTab && tabs.some(tab => tab.key === requestedTab)) activeTab.value = requestedTab;
    poller = setInterval(refreshStatus, 3000);
});
onBeforeUnmount(() => { if (poller) clearInterval(poller); });
</script>

<template>
    <Head title="Bing Ads" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '付费广告' }, { label: 'Bing Ads' }]">
        <div class="mx-auto max-w-[1500px] space-y-6">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-6 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-600">Microsoft Advertising · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Bing Ads</h1>
                    <p class="mt-2 text-sm text-slate-500">账户与日指标按当前店铺隔离，页面只读取本地 MySQL 数据。</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold" :class="status.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                    <i class="h-2 w-2 rounded-full" :class="status.configured ? 'bg-emerald-500' : 'bg-amber-500'" />
                    {{ status.configured ? 'Microsoft Ads 已连接' : '尚未配置' }}
                </span>
            </header>

            <section v-if="!status.configured" class="rounded-3xl border border-amber-200 bg-white px-7 py-14 shadow-sm sm:px-10">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-600">需要完成配置</p>
                        <h2 class="mt-3 text-2xl font-semibold text-slate-950">Bing Ads 尚未连接</h2>
                        <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-500">配置 Microsoft Ads OAuth、Developer Token 与账户 ID 后，系统会自动同步最近数据与历史数据。</p>
                    </div>
                    <Link :href="status.settings_url" class="inline-flex h-12 shrink-0 items-center justify-center rounded-xl bg-slate-950 px-7 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">前往配置</Link>
                </div>
            </section>

            <template v-else>
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1.35fr)_minmax(0,0.72fr)_auto] lg:items-end">
                        <label class="block min-w-0">
                            <span class="mb-2 block text-sm font-medium text-slate-500">广告账户</span>
                            <select v-model="filters.account" aria-label="广告账户" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 outline-none ring-cyan-500 focus:ring-2" @change="loadData()">
                                <option v-for="account in overview?.accounts" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
                            </select>
                        </label>
                        <div class="min-w-0">
                            <span class="mb-2 block text-sm font-medium text-slate-500">统计日期</span>
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-xl border border-slate-200 px-3">
                                <input v-model="filters.date_from" aria-label="开始日期" type="date" class="h-12 min-w-0 border-0 bg-transparent text-sm font-medium text-slate-800 outline-none" @change="loadData()" />
                                <span class="text-slate-300">—</span>
                                <input v-model="filters.date_to" aria-label="结束日期" type="date" class="h-12 min-w-0 border-0 bg-transparent text-sm font-medium text-slate-800 outline-none" @change="loadData()" />
                            </div>
                        </div>
                        <label class="block min-w-0">
                            <span class="mb-2 block text-sm font-medium text-slate-500">数据对比</span>
                            <select v-model="comparisonEnabled" aria-label="数据对比" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 outline-none ring-cyan-500 focus:ring-2">
                                <option :value="true">对比上一时段</option>
                                <option :value="false">不显示对比</option>
                            </select>
                        </label>
                        <button v-if="canSync" type="button" class="inline-flex h-12 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-slate-950 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400" :disabled="busy" @click="syncData">
                            <span :class="syncing ? 'animate-spin' : ''">↻</span>{{ syncing ? '同步中' : '同步' }}
                        </button>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500">
                        <span>当前账户：{{ selectedAccount ? publicAccountName(selectedAccount.name) : (filters.account || '—') }} · 币种 {{ overview?.currency || 'USD' }}</span>
                    </div>
                </section>

                <nav class="flex gap-2 overflow-x-auto border-b border-slate-200 pb-3">
                    <button v-for="tab in tabs" :key="tab.key" type="button" class="inline-flex h-12 shrink-0 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition" :class="activeTab === tab.key ? 'border-cyan-200 bg-cyan-50 text-cyan-700 shadow-sm' : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'" @click="selectTab(tab.key)">
                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-lg bg-slate-100 px-1 text-xs" :class="activeTab === tab.key ? 'bg-cyan-600 text-white' : ''">{{ tab.icon }}</span>{{ tab.label }}
                    </button>
                </nav>

                <section v-if="activeTab === 'overview' && overview" class="relative rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-cyan-100 bg-cyan-50/60 px-5 py-4 text-sm">
                        <span class="font-medium text-cyan-800">统计周期：{{ overview.filters.date_from }} — {{ overview.filters.date_to }}</span>
                        <span v-if="comparisonEnabled" class="text-slate-500">对比：{{ overview.comparison_period.date_from }} — {{ overview.comparison_period.date_to }}</span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="card in cards" :key="card.key" class="group relative min-h-48 overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                            <i class="absolute inset-x-0 top-0 h-1" :style="{ backgroundColor: card.color }" />
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                                <span class="h-9 w-9 rounded-xl" :style="{ backgroundColor: card.tint }" />
                            </div>
                            <p class="mt-5 text-3xl font-semibold tracking-tight" :style="{ color: card.color }">{{ format(overview.current[card.key], card.format) }}</p>
                            <div v-if="comparisonEnabled" class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                <span class="font-semibold" :class="delta(card) === null ? 'text-slate-400' : (delta(card) ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-500'">{{ deltaText(card) }}</span>
                                <span class="text-slate-400">上期 {{ previousText(card) }}</span>
                            </div>
                            <p class="mt-3 text-xs text-slate-400">{{ card.note(overview.current) }}</p>
                        </article>
                    </div>

                    <div class="mt-7 grid gap-5 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">花费 &amp; ROAS 趋势</h3>
                                    <p class="mt-1 text-xs text-slate-400">按日期位置对齐本期与上一等长周期</p>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-cyan-500" />花费</span>
                                    <span v-if="comparisonEnabled" class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-cyan-900" />上期花费</span>
                                    <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 bg-emerald-500" />ROAS</span>
                                    <span v-if="comparisonEnabled" class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 border-t-2 border-dashed border-emerald-500" />上期 ROAS</span>
                                </div>
                            </div>
                            <div v-if="trendChart.points.length" class="relative mt-4 w-full" @mouseleave="hoveredTrend = null">
                                <svg v-readable-chart class="h-auto min-h-[300px] w-full" :viewBox="`0 0 ${trendChart.width} ${trendChart.height}`" role="img" aria-label="Bing Ads 每日花费与 ROAS 趋势">
                                    <g v-for="tick in trendChart.ticks" :key="tick.y">
                                        <line :x1="trendChart.left" :x2="trendChart.width - trendChart.right" :y1="tick.y" :y2="tick.y" stroke="#dbe5ef" stroke-dasharray="4 5" />
                                        <text x="4" :y="tick.y + 4" fill="#94a3b8" font-size="12">{{ compactNumber(tick.amount) }}</text>
                                        <text :x="trendChart.width - 4" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ tick.roas.toFixed(1) }}×</text>
                                    </g>
                                    <text :x="trendChart.left" y="20" fill="#94a3b8" font-size="12">花费</text>
                                    <text :x="trendChart.width - trendChart.right" y="20" text-anchor="end" fill="#94a3b8" font-size="12">ROAS</text>
                                    <g v-for="point in trendChart.points" :key="point.date">
                                        <rect v-if="comparisonEnabled" :x="point.x - trendChart.barWidth - 2" :y="point.previousBarY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.previousBarY" rx="2" fill="#164e63" opacity="0.78" />
                                        <rect :x="comparisonEnabled ? point.x + 2 : point.x - trendChart.barWidth / 2" :y="point.currentBarY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.currentBarY" rx="2" fill="#06b6d4" />
                                        <text v-if="point.showLabel" :x="point.x" :y="trendChart.height - 17" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text>
                                        <rect :x="point.x - trendChart.step / 2" :y="trendChart.top" :width="trendChart.step" :height="trendChart.plotHeight" fill="transparent" class="cursor-pointer" @mouseenter="hoveredTrend = point.index" />
                                    </g>
                                    <polyline :points="trendChart.currentLine" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <polyline v-if="comparisonEnabled" :points="trendChart.previousLine" fill="none" stroke="#10b981" stroke-width="2" stroke-dasharray="7 6" stroke-linecap="round" stroke-linejoin="round" opacity="0.75" />
                                    <circle v-for="point in trendChart.points" :key="`current-roas-${point.date}`" :cx="point.x" :cy="point.currentRoasY" r="3.5" fill="#10b981" stroke="white" stroke-width="1.5" />
                                    <line v-if="activeTrend" :x1="activeTrend.x" :x2="activeTrend.x" :y1="trendChart.top" :y2="trendChart.top + trendChart.plotHeight" stroke="#64748b" stroke-dasharray="5 5" />
                                </svg>
                                <div v-if="activeTrend" class="pointer-events-none absolute top-10 z-10 grid min-w-52 -translate-x-1/2 gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500 shadow-xl" :style="{ left: `${Math.min(82, Math.max(18, activeTrend.x / trendChart.width * 100))}%` }">
                                    <strong class="mb-0.5 text-sm text-slate-800">{{ activeTrend.date }}</strong>
                                    <span class="flex justify-between gap-5">花费 <b class="text-cyan-600">{{ format(activeTrend.spend, 'money') }}</b></span>
                                    <span class="flex justify-between gap-5">ROAS <b class="text-emerald-600">{{ activeTrend.roas.toFixed(2) }}×</b></span>
                                    <template v-if="comparisonEnabled && activeTrend.previous">
                                        <span class="mt-1 border-t border-slate-100 pt-1.5 text-slate-400">上期 {{ activeTrend.previous.date }}</span>
                                        <span class="flex justify-between gap-5">上期花费 <b class="text-slate-700">{{ format(activeTrend.previous.spend, 'money') }}</b></span>
                                        <span class="flex justify-between gap-5">上期 ROAS <b class="text-slate-700">{{ activeTrend.previous.roas.toFixed(2) }}×</b></span>
                                    </template>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无逐日数据</div>
                        </article>

                        <article class="relative min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6">
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">转化漏斗</h3>
                                <p class="mt-1 text-xs text-slate-400">{{ overview.filters.date_from }} — {{ overview.filters.date_to }} · 当前周期汇总</p>
                            </div>
                            <div class="mt-4 grid items-center gap-4 md:grid-cols-[minmax(0,1fr)_118px]">
                                <div class="relative" @mouseleave="hoveredFunnel = null">
                                    <svg v-readable-chart class="h-auto min-h-[300px] w-full" viewBox="0 0 600 310" role="img" aria-label="Bing Ads 曝光、点击、加购、结账、购买转化漏斗">
                                        <g v-for="stage in funnelChart" :key="stage.key" class="cursor-pointer" @mouseenter="hoveredFunnel = stage.index">
                                            <path :d="stage.path" :fill="stage.color" :opacity="activeFunnel && activeFunnel.index !== stage.index ? 0.72 : 1" stroke="white" stroke-width="1.5" />
                                            <text x="300" :y="stage.y + 22" text-anchor="middle" fill="white" font-size="13" font-weight="700">{{ stage.label }}</text>
                                            <text x="300" :y="stage.y + 40" text-anchor="middle" fill="white" font-size="14" font-weight="700">{{ format(stage.value, 'number') }}</text>
                                        </g>
                                    </svg>
                                    <div v-if="activeFunnel" class="pointer-events-none absolute bottom-2 left-1/2 z-10 flex -translate-x-1/2 items-center gap-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-lg">
                                        <span class="text-slate-500">{{ activeFunnel.label }}</span>
                                        <strong class="text-slate-900">{{ format(activeFunnel.value, 'number') }}</strong>
                                    </div>
                                </div>
                                <ul class="grid grid-cols-2 gap-2 text-xs text-slate-500 md:grid-cols-1">
                                    <li v-for="stage in funnelChart" :key="`legend-${stage.key}`" class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-2">
                                        <i class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: stage.color }" />
                                        <span>{{ stage.label }}</span>
                                    </li>
                                </ul>
                            </div>
                        </article>
                    </div>
                    <div v-if="!hasMetrics" class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-5 text-sm leading-6 text-slate-500">
                        当前账户在所选时间内尚无本地日指标。可点击“同步 Microsoft Ads”重新拉取；同步完成后本页会按 Spend、Revenue、Impressions、Clicks 与 AllConversionsQualified 自动计算。
                    </div>
                    <div v-if="loading" class="absolute inset-0 grid place-items-center rounded-3xl bg-white/70 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取 Bing Ads 数据…</div>
                </section>

                <section v-else-if="activeTab === 'trend' && overview" class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-cyan-100 bg-cyan-50/60 px-5 py-4 text-sm">
                        <span class="font-medium text-cyan-800">趋势周期：{{ overview.filters.date_from }} — {{ overview.filters.date_to }}</span>
                        <span v-if="comparisonEnabled" class="text-slate-500">对比：{{ overview.comparison_period.date_from }} — {{ overview.comparison_period.date_to }}</span>
                    </div>

                    <article class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-600">趋势分析</p>
                                <h2 class="mt-2 text-xl font-semibold text-slate-950">每日花费 &amp; ROAS</h2>
                                <p class="mt-1 text-sm text-slate-500">本期按日表现，并与上一等长周期按日期位置对齐</p>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500">
                                <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-6 rounded bg-cyan-500" />花费</span>
                                <span v-if="comparisonEnabled" class="inline-flex items-center gap-1.5"><i class="h-2.5 w-6 rounded bg-cyan-900" />上期花费</span>
                                <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-6 bg-emerald-500" />ROAS</span>
                                <span v-if="comparisonEnabled" class="inline-flex items-center gap-1.5"><i class="h-0.5 w-6 border-t-2 border-dashed border-emerald-500" />上期 ROAS</span>
                            </div>
                        </div>
                        <div v-if="trendChart.points.length" class="relative mt-6 w-full" @mouseleave="hoveredTrend = null">
                            <svg v-readable-chart class="h-auto min-h-[380px] w-full" :viewBox="`0 0 ${trendChart.width} ${trendChart.height}`" role="img" aria-label="Bing Ads 趋势分析每日花费与 ROAS">
                                <g v-for="tick in trendChart.ticks" :key="`detail-${tick.y}`">
                                    <line :x1="trendChart.left" :x2="trendChart.width - trendChart.right" :y1="tick.y" :y2="tick.y" stroke="#dbe5ef" stroke-dasharray="4 5" />
                                    <text x="4" :y="tick.y + 4" fill="#94a3b8" font-size="12">{{ compactNumber(tick.amount) }}</text>
                                    <text :x="trendChart.width - 4" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ tick.roas.toFixed(1) }}×</text>
                                </g>
                                <text :x="trendChart.left" y="20" fill="#94a3b8" font-size="12">花费</text>
                                <text :x="trendChart.width - trendChart.right" y="20" text-anchor="end" fill="#94a3b8" font-size="12">ROAS</text>
                                <g v-for="point in trendChart.points" :key="`detail-${point.date}`">
                                    <rect v-if="comparisonEnabled" :x="point.x - trendChart.barWidth - 2" :y="point.previousBarY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.previousBarY" rx="2" fill="#164e63" opacity="0.78" />
                                    <rect :x="comparisonEnabled ? point.x + 2 : point.x - trendChart.barWidth / 2" :y="point.currentBarY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.currentBarY" rx="2" fill="#06b6d4" />
                                    <text v-if="point.showLabel" :x="point.x" :y="trendChart.height - 17" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text>
                                    <rect :x="point.x - trendChart.step / 2" :y="trendChart.top" :width="trendChart.step" :height="trendChart.plotHeight" fill="transparent" class="cursor-pointer" @mouseenter="hoveredTrend = point.index" />
                                </g>
                                <polyline :points="trendChart.currentLine" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                <polyline v-if="comparisonEnabled" :points="trendChart.previousLine" fill="none" stroke="#10b981" stroke-width="2" stroke-dasharray="7 6" stroke-linecap="round" stroke-linejoin="round" opacity="0.75" />
                                <circle v-for="point in trendChart.points" :key="`detail-roas-${point.date}`" :cx="point.x" :cy="point.currentRoasY" r="3.5" fill="#10b981" stroke="white" stroke-width="1.5" />
                                <line v-if="activeTrend" :x1="activeTrend.x" :x2="activeTrend.x" :y1="trendChart.top" :y2="trendChart.top + trendChart.plotHeight" stroke="#64748b" stroke-dasharray="5 5" />
                            </svg>
                            <div v-if="activeTrend" class="pointer-events-none absolute top-14 z-10 grid min-w-56 -translate-x-1/2 gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500 shadow-xl" :style="{ left: `${Math.min(82, Math.max(18, activeTrend.x / trendChart.width * 100))}%` }">
                                <strong class="text-sm text-slate-800">{{ activeTrend.date }}</strong>
                                <span class="flex justify-between gap-5">花费 <b class="text-cyan-600">{{ format(activeTrend.spend, 'money') }}</b></span>
                                <span class="flex justify-between gap-5">ROAS <b class="text-emerald-600">{{ activeTrend.roas.toFixed(2) }}×</b></span>
                                <template v-if="comparisonEnabled && activeTrend.previous">
                                    <span class="mt-1 border-t border-slate-100 pt-1.5 text-slate-400">上期 {{ activeTrend.previous.date }}</span>
                                    <span class="flex justify-between gap-5">上期花费 <b class="text-slate-700">{{ format(activeTrend.previous.spend, 'money') }}</b></span>
                                    <span class="flex justify-between gap-5">上期 ROAS <b class="text-slate-700">{{ activeTrend.previous.roas.toFixed(2) }}×</b></span>
                                </template>
                            </div>
                        </div>
                    </article>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">每日 CTR 趋势</h2>
                                <p class="mt-1 text-sm text-slate-500">每日点击数 ÷ 每日曝光量</p>
                            </div>
                            <div v-if="ctrChart.points.length" class="relative mt-5" @mouseleave="hoveredCtr = null">
                                <svg v-readable-chart class="h-auto min-h-[300px] w-full" :viewBox="`0 0 ${ctrChart.width} ${ctrChart.height}`" role="img" aria-label="Bing Ads 每日 CTR 趋势">
                                    <defs>
                                        <linearGradient id="bingCtrArea" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="#0ea5e9" stop-opacity="0.3" />
                                            <stop offset="100%" stop-color="#0ea5e9" stop-opacity="0.03" />
                                        </linearGradient>
                                    </defs>
                                    <g v-for="tick in ctrChart.ticks" :key="`ctr-${tick.y}`">
                                        <line :x1="ctrChart.left" :x2="ctrChart.width - ctrChart.right" :y1="tick.y" :y2="tick.y" stroke="#dbe5ef" stroke-dasharray="4 5" />
                                        <text x="4" :y="tick.y + 4" fill="#94a3b8" font-size="12">{{ tick.value.toFixed(1) }}%</text>
                                    </g>
                                    <polygon :points="ctrChart.area" fill="url(#bingCtrArea)" />
                                    <polyline :points="ctrChart.line" fill="none" stroke="#0ea5e9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <g v-for="point in ctrChart.points" :key="`ctr-point-${point.date}`">
                                        <circle :cx="point.x" :cy="point.y" r="3.5" fill="#0ea5e9" stroke="white" stroke-width="1.5" />
                                        <text v-if="point.showLabel" :x="point.x" :y="ctrChart.height - 16" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text>
                                        <rect :x="point.x - Math.max(10, ctrChart.step / 2)" :y="ctrChart.top" :width="Math.max(20, ctrChart.step)" :height="ctrChart.plotHeight" fill="transparent" class="cursor-pointer" @mouseenter="hoveredCtr = point.index" />
                                    </g>
                                    <line v-if="activeCtr" :x1="activeCtr.x" :x2="activeCtr.x" :y1="ctrChart.top" :y2="ctrChart.baseline" stroke="#64748b" stroke-dasharray="5 5" />
                                </svg>
                                <div v-if="activeCtr" class="pointer-events-none absolute right-4 top-10 grid min-w-40 gap-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-xl">
                                    <strong class="text-sm text-slate-800">{{ activeCtr.date }}</strong>
                                    <span class="flex justify-between gap-5 text-slate-500">CTR <b class="text-cyan-600">{{ formatPercent(activeCtr.ctr) }}</b></span>
                                    <span class="flex justify-between gap-5 text-slate-500">点击 <b class="text-slate-800">{{ format(activeCtr.clicks, 'number') }}</b></span>
                                    <span class="flex justify-between gap-5 text-slate-500">曝光 <b class="text-slate-800">{{ format(activeCtr.impressions, 'number') }}</b></span>
                                </div>
                            </div>
                        </article>

                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">广告系列花费占比</h2>
                                <p class="mt-1 text-sm text-slate-500">当前周期内各广告系列 Spend 占比</p>
                            </div>
                            <div v-if="campaignPie.length" class="mt-5 grid items-center gap-5 md:grid-cols-[300px_minmax(0,1fr)]">
                                <svg class="mx-auto h-72 w-72" viewBox="0 0 300 300" role="img" aria-label="Bing Ads 广告系列花费占比" @mouseleave="hoveredCampaign = null">
                                    <circle v-if="campaignPie.length === 1" cx="150" cy="150" r="108" :fill="campaignPie[0].color" @mouseenter="hoveredCampaign = 0" />
                                    <path v-for="slice in campaignPie" v-else :key="slice.id" :d="slice.path" :fill="slice.color" stroke="white" stroke-width="1.5" class="cursor-pointer" :opacity="activeCampaign && activeCampaign.index !== slice.index ? 0.72 : 1" @mouseenter="hoveredCampaign = slice.index" />
                                </svg>
                                <ul class="min-w-0 space-y-2 text-xs text-slate-500">
                                    <li v-for="slice in campaignPie" :key="`campaign-${slice.id}`" class="grid grid-cols-[10px_minmax(0,1fr)_auto] items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5">
                                        <i class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: slice.color }" />
                                        <span class="truncate" :title="slice.name">{{ slice.name }}</span>
                                        <strong class="text-slate-800">{{ slice.share.toFixed(2) }}%</strong>
                                    </li>
                                </ul>
                            </div>
                            <div v-else class="grid min-h-[300px] place-items-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 text-center text-sm leading-6 text-slate-500">
                                当前周期暂无广告系列级数据，请点击“同步 Microsoft Ads”补齐。
                            </div>
                            <div v-if="activeCampaign" class="pointer-events-none absolute bottom-8 left-8 z-10 grid min-w-56 gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-xl">
                                <strong class="max-w-64 truncate text-sm text-slate-800">{{ activeCampaign.name }}</strong>
                                <span class="flex justify-between gap-5 text-slate-500">花费 <b class="text-cyan-600">{{ format(activeCampaign.spend, 'money') }}</b></span>
                                <span class="flex justify-between gap-5 text-slate-500">占比 <b class="text-slate-800">{{ activeCampaign.share.toFixed(2) }}%</b></span>
                            </div>
                        </article>
                    </div>

                    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-100 p-6 sm:flex-row sm:items-end sm:justify-between sm:p-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">每日花费明细</h2>
                                <p class="mt-1 text-sm text-slate-500">当前账户与日期范围内的本地日指标</p>
                            </div>
                            <label class="block w-full sm:w-72">
                                <span class="mb-2 block text-xs font-medium text-slate-500">按日期筛选</span>
                                <input v-model="dailySearch" type="search" placeholder="例如 2026-08" class="h-11 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none ring-cyan-500 focus:ring-2" @input="dailyPage = 1" />
                            </label>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-[1480px] w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                                    <tr>
                                        <th v-for="column in dailyColumns" :key="column.key" class="whitespace-nowrap border-b border-slate-200 px-5 py-4">
                                            <button type="button" class="inline-flex items-center gap-1.5 transition hover:text-slate-900" @click="sortDaily(column.key)">
                                                {{ column.label }} <span class="text-slate-300">{{ dailySortIcon(column.key) }}</span>
                                            </button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <tr v-for="row in paginatedDailyRows" :key="row.date" class="transition hover:bg-cyan-50/40">
                                        <td class="whitespace-nowrap px-5 py-4 font-medium text-slate-900">{{ row.date }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold text-cyan-600">{{ format(row.spend, 'money') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold text-emerald-600">{{ format(row.revenue, 'money') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold text-emerald-600">{{ format(row.roas, 'ratio') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.impressions, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.clicks, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ formatPercent(row.ctr) }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.cpc, 'money') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.conversions, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.add_to_cart, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.checkout, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.cpa, 'money') }}</td>
                                    </tr>
                                    <tr v-if="!paginatedDailyRows.length">
                                        <td colspan="12" class="px-6 py-16 text-center text-sm text-slate-400">没有符合筛选条件的每日数据</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 text-xs text-slate-500 sm:px-8">
                            <span>共 {{ filteredDailyRows.length }} 天 · 第 {{ dailyPage }} / {{ dailyPageCount }} 页</span>
                            <div class="flex gap-2">
                                <button type="button" class="h-9 rounded-lg border border-slate-200 bg-white px-4 font-semibold text-slate-600 transition hover:border-cyan-300 hover:text-cyan-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="dailyPage <= 1" @click="dailyPage--">上一页</button>
                                <button type="button" class="h-9 rounded-lg border border-slate-200 bg-white px-4 font-semibold text-slate-600 transition hover:border-cyan-300 hover:text-cyan-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="dailyPage >= dailyPageCount" @click="dailyPage++">下一页</button>
                            </div>
                        </div>
                    </article>

                    <div v-if="loading" class="fixed inset-0 z-50 grid place-items-center bg-white/60 text-sm font-medium text-slate-600 backdrop-blur-[1px]">正在读取 Bing Ads 趋势数据…</div>
                </section>

                <section v-else-if="activeTab === 'campaigns' && overview" class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-cyan-100 bg-cyan-50/60 px-5 py-4 text-sm">
                        <span class="font-medium text-cyan-800">广告系列周期：{{ overview.filters.date_from }} — {{ overview.filters.date_to }}</span>
                        <span class="text-slate-500">仅展示 Microsoft Ads 状态为 Active / Enabled 的系列</span>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">广告系列花费占比</h2>
                                <p class="mt-1 text-sm text-slate-500">按花费排序前 6 名，分母为前 6 名花费合计</p>
                            </div>
                            <div v-if="campaignPie.length" class="mt-6 grid items-center gap-5 md:grid-cols-[300px_minmax(0,1fr)]">
                                <svg class="mx-auto h-72 w-72" viewBox="0 0 300 300" role="img" aria-label="Bing Ads 广告系列页花费占比" @mouseleave="hoveredCampaign = null">
                                    <circle v-if="campaignPie.length === 1" cx="150" cy="150" r="108" :fill="campaignPie[0].color" @mouseenter="hoveredCampaign = 0" />
                                    <path v-for="slice in campaignPie" v-else :key="`campaign-tab-${slice.id}`" :d="slice.path" :fill="slice.color" stroke="white" stroke-width="1.5" class="cursor-pointer transition-opacity" :opacity="activeCampaign && activeCampaign.index !== slice.index ? 0.68 : 1" @mouseenter="hoveredCampaign = slice.index" />
                                </svg>
                                <ul class="min-w-0 space-y-2 text-xs text-slate-500">
                                    <li v-for="slice in campaignPie" :key="`campaign-tab-legend-${slice.id}`" class="grid grid-cols-[10px_minmax(0,1fr)_auto] items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5">
                                        <i class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: slice.color }" />
                                        <span class="truncate" :title="slice.name">{{ slice.name }}</span>
                                        <strong class="text-slate-800">{{ slice.share.toFixed(2) }}%</strong>
                                    </li>
                                </ul>
                            </div>
                            <div v-else class="mt-6 grid min-h-[300px] place-items-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 text-center text-sm text-slate-500">当前周期暂无活动广告系列数据</div>
                            <div v-if="activeCampaign" class="pointer-events-none absolute bottom-8 left-8 z-10 grid min-w-56 gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-xl">
                                <strong class="max-w-64 truncate text-sm text-slate-800">{{ activeCampaign.name }}</strong>
                                <span class="flex justify-between gap-5 text-slate-500">花费 <b class="text-cyan-600">{{ format(activeCampaign.spend, 'money') }}</b></span>
                                <span class="flex justify-between gap-5 text-slate-500">占比 <b class="text-slate-800">{{ activeCampaign.share.toFixed(2) }}%</b></span>
                            </div>
                        </article>

                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">广告系列 ROAS 对比</h2>
                                <p class="mt-1 text-sm text-slate-500">花费前 6 名系列的 Revenue ÷ Spend</p>
                            </div>
                            <div v-if="campaignRoasChart.rows.length" class="relative mt-6 overflow-x-auto" @mouseleave="hoveredCampaignRoas = null">
                                <svg v-readable-chart class="h-auto w-full" :viewBox="`0 0 ${campaignRoasChart.width} ${campaignRoasChart.height}`" role="img" aria-label="Bing Ads 广告系列 ROAS 对比">
                                    <g v-for="tick in campaignRoasChart.ticks" :key="`roas-tick-${tick.x}`">
                                        <line :x1="tick.x" :x2="tick.x" :y1="campaignRoasChart.top" :y2="campaignRoasChart.height - campaignRoasChart.bottom" stroke="#e2e8f0" stroke-dasharray="4 5" />
                                        <text :x="tick.x" :y="campaignRoasChart.height - 7" text-anchor="middle" fill="#94a3b8" font-size="12">{{ tick.value.toFixed(1) }}×</text>
                                    </g>
                                    <g v-for="row in campaignRoasChart.rows" :key="`roas-row-${row.id}`" @mouseenter="hoveredCampaignRoas = row.index">
                                        <text :x="campaignRoasChart.left - 14" :y="row.y + 18" text-anchor="end" fill="#64748b" font-size="12"><title>{{ row.name }}</title>{{ row.shortName }}</text>
                                        <rect :x="campaignRoasChart.left" :y="row.y" :width="Math.max(row.roas > 0 ? 2 : 0, row.width)" :height="row.height" rx="5" :fill="row.roas >= 6 ? '#10b981' : row.roas >= 3 ? '#34d399' : '#f59e0b'" :opacity="activeCampaignRoas && activeCampaignRoas.index !== row.index ? 0.6 : 0.92" />
                                        <text :x="Math.min(campaignRoasChart.width - 34, campaignRoasChart.left + row.width + 10)" :y="row.y + 18" fill="#475569" font-size="12" font-weight="600">{{ row.roas.toFixed(2) }}×</text>
                                        <rect :x="0" :y="row.y - 7" :width="campaignRoasChart.width" :height="row.height + 14" fill="transparent" class="cursor-pointer" />
                                    </g>
                                </svg>
                                <div v-if="activeCampaignRoas" class="pointer-events-none absolute right-4 top-3 z-10 grid min-w-52 gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-xl">
                                    <strong class="max-w-64 truncate text-sm text-slate-800">{{ activeCampaignRoas.name }}</strong>
                                    <span class="flex justify-between gap-5 text-slate-500">ROAS <b class="text-emerald-600">{{ activeCampaignRoas.roas.toFixed(2) }}×</b></span>
                                    <span class="flex justify-between gap-5 text-slate-500">收入 <b class="text-slate-800">{{ format(activeCampaignRoas.revenue, 'money') }}</b></span>
                                </div>
                            </div>
                            <div v-else class="mt-6 grid min-h-[300px] place-items-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 text-center text-sm text-slate-500">当前周期暂无活动广告系列数据</div>
                        </article>
                    </div>

                    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-100 p-6 sm:flex-row sm:items-end sm:justify-between sm:p-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">广告系列明细</h2>
                                <p class="mt-1 text-sm text-slate-500">全部活动系列，默认按花费从高到低排列</p>
                            </div>
                            <label class="block w-full sm:w-80">
                                <span class="mb-2 block text-xs font-medium text-slate-500">搜索广告系列</span>
                                <input v-model="campaignSearch" type="search" placeholder="输入广告系列名称" class="h-11 w-full rounded-xl border border-slate-200 px-4 text-sm outline-none ring-cyan-500 focus:ring-2" @input="campaignPage = 1" />
                            </label>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-[1120px] w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                                    <tr>
                                        <th class="border-b border-slate-200 px-5 py-4">广告系列</th>
                                        <th class="border-b border-slate-200 px-5 py-4">花费</th>
                                        <th class="border-b border-slate-200 px-5 py-4">ROAS</th>
                                        <th class="border-b border-slate-200 px-5 py-4">曝光</th>
                                        <th class="border-b border-slate-200 px-5 py-4">点击</th>
                                        <th class="border-b border-slate-200 px-5 py-4">CTR</th>
                                        <th class="border-b border-slate-200 px-5 py-4">CPC</th>
                                        <th class="border-b border-slate-200 px-5 py-4">转化</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <tr v-for="row in paginatedCampaignRows" :key="row.id" class="transition hover:bg-cyan-50/40">
                                        <td class="min-w-64 px-5 py-4">
                                            <div class="max-w-80 truncate font-semibold text-slate-900" :title="row.name">{{ row.name }}</div>
                                            <div class="mt-1 flex items-center gap-2 text-xs text-slate-400"><span>{{ row.id }}</span><span class="rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">{{ row.status }}</span></div>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold text-cyan-600">{{ format(row.spend, 'money') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold text-emerald-600">{{ format(row.roas, 'ratio') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.impressions, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.clicks, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ formatPercent(row.ctr) }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ format(row.cpc, 'money') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">{{ row.conversions.toFixed(1) }}</td>
                                    </tr>
                                    <tr v-if="!paginatedCampaignRows.length">
                                        <td colspan="8" class="px-6 py-16 text-center text-sm text-slate-400">没有符合筛选条件的活动广告系列</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 text-xs text-slate-500 sm:px-8">
                            <span>共 {{ filteredCampaignRows.length }} 个活动系列 · 第 {{ campaignPage }} / {{ campaignPageCount }} 页</span>
                            <div class="flex gap-2">
                                <button type="button" class="h-9 rounded-lg border border-slate-200 bg-white px-4 font-semibold text-slate-600 transition hover:border-cyan-300 hover:text-cyan-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="campaignPage <= 1" @click="campaignPage--">上一页</button>
                                <button type="button" class="h-9 rounded-lg border border-slate-200 bg-white px-4 font-semibold text-slate-600 transition hover:border-cyan-300 hover:text-cyan-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="campaignPage >= campaignPageCount" @click="campaignPage++">下一页</button>
                            </div>
                        </div>
                    </article>

                    <div v-if="loading" class="fixed inset-0 z-50 grid place-items-center bg-white/60 text-sm font-medium text-slate-600 backdrop-blur-[1px]">正在读取 Bing Ads 广告系列数据…</div>
                </section>

                <section v-else class="grid min-h-[420px] place-items-center rounded-3xl border border-slate-200 bg-white px-6 text-center shadow-sm">
                    <div>
                        <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-cyan-50 text-xl text-cyan-700">{{ tabs.find(tab => tab.key === activeTab)?.icon }}</span>
                        <h2 class="mt-5 text-xl font-semibold text-slate-950">{{ tabs.find(tab => tab.key === activeTab)?.label }}</h2>
                        <p class="mt-2 text-sm text-slate-500">总览数据结构完成后将在此继续接入对应明细。</p>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
