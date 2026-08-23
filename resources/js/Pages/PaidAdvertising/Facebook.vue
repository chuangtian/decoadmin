<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import MetaAdsCampaignRoasChart from '../../Components/PaidAdvertising/MetaAdsCampaignRoasChart.vue';
import MetaAdsCampaignSpendChart from '../../Components/PaidAdvertising/MetaAdsCampaignSpendChart.vue';
import MetaAdsCampaignTable from '../../Components/PaidAdvertising/MetaAdsCampaignTable.vue';
import MetaAdsCopyLibrary from '../../Components/PaidAdvertising/MetaAdsCopyLibrary.vue';
import MetaAdsCtrTrendChart from '../../Components/PaidAdvertising/MetaAdsCtrTrendChart.vue';
import MetaAdsFunnelChart from '../../Components/PaidAdvertising/MetaAdsFunnelChart.vue';
import MetaAdsCreativeLibrary, { type MetaAdsCreativeItem } from '../../Components/PaidAdvertising/MetaAdsCreativeLibrary.vue';
import MetaAdsTrendChart from '../../Components/PaidAdvertising/MetaAdsTrendChart.vue';
import { useToast } from '../../composables/useToast';

type SyncState = 'not_configured' | 'pending' | 'syncing' | 'backfilling' | 'completed' | 'failed' | 'partial_failed';
type MetricValue = number | null;

interface MetaAdsStatus {
    schema: 'meta-ads-sync-status-v2';
    configured: boolean;
    state: SyncState;
    settings_url: string;
    mode: 'full' | 'priority' | 'backfill' | 'incremental' | null;
    data_ready: boolean;
    progress_percent: number;
    completed_shards: number;
    total_shards: number;
    failed_shards: number;
    eta_seconds: number | null;
    started_at: string | null;
    last_activity_at: string | null;
    stalled: boolean;
    last_success_at: string | null;
    error_code: string | null;
    message: string | null;
    last_metric_date: string | null;
    data_synced_at: string | null;
}

interface OverviewSummary {
    spend: number;
    purchase_value: number;
    purchases: number;
    impressions: number;
    reach: number;
    clicks: number;
    inline_link_clicks: number;
    add_to_cart: number;
    initiate_checkout: number;
    roas: MetricValue;
    ctr: MetricValue;
    cpc: MetricValue;
    cpm: MetricValue;
    cost_per_purchase: MetricValue;
    cost_per_add_to_cart: MetricValue;
    cost_per_checkout: MetricValue;
}

interface MetaAdsDailyMetric {
    date: string;
    spend: number;
    purchase_value: number;
    purchases: number;
    impressions: number;
    clicks: number;
    roas: number | null;
    ctr: MetricValue;
    cpc: MetricValue;
}

interface MetaAdsCampaignMetric {
    id: string;
    name: string;
    spend: number;
    purchase_value: number;
    roas: MetricValue;
    impressions: number;
    reach: number;
    clicks: number;
    ctr: MetricValue;
    cpc: MetricValue;
    frequency: MetricValue;
    purchases: number;
}

interface MetaAdsOverview {
    schema: 'meta-ads-overview-v1';
    accounts: Array<{ id: string; name: string; label: string; currency: string | null; active: boolean }>;
    filters: { account: string; date_from: string; date_to: string; compare: 'previous' | 'none'; timezone: string };
    comparison_period: { date_from: string; date_to: string } | null;
    currency: string | null;
    summary: OverviewSummary;
    comparison: OverviewSummary | null;
    deltas: Record<keyof OverviewSummary, number | null> | null;
    daily: MetaAdsDailyMetric[];
    comparison_daily: MetaAdsDailyMetric[];
    campaign_spend: {
        total_spend: number;
        items: Array<{ id: string; name: string; spend: number; share: number | null }>;
    };
    campaigns: {
        total: number;
        limit: number;
        truncated: boolean;
        items: MetaAdsCampaignMetric[];
    };
}

interface MetaAdsCreativeLibraryResponse {
    schema: 'meta-ads-creative-library-v1' | 'meta-ads-copy-library-v1';
    filters: { account: string; date_from: string; date_to: string };
    items: MetaAdsCreativeItem[];
    pagination: { page: number; per_page: number; last_page: number; total: number; from: number | null; to: number | null };
}

