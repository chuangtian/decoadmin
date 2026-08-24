<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CriteoCampaignPerformanceTable from '../../Components/PaidAdvertising/CriteoCampaignPerformanceTable.vue';
import CriteoCampaignRoasComparison from '../../Components/PaidAdvertising/CriteoCampaignRoasComparison.vue';
import CriteoCampaignSpendShare from '../../Components/PaidAdvertising/CriteoCampaignSpendShare.vue';
import CriteoConversionFunnel from '../../Components/PaidAdvertising/CriteoConversionFunnel.vue';
import CriteoCtrTrend from '../../Components/PaidAdvertising/CriteoCtrTrend.vue';
import CriteoDailyPerformanceTable from '../../Components/PaidAdvertising/CriteoDailyPerformanceTable.vue';
import CriteoSpendRoasTrend from '../../Components/PaidAdvertising/CriteoSpendRoasTrend.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../composables/useToast';

type ChannelState = 'not_configured' | 'pending' | 'syncing' | 'backfilling' | 'completed' | 'failed' | 'partial_failed';
type ChannelStatus = {
    schema: 'advertising-channel-sync-status-v1';
    channel: 'criteo';
    label: string;
    configured: boolean;
    settings_url: string;
    state: ChannelState;
    data_ready: boolean;
    progress_percent: number;
    last_metric_date: string | null;
    data_synced_at: string | null;
    message: string | null;
};
type Summary = {
    spend: number;
    revenue: number;
    conversions: number;
    roas: number;
    impressions: number;
    clicks: number;
    ctr: number;
    cpc: number;
    cpa: number;
};
type DailyMetric = Summary & { date: string };
type CampaignPerformance = {
    id: string;
    name: string;
    spend: number;
    revenue: number;
    roas: number;
    impressions: number;
    clicks: number;
    ctr: number;
    cpc: number;
    conversions: number;
    cpa: number;
};
type Overview = {
    schema: 'criteo-ads-overview-v3';
    available: boolean;
    accounts: Array<{ id: string; name: string; currency: string; timezone: string }>;
    period: { label: string; date_from: string; date_to: string; days_expected: number; days_available: number };
    comparison_period: { date_from: string; date_to: string };
    attribution: { label: string; click_window_days: number; view_window_hours: number };
    report_timezone: { id: string; label: string };
    currency: string;
    current: Summary;
    previous: Summary;
    deltas: Record<keyof Summary, number | null>;
    daily: DailyMetric[];
    campaign_spend_share: {
        available: boolean;
        total_spend: number;
        items: Array<{ id: string; name: string; spend: number; share: number }>;
    };
    campaigns: CampaignPerformance[];
    last_synced_at: string | null;
};
type MetricKey = keyof Summary;
type MetricFormat = 'money' | 'number' | 'ratio' | 'percent';

const props = defineProps<{
    store: { id: number; name: string };
    channelStatus: ChannelStatus;
    criteoOverview: Overview | null;
    canSync: boolean;
}>();

const status = ref(props.channelStatus);
const overview = ref(props.criteoOverview);
const loading = ref(false);
const syncing = ref(false);
const activeView = ref<'overview' | 'trend' | 'campaigns'>('overview');
const syncBaseline = ref<string | null>(null);
const syncStartedAt = ref(0);
const toast = useToast();
let poller: ReturnType<typeof setInterval> | null = null;

