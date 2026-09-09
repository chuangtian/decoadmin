<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../composables/useToast';

type ChannelState = 'not_configured' | 'pending' | 'syncing' | 'backfilling' | 'completed' | 'failed' | 'partial_failed';
type ChannelStatus = {
    channel: string; label: string; configured: boolean; settings_url: string; state: ChannelState;
    data_ready: boolean; progress_percent: number; last_metric_date: string | null; message: string | null;
};
type Summary = {
    spend: number; revenue: number; roas: number; cpa: number; add_to_cart: number; checkout: number;
    add_to_cart_cost: number; checkout_cost: number; impressions: number; clicks: number;
    conversions: number; ctr: number; cpc: number;
};
type TrendPoint = {
    date: string;
    spend: number;
    revenue: number;
    roi: number;
    impressions: number;
    clicks: number;
    conversions: number;
};
type Campaign = { id: string; name: string; spend: number; share: number };
type CampaignDetail = Campaign & {
    status: string;
    objective_type: string;
    revenue: number;
    roas: number;
    impressions: number;
    clicks: number;
    ctr: number;
    conversions: number;
    cpc: number;
};
type Creative = {
    id: string;
    name: string;
    body: string;
    text_variants: string[];
    format: string;
    video_id: string | null;
    campaign_id: string | null;
    campaign_name: string | null;
    spend: number;
    revenue: number;
    roas: number;
    impressions: number;
    clicks: number;
    ctr: number;
    purchases: number;
    video_plays: number;
    video_2s_rate: number;
    average_play_time: number;
};
type Overview = {
    accounts: Array<{ id: string; name: string; currency: string; timezone: string | null }>;
    filters: { account: string | null; date_from: string; date_to: string };
    comparison_period: { date_from: string; date_to: string };
    currency: string; current: Summary; previous: Summary; trend: TrendPoint[]; previous_trend: TrendPoint[];
    campaigns: Campaign[]; campaign_details: CampaignDetail[]; creatives: Creative[];
    deltas: Record<keyof Summary, number | null>;
};

const props = defineProps<{
    store: { id: number; name: string }; channelStatus: ChannelStatus; tiktokOverview: Overview | null; canSync: boolean;
}>();
const status = ref(props.channelStatus);
const overview = ref(props.tiktokOverview);
const activeTab = ref('overview');
const loading = ref(false);
const syncing = ref(false);
const hoveredTrend = ref<number | null>(null);
const hoveredCampaign = ref<number | null>(null);
const hoveredCampaignTab = ref<number | null>(null);
const selectedCreative = ref<Creative | null>(null);
const filters = ref({
    account: props.tiktokOverview?.filters.account ?? '',
    date_from: props.tiktokOverview?.filters.date_from ?? '',
    date_to: props.tiktokOverview?.filters.date_to ?? '',
});
const toast = useToast();
let poller: ReturnType<typeof setInterval> | null = null;

const tabs = [
    { key: 'overview', label: '总览', icon: '▥' },
    { key: 'trend', label: '趋势分析', icon: '↗' },
    { key: 'campaigns', label: '广告系列', icon: '▤' },
    { key: 'creatives', label: '优质素材', icon: '▶' },
];
const cards: Array<{ key: keyof Summary; label: string; format: 'money' | 'number' | 'ratio'; color: string }> = [
    { key: 'spend', label: '广告花费', format: 'money', color: '#3b82f6' },
    { key: 'revenue', label: '广告收入', format: 'money', color: '#22c55e' },
    { key: 'roas', label: 'ROAS', format: 'ratio', color: '#10b981' },
    { key: 'conversions', label: '购买数', format: 'number', color: '#16a34a' },
    { key: 'impressions', label: '曝光量', format: 'number', color: '#2563eb' },
    { key: 'clicks', label: '点击数', format: 'number', color: '#f59e0b' },
    { key: 'cpc', label: '平均 CPC', format: 'money', color: '#f97316' },
    { key: 'cpa', label: 'CPA', format: 'money', color: '#ea580c' },
    { key: 'add_to_cart', label: '加购数', format: 'number', color: '#8b5cf6' },
    { key: 'checkout', label: '结账数', format: 'number', color: '#14b8a6' },
    { key: 'add_to_cart_cost', label: '单次加购成本', format: 'money', color: '#a855f7' },
    { key: 'checkout_cost', label: '单次结账成本', format: 'money', color: '#eab308' },
];
const busy = computed(() => syncing.value || ['pending', 'syncing', 'backfilling'].includes(status.value.state));
const selectedAccount = computed(() => overview.value?.accounts.find((item) => item.id === filters.value.account));

const trendChart = computed(() => {
    const width = 760, height = 330, left = 58, right = 56, top = 42, bottom = 48;
    const plotWidth = width - left - right, plotHeight = height - top - bottom;
    const current = overview.value?.trend ?? [], previous = overview.value?.previous_trend ?? [];
    const amountMax = Math.max(1, ...current.map(p => p.spend), ...previous.map(p => p.spend)) * 1.12;
    const roiMax = Math.max(1, ...current.map(p => p.roi), ...previous.map(p => p.roi)) * 1.12;
    const step = current.length ? plotWidth / current.length : plotWidth;
    const points = current.map((point, index) => {
        const x = left + step * (index + .5);
        const prior = previous[index];
        return { ...point, index, prior, x, currentBarY: top + plotHeight - point.spend / amountMax * plotHeight,
            previousBarY: top + plotHeight - (prior?.spend ?? 0) / amountMax * plotHeight,
            currentRoiY: top + plotHeight - point.roi / roiMax * plotHeight,
            previousRoiY: top + plotHeight - (prior?.roi ?? 0) / roiMax * plotHeight };
    });
    return { width, height, left, right, top, bottom, plotWidth, plotHeight, step, amountMax, roiMax, points,
        currentLine: points.map(p => `${p.x},${p.currentRoiY}`).join(' '),
        previousLine: points.filter(p => p.prior).map(p => `${p.x},${p.previousRoiY}`).join(' '),
        ticks: [0,1,2,3,4].map(i => ({ y: top + plotHeight - i / 4 * plotHeight, amount: amountMax * i / 4, roi: roiMax * i / 4 })) };
});
const activeTrend = computed(() => hoveredTrend.value === null ? null : trendChart.value.points[hoveredTrend.value]);
const dailyRows = computed(() => (overview.value?.trend ?? []).map((point) => ({
    ...point,
    ctr: point.impressions > 0 ? (point.clicks / point.impressions) * 100 : 0,
})));