const props = defineProps<{
    store: { id: number; name: string };
    metaAdsStatus: MetaAdsStatus;
    overview: MetaAdsOverview;
    canSync: boolean;
}>();

const status = ref(props.metaAdsStatus);
const overview = ref(props.overview);
const filters = ref({ ...props.overview.filters });
const activeTab = ref('overview');
const loading = ref(false);
const checking = ref(false);
const manualSyncing = ref(false);
const manualSyncPending = ref(false);
const manualSyncStarted = ref(false);
const manualSyncRequestedAt = ref(0);
const creativeLoading = ref(false);
const creativeLibrary = ref<MetaAdsCreativeLibraryResponse>({
    schema: 'meta-ads-creative-library-v1',
    filters: { account: filters.value.account, date_from: filters.value.date_from, date_to: filters.value.date_to },
    items: [],
    pagination: { page: 1, per_page: 6, last_page: 1, total: 0, from: null, to: null },
});
const copyLoading = ref(false);
const copyLibrary = ref<MetaAdsCreativeLibraryResponse>({
    schema: 'meta-ads-copy-library-v1',
    filters: { account: filters.value.account, date_from: filters.value.date_from, date_to: filters.value.date_to },
    items: [],
    pagination: { page: 1, per_page: 6, last_page: 1, total: 0, from: null, to: null },
});
const toast = useToast();
let poller: ReturnType<typeof setInterval> | null = null;
let creativeRequest: AbortController | null = null;
let copyRequest: AbortController | null = null;

const tabs = [
    { key: 'overview', label: '总览' },
    { key: 'trend', label: '趋势分析' },
    { key: 'campaigns', label: '广告系列' },
    { key: 'creative', label: '优质素材' },
    { key: 'copy', label: '优质文案' },
];

const isActive = computed(() => ['pending', 'syncing', 'backfilling'].includes(status.value.state));
const dashboardReady = computed(() => status.value.data_ready || overview.value.accounts.length > 0);
const syncButtonBusy = computed(() => manualSyncing.value || manualSyncPending.value || isActive.value);
const progressWidth = computed(() => `${Math.max(2, Math.min(100, status.value.progress_percent || 0))}%`);
const selectedAccount = computed(() => overview.value.accounts.find((account) => account.id === filters.value.account));
const selectedAccountName = computed(() => selectedAccount.value?.name ?? '全部广告账户');
const trendTitle = computed(() => `每日花费 & ROAS（${filters.value.date_from} ～ ${filters.value.date_to}）`);
const currency = computed(() => {
    const value = selectedAccount.value?.currency ?? overview.value.currency ?? 'USD';
    return /^[A-Z]{3}$/.test(value) ? value : 'USD';
});
const lastSuccessLabel = computed(() => status.value.last_success_at ? formatDateTime(status.value.last_success_at) : '暂无完成记录');
const etaLabel = computed(() => {
    if (status.value.stalled) return '后台任务正在等待自动重试';
    const seconds = status.value.eta_seconds;
    if (seconds === null) return '正在估算剩余时间';
    if (seconds <= 0) return '即将完成';
    if (seconds < 60) return '预计不到 1 分钟';
    const minutes = Math.ceil(seconds / 60);
    if (minutes < 60) return `预计还需约 ${minutes} 分钟`;
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return remainder > 0 ? `预计还需约 ${hours} 小时 ${remainder} 分钟` : `预计还需约 ${hours} 小时`;
});

const metricCards = computed(() => [
    { key: 'spend' as const, label: '广告花费', value: money(overview.value.summary.spend), tone: 'blue' },
    { key: 'purchase_value' as const, label: '转化销售额', value: money(overview.value.summary.purchase_value), tone: 'emerald' },
    { key: 'roas' as const, label: 'ROAS', value: multiple(overview.value.summary.roas), tone: 'amber' },
    { key: 'purchases' as const, label: '购买次数', value: number(overview.value.summary.purchases), tone: 'violet' },
]);