const primaryCards: Array<{ key: 'spend' | 'revenue' | 'conversions' | 'roas'; label: string; format: MetricFormat; note: string; color: string; tint: string }> = [
    { key: 'spend', label: '广告花费', format: 'money', note: 'AdvertiserCost', color: '#2563eb', tint: '#eff6ff' },
    { key: 'revenue', label: '归因收入', format: 'money', note: '点击 7 天 + 展示 24 小时', color: '#059669', tint: '#ecfdf5' },
    { key: 'conversions', label: '转化数', format: 'number', note: 'SalesPc7d + SalesPv24h', color: '#0f766e', tint: '#f0fdfa' },
    { key: 'roas', label: 'ROAS', format: 'ratio', note: '归因收入 ÷ 广告花费', color: '#ea580c', tint: '#fff7ed' },
];
const trafficCards: Array<{ key: 'impressions' | 'clicks' | 'ctr' | 'cpc' | 'cpa'; label: string; format: MetricFormat; note: string }> = [
    { key: 'impressions', label: '曝光量', format: 'number', note: 'Displays' },
    { key: 'clicks', label: '点击数', format: 'number', note: 'Clicks' },
    { key: 'ctr', label: '点击率', format: 'percent', note: '点击 ÷ 曝光' },
    { key: 'cpc', label: '平均 CPC', format: 'money', note: '花费 ÷ 点击' },
    { key: 'cpa', label: '平均 CPA', format: 'money', note: '花费 ÷ 转化' },
];
const accountLabel = computed(() => {
    const accounts = overview.value?.accounts ?? [];
    if (accounts.length === 1) return accounts[0].name;
    if (accounts.length > 1) return `${accounts.length} 个广告账户`;
    return '等待同步广告账户';
});
const previousAvailable = computed(() => overview.value
    ? Object.values(overview.value.previous).some((value) => Number(value) > 0)
    : false);
const coverageComplete = computed(() => overview.value
    ? overview.value.period.days_available >= overview.value.period.days_expected
    : false);
const busy = computed(() => loading.value || syncing.value || ['syncing', 'backfilling'].includes(status.value.state));
const maxDailyRevenue = computed(() => Math.max(1, ...(overview.value?.daily.map((row) => row.revenue) ?? [0])));

function formatMetric(value: number, format: MetricFormat): string {
    if (format === 'money') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: overview.value?.currency || 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value || 0);
    }
    if (format === 'ratio') return `${Number(value || 0).toFixed(2)}×`;
    if (format === 'percent') return `${Number(value || 0).toFixed(2)}%`;
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value || 0);
}

function formatDate(date: string): string {
    const parsed = new Date(`${date}T12:00:00Z`);
    return Number.isNaN(parsed.getTime())
        ? date
        : new Intl.DateTimeFormat('zh-CN', { month: 'short', day: 'numeric', weekday: 'short', timeZone: overview.value?.report_timezone.id || 'UTC' }).format(parsed);
}

function formatDateTime(date: string | null): string {
    if (!date) return '尚未同步';
    const parsed = new Date(date);
    return Number.isNaN(parsed.getTime())
        ? date
        : new Intl.DateTimeFormat('zh-CN', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: overview.value?.report_timezone.id || 'UTC' }).format(parsed);
}

function deltaLabel(key: MetricKey): string {
    const value = overview.value?.deltas[key];
    if (value === null || value === undefined) return '暂无上期基准';
    return `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`;
}

function deltaClass(key: MetricKey): string {
    const value = overview.value?.deltas[key];
    if (value === null || value === undefined || key === 'spend' || key === 'cpc' || key === 'cpa') return 'bg-slate-100 text-slate-500';
    return value >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600';
}

function revenueWidth(row: DailyMetric): string {
    return `${Math.max(0, Math.min(100, (row.revenue / maxDailyRevenue.value) * 100))}%`;
}

async function loadData(showSuccess = false): Promise<void> {
    if (loading.value) return;
    loading.value = true;
    try {
        const response = await fetch('/paid-advertising/criteo/data', { headers: { Accept: 'application/json' } });
        const payload = await response.json().catch(() => null) as { data?: Overview; message?: string } | null;
        if (!response.ok || !payload?.data) throw new Error(payload?.message || 'Criteo 总览读取失败。');
        overview.value = payload.data;
        if (showSuccess) toast.success('Criteo 总览已刷新。');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Criteo 总览读取失败。');
    } finally {
        loading.value = false;
    }
}