function polar(center: number, radius: number, angle: number) {
    return { x: center + radius * Math.cos(angle), y: center + radius * Math.sin(angle) };
}
const campaignPie = computed(() => {
    const data = overview.value?.campaigns ?? [], center = 145, radius = 112;
    const colors = ['#3b82f6','#ef4444','#f59e0b','#22c55e','#8b5cf6','#f97316','#ec4899'];
    let cursor = -Math.PI / 2;
    return data.map((item, index) => {
        const angle = item.share / 100 * Math.PI * 2, start = cursor, end = cursor + angle;
        const a = polar(center, radius, start), b = polar(center, radius, end); cursor = end;
        return { ...item, index, color: colors[index % colors.length], path: data.length === 1 ? '' :
            `M ${center} ${center} L ${a.x} ${a.y} A ${radius} ${radius} 0 ${angle > Math.PI ? 1 : 0} 1 ${b.x} ${b.y} Z` };
    });
});
const activeCampaign = computed(() => hoveredCampaign.value === null ? null : campaignPie.value[hoveredCampaign.value]);
const campaignRows = computed(() => overview.value?.campaign_details ?? []);
const campaignChartRows = computed(() => campaignRows.value.slice(0, 6));
const campaignTabPie = computed(() => {
    const data = campaignChartRows.value, center = 145, radius = 112;
    const colors = ['#3b82f6','#ef4444','#f59e0b','#22c55e','#8b5cf6','#f97316'];
    const total = Math.max(0, data.reduce((sum, item) => sum + item.spend, 0));
    let cursor = -Math.PI / 2;
    return data.map((item, index) => {
        const share = total > 0 ? item.spend / total * 100 : 0;
        const angle = share / 100 * Math.PI * 2, start = cursor, end = cursor + angle;
        const a = polar(center, radius, start), b = polar(center, radius, end); cursor = end;
        return { ...item, index, share, color: colors[index], path: data.length === 1 ? '' :
            `M ${center} ${center} L ${a.x} ${a.y} A ${radius} ${radius} 0 ${angle > Math.PI ? 1 : 0} 1 ${b.x} ${b.y} Z` };
    });
});
const activeCampaignTab = computed(() => hoveredCampaignTab.value === null ? null : campaignTabPie.value[hoveredCampaignTab.value]);
const campaignRoasChart = computed(() => {
    const rows = campaignChartRows.value;
    const width = 760, left = 250, right = 70, top = 26, rowHeight = 47;
    const height = Math.max(260, top * 2 + rows.length * rowHeight);
    const max = Math.max(1, ...rows.map((item) => item.roas)) * 1.08;
    const plotWidth = width - left - right;
    return { width, height, left, right, top, rowHeight, max, rows: rows.map((item, index) => ({
        ...item,
        y: top + index * rowHeight,
        barWidth: item.roas / max * plotWidth,
    })), ticks: [0, 1, 2, 3, 4].map((index) => ({ x: left + index / 4 * plotWidth, value: max * index / 4 })) };
});