const detailCards = computed(() => [
    { key: 'impressions' as const, label: '展示次数', value: integer(overview.value.summary.impressions) },
    { key: 'reach' as const, label: '触达合计', value: integer(overview.value.summary.reach) },
    { key: 'clicks' as const, label: '点击次数', value: integer(overview.value.summary.clicks) },
    { key: 'inline_link_clicks' as const, label: '链接点击', value: integer(overview.value.summary.inline_link_clicks) },
    { key: 'ctr' as const, label: '点击率', value: percentage(overview.value.summary.ctr) },
    { key: 'cpc' as const, label: '单次点击费用', value: moneyOrDash(overview.value.summary.cpc) },
    { key: 'cpm' as const, label: '千次展示费用', value: moneyOrDash(overview.value.summary.cpm) },
    { key: 'cost_per_purchase' as const, label: '单次购买费用', value: moneyOrDash(overview.value.summary.cost_per_purchase) },
    { key: 'add_to_cart' as const, label: '加购数', value: number(overview.value.summary.add_to_cart) },
    { key: 'initiate_checkout' as const, label: '结账数', value: number(overview.value.summary.initiate_checkout) },
    { key: 'cost_per_add_to_cart' as const, label: '单次加购成本', value: moneyOrDash(overview.value.summary.cost_per_add_to_cart) },
    { key: 'cost_per_checkout' as const, label: '单次结账成本', value: moneyOrDash(overview.value.summary.cost_per_checkout) },
]);

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function money(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency', currency: currency.value, minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value);
}

function moneyOrDash(value: MetricValue): string { return value === null ? '—' : money(value); }
function integer(value: number): string { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value); }
function number(value: number): string { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value); }
function percentage(value: MetricValue): string { return value === null ? '—' : `${value.toFixed(2)}%`; }
function multiple(value: MetricValue): string { return value === null ? '—' : `${value.toFixed(2)}×`; }

function deltaLabel(key: keyof OverviewSummary): string {
    const value = overview.value.deltas?.[key];
    if (value === null || value === undefined) return '暂无可比数据';
    if (value === 0) return '与上一时段持平';
    return `${value > 0 ? '↑' : '↓'} ${Math.abs(value).toFixed(1)}%`;
}

function deltaClass(key: keyof OverviewSummary): string {
    const value = overview.value.deltas?.[key];
    if (value === null || value === undefined || value === 0) return 'text-slate-400';
    return value > 0 ? 'text-emerald-600' : 'text-rose-600';
}

function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

const stopPolling = () => {
    if (poller !== null) {
        clearInterval(poller);
        poller = null;
    }
};

const startPolling = (force = false) => {
    if ((!force && !isActive.value) || poller !== null) return;
    poller = setInterval(refreshStatus, 4000);
};

const loadCreatives = async (page = 1, showError = true) => {
    if (!status.value.configured) return;
    creativeRequest?.abort();
    const controller = new AbortController();
    creativeRequest = controller;
    creativeLoading.value = true;
    try {
        const query = new URLSearchParams({
            account: filters.value.account,
            date_from: filters.value.date_from,
            date_to: filters.value.date_to,
            page: String(page),
        });
        const response = await fetch(`/paid-advertising/facebook/creatives?${query.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });
        const payload = await response.json().catch(() => null) as { data?: MetaAdsCreativeLibraryResponse; message?: string } | null;
        if (!response.ok || !payload?.data) throw new Error(payload?.message || '素材数据加载失败');
        creativeLibrary.value = payload.data;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        if (showError) toast.error(error instanceof Error ? error.message : '优秀素材加载失败。');
    } finally {
        if (creativeRequest === controller) {
            creativeRequest = null;
            creativeLoading.value = false;
        }
    }
};

const loadCopies = async (page = 1, showError = true) => {
    if (!status.value.configured) return;
    copyRequest?.abort();
    const controller = new AbortController();
    copyRequest = controller;
    copyLoading.value = true;
    try {
        const query = new URLSearchParams({
            account: filters.value.account,
            date_from: filters.value.date_from,
            date_to: filters.value.date_to,
            page: String(page),
        });
        const response = await fetch(`/paid-advertising/facebook/copies?${query.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });
        const payload = await response.json().catch(() => null) as { data?: MetaAdsCreativeLibraryResponse; message?: string } | null;
        if (!response.ok || !payload?.data) throw new Error(payload?.message || '文案数据加载失败');
        copyLibrary.value = payload.data;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        if (showError) toast.error(error instanceof Error ? error.message : '优质文案加载失败。');
    } finally {
        if (copyRequest === controller) {
            copyRequest = null;
            copyLoading.value = false;
        }
    }
};