async function refreshStatus(): Promise<void> {
    try {
        const response = await fetch('/paid-advertising/criteo/status', { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const nextStatus = (await response.json()).data as ChannelStatus;
        status.value = nextStatus;
        if (!syncing.value) return;

        const changed = nextStatus.data_synced_at !== null && nextStatus.data_synced_at !== syncBaseline.value;
        const timedOut = Date.now() - syncStartedAt.value > 45_000;
        if (nextStatus.state === 'failed') {
            syncing.value = false;
            toast.error(nextStatus.message || 'Criteo 数据同步失败。');
        } else if (changed || timedOut) {
            syncing.value = false;
            await loadData();
            toast.success(changed ? 'Criteo 数据同步完成。' : '已重新读取 Criteo 总览。');
        }
    } catch {
        // A short app or network restart should not surface as an uncaught UI error.
    }
}

async function syncData(): Promise<void> {
    if (!props.canSync || busy.value) return;
    syncing.value = true;
    syncBaseline.value = status.value.data_synced_at;
    syncStartedAt.value = Date.now();
    try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/paid-advertising/criteo/sync', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        const payload = await response.json().catch(() => null) as { message?: string; status?: ChannelStatus } | null;
        if (!response.ok) throw new Error(payload?.message || 'Criteo 数据同步任务提交失败。');
        if (payload?.status) status.value = payload.status;
        toast.success(payload?.message || 'Criteo 数据同步任务已提交。');
    } catch (error) {
        syncing.value = false;
        toast.error(error instanceof Error ? error.message : 'Criteo 数据同步任务提交失败。');
    }
}

onMounted(() => {
    poller = setInterval(refreshStatus, 4000);
});
onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});
</script>