function format(value: number, kind: 'money' | 'number' | 'ratio') {
    if (kind === 'money') return new Intl.NumberFormat('en-US', { style: 'currency', currency: overview.value?.currency || 'USD', maximumFractionDigits: 2 }).format(value || 0);
    if (kind === 'ratio') return `${Number(value || 0).toFixed(2)}×`;
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value || 0);
}
function accountLabel(account: { id: string; name: string }) {
    return account.name.includes(account.id) ? account.name : `${account.name} (${account.id})`;
}
function compact(value: number) {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value || 0);
}
function deltaLabel(value: number | null | undefined) {
    return value === null || value === undefined ? '上期为 0' : `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`;
}
function params() {
    const value = new URLSearchParams();
    if (filters.value.account) value.set('account', filters.value.account);
    value.set('date_from', filters.value.date_from); value.set('date_to', filters.value.date_to);
    return value;
}
async function loadData() {
    if (!filters.value.date_from || !filters.value.date_to) return;
    loading.value = true;
    try {
        const response = await fetch(`/paid-advertising/tiktok/data?${params()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('TikTok Ads 数据读取失败。');
        overview.value = (await response.json()).data;
        filters.value.account = overview.value?.filters.account ?? '';
    } catch (error) { toast.error(error instanceof Error ? error.message : '数据读取失败。'); }
    finally { loading.value = false; }
}
async function refreshStatus() {
    const response = await fetch('/paid-advertising/tiktok/status', { headers: { Accept: 'application/json' } });
    if (response.ok) {
        status.value = (await response.json()).data;
        if (!['pending','syncing','backfilling'].includes(status.value.state) && syncing.value) {
            syncing.value = false; await loadData(); toast.success('TikTok Ads 数据同步完成。');
        }
    }
}
async function syncData() {
    if (!props.canSync || busy.value) return;
    syncing.value = true;
    try {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/paid-advertising/tiktok/sync', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || '同步任务提交失败。');
        status.value = payload.status; toast.success(payload.message);
    } catch (error) { syncing.value = false; toast.error(error instanceof Error ? error.message : '同步任务提交失败。'); }
}
onMounted(() => { poller = setInterval(refreshStatus, 3000); });
onBeforeUnmount(() => { if (poller) clearInterval(poller); });
</script>

<template>
    <Head title="TikTok Ads" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '付费广告' }, { label: 'TikTok Ads' }]">
        <div class="mx-auto max-w-[1500px] space-y-6">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-6 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">当前店铺 · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">TikTok Ads</h1>
                    <p class="mt-2 text-sm text-slate-500">广告账户与日指标按店铺独立同步，页面只读取本地 MySQL 数据库。</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold" :class="status.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                    <i class="h-2 w-2 rounded-full" :class="status.configured ? 'bg-emerald-500' : 'bg-amber-500'" />
                    {{ status.configured ? '凭证已配置' : '尚未配置' }}
                </span>
            </header>

            <section v-if="!status.configured" class="rounded-3xl border border-amber-300 bg-white px-7 py-12 shadow-sm sm:px-10 lg:py-16">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-600">需要完成配置</p>
                        <h2 class="mt-3 text-2xl font-semibold text-slate-950">TikTok Ads 尚未配置</h2>
                        <p class="mt-4 max-w-4xl text-sm leading-7 text-slate-500">配置 TikTok Access Token 与广告主账号后，系统会按店铺同步数据到本地数据库。</p>
                    </div>
                    <Link :href="status.settings_url" class="inline-flex h-14 shrink-0 items-center justify-center rounded-2xl bg-slate-950 px-8 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">前往配置 TikTok Token</Link>
                </div>
            </section>

            <template v-else>
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-4 xl:grid-cols-[minmax(250px,1.2fr)_minmax(390px,1fr)_auto] xl:items-end">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-500">广告账户</span>
                            <select v-model="filters.account" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 outline-none ring-blue-500 focus:ring-2" @change="loadData">
                                <option v-for="account in overview?.accounts" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
                            </select>
                        </label>
                        <div>
                            <span class="mb-2 block text-sm font-medium text-slate-500">统计日期</span>
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3">
                                <input v-model="filters.date_from" type="date" class="h-12 min-w-0 border-0 bg-transparent text-sm font-medium text-slate-800 outline-none" @change="loadData" />
                                <span class="text-slate-300">—</span>
                                <input v-model="filters.date_to" type="date" class="h-12 min-w-0 border-0 bg-transparent text-sm font-medium text-slate-800 outline-none" @change="loadData" />
                            </div>
                        </div>
                        <button type="button" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-slate-950 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!canSync || busy" @click="syncData">
                            <span class="text-lg" :class="busy ? 'animate-spin' : ''">↻</span>
                            {{ busy ? '同步中' : '同步' }}
                        </button>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500">
                        <span>当前账户：{{ selectedAccount?.name || '—' }} · 币种 {{ overview?.currency || 'USD' }}</span>
                        <span>对比上一周期：{{ overview?.comparison_period.date_from }} — {{ overview?.comparison_period.date_to }}</span>
                    </div>
                </section>

                <nav class="flex gap-2 overflow-x-auto border-b border-slate-200 pb-3">
                    <button v-for="tab in tabs" :key="tab.key" type="button" class="inline-flex h-12 shrink-0 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition" :class="activeTab === tab.key ? 'border-blue-200 bg-blue-50 text-blue-700 shadow-sm' : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'" @click="activeTab = tab.key">
                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-lg bg-slate-100 px-1 text-xs" :class="activeTab === tab.key ? 'bg-blue-600 text-white' : ''">{{ tab.icon }}</span>
                        {{ tab.label }}
                    </button>
                </nav>

                <section v-if="activeTab === 'overview' && overview" class="relative rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-slate-950">数据总览</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ overview.filters.date_from }} — {{ overview.filters.date_to }}，与紧邻的上一等长周期对比</p>
                        </div>
                        <span class="rounded-full bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-500">数据截止 {{ status.last_metric_date || overview.filters.date_to }}</span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="card in cards" :key="card.key" class="rounded-2xl border border-t-4 bg-white px-5 py-5 shadow-sm" :style="{ borderColor: card.color }">
                            <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                            <p class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ format(overview.current[card.key], card.format) }}</p>
                            <div class="mt-4 flex items-center justify-between gap-2 text-xs">
                                <span class="font-semibold" :class="(overview.deltas[card.key] ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-500'">{{ deltaLabel(overview.deltas[card.key]) }}</span>
                                <span class="text-slate-400">上期 {{ format(overview.previous[card.key], card.format) }}</span>
                            </div>
                        </article>
                    </div>

                    <div class="mt-6 grid gap-5 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/40 p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">花费 &amp; ROAS 趋势</h3>
                                    <p class="mt-1 text-xs text-slate-400">当前周期与上一等长周期</p>
                                </div>
                                <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-blue-500" />花费</span>
                                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-blue-800" />上期花费</span>
                                    <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 bg-emerald-500" />ROAS</span>
                                    <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 border-t border-dashed border-emerald-500" />上期 ROAS</span>
                                </div>
                            </div>
                            <div v-if="trendChart.points.length" class="relative mt-4 w-full" @mouseleave="hoveredTrend = null">
                                <svg v-readable-chart class="h-auto w-full" :viewBox="`0 0 ${trendChart.width} ${trendChart.height}`" role="img" aria-label="TikTok Ads 每日花费与 ROAS 趋势">
                                    <g v-for="tick in trendChart.ticks" :key="tick.y"><line :x1="trendChart.left" :x2="trendChart.width-trendChart.right" :y1="tick.y" :y2="tick.y" class="grid-line"/><text x="4" :y="tick.y+4">{{ compact(tick.amount) }}</text><text :x="trendChart.width-48" :y="tick.y+4">{{ tick.roi.toFixed(0) }}×</text></g>
                                    <g v-for="point in trendChart.points" :key="point.date"><rect :x="point.x-16" :y="point.previousBarY" width="14" :height="trendChart.top+trendChart.plotHeight-point.previousBarY" class="bar prior"/><rect :x="point.x+2" :y="point.currentBarY" width="14" :height="trendChart.top+trendChart.plotHeight-point.currentBarY" class="bar current"/><text :x="point.x" :y="trendChart.height-17" text-anchor="middle">{{ point.date.slice(5) }}</text><rect :x="point.x-trendChart.step/2" :y="trendChart.top" :width="trendChart.step" :height="trendChart.plotHeight" fill="transparent" @mouseenter="hoveredTrend=point.index"/></g>
                                    <polyline :points="trendChart.currentLine" class="roi current-roi"/><polyline :points="trendChart.previousLine" class="roi prior-roi"/>
                                    <line v-if="activeTrend" :x1="activeTrend.x" :x2="activeTrend.x" :y1="trendChart.top" :y2="trendChart.top+trendChart.plotHeight" class="hover-line"/>
                                </svg>
                                <div v-if="activeTrend" class="tooltip trend-tip"><b>{{ activeTrend.date }}</b><span>花费 <strong>{{ format(activeTrend.spend,'money') }}</strong></span><span>ROAS <strong>{{ activeTrend.roi.toFixed(2) }}×</strong></span><span v-if="activeTrend.prior">上期花费 <strong>{{ format(activeTrend.prior.spend,'money') }}</strong></span><span v-if="activeTrend.prior">上期 ROAS <strong>{{ activeTrend.prior.roi.toFixed(2) }}×</strong></span></div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无趋势数据</div>
                        </article>

                        <article class="relative min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/40 p-5">
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">广告系列花费占比</h3>
                                <p class="mt-1 text-xs text-slate-400">按当前周期广告系列汇总</p>
                            </div>
                            <div v-if="campaignPie.length" class="mt-5 grid items-center gap-5 md:grid-cols-[240px_minmax(0,1fr)]">
                                <svg class="mx-auto h-56 w-56" viewBox="0 0 290 290" @mouseleave="hoveredCampaign=null"><circle v-if="campaignPie.length===1" cx="145" cy="145" r="112" :fill="campaignPie[0].color" @mouseenter="hoveredCampaign=0"/><path v-for="slice in campaignPie" v-else :key="slice.id" :d="slice.path" :fill="slice.color" @mouseenter="hoveredCampaign=slice.index"/></svg>
                                <ul class="min-w-0 space-y-2 text-xs text-slate-500"><li v-for="slice in campaignPie" :key="slice.id" class="grid grid-cols-[10px_minmax(0,1fr)_auto] items-center gap-2"><i class="h-2.5 w-2.5 rounded-sm" :style="{background:slice.color}"/><span class="truncate">{{ slice.name }}</span><b class="text-slate-700">{{ slice.share.toFixed(1) }}%</b></li></ul>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">当前周期暂无广告系列数据，请点击同步补齐。</div>
                            <div v-if="activeCampaign" class="tooltip campaign-tip"><b>{{ activeCampaign.name }}</b><span>花费 <strong>{{ format(activeCampaign.spend,'money') }}</strong></span><span>占比 <strong>{{ activeCampaign.share.toFixed(2) }}%</strong></span></div>
                        </article>
                    </div>
                    <div v-if="loading" class="absolute inset-0 grid place-items-center rounded-3xl bg-white/70 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取 MySQL 数据…</div>
                </section>

                <section v-else-if="activeTab === 'trend' && overview" class="space-y-6">
                    <article class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">趋势分析</p>
                                <h2 class="mt-2 text-xl font-semibold text-slate-950">每日花费 &amp; ROAS</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ overview.filters.date_from }} — {{ overview.filters.date_to }}，与上一等长周期按日期位置对齐</p>
                            </div>
                            <div class="flex flex-wrap gap-4 text-xs font-medium text-slate-500">
                                <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-6 rounded bg-blue-500" />花费</span>
                                <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-6 rounded bg-blue-800" />上期花费</span>
                                <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-6 bg-emerald-500" />ROAS</span>
                                <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-6 border-t-2 border-dashed border-emerald-500" />上期 ROAS</span>
                            </div>
                        </div>

                        <div v-if="trendChart.points.length" class="relative mt-6 w-full" @mouseleave="hoveredTrend = null">
                            <svg v-readable-chart class="h-auto min-h-[360px] w-full" :viewBox="`0 0 ${trendChart.width} ${trendChart.height}`" role="img" aria-label="TikTok Ads 每日花费与 ROAS 趋势分析">
                                <g v-for="tick in trendChart.ticks" :key="tick.y">
                                    <line :x1="trendChart.left" :x2="trendChart.width-trendChart.right" :y1="tick.y" :y2="tick.y" class="grid-line" />
                                    <text x="4" :y="tick.y+4">{{ compact(tick.amount) }}</text>
                                    <text :x="trendChart.width-48" :y="tick.y+4">{{ tick.roi.toFixed(0) }}×</text>
                                </g>
                                <g v-for="point in trendChart.points" :key="point.date">
                                    <rect :x="point.x-16" :y="point.previousBarY" width="14" :height="trendChart.top+trendChart.plotHeight-point.previousBarY" class="bar prior" />
                                    <rect :x="point.x+2" :y="point.currentBarY" width="14" :height="trendChart.top+trendChart.plotHeight-point.currentBarY" class="bar current" />
                                    <text :x="point.x" :y="trendChart.height-17" text-anchor="middle">{{ point.date.slice(5) }}</text>
                                    <rect :x="point.x-trendChart.step/2" :y="trendChart.top" :width="trendChart.step" :height="trendChart.plotHeight" fill="transparent" @mouseenter="hoveredTrend=point.index" />
                                </g>
                                <polyline :points="trendChart.currentLine" class="roi current-roi" />
                                <polyline :points="trendChart.previousLine" class="roi prior-roi" />
                                <line v-if="activeTrend" :x1="activeTrend.x" :x2="activeTrend.x" :y1="trendChart.top" :y2="trendChart.top+trendChart.plotHeight" class="hover-line" />
                            </svg>
                            <div v-if="activeTrend" class="tooltip trend-detail-tip">
                                <b>{{ activeTrend.date }}</b>
                                <span>花费 <strong>{{ format(activeTrend.spend, 'money') }}</strong></span>
                                <span>ROAS <strong>{{ activeTrend.roi.toFixed(2) }}×</strong></span>
                                <span v-if="activeTrend.prior">上期花费 <strong>{{ format(activeTrend.prior.spend, 'money') }}</strong></span>
                                <span v-if="activeTrend.prior">上期 ROAS <strong>{{ activeTrend.prior.roi.toFixed(2) }}×</strong></span>
                            </div>
                        </div>
                        <div v-else class="grid min-h-80 place-items-center text-sm text-slate-400">所选时间段暂无趋势数据</div>
                        <div v-if="loading" class="absolute inset-0 grid place-items-center rounded-3xl bg-white/70 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取 MySQL 数据…</div>
                    </article>

                    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-6 py-5 sm:px-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">每日明细</h2>
                                <p class="mt-1 text-sm text-slate-500">当前周期的账户级日粒度洞察数据</p>
                            </div>
                            <span class="text-sm text-slate-400">共 {{ dailyRows.length }} 条</span>
                        </div>
                        <div v-if="dailyRows.length" class="overflow-x-auto">
                            <table class="min-w-[920px] w-full text-left">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-6 py-4">日期</th>
                                        <th class="px-6 py-4 text-right">花费</th>
                                        <th class="px-6 py-4 text-right">ROAS</th>
                                        <th class="px-6 py-4 text-right">曝光</th>
                                        <th class="px-6 py-4 text-right">点击</th>
                                        <th class="px-6 py-4 text-right">CTR</th>
                                        <th class="px-6 py-4 text-right">购买</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                                    <tr v-for="row in dailyRows" :key="row.date" class="transition hover:bg-slate-50/80">
                                        <td class="whitespace-nowrap px-6 py-4 font-medium text-slate-900">{{ row.date }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums">{{ format(row.spend, 'money') }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right font-semibold tabular-nums" :class="row.roi >= 3.5 ? 'text-emerald-600' : 'text-rose-500'">{{ row.roi.toFixed(2) }}×</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums">{{ format(row.impressions, 'number') }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums">{{ format(row.clicks, 'number') }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums">{{ row.ctr.toFixed(2) }}%</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums">{{ format(row.conversions, 'number') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="grid min-h-60 place-items-center text-sm text-slate-400">所选时间段暂无每日明细</div>
                    </article>
                </section>

                <section v-else-if="activeTab === 'campaigns' && overview" class="space-y-6">
                    <div class="grid gap-6 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">广告系列</p>
                                <h2 class="mt-2 text-xl font-semibold text-slate-950">广告系列花费占比</h2>
                                <p class="mt-1 text-sm text-slate-500">当前时间段花费最高的 6 个广告系列</p>
                            </div>
                            <div v-if="campaignTabPie.length" class="mt-6 grid items-center gap-6 md:grid-cols-[280px_minmax(0,1fr)]">
                                <svg class="mx-auto h-64 w-64" viewBox="0 0 290 290" @mouseleave="hoveredCampaignTab=null">
                                    <circle v-if="campaignTabPie.length===1" cx="145" cy="145" r="112" :fill="campaignTabPie[0].color" @mouseenter="hoveredCampaignTab=0" />
                                    <path v-for="slice in campaignTabPie" v-else :key="slice.id" :d="slice.path" :fill="slice.color" class="cursor-pointer transition-opacity hover:opacity-80" @mouseenter="hoveredCampaignTab=slice.index" />
                                </svg>
                                <ul class="min-w-0 space-y-3 text-sm text-slate-500">
                                    <li v-for="slice in campaignTabPie" :key="slice.id" class="grid grid-cols-[12px_minmax(0,1fr)_auto] items-center gap-2">
                                        <i class="h-3 w-3 rounded-sm" :style="{background:slice.color}" />
                                        <span class="truncate" :title="slice.name">{{ slice.name }}</span>
                                        <b class="tabular-nums text-slate-700">{{ slice.share.toFixed(1) }}%</b>
                                    </li>
                                </ul>
                            </div>
                            <div v-else class="grid min-h-80 place-items-center text-sm text-slate-400">所选时间段暂无广告系列数据</div>
                            <div v-if="activeCampaignTab" class="tooltip campaign-tab-tip">
                                <b>{{ activeCampaignTab.name }}</b>
                                <span>花费 <strong>{{ format(activeCampaignTab.spend, 'money') }}</strong></span>
                                <span>占比 <strong>{{ activeCampaignTab.share.toFixed(2) }}%</strong></span>
                            </div>
                        </article>

                        <article class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-600">投放效率</p>
                                <h2 class="mt-2 text-xl font-semibold text-slate-950">广告系列 ROAS 对比</h2>
                                <p class="mt-1 text-sm text-slate-500">按花费最高的 6 个广告系列比较</p>
                            </div>
                            <div v-if="campaignRoasChart.rows.length" class="mt-7 overflow-x-auto">
                                <svg v-readable-chart class="min-w-[620px] w-full" :viewBox="`0 0 ${campaignRoasChart.width} ${campaignRoasChart.height}`" role="img" aria-label="TikTok 广告系列 ROAS 对比">
                                    <g v-for="tick in campaignRoasChart.ticks" :key="tick.x">
                                        <line :x1="tick.x" :x2="tick.x" :y1="campaignRoasChart.top-8" :y2="campaignRoasChart.height-24" class="grid-line" />
                                        <text :x="tick.x" :y="campaignRoasChart.height-6" text-anchor="middle">{{ tick.value.toFixed(0) }}×</text>
                                    </g>
                                    <g v-for="row in campaignRoasChart.rows" :key="row.id">
                                        <text :x="campaignRoasChart.left-12" :y="row.y+20" text-anchor="end"><title>{{ row.name }}</title>{{ row.name.length > 22 ? row.name.slice(0, 22) + '…' : row.name }}</text>
                                        <rect :x="campaignRoasChart.left" :y="row.y" :width="row.barWidth" height="28" rx="4" :fill="row.roas >= 3.5 ? '#22a875' : '#ef5261'" />
                                        <text :x="campaignRoasChart.left+row.barWidth+9" :y="row.y+19" class="font-semibold">{{ row.roas.toFixed(2) }}×</text>
                                    </g>
                                </svg>
                            </div>
                            <div v-else class="grid min-h-80 place-items-center text-sm text-slate-400">所选时间段暂无广告系列数据</div>
                        </article>
                    </div>

                    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-6 py-5 sm:px-8">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">广告系列明细</h2>
                                <p class="mt-1 text-sm text-slate-500">当前账户和所选日期范围内的广告系列聚合数据</p>
                            </div>
                            <span class="text-sm text-slate-400">共 {{ campaignRows.length }} 条</span>
                        </div>
                        <div v-if="campaignRows.length" class="overflow-x-auto">
                            <table class="min-w-[1180px] w-full text-left">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-6 py-4">广告系列</th>
                                        <th class="px-5 py-4">状态</th>
                                        <th class="px-5 py-4 text-right">花费</th>
                                        <th class="px-5 py-4 text-right">ROAS</th>
                                        <th class="px-5 py-4 text-right">点击</th>
                                        <th class="px-5 py-4 text-right">CTR</th>
                                        <th class="px-5 py-4 text-right">转化</th>
                                        <th class="px-6 py-4 text-right">CPC</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                                    <tr v-for="row in campaignRows" :key="row.id" class="transition hover:bg-slate-50/80">
                                        <td class="max-w-[380px] px-6 py-4 font-medium text-slate-900"><span class="line-clamp-2" :title="row.name">{{ row.name }}</span></td>
                                        <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="row.status === 'ENABLE' || row.status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ row.status }}</span></td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ format(row.spend, 'money') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums" :class="row.roas >= 3.5 ? 'text-emerald-600' : 'text-rose-500'">{{ row.roas.toFixed(2) }}×</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ format(row.clicks, 'number') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ row.ctr.toFixed(2) }}%</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ format(row.conversions, 'number') }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums">{{ format(row.cpc, 'money') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="grid min-h-60 place-items-center text-sm text-slate-400">所选时间段暂无广告系列明细</div>
                    </article>
                </section>

                <section v-else-if="activeTab === 'creatives' && overview" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-6 sm:px-8">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">当前账户 · 当前日期范围</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">优质素材库</h2>
                        <p class="mt-2 text-sm text-slate-500">按 ROAS 从高到低展示花费大于 0 的前 12 个 TikTok 广告素材。</p>
                    </div>
                    <div v-if="overview.creatives.length" class="grid gap-5 p-6 sm:grid-cols-2 sm:p-8 xl:grid-cols-3">
                        <button
                            v-for="creative in overview.creatives"
                            :key="creative.id"
                            type="button"
                            class="group min-w-0 rounded-2xl border border-slate-200 bg-slate-50/60 p-5 text-left transition hover:-translate-y-0.5 hover:border-blue-300 hover:bg-white hover:shadow-lg"
                            @click="selectedCreative = creative"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="line-clamp-2 min-h-12 text-base font-semibold leading-6 text-slate-950">{{ creative.name }}</h3>
                                <span class="shrink-0 rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white">{{ creative.format || 'VIDEO' }}</span>
                            </div>
                            <p v-if="creative.body" class="mt-3 line-clamp-2 min-h-10 text-sm leading-5 text-slate-500">{{ creative.body }}</p>
                            <p v-else class="mt-3 min-h-10 text-sm text-slate-400">暂无广告文案</p>
                            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold tabular-nums">
                                <span class="text-emerald-600">ROAS {{ creative.roas.toFixed(2) }}×</span>
                                <span class="text-blue-600">CTR {{ creative.ctr.toFixed(2) }}%</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-x-5 gap-y-2 border-t border-slate-200 pt-4 text-xs">
                                <div><dt class="text-slate-400">视频播放</dt><dd class="mt-1 font-semibold text-slate-700">{{ format(creative.video_plays, 'number') }}</dd></div>
                                <div><dt class="text-slate-400">2 秒播放率</dt><dd class="mt-1 font-semibold text-slate-700">{{ creative.video_2s_rate.toFixed(1) }}%</dd></div>
                                <div><dt class="text-slate-400">花费</dt><dd class="mt-1 font-semibold text-slate-700">{{ format(creative.spend, 'money') }}</dd></div>
                                <div><dt class="text-slate-400">购买</dt><dd class="mt-1 font-semibold text-slate-700">{{ format(creative.purchases, 'number') }}</dd></div>
                            </dl>
                        </button>
                    </div>
                    <div v-else class="grid min-h-80 place-items-center px-6 text-center text-sm text-slate-400">
                        所选账户和日期范围暂无广告级素材数据，请点击同步补齐。
                    </div>
                </section>

                <section v-else-if="activeTab !== 'overview'" class="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                    <h2 class="text-xl font-semibold text-slate-950">{{ tabs.find(t => t.key === activeTab)?.label }}</h2>
                    <p class="mt-2 text-sm text-slate-500">该板块暂未接入，按当前需求保持为空。</p>
                </section>
            </template>
        </div>

        <Teleport to="body">
            <div v-if="selectedCreative" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="TikTok 优质素材详情" @click.self="selectedCreative = null">
                <article class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
                    <header class="sticky top-0 z-10 flex items-start justify-between gap-5 border-b border-slate-100 bg-white px-6 py-5 sm:px-8">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">TikTok 优质素材</p>
                            <h2 class="mt-2 text-xl font-semibold leading-7 text-slate-950">{{ selectedCreative.name }}</h2>
                        </div>
                        <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-xl text-slate-500 transition hover:bg-slate-200 hover:text-slate-900" aria-label="关闭" @click="selectedCreative = null">×</button>
                    </header>
                    <div class="space-y-7 px-6 py-6 sm:px-8 sm:py-8">
                        <section class="grid gap-4 rounded-2xl bg-slate-50 p-5 text-sm sm:grid-cols-2">
                            <div><p class="text-slate-400">广告 ID</p><p class="mt-1 break-all font-semibold text-slate-800">{{ selectedCreative.id }}</p></div>
                            <div><p class="text-slate-400">素材格式</p><p class="mt-1 font-semibold text-slate-800">{{ selectedCreative.format || 'VIDEO' }}</p></div>
                            <div class="sm:col-span-2"><p class="text-slate-400">广告系列</p><p class="mt-1 break-words font-semibold text-slate-800">{{ selectedCreative.campaign_name || selectedCreative.campaign_id || '—' }}</p></div>
                        </section>

                        <section>
                            <h3 class="text-base font-semibold text-slate-950">广告文案</h3>
                            <p v-if="selectedCreative.body" class="mt-3 whitespace-pre-wrap rounded-2xl bg-slate-50 p-5 text-sm leading-7 text-slate-700">{{ selectedCreative.body }}</p>
                            <p v-else class="mt-3 rounded-2xl bg-slate-50 p-5 text-sm text-slate-400">暂无广告文案</p>
                        </section>

                        <section v-if="selectedCreative.text_variants.length">
                            <h3 class="text-base font-semibold text-slate-950">广告文案（{{ selectedCreative.text_variants.length }} 条变体）</h3>
                            <ol class="mt-3 space-y-3">
                                <li v-for="(variant, index) in selectedCreative.text_variants" :key="`${selectedCreative.id}-${index}`" class="flex gap-3 rounded-2xl border border-slate-200 p-4 text-sm leading-6 text-slate-600">
                                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-slate-900 text-xs font-semibold text-white">{{ index + 1 }}</span>
                                    <span class="whitespace-pre-wrap">{{ variant }}</span>
                                </li>
                            </ol>
                        </section>

                        <section>
                            <h3 class="text-base font-semibold text-slate-950">投放数据</h3>
                            <dl class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                <div class="rounded-2xl bg-emerald-50 p-4"><dt class="text-xs text-emerald-700">ROAS</dt><dd class="mt-2 text-xl font-semibold text-emerald-700">{{ selectedCreative.roas.toFixed(2) }}×</dd></div>
                                <div class="rounded-2xl bg-blue-50 p-4"><dt class="text-xs text-blue-700">CTR</dt><dd class="mt-2 text-xl font-semibold text-blue-700">{{ selectedCreative.ctr.toFixed(2) }}%</dd></div>
                                <div class="rounded-2xl bg-violet-50 p-4"><dt class="text-xs text-violet-700">购买数</dt><dd class="mt-2 text-xl font-semibold text-violet-700">{{ format(selectedCreative.purchases, 'number') }}</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">花费</dt><dd class="mt-2 text-xl font-semibold text-slate-900">{{ format(selectedCreative.spend, 'money') }}</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">曝光</dt><dd class="mt-2 font-semibold text-slate-900">{{ format(selectedCreative.impressions, 'number') }}</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">点击</dt><dd class="mt-2 font-semibold text-slate-900">{{ format(selectedCreative.clicks, 'number') }}</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">视频播放</dt><dd class="mt-2 font-semibold text-slate-900">{{ format(selectedCreative.video_plays, 'number') }}</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">2 秒播放率</dt><dd class="mt-2 font-semibold text-slate-900">{{ selectedCreative.video_2s_rate.toFixed(2) }}%</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">平均播放时长</dt><dd class="mt-2 font-semibold text-slate-900">{{ selectedCreative.average_play_time.toFixed(2) }}s</dd></div>
                                <div class="rounded-2xl bg-slate-50 p-4"><dt class="text-xs text-slate-500">广告收入</dt><dd class="mt-2 font-semibold text-slate-900">{{ format(selectedCreative.revenue, 'money') }}</dd></div>
                            </dl>
                        </section>
                        <div class="flex justify-end border-t border-slate-100 pt-5">
                            <button type="button" class="h-11 rounded-xl bg-slate-950 px-7 text-sm font-semibold text-white transition hover:bg-slate-800" @click="selectedCreative = null">关闭</button>
                        </div>
                    </div>
                </article>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.tt-page {
    max-width: 1600px;
    margin: 0 auto;
    padding: 24px 32px 48px;
    color: #081126;
}

.tt-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border-bottom: 1px solid #dbe5f1;
    padding-bottom: 20px;
}

.tt-heading h1 {
    margin: 3px 0 6px;
    font-size: 30px;
    line-height: 1.2;
}

.tt-heading p {
    margin: 0;
    color: #68809f;
    font-size: 14px;
}

.eyebrow {
    color: #059669 !important;
    font-size: 12px !important;
    font-weight: 800;
    letter-spacing: .18em;
    text-transform: uppercase;
}

.credential {
    flex: none;
    border-radius: 999px;
    background: #e8fbf2;
    padding: 8px 14px;
    color: #05855f;
    font-size: 13px;
    font-weight: 700;
}

.credential i {
    display: inline-block;
    width: 8px;
    height: 8px;
    margin-right: 7px;
    border-radius: 50%;
    background: #10b981;
}

.credential.off {
    background: #fff7e6;
    color: #c76900;
}

.credential.off i { background: #f59e0b; }

.setup-card,
.filter-card,
.overview,
.empty-panel {
    margin-top: 24px;
    border: 1px solid #dce5ef;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 2px 6px #0f172a0f;
}

.setup-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 28px;
    border-color: #f6c945;
    padding: 36px 40px;
}

.setup-card small {
    color: #e56e00;
    font-size: 12px;
    font-weight: 800;
}

.setup-card h2 {
    margin: 10px 0;
    font-size: 22px;
}

.setup-card p {
    margin: 0;
    color: #657b98;
    font-size: 14px;
}

.filter-card {
    display: grid;
    grid-template-columns: minmax(240px, 1fr) minmax(360px, 1.2fr) auto;
    align-items: end;
    gap: 16px;
    padding: 22px 24px;
}

.filter-card label {
    color: #5d7190;
    font-size: 14px;
    font-weight: 700;
}

.filter-card select,
.date-range {
    display: flex;
    box-sizing: border-box;
    width: 100%;
    height: 48px;
    margin-top: 8px;
    border: 1px solid #d5e0ec;
    border-radius: 12px;
    background: #fff;
    padding: 0 14px;
    font-size: 14px;
}

.filter-card select { appearance: auto; }

.date-range {
    align-items: center;
    gap: 8px;
}

.date-range input {
    min-width: 0;
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
}

.filter-card footer {
    grid-column: 1 / -1;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid #e3ebf4;
    padding-top: 16px;
    color: #7186a3;
    font-size: 12px;
}

.dark-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 48px;
    border: 0;
    border-radius: 12px;
    background: #02091d;
    padding: 0 24px;
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    text-decoration: none;
}

.dark-button:disabled { opacity: .5; }

.tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    border-bottom: 1px solid #dce5ef;
    padding: 24px 0 14px;
}

.tabs button {
    display: inline-flex;
    align-items: center;
    height: 48px;
    border: 1px solid #d8e3ef;
    border-radius: 12px;
    background: #fff;
    padding: 0 16px;
    color: #607490;
    font-size: 14px;
    font-weight: 800;
    white-space: nowrap;
}

.tabs b {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    margin-right: 7px;
    border-radius: 8px;
    background: #f1f5f9;
    padding: 0 5px;
}

.tabs button.active {
    border-color: #60a5fa;
    background: #eff6ff;
    color: #155eef;
    box-shadow: 0 2px 5px #2563eb18;
}

.overview { padding: 24px; }
.overview.loading { pointer-events: none; opacity: .6; }

.section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.section-title h2 {
    margin: 0 0 5px;
    font-size: 20px;
}

.section-title p,
.section-title span {
    color: #7890af;
    font-size: 13px;
}

.section-title span {
    border-radius: 999px;
    background: #f4f7fb;
    padding: 7px 12px;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.metric-card {
    --accent: #3b82f6;
    min-height: 118px;
    border: 1px solid var(--accent);
    border-top-width: 4px;
    border-radius: 16px;
    padding: 18px 20px;
    box-shadow: 0 2px 5px #0f172a10;
}

.metric-card h3 {
    margin: 0 0 13px;
    color: #657b98;
    font-size: 14px;
}

.metric-card > strong { font-size: 24px; }

.metric-card footer {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-top: 15px;
    color: #8ba0ba;
    font-size: 12px;
}

.metric-card em {
    font-style: normal;
    font-weight: 800;
}

.good { color: #00996e; }
.bad { color: #f43f5e; }

.chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 24px;
}

.chart-card {
    position: relative;
    min-height: 340px;
    overflow: hidden;
    border: 1px solid #dce4ee;
    border-radius: 18px;
    padding: 22px;
}

.chart-card h2 {
    margin: 0 0 14px;
    font-size: 18px;
}

.chart-card > svg {
    display: block;
    width: 100%;
    height: 265px;
}

.legend {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    color: #6c809d;
    font-size: 12px;
}

.legend span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend i {
    width: 20px;
    height: 8px;
    border-radius: 3px;
}

.legend .blue { background: #4285ed; }
.legend .navy { background: #205a9d; }
.legend .line { height: 3px; background: #20a875; }
.legend .dash { background: repeating-linear-gradient(90deg, #20a875 0 7px, transparent 7px 12px); }

svg text {
    fill: #7c90ad;
    font-size: 12px;
}

.grid-line { stroke: #dce5ef; stroke-dasharray: 4 4; }
.bar.current { fill: #4285ed; }
.bar.prior { fill: #205a9d; }
.roi { fill: none; stroke: #20a875; stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; }
.prior-roi { stroke-dasharray: 10 7; opacity: .75; }
.hover-line { stroke: #6e82a0; stroke-dasharray: 5 4; }

.tooltip {
    position: absolute;
    z-index: 3;
    display: flex;
    flex-direction: column;
    gap: 7px;
    border: 1px solid #d3dce8;
    border-radius: 12px;
    background: #fff;
    padding: 12px 15px;
    box-shadow: 0 12px 28px #0f172a24;
    color: #53647c;
    font-size: 12px;
}

.tooltip span {
    display: flex;
    justify-content: space-between;
    gap: 28px;
}

.trend-tip { left: 46%; top: 90px; }
.trend-detail-tip { left: 50%; top: 86px; transform: translateX(-50%); }
.campaign-tip { left: 28px; top: 110px; }
.campaign-tab-tip { left: 32px; top: 130px; max-width: min(420px, calc(100% - 64px)); }

.pie-wrap {
    display: grid;
    grid-template-columns: 230px minmax(0, 1fr);
    align-items: center;
    gap: 16px;
    margin-top: 20px;
}

.pie-wrap > svg {
    width: 220px;
    height: 220px;
}

.pie-wrap ul {
    list-style: none;
    padding: 0;
}

.pie-wrap li {
    display: grid;
    grid-template-columns: 12px minmax(0, 1fr) auto;
    gap: 8px;
    margin: 10px 0;
    color: #647895;
    font-size: 12px;
}

.pie-wrap li i {
    width: 10px;
    height: 10px;
    border-radius: 3px;
}

.pie-wrap li span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.empty,
.empty-panel { color: #8295ae; }

.empty-panel {
    padding: 48px;
    text-align: center;
}

.empty-panel h2 { color: #17233a; }

@media (max-width: 1100px) {
    .metric-grid { grid-template-columns: repeat(2, 1fr); }
    .chart-grid { grid-template-columns: 1fr; }
    .filter-card { grid-template-columns: 1fr; }
    .filter-card footer { grid-column: auto; flex-direction: column; gap: 8px; }
    .date-label { grid-row: auto; }
    .tt-page { padding: 22px 20px 40px; }
}

@media (max-width: 640px) {
    .tt-heading,
    .setup-card,
    .section-title { align-items: flex-start; flex-direction: column; }
    .metric-grid { grid-template-columns: 1fr; }
    .overview,
    .filter-card,
    .setup-card { padding: 18px; }
    .pie-wrap { grid-template-columns: 1fr; }
    .pie-wrap > svg { margin: 0 auto; }
}
</style>