const loadOverview = async (showError = true) => {
    if (loading.value || !status.value.configured) return;
    loading.value = true;
    try {
        const query = new URLSearchParams({
            account: filters.value.account,
            date_from: filters.value.date_from,
            date_to: filters.value.date_to,
            compare: filters.value.compare,
        });
        const response = await fetch(`/paid-advertising/facebook/data?${query.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json().catch(() => null) as { data?: MetaAdsOverview; message?: string } | null;
        if (!response.ok || !payload?.data) throw new Error(payload?.message || '数据加载失败');
        overview.value = payload.data;
        filters.value = { ...payload.data.filters };
        if (activeTab.value === 'creative') void loadCreatives(1, showError);
        if (activeTab.value === 'copy') void loadCopies(1, showError);
    } catch (error) {
        if (showError) toast.error(error instanceof Error ? error.message : 'Facebook Ads 数据加载失败。');
    } finally {
        loading.value = false;
    }
};

const refreshStatus = async () => {
    if (checking.value) return;
    checking.value = true;
    const wasActive = isActive.value;
    try {
        const response = await fetch('/paid-advertising/facebook/status', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Meta Ads status request failed');
        const payload = await response.json() as { data: MetaAdsStatus };
        status.value = payload.data;
        if (manualSyncPending.value && isActive.value) manualSyncStarted.value = true;

        const manualFinished = manualSyncPending.value && manualSyncStarted.value && !isActive.value;
        if ((wasActive && !isActive.value) || manualFinished || (!wasActive && status.value.data_ready && overview.value.accounts.length === 0)) {
            await loadOverview(false);
        }
        if (manualFinished) {
            manualSyncPending.value = false;
            manualSyncStarted.value = false;
            status.value.state === 'failed' ? toast.error('Meta Ads 数据同步失败，请检查 Token 权限。') : toast.success('Meta Ads 数据同步完成。');
        }
        if (manualSyncPending.value && !manualSyncStarted.value && Date.now() - manualSyncRequestedAt.value > 120_000) {
            manualSyncPending.value = false;
            toast.info('同步任务仍在后台排队，稍后会自动执行。');
        }
        if (!isActive.value && !manualSyncPending.value) stopPolling();
    } catch {
        // A temporary status request failure must not interrupt the background import.
    } finally {
        checking.value = false;
    }
};

const requestSync = async () => {
    if (syncButtonBusy.value || !props.canSync) return;
    manualSyncing.value = true;
    try {
        const response = await fetch('/paid-advertising/facebook/sync', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                account: filters.value.account,
                date_from: filters.value.date_from,
                date_to: filters.value.date_to,
            }),
        });
        const payload = await response.json().catch(() => null) as {
            message?: string;
            status?: MetaAdsStatus;
            sync?: { already_running?: boolean };
        } | null;
        if (!response.ok) throw new Error(payload?.message || '同步任务提交失败。');
        if (payload?.status) status.value = payload.status;
        manualSyncPending.value = true;
        manualSyncStarted.value = Boolean(payload?.sync?.already_running || isActive.value);
        manualSyncRequestedAt.value = Date.now();
        toast.info(payload?.message || 'Meta Ads 同步任务已提交，后台将静默执行。');
        startPolling(true);
        window.setTimeout(refreshStatus, 1200);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '同步任务提交失败。');
    } finally {
        manualSyncing.value = false;
    }
};

onMounted(() => {
    if (isActive.value) {
        refreshStatus();
        startPolling();
    }
});
watch(activeTab, (tab) => {
    if (tab === 'creative') void loadCreatives(1);
    if (tab === 'copy') void loadCopies(1);
});
onBeforeUnmount(() => {
    stopPolling();
    creativeRequest?.abort();
    copyRequest?.abort();
});
</script>

<template>
    <Head title="Facebook Ads" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '付费广告' }, { label: 'Facebook Ads' }]">
        <div class="mx-auto max-w-[1500px] space-y-6">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-6 2xl:flex-row 2xl:items-end 2xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">当前店铺 · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Facebook Ads</h1>
                    <p class="mt-2 text-sm text-slate-500">账户：{{ selectedAccountName }}</p>
                </div>

                <div v-if="status.configured && dashboardReady" class="flex w-full flex-wrap items-end gap-3 2xl:w-auto">
                    <label class="min-w-[18rem] text-xs font-semibold text-slate-500">
                        广告账户
                        <select v-model="filters.account" class="mt-1.5 h-12 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring-blue-500" @change="loadOverview()">
                            <option value="all">全部广告账户</option>
                            <option v-for="account in overview.accounts" :key="account.id" :value="account.id">{{ account.label }}</option>
                        </select>
                    </label>
                    <div class="text-xs font-semibold text-slate-500">
                        统计日期
                        <div class="mt-1.5 flex h-12 items-center gap-1 rounded-xl border border-slate-200 bg-white px-2 shadow-sm focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                            <input v-model="filters.date_from" aria-label="起始日期" type="date" class="h-10 min-w-0 border-0 bg-transparent px-1 text-sm font-normal text-slate-800 focus:ring-0" @change="loadOverview()" />
                            <span class="text-slate-300">—</span>
                            <input v-model="filters.date_to" aria-label="截止日期" type="date" class="h-10 min-w-0 border-0 bg-transparent px-1 text-sm font-normal text-slate-800 focus:ring-0" @change="loadOverview()" />
                        </div>
                    </div>
                    <label class="min-w-[10.5rem] text-xs font-semibold text-slate-500">
                        数据对比
                        <select v-model="filters.compare" class="mt-1.5 h-12 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:ring-blue-500" @change="loadOverview()"><option value="previous">对比上一时段</option><option value="none">不对比</option></select>
                    </label>
                    <button type="button" class="inline-flex h-12 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="syncButtonBusy || !canSync" :title="canSync ? '静默同步当前店铺的 Meta Ads 数据' : '当前账号没有执行同步的权限'" @click="requestSync">
                        <svg class="h-4 w-4" :class="syncButtonBusy ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 11a8 8 0 1 0 2 5.3"/><path d="M20 4v7h-7"/></svg>
                        {{ syncButtonBusy ? '同步中' : '同步' }}
                    </button>
                </div>
            </header>

            <section v-if="status.state === 'not_configured'" class="overflow-hidden rounded-3xl border border-amber-200 bg-white shadow-sm">
                <div class="grid gap-8 px-6 py-10 sm:px-10 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                    <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-600">需要完成配置</p><h2 class="mt-2 text-xl font-semibold text-slate-950">Facebook / Meta Ads 尚未配置</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">配置具有 ads_read 权限的 Meta Access Token 后，系统会先同步账户和最近 7 天数据，再在后台补齐最近半年。</p></div>
                    <Link :href="status.settings_url" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">前往配置 Meta Token</Link>
                </div>
            </section>

            <section v-else-if="isActive && !dashboardReady" class="overflow-hidden rounded-3xl border border-blue-200 bg-white shadow-sm">
                <div class="relative px-6 py-9 sm:px-10">
                    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-500 via-cyan-400 to-emerald-400" />
                    <div class="flex flex-col gap-7 lg:flex-row lg:items-center lg:justify-between">
                        <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">后台静默任务 · 可安全离开页面</p><h2 class="mt-2 text-xl font-semibold text-slate-950">数据同步中</h2><p class="mt-2 text-sm leading-6 text-slate-500">{{ status.message }}</p></div>
                        <div class="rounded-2xl bg-slate-50 px-5 py-4 ring-1 ring-slate-100"><p class="text-xs text-slate-400">预计完成时间</p><p class="mt-1 font-semibold text-slate-900">{{ etaLabel }}</p></div>
                    </div>
                    <div class="mt-8"><div class="mb-2 flex justify-between text-xs font-semibold text-slate-500"><span>{{ status.completed_shards }} / {{ status.total_shards || '—' }} 个数据分片</span><span class="text-blue-600">{{ status.progress_percent.toFixed(1) }}%</span></div><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-blue-600 to-cyan-400 transition-[width] duration-700" :style="{ width: progressWidth }" /></div></div>
                </div>
            </section>

            <section v-else-if="['failed', 'partial_failed'].includes(status.state) && !dashboardReady" class="rounded-3xl border border-rose-200 bg-white px-6 py-9 shadow-sm sm:px-10">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-600">同步未完成</p><h2 class="mt-2 text-xl font-semibold text-slate-950">Meta Ads 数据同步失败</h2><p class="mt-2 text-sm text-slate-500">{{ status.message }}</p><Link :href="status.settings_url" class="mt-5 inline-flex rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">检查 Meta Token</Link>
            </section>

            <template v-else-if="dashboardReady">
                <div class="flex gap-1 overflow-x-auto border-b border-slate-200">
                    <button v-for="tab in tabs" :key="tab.key" type="button" class="relative shrink-0 px-5 py-3 text-sm font-semibold transition" :class="activeTab === tab.key ? 'text-blue-600' : 'text-slate-500 hover:text-slate-800'" @click="activeTab = tab.key">{{ tab.label }}<span v-if="activeTab === tab.key" class="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-blue-600" /></button>
                </div>

                <section v-if="activeTab === 'overview'" class="space-y-5" :class="loading ? 'pointer-events-none opacity-60' : ''" aria-live="polite">
                    <div v-if="isActive || status.state === 'partial_failed'" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl px-4 py-3 text-sm ring-1" :class="status.state === 'partial_failed' ? 'bg-amber-50 text-amber-800 ring-amber-200' : 'bg-blue-50 text-blue-800 ring-blue-100'"><span>{{ isActive ? status.message : '最近数据可用，历史数据回填未完全成功。' }}</span><span class="text-xs font-semibold">{{ isActive ? etaLabel : '请检查 Token 后重新同步' }}</span></div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="card in metricCards" :key="card.key" class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <span class="absolute inset-x-0 top-0 h-1" :class="{ 'bg-blue-500': card.tone === 'blue', 'bg-emerald-500': card.tone === 'emerald', 'bg-amber-500': card.tone === 'amber', 'bg-violet-500': card.tone === 'violet' }" /><p class="text-sm font-medium text-slate-500">{{ card.label }}</p><p class="mt-4 truncate text-2xl font-semibold tracking-tight text-slate-950">{{ card.value }}</p><p v-if="filters.compare === 'previous'" class="mt-3 text-xs font-semibold" :class="deltaClass(card.key)">{{ deltaLabel(card.key) }}</p>
                        </article>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        <article v-for="card in detailCards" :key="card.key" class="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm"><p class="text-xs font-medium text-slate-400">{{ card.label }}</p><p class="mt-2 truncate text-lg font-semibold text-slate-900">{{ card.value }}</p><p v-if="filters.compare === 'previous'" class="mt-2 truncate text-[11px] font-semibold" :class="deltaClass(card.key)">{{ deltaLabel(card.key) }}</p></article>
                    </div>

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,0.65fr)]">
                        <MetaAdsTrendChart
                            :current="overview.daily"
                            :previous="overview.comparison_daily"
                            :date-from="filters.date_from"
                            :date-to="filters.date_to"
                            :previous-date-from="overview.comparison_period?.date_from ?? null"
                            :comparison-enabled="filters.compare === 'previous'"
                            :currency="currency"
                        />
                        <MetaAdsFunnelChart
                            :impressions="overview.summary.impressions"
                            :clicks="overview.summary.clicks"
                            :purchases="overview.summary.purchases"
                        />
                    </div>

                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4"><div><h2 class="font-semibold text-slate-950">每日投放明细</h2><p class="mt-1 text-xs text-slate-500">数据来自当前店铺数据库中的 Meta 账户级按日洞察。</p></div><p class="text-xs font-medium text-slate-400">最近完成：{{ lastSuccessLabel }}</p></header>
                        <div v-if="overview.daily.length" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-100 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500"><tr><th class="px-5 py-3">日期</th><th class="px-5 py-3 text-right">花费</th><th class="px-5 py-3 text-right">转化销售额</th><th class="px-5 py-3 text-right">ROAS</th><th class="px-5 py-3 text-right">购买</th><th class="px-5 py-3 text-right">展示</th><th class="px-5 py-3 text-right">点击</th><th class="px-5 py-3 text-right">CTR</th><th class="px-5 py-3 text-right">CPC</th></tr></thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700"><tr v-for="row in overview.daily" :key="row.date" class="hover:bg-slate-50/70"><td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ row.date }}</td><td class="whitespace-nowrap px-5 py-3 text-right">{{ money(row.spend) }}</td><td class="whitespace-nowrap px-5 py-3 text-right">{{ money(row.purchase_value) }}</td><td class="whitespace-nowrap px-5 py-3 text-right font-semibold">{{ multiple(row.roas) }}</td><td class="px-5 py-3 text-right">{{ number(row.purchases) }}</td><td class="px-5 py-3 text-right">{{ integer(row.impressions) }}</td><td class="px-5 py-3 text-right">{{ integer(row.clicks) }}</td><td class="whitespace-nowrap px-5 py-3 text-right">{{ percentage(row.ctr) }}</td><td class="whitespace-nowrap px-5 py-3 text-right">{{ moneyOrDash(row.cpc) }}</td></tr></tbody>
                            </table>
                        </div>
                        <div v-else class="flex min-h-48 items-center justify-center px-6 py-12 text-sm text-slate-400">所选时间范围暂无 Meta Ads 数据</div>
                    </section>
                </section>

                <section v-else-if="activeTab === 'trend'" class="space-y-5" :class="loading ? 'pointer-events-none opacity-60' : ''" aria-live="polite">
                    <MetaAdsTrendChart
                        :current="overview.daily"
                        :previous="overview.comparison_daily"
                        :date-from="filters.date_from"
                        :date-to="filters.date_to"
                        :previous-date-from="overview.comparison_period?.date_from ?? null"
                        :comparison-enabled="filters.compare === 'previous'"
                        :currency="currency"
                        :title="trendTitle"
                        description="当前周期与上一周期的每日花费、ROAS 对比"
                    />

                    <div class="grid gap-5 xl:grid-cols-2">
                        <MetaAdsCtrTrendChart
                            :daily="overview.daily"
                            :date-from="filters.date_from"
                            :date-to="filters.date_to"
                        />
                        <MetaAdsCampaignSpendChart
                            :campaigns="overview.campaign_spend.items"
                            :total-spend="overview.campaign_spend.total_spend"
                            :currency="currency"
                        />
                    </div>
                </section>

                <section v-else-if="activeTab === 'campaigns'" class="space-y-5" :class="loading ? 'pointer-events-none opacity-60' : ''" aria-live="polite">
                    <div class="grid gap-5 xl:grid-cols-2">
                        <MetaAdsCampaignSpendChart
                            :campaigns="overview.campaign_spend.items"
                            :total-spend="overview.campaign_spend.total_spend"
                            :currency="currency"
                        />
                        <MetaAdsCampaignRoasChart
                            :campaigns="overview.campaigns.items"
                            :currency="currency"
                        />
                    </div>

                    <MetaAdsCampaignTable
                        :campaigns="overview.campaigns.items"
                        :total="overview.campaigns.total"
                        :truncated="overview.campaigns.truncated"
                        :limit="overview.campaigns.limit"
                        :currency="currency"
                    />
                </section>

                <MetaAdsCreativeLibrary
                    v-else-if="activeTab === 'creative'"
                    :items="creativeLibrary.items"
                    :loading="creativeLoading"
                    :page="creativeLibrary.pagination.page"
                    :last-page="creativeLibrary.pagination.last_page"
                    :total="creativeLibrary.pagination.total"
                    :currency="currency"
                    @page-change="loadCreatives"
                />

                <MetaAdsCopyLibrary
                    v-else-if="activeTab === 'copy'"
                    :items="copyLibrary.items"
                    :loading="copyLoading"
                    :page="copyLibrary.pagination.page"
                    :last-page="copyLibrary.pagination.last_page"
                    :total="copyLibrary.pagination.total"
                    :currency="currency"
                    @page-change="loadCopies"
                />

                <section v-else class="min-h-[420px] rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="内容待补充" />
            </template>
        </div>
    </AppLayout>
</template>