<template>
    <Head title="Criteo" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '付费广告' }, { label: 'Criteo' }]">
        <div class="mx-auto max-w-[1500px] space-y-6">
            <header class="flex flex-col gap-6 border-b border-slate-200 pb-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-orange-600">Criteo Commerce Growth</p>
                        <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-semibold" :class="status.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                            <i class="h-1.5 w-1.5 rounded-full" :class="status.configured ? 'bg-emerald-500' : 'bg-amber-500'" />
                            {{ status.configured ? '已连接' : '未配置' }}
                        </span>
                    </div>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Criteo</h1>
                    <p class="mt-2 text-sm text-slate-500">账户：<span class="font-medium text-slate-700">{{ accountLabel }}</span></p>
                </div>
                <button
                    v-if="status.configured && canSync"
                    type="button"
                    class="inline-flex h-12 w-fit items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="busy"
                    @click="syncData"
                >
                    <svg class="h-4 w-4" :class="syncing ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5M4 13a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5" />
                    </svg>
                    {{ syncing ? '同步中…' : '同步数据' }}
                </button>
                <button
                    v-else-if="status.configured"
                    type="button"
                    class="inline-flex h-12 w-fit items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-50"
                    :disabled="loading"
                    @click="loadData(true)"
                >
                    {{ loading ? '刷新中…' : '刷新总览' }}
                </button>
            </header>

            <section v-if="!status.configured" class="rounded-3xl border border-amber-200 bg-white px-7 py-14 shadow-sm sm:px-10">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-600">需要完成配置</p>
                        <h2 class="mt-3 text-2xl font-semibold text-slate-950">Criteo 尚未连接</h2>
                        <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-500">配置 API Client ID 与 Client Secret 后，系统会自动发现当前广告主，并将报告数据按店铺隔离同步到本地数据库。</p>
                    </div>
                    <Link :href="status.settings_url" class="inline-flex h-12 shrink-0 items-center justify-center rounded-xl bg-slate-950 px-7 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">前往配置</Link>
                </div>
            </section>

            <template v-else>
                <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
                        <div>
                            <p class="text-xs font-medium text-slate-400">固定统计周期</p>
                            <p class="mt-1 font-semibold text-slate-800">{{ overview?.period.date_from }} — {{ overview?.period.date_to }}</p>
                        </div>
                        <div class="h-9 w-px bg-slate-200 max-md:hidden" />
                        <div>
                            <p class="text-xs font-medium text-slate-400">Criteo 归因口径</p>
                            <p class="mt-1 font-semibold text-slate-800">{{ overview?.attribution.label || '点击后 7 天 + 展示后 24 小时' }}</p>
                        </div>
                        <div class="h-9 w-px bg-slate-200 max-md:hidden" />
                        <div>
                            <p class="text-xs font-medium text-slate-400">报表时区</p>
                            <p class="mt-1 font-semibold text-slate-800">{{ overview?.report_timezone.label || 'Criteo 系统时间 (UTC)' }}</p>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400">最近同步：{{ formatDateTime(overview?.last_synced_at ?? null) }}</p>
                </section>

                <nav class="flex border-b border-slate-200" aria-label="Criteo 数据视图">
                    <button type="button" class="inline-flex h-12 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition" :class="activeView === 'overview' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'" :aria-current="activeView === 'overview' ? 'page' : undefined" @click="activeView = 'overview'">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M4 19V9m6 10V5m6 14v-7m4 7H2" />
                        </svg>
                        总览
                    </button>
                    <button type="button" class="inline-flex h-12 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition" :class="activeView === 'trend' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'" :aria-current="activeView === 'trend' ? 'page' : undefined" @click="activeView = 'trend'">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 17 5-5 4 4 8-9" /><path d="M15 7h5v5" /></svg>
                        趋势分析
                    </button>
                    <button type="button" class="inline-flex h-12 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition" :class="activeView === 'campaigns' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'" :aria-current="activeView === 'campaigns' ? 'page' : undefined" @click="activeView = 'campaigns'">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="4" width="14" height="16" rx="2" /><path d="M9 8h6M9 12h6M9 16h4" /></svg>
                        广告系列
                    </button>
                </nav>

                <div v-if="['syncing', 'backfilling'].includes(status.state) || syncing" class="rounded-2xl border border-blue-100 bg-blue-50 px-5 py-4 text-sm text-blue-800">
                    <div class="flex items-center justify-between gap-4">
                        <span>{{ status.message || 'Criteo 数据正在后台同步，当前总览仍可继续查看。' }}</span>
                        <span class="shrink-0 font-semibold tabular-nums">{{ status.progress_percent || 0 }}%</span>
                    </div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-blue-100"><div class="h-full rounded-full bg-blue-600 transition-all" :style="{ width: `${Math.max(2, status.progress_percent || 0)}%` }" /></div>
                </div>

                <section v-if="overview?.available" class="space-y-6">
                    <template v-if="activeView === 'overview'">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="card in primaryCards" :key="card.key" class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <i class="absolute inset-x-0 top-0 h-1" :style="{ backgroundColor: card.color }" />
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                                    <p class="mt-4 text-3xl font-semibold tracking-tight tabular-nums" :style="{ color: card.color }">{{ formatMetric(overview.current[card.key], card.format) }}</p>
                                </div>
                                <span class="grid h-11 w-11 place-items-center rounded-xl" :style="{ backgroundColor: card.tint, color: card.color }">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 15 5-5 4 4 7-7" /><path d="M14 7h6v6" /></svg>
                                </span>
                            </div>
                            <div class="mt-5 flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full px-2.5 py-1 font-semibold" :class="deltaClass(card.key)">{{ deltaLabel(card.key) }}</span>
                                <span class="text-slate-400">对比前 7 天</span>
                            </div>
                            <p class="mt-3 text-xs text-slate-400">{{ card.note }}</p>
                        </article>
                    </div>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Traffic quality</p>
                                <h2 class="mt-2 text-xl font-semibold text-slate-950">流量与效率</h2>
                                <p class="mt-1 text-sm text-slate-500">直接使用 Criteo 报告中的 Displays、Clicks 与 Sales 计算。</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">币种 {{ overview.currency }}</span>
                        </div>
                        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                            <article v-for="card in trafficCards" :key="card.key" class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                                <p class="mt-3 text-2xl font-semibold tracking-tight text-slate-950 tabular-nums">{{ formatMetric(overview.current[card.key], card.format) }}</p>
                                <div class="mt-3 flex items-center justify-between gap-2 text-xs">
                                    <span class="text-slate-400">{{ card.note }}</span>
                                    <span class="rounded-full px-2 py-1 font-semibold" :class="deltaClass(card.key)">{{ deltaLabel(card.key) }}</span>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="grid min-w-0 gap-6 min-[1700px]:grid-cols-2">
                        <CriteoSpendRoasTrend :currency="overview.currency" :points="overview.daily" />
                        <CriteoConversionFunnel
                            :impressions="overview.current.impressions"
                            :clicks="overview.current.clicks"
                            :conversions="overview.current.conversions"
                        />
                    </section>

                    <CriteoDailyPerformanceTable
                        :currency="overview.currency"
                        :rows="overview.daily"
                        :days-available="overview.period.days_available"
                        :days-expected="overview.period.days_expected"
                    />

                    <section class="grid gap-4 rounded-2xl border border-orange-100 bg-orange-50/70 p-5 text-sm text-orange-950 md:grid-cols-[1fr_auto] md:items-center">
                        <div>
                            <p class="font-semibold">数据口径说明</p>
                            <p class="mt-1 leading-6 text-orange-800">归因收入为 RevenueGeneratedPc7d + RevenueGeneratedPv24h；转化数为 SalesPc7d + SalesPv24h。两者都按 Criteo 系统时间（UTC）生成报表，并使用点击后 7 天、展示后 24 小时的同一归因口径。</p>
                        </div>
                        <span v-if="previousAvailable" class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm">已启用前 7 天对比</span>
                        <span v-else class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-500 shadow-sm">等待上期数据</span>
                    </section>
                    </template>

                    <template v-else-if="activeView === 'trend'">
                        <section class="flex flex-col gap-4 rounded-2xl border border-blue-100 bg-blue-50/70 px-5 py-4 text-sm text-blue-950 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-white text-blue-600 shadow-sm">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5m0-9h.01" /></svg>
                                </span>
                                <div>
                                    <p class="font-semibold">固定归因与统计口径</p>
                                    <p class="mt-1 leading-6 text-blue-800">{{ overview.attribution.label }} · {{ overview.report_timezone.label }} · {{ overview.period.label }}</p>
                                </div>
                            </div>
                            <span class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm">{{ overview.period.date_from }} — {{ overview.period.date_to }}</span>
                        </section>

                        <CriteoSpendRoasTrend :currency="overview.currency" :points="overview.daily" />

                        <section class="grid min-w-0 items-stretch gap-6 xl:grid-cols-2">
                            <CriteoCtrTrend :points="overview.daily" />
                            <CriteoCampaignSpendShare :currency="overview.currency" :breakdown="overview.campaign_spend_share" />
                        </section>

                        <CriteoDailyPerformanceTable
                            :currency="overview.currency"
                            :rows="overview.daily"
                            :days-available="overview.period.days_available"
                            :days-expected="overview.period.days_expected"
                        />

                        <section class="rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 shadow-sm">
                            广告系列占比直接按 Criteo 的 CampaignId / Campaign 维度汇总花费；CTR、ROAS 与每日明细均使用同一 Criteo 系统时间（UTC）最近 7 天窗口。
                        </section>
                    </template>

                    <template v-else>
                        <section class="flex flex-col gap-4 rounded-2xl border border-orange-100 bg-orange-50/70 px-5 py-4 text-sm text-orange-950 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-white text-orange-600 shadow-sm">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5m0-9h.01" /></svg>
                                </span>
                                <div>
                                    <p class="font-semibold">广告系列统计口径</p>
                                    <p class="mt-1 leading-6 text-orange-800">{{ overview.attribution.label }} · {{ overview.report_timezone.label }} · {{ overview.period.label }}</p>
                                </div>
                            </div>
                            <span class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-orange-700 shadow-sm">{{ overview.period.date_from }} — {{ overview.period.date_to }}</span>
                        </section>

                        <section class="grid min-w-0 items-stretch gap-6 xl:grid-cols-2">
                            <CriteoCampaignSpendShare :currency="overview.currency" :breakdown="overview.campaign_spend_share" />
                            <CriteoCampaignRoasComparison :campaigns="overview.campaigns" />
                        </section>

                        <CriteoCampaignPerformanceTable :currency="overview.currency" :campaigns="overview.campaigns" />

                        <section class="rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm leading-6 text-slate-600 shadow-sm">
                            花费、曝光、点击、转化均直接来自 Criteo Campaign 报表；ROAS、CTR、CPC 在后端按汇总值计算，搜索只筛选当前已加载的广告系列，不改变数据口径。
                        </section>
                    </template>
                </section>

                <section v-else class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm">
                    <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-orange-50 text-orange-600">
                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2" /></svg>
                    </span>
                    <h2 class="mt-5 text-xl font-semibold text-slate-950">等待首批 Criteo 指标</h2>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">广告账户已经连接，但本地数据库还没有可展示的日指标。点击“同步数据”后，系统会从 Criteo API 拉取并按当前店铺保存。</p>
                </section>

                <div v-if="loading" class="fixed inset-0 z-30 grid place-items-center bg-white/40 backdrop-blur-[1px]">
                    <span class="rounded-full bg-slate-950 px-4 py-2 text-sm font-medium text-white shadow-lg">正在刷新 Criteo 总览…</span>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
