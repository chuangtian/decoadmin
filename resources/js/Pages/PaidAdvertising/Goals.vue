<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import GoalProgressCharts from '../../Components/PaidAdvertising/GoalProgressCharts.vue';
import GoogleAdsGoalTemplate from '../../Components/PaidAdvertising/GoogleAdsGoalTemplate.vue';
import PersonalFacebookGoalTemplate from '../../Components/PaidAdvertising/PersonalFacebookGoalTemplate.vue';
import { useToast } from '../../composables/useToast';
import AppLayout from '../../Layouts/AppLayout.vue';

type GoalType = 'personal_facebook' | 'google_ads';
type PeriodMode = 'month' | 'range';

interface PeriodSelection {
    schema: 'paid-advertising-goal-period-v1';
    mode: PeriodMode;
    month: string | null;
    date_from: string;
    date_to: string;
    label: string;
    timezone: string;
}

type MetricTone = 'blue' | 'rose' | 'emerald' | 'amber';
type ChannelSummaryTone = 'rose' | 'blue' | 'orange' | 'violet';

interface ChannelSummaryItem {
    key: string;
    label: string;
    value: number | null;
    format: 'currency' | 'roi';
    tone: ChannelSummaryTone;
    source_field: string;
}

interface GoalMetricCard {
    key: 'daily_sales' | 'refunds' | 'monthly_sales' | 'completion_rate';
    label: string;
    value: number | null;
    format: 'currency' | 'percent';
    tone: MetricTone;
    source_fields: string[];
}

interface GoalMetrics {
    schema: 'paid-advertising-goal-metrics-v1';
    available: boolean;
    source: 'feishu';
    currency: string;
    as_of_date: string | null;
    synced_at: string | null;
    missing_fields: string[];
    message: string | null;
    cards: GoalMetricCard[];
    project_sales: {
        schema: 'paid-advertising-project-sales-v1';
        available: boolean;
        as_of_date: string | null;
        title: string;
        message: string | null;
        products: Array<{
            key: string;
            label: string;
            value: number;
            unit: string;
            source_field: string;
        }>;
        site_metrics: Array<{
            key: string;
            label: string;
            value: number;
            source_field: string;
        }>;
    };
    channel_performance: {
        schema: 'paid-advertising-channel-performance-v1';
        available: boolean;
        as_of_date: string | null;
        title: string;
        message: string | null;
        channels: Array<{
            key: string;
            label: string;
            spend: number | null;
            sales: number | null;
            roi: number | null;
            source_fields: {
                spend: string;
                sales: string;
                roi: string;
            };
        }>;
        summary: ChannelSummaryItem[];
    };
    progress: {
        schema: 'paid-advertising-goal-progress-v1';
        available: boolean;
        message: string | null;
        points: Array<{
            date: string;
            daily_sales: number;
            daily_refunds: number;
            cumulative_sales: number;
        }>;
        monthly_target: number | null;
        completion: {
            cumulative_sales: number | null;
            monthly_refunds: number | null;
            target: number | null;
            rate: number | null;
            remaining: number | null;
        };
        source_fields: {
            daily_sales: string;
            daily_refunds: string;
            cumulative_sales: string;
            monthly_target: string;
            monthly_refunds: string;
        };
    };
}

interface PersonalFacebookSummary {
    schema: 'paid-advertising-personal-facebook-summary-v1';
    available: boolean;
    source: 'database_sync';
    currency: string;
    as_of_date: string | null;
    day_label: string;
    synced_at: string | null;
    missing_fields: string[];
    message: string | null;
    values: {
        daily_sales: number | null;
        monthly_sales: number | null;
        monthly_target: number | null;
        completion_rate: number | null;
        monthly_spend: number | null;
        roas: number | null;
    };
    facebook: {
        schema: 'paid-advertising-personal-facebook-channel-goal-v1';
        available: boolean;
        message: string | null;
        as_of_date: string | null;
        values: {
            daily_sales: number | null;
            period_sales: number | null;
            target: number | null;
            completion_rate: number | null;
            time_progress: number | null;
            time_variance: number | null;
            daily_needed: number | null;
            daily_achievement_rate: number | null;
            elapsed_days: number;
            remaining_days: number;
        };
        source_fields: {
            daily_sales: string;
            period_sales: string;
            target: string;
            completion_rate: string[];
            time_progress: string;
            time_variance: string[];
            daily_needed: string[];
            daily_achievement_rate: string[];
        };
    };
    source_fields: {
        daily_sales: string;
        monthly_sales: string;
        monthly_target: string;
        completion_rate: string[];
        monthly_spend: string[];
        roas: string[];
    };
}

interface GoalTab {
    key: string;
    label: string;
    kind: 'overall' | 'board';
    board_id: number | null;
    type: GoalType | null;
    deletable: boolean;
    clearable: boolean;
}

interface GoalPage {
    schema: 'paid-advertising-goals-v1';
    active_tab: string;
    tabs: GoalTab[];
    type_options: Array<{ value: GoalType; label: string }>;
    configuration: {
        schema: 'feishu-data-link-status-v1';
        section: 'advertising_goals';
        configured: boolean;
        has_configuration: boolean;
        missing_fields: string[];
    };
    period: PeriodSelection;
    metrics: GoalMetrics;
    template_data: {
        personal_facebook: PersonalFacebookSummary | null;
        google_ads: null;
    };
}

interface SyncRun {
    id: string;
    tab: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    result: {
        sources: number;
        fields: number;
        inserted: number;
        updated: number;
        deleted: number;
        skipped: number;
    } | null;
    message: string | null;
}

const props = defineProps<{
    store: { id: number; name: string };
    goalPage: GoalPage;
    canManage: boolean;
    canRefresh: boolean;
}>();

const toast = useToast();
const activeTab = ref(props.goalPage.active_tab);
const activeTabDefinition = computed(() => props.goalPage.tabs.find((tab) => tab.key === activeTab.value) ?? null);
const periodDialogOpen = ref(false);
const periodApplying = ref(false);
const periodMode = ref<PeriodMode>(props.goalPage.period.mode);
const periodMonth = ref(props.goalPage.period.month ?? '');
const periodFrom = ref(props.goalPage.period.date_from);
const periodTo = ref(props.goalPage.period.date_to);
const periodError = ref('');
const metricsLoading = ref(false);
const regionRefreshing = ref(false);
const addDialogOpen = ref(false);
const deleteTarget = ref<GoalTab | null>(null);
const deleteProcessing = ref(false);
const refreshProcessing = ref(false);
const nameInput = ref<HTMLInputElement | null>(null);
const addForm = useForm({
    name: '',
    type: '' as GoalType | '',
    feishu_app_token: '',
    feishu_table_id: '',
    feishu_view_id: '',
});
const overallForm = useForm({
    feishu_app_token: '',
    feishu_table_id: '',
    feishu_view_id: '',
});
let refreshPollTimer: number | null = null;
let refreshPollingCancelled = false;

watch(() => props.goalPage.active_tab, (tab) => { activeTab.value = tab; });
watch(() => props.goalPage.tabs, (tabs) => {
    if (!tabs.some((tab) => tab.key === activeTab.value)) activeTab.value = 'overall';
});
watch(() => props.goalPage.period, (period) => {
    periodMode.value = period.mode;
    periodMonth.value = period.month ?? '';
    periodFrom.value = period.date_from;
    periodTo.value = period.date_to;
}, { deep: true });

const periodQuery = () => props.goalPage.period.mode === 'month'
    ? { period_mode: 'month', month: props.goalPage.period.month ?? '' }
    : {
        period_mode: 'range',
        date_from: props.goalPage.period.date_from,
        date_to: props.goalPage.period.date_to,
    };

const selectTab = (tab: GoalTab) => {
    if (tab.key === activeTab.value || metricsLoading.value) return;

    const previousTab = activeTab.value;
    activeTab.value = tab.key;
    router.get('/paid-advertising/goals', { tab: tab.key, ...periodQuery() }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => { metricsLoading.value = true; },
        onError: () => { activeTab.value = previousTab; },
        onFinish: () => { metricsLoading.value = false; },
    });
};

const metricToneClasses: Record<MetricTone, { accent: string; badge: string; value: string }> = {
    blue: {
        accent: 'bg-blue-500',
        badge: 'bg-blue-50 text-blue-700 ring-blue-100',
        value: 'text-blue-700',
    },
    rose: {
        accent: 'bg-rose-500',
        badge: 'bg-rose-50 text-rose-700 ring-rose-100',
        value: 'text-rose-600',
    },
    emerald: {
        accent: 'bg-emerald-500',
        badge: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        value: 'text-emerald-700',
    },
    amber: {
        accent: 'bg-amber-500',
        badge: 'bg-amber-50 text-amber-700 ring-amber-100',
        value: 'text-amber-700',
    },
};

const formatMetricValue = (card: GoalMetricCard) => {
    if (card.value === null) return '—';
    if (card.format === 'percent') return `${card.value.toFixed(1)}%`;

    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: props.goalPage.metrics.currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(card.value);
    } catch {
        return `$${card.value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
};

const metricSourceLabel = (card: GoalMetricCard) => card.source_fields.join(' ÷ ');
const projectToneClasses = [
    { surface: 'bg-blue-50 text-blue-700 ring-blue-100', dot: 'bg-blue-500' },
    { surface: 'bg-emerald-50 text-emerald-700 ring-emerald-100', dot: 'bg-emerald-500' },
    { surface: 'bg-violet-50 text-violet-700 ring-violet-100', dot: 'bg-violet-500' },
    { surface: 'bg-amber-50 text-amber-700 ring-amber-100', dot: 'bg-amber-500' },
    { surface: 'bg-orange-50 text-orange-700 ring-orange-100', dot: 'bg-orange-500' },
];
const formatCount = (value: number) => new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 0 }).format(value);
const channelAccentClasses: Record<string, string> = {
    facebook: 'border-t-blue-500',
    google: 'border-t-sky-500',
    tiktok: 'border-t-slate-800',
    bing: 'border-t-cyan-500',
    criteo: 'border-t-orange-500',
};
const channelLabelClasses: Record<string, string> = {
    facebook: 'text-blue-700',
    google: 'text-sky-700',
    tiktok: 'text-slate-900',
    bing: 'text-cyan-700',
    criteo: 'text-orange-700',
};
const summaryToneClasses: Record<ChannelSummaryTone, string> = {
    rose: 'text-rose-600',
    blue: 'text-blue-700',
    orange: 'text-orange-600',
    violet: 'text-violet-700',
};
const formatChannelCurrency = (value: number | null) => {
    if (value === null || value === 0) return '—';

    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: props.goalPage.metrics.currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    } catch {
        return `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
};
const formatRoi = (value: number | null) => value === null || value === 0 ? '—' : `${value.toFixed(2)}×`;
const roiValueClass = (value: number | null) => {
    if (value === null || value === 0) return 'text-slate-400';
    if (value >= 4) return 'text-emerald-600';
    if (value >= 2) return 'text-blue-700';
    return 'text-rose-600';
};
const formatChannelSummary = (item: ChannelSummaryItem) => item.format === 'currency'
    ? formatChannelCurrency(item.value)
    : formatRoi(item.value);

const openPeriodDialog = () => {
    periodMode.value = props.goalPage.period.mode;
    periodMonth.value = props.goalPage.period.month ?? '';
    periodFrom.value = props.goalPage.period.date_from;
    periodTo.value = props.goalPage.period.date_to;
    periodError.value = '';
    periodDialogOpen.value = true;
};

const closePeriodDialog = () => {
    if (periodApplying.value) return;
    periodDialogOpen.value = false;
    periodError.value = '';
};

const applyPeriod = () => {
    periodError.value = '';

    if (periodMode.value === 'month' && !periodMonth.value) {
        periodError.value = '请选择月份。';
        return;
    }

    if (periodMode.value === 'range') {
        if (!periodFrom.value || !periodTo.value) {
            periodError.value = '请选择完整的开始和结束日期。';
            return;
        }
        if (periodFrom.value > periodTo.value) {
            periodError.value = '结束日期不能早于开始日期。';
            return;
        }

        const rangeDays = (Date.parse(`${periodTo.value}T00:00:00Z`) - Date.parse(`${periodFrom.value}T00:00:00Z`)) / 86_400_000;
        if (rangeDays > 365) {
            periodError.value = '日期范围最多可选择 366 天。';
            return;
        }
    }

    periodApplying.value = true;
    const query = periodMode.value === 'month'
        ? { tab: activeTab.value, period_mode: 'month', month: periodMonth.value }
        : { tab: activeTab.value, period_mode: 'range', date_from: periodFrom.value, date_to: periodTo.value };

    router.get('/paid-advertising/goals', query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onSuccess: () => { periodDialogOpen.value = false; },
        onFinish: () => { periodApplying.value = false; },
    });
};

const resetPeriod = () => {
    periodApplying.value = true;
    router.get('/paid-advertising/goals', { tab: activeTab.value }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onSuccess: () => { periodDialogOpen.value = false; },
        onFinish: () => { periodApplying.value = false; },
    });
};

const openAddDialog = async () => {
    addForm.clearErrors();
    addDialogOpen.value = true;
    await nextTick();
    nameInput.value?.focus();
};

const closeAddDialog = () => {
    if (addForm.processing) return;
    addDialogOpen.value = false;
    addForm.reset();
    addForm.clearErrors();
};

const submitAdd = () => {
    addForm.transform((data) => ({ ...data, ...periodQuery() })).post('/paid-advertising/goals', {
        only: ['goalPage'],
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onSuccess: () => {
            const configuredTab = props.goalPage.active_tab;
            addDialogOpen.value = false;
            addForm.reset();
            if (props.canRefresh) void refreshFromFeishu(configuredTab);
        },
    });
};

const submitOverall = () => {
    overallForm.transform((data) => ({ ...data, ...periodQuery() })).put('/paid-advertising/goals/overall', {
        only: ['goalPage'],
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onSuccess: () => {
            overallForm.reset();
            if (props.canRefresh) void refreshFromFeishu('overall');
        },
    });
};

const csrfToken = () => {
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

const waitForNextStatusCheck = () => new Promise<void>((resolve) => {
    refreshPollTimer = window.setTimeout(() => {
        refreshPollTimer = null;
        resolve();
    }, 1500);
});

const parseSyncResponse = async (response: Response): Promise<SyncRun> => {
    const payload = await response.json().catch(() => null) as { sync?: SyncRun } | null;

    if (!response.ok || !payload?.sync) {
        throw new Error('无法获取同步状态，请稍后重试。');
    }

    return payload.sync;
};

const completionMessage = (tabLabel: string, sync: SyncRun) => {
    const result = sync.result;
    if (!result) return `${tabLabel}飞书数据同步完成。`;

    const changed = result.inserted + result.updated + result.deleted;
    if (changed === 0 && result.skipped === 0) return `${tabLabel}飞书数据已是最新。`;

    return `${tabLabel}同步完成：字段 ${result.fields}，新增 ${result.inserted}，更新 ${result.updated}，删除 ${result.deleted}，跳过 ${result.skipped}。`;
};

const reloadGoalRegion = (targetTab: string) => new Promise<void>((resolve) => {
    metricsLoading.value = true;
    router.get('/paid-advertising/goals', { tab: targetTab, ...periodQuery() }, {
        only: ['goalPage'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        showProgress: false,
        onFinish: () => {
            metricsLoading.value = false;
            resolve();
        },
    });
});

const refreshFromFeishu = async (requestedTab?: string) => {
    if (refreshProcessing.value) return;

    const targetTab = requestedTab ?? activeTab.value;
    const targetLabel = props.goalPage.tabs.find((tab) => tab.key === targetTab)?.label ?? '当前页签';
    refreshProcessing.value = true;
    regionRefreshing.value = true;
    refreshPollingCancelled = false;
    try {
        const response = await fetch('/paid-advertising/goals/refresh', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ tab: targetTab }),
        });
        let sync = await parseSyncResponse(response);
        const pollingDeadline = Date.now() + (32 * 60 * 1000);

        while (!refreshPollingCancelled && ['queued', 'running'].includes(sync.status)) {
            if (Date.now() >= pollingDeadline) {
                throw new Error('同步时间较长，后台仍会继续执行，请稍后再查看。');
            }

            await waitForNextStatusCheck();
            if (refreshPollingCancelled) return;

            const statusResponse = await fetch(`/paid-advertising/goals/refresh/${encodeURIComponent(sync.id)}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            sync = await parseSyncResponse(statusResponse);
        }

        if (refreshPollingCancelled) return;

        if (sync.status === 'completed') {
            await reloadGoalRegion(targetTab);
            toast.add({
                type: 'success',
                title: '同步完成',
                message: completionMessage(targetLabel, sync),
                duration: 5000,
            });
        } else {
            toast.add({
                type: 'error',
                title: '同步失败',
                message: sync.message || `${targetLabel}同步失败，请检查飞书配置后重试。`,
                duration: 7000,
            });
        }
    } catch (error) {
        if (!refreshPollingCancelled) {
            toast.add({
                type: 'error',
                title: '同步失败',
                message: error instanceof Error ? error.message : '无法启动同步，请稍后重试。',
                duration: 7000,
            });
        }
    } finally {
        refreshProcessing.value = false;
        regionRefreshing.value = false;
    }
};

onBeforeUnmount(() => {
    refreshPollingCancelled = true;
    if (refreshPollTimer !== null) window.clearTimeout(refreshPollTimer);
});

const hasTabAction = (tab: GoalTab) => tab.deletable || tab.clearable;
const tabActionLabel = (tab: GoalTab) => tab.kind === 'overall'
    ? '清除总目标数据和飞书配置'
    : `删除 ${tab.label}`;

const openDeleteDialog = (tab: GoalTab) => {
    if (!hasTabAction(tab)) return;
    if (tab.kind === 'board' && tab.board_id === null) return;
    deleteTarget.value = tab;
};

const closeDeleteDialog = () => {
    if (!deleteProcessing.value) deleteTarget.value = null;
};

const confirmDelete = () => {
    const target = deleteTarget.value;
    if (!target || deleteProcessing.value) return;
    if (target.kind === 'board' && target.board_id === null) return;

    deleteProcessing.value = true;
    const url = target.kind === 'overall'
        ? '/paid-advertising/goals/overall'
        : `/paid-advertising/goals/${target.board_id}`;
    router.delete(url, {
        data: { confirmed: true },
        preserveScroll: true,
        onSuccess: () => { deleteTarget.value = null; },
        onFinish: () => { deleteProcessing.value = false; },
    });
};
</script>

<template>
    <Head title="广告目标" />
    <AppLayout :breadcrumbs="[{ label: '付费广告' }, { label: '广告目标' }]">
        <div class="mx-auto max-w-7xl">
            <header class="flex flex-wrap items-start justify-between gap-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">当前店铺 · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">广告目标</h1>
                    <p class="mt-2 text-sm text-slate-500">各付费广告渠道的月度目标进度汇总。</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2.5">
                    <button
                        type="button"
                        class="group inline-flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-left shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50/60 focus:outline-none focus:ring-4 focus:ring-emerald-100"
                        aria-label="选择统计时间"
                        @click="openPeriodDialog"
                    >
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100 transition group-hover:bg-white">
                            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg>
                        </span>
                        <span>
                            <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">统计时间</span>
                            <span class="mt-0.5 block whitespace-nowrap text-sm font-semibold text-slate-800">{{ goalPage.period.label }}</span>
                        </span>
                        <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m6 8 4 4 4-4"/></svg>
                    </button>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-200"
                        @click="openAddDialog"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        添加
                    </button>
                    <button
                        v-if="canRefresh"
                        type="button"
                        :disabled="refreshProcessing"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="refreshFromFeishu()"
                    >
                        <svg class="h-4 w-4" :class="{ 'animate-spin': refreshProcessing }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6v5h-5M4 18v-5h5"/><path d="M18.4 9A7 7 0 0 0 6.2 6.2L4 8m16 8-2.2 1.8A7 7 0 0 1 5.6 15"/></svg>
                        {{ refreshProcessing ? '同步中…' : '刷新' }}
                    </button>
                </div>
            </header>

            <section class="relative mt-8" :aria-busy="regionRefreshing">
                <div :class="{ 'pointer-events-none select-none opacity-55': regionRefreshing }">
                    <div class="overflow-x-auto border-b border-slate-200">
                        <nav class="flex min-w-max gap-1" aria-label="广告目标导航" role="tablist">
                    <div v-for="tab in goalPage.tabs" :key="tab.key" class="group relative shrink-0">
                        <button
                            :id="`advertising-goals-tab-${tab.key}`"
                            type="button"
                            role="tab"
                            :aria-selected="activeTab === tab.key"
                            :aria-controls="`advertising-goals-panel-${tab.key}`"
                            class="border-b-2 py-3 pl-4 text-sm font-semibold transition"
                            :class="[
                                activeTab === tab.key
                                    ? 'border-emerald-600 text-emerald-700'
                                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800',
                                canManage && hasTabAction(tab) ? 'pr-10' : 'pr-4',
                            ]"
                            @click="selectTab(tab)"
                        >
                            {{ tab.label }}
                        </button>
                        <button
                            v-if="canManage && hasTabAction(tab)"
                            type="button"
                            class="absolute top-1.5 right-1 grid h-6 w-6 place-items-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-200"
                            :aria-label="tabActionLabel(tab)"
                            :title="tabActionLabel(tab)"
                            @click="openDeleteDialog(tab)"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 5 10 10M15 5 5 15"/></svg>
                        </button>
                    </div>
                        </nav>
                    </div>

                    <div :id="`advertising-goals-panel-${activeTab}`" class="mt-6" role="tabpanel" :aria-labelledby="`advertising-goals-tab-${activeTab}`">
                <form
                    v-if="activeTab === 'overall' && !goalPage.configuration.configured && canManage"
                    class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
                    @submit.prevent="submitOverall"
                >
                    <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">总目标 · 当前店铺独立配置</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">配置飞书表格</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">请填写 App Token、Table ID 和 View ID。三项均为必填，配置值将加密保存且不会在页面回显。</p>
                    </header>

                    <div class="space-y-5 px-5 py-6 sm:px-7">
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">App Token</span>
                            <input v-model="overallForm.feishu_app_token" required type="password" autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-mono text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="输入 App Token" />
                            <span v-if="overallForm.errors.feishu_app_token" class="mt-1.5 block text-xs text-rose-600">{{ overallForm.errors.feishu_app_token }}</span>
                        </label>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-800">Table ID</span>
                                <input v-model="overallForm.feishu_table_id" required autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-mono text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="输入 Table ID" />
                                <span v-if="overallForm.errors.feishu_table_id" class="mt-1.5 block text-xs text-rose-600">{{ overallForm.errors.feishu_table_id }}</span>
                            </label>
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-800">View ID</span>
                                <input v-model="overallForm.feishu_view_id" required autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-mono text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="输入 View ID" />
                                <span v-if="overallForm.errors.feishu_view_id" class="mt-1.5 block text-xs text-rose-600">{{ overallForm.errors.feishu_view_id }}</span>
                            </label>
                        </div>
                    </div>

                    <footer class="flex justify-end border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-7">
                        <button :disabled="overallForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">{{ overallForm.processing ? '保存中…' : '确认配置' }}</button>
                    </footer>
                </form>
                <section v-else-if="activeTab === 'overall' && !goalPage.configuration.configured" class="rounded-2xl border border-slate-200 bg-white px-6 py-14 text-center shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">请配置飞书表格</h2>
                    <p class="mt-2 text-sm text-slate-500">当前店铺尚未完整配置广告目标多维表格，请联系店铺管理员完成配置。</p>
                </section>
                <section v-else-if="activeTab === 'overall'" aria-label="总目标内容">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :class="{ 'animate-pulse opacity-60': metricsLoading }">
                        <article
                            v-for="card in goalPage.metrics.cards"
                            :key="card.key"
                            class="relative min-h-48 overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md"
                        >
                            <span class="absolute inset-x-0 top-0 h-1" :class="metricToneClasses[card.tone].accent" />
                            <div class="flex items-start justify-between gap-4">
                                <p class="pt-1 text-sm font-semibold text-slate-500">{{ card.label }}</p>
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl ring-1" :class="metricToneClasses[card.tone].badge">
                                    <svg v-if="card.key === 'daily_sales'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
                                    <svg v-else-if="card.key === 'refunds'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 7H5v-4M5.4 7A8 8 0 1 1 4 14"/></svg>
                                    <svg v-else-if="card.key === 'monthly_sales'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 19V9m7 10V5m7 14v-7M3 19h18"/></svg>
                                    <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m8.5 15.5 7-7M9 9h.01M15 15h.01"/></svg>
                                </span>
                            </div>
                            <p class="mt-4 whitespace-nowrap text-[1.55rem] font-semibold tracking-tight 2xl:text-[1.9rem]" :class="metricToneClasses[card.tone].value">
                                {{ formatMetricValue(card) }}
                            </p>
                            <div class="absolute inset-x-5 bottom-4 border-t border-slate-100 pt-3">
                                <p class="truncate text-xs text-slate-400" :title="`飞书字段：${metricSourceLabel(card)}`">飞书字段 · {{ metricSourceLabel(card) }}</p>
                            </div>
                        </article>
                    </div>

                    <div v-if="goalPage.metrics.message" class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                        <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
                        <p>{{ goalPage.metrics.message }}</p>
                    </div>

                    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" :class="{ 'animate-pulse opacity-60': metricsLoading }" aria-labelledby="project-sales-title">
                        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-5 sm:px-6">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">飞书最新有效记录</p>
                                <h2 id="project-sales-title" class="mt-1.5 text-lg font-semibold text-slate-950">{{ goalPage.metrics.project_sales.title }}</h2>
                            </div>
                            <span v-if="goalPage.metrics.project_sales.available" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">直接读取该行数据</span>
                        </header>

                        <div v-if="goalPage.metrics.project_sales.available" class="border-t border-slate-100 px-5 py-6 sm:px-6">
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <article
                                    v-for="(product, index) in goalPage.metrics.project_sales.products"
                                    :key="product.key"
                                    class="rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-100"
                                    :title="`飞书字段：${product.source_field}`"
                                >
                                    <div class="flex items-center gap-2 text-sm font-semibold text-slate-500">
                                        <span class="h-2 w-2 rounded-full" :class="projectToneClasses[index % projectToneClasses.length].dot" />
                                        {{ product.label }}
                                    </div>
                                    <div class="mt-5 flex items-end justify-between gap-3">
                                        <strong class="text-2xl font-semibold tracking-tight" :class="projectToneClasses[index % projectToneClasses.length].surface.split(' ')[1]">{{ formatCount(product.value) }}</strong>
                                        <span class="pb-0.5 text-xs font-medium text-slate-400">{{ product.unit }}</span>
                                    </div>
                                </article>
                            </div>

                            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                <article
                                    v-for="(item, index) in goalPage.metrics.project_sales.site_metrics"
                                    :key="item.key"
                                    class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3.5"
                                    :title="`飞书字段：${item.source_field}`"
                                >
                                    <span class="text-sm font-semibold text-slate-500">{{ item.label }}</span>
                                    <strong class="text-lg font-semibold" :class="projectToneClasses[index % projectToneClasses.length].surface.split(' ')[1]">{{ formatCount(item.value) }}</strong>
                                </article>
                            </div>
                        </div>

                        <div v-else class="border-t border-slate-100 px-6 py-12 text-center">
                            <div class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg>
                            </div>
                            <p class="mt-3 text-sm font-semibold text-slate-700">{{ goalPage.metrics.project_sales.message }}</p>
                        </div>
                    </section>

                    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" :class="{ 'animate-pulse opacity-60': metricsLoading }" aria-labelledby="channel-performance-title">
                        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-5 sm:px-6">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">已同步渠道归因数据</p>
                                <h2 id="channel-performance-title" class="mt-1.5 text-lg font-semibold text-slate-950">{{ goalPage.metrics.channel_performance.title }}</h2>
                            </div>
                            <span v-if="goalPage.metrics.channel_performance.available" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">读取数据库同步记录</span>
                        </header>

                        <div v-if="goalPage.metrics.channel_performance.available" class="border-t border-slate-100 px-5 py-6 sm:px-6">
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                                <article
                                    v-for="channel in goalPage.metrics.channel_performance.channels"
                                    :key="channel.key"
                                    class="rounded-xl border border-slate-200 border-t-2 bg-slate-50/70 p-4"
                                    :class="channelAccentClasses[channel.key]"
                                >
                                    <h3 class="text-sm font-semibold" :class="channelLabelClasses[channel.key]">{{ channel.label }}</h3>
                                    <dl class="mt-5 space-y-4">
                                        <div :title="`飞书字段：${channel.source_fields.spend}`">
                                            <dt class="text-xs font-medium text-slate-400">花费</dt>
                                            <dd class="mt-1 whitespace-nowrap text-base font-semibold tabular-nums text-slate-800">{{ formatChannelCurrency(channel.spend) }}</dd>
                                        </div>
                                        <div :title="`飞书字段：${channel.source_fields.sales}`">
                                            <dt class="text-xs font-medium text-slate-400">销售额</dt>
                                            <dd class="mt-1 whitespace-nowrap text-base font-semibold tabular-nums text-slate-800">{{ formatChannelCurrency(channel.sales) }}</dd>
                                        </div>
                                        <div :title="`飞书字段：${channel.source_fields.roi}`">
                                            <dt class="text-xs font-medium text-slate-400">ROI</dt>
                                            <dd class="mt-1 text-lg font-semibold tabular-nums" :class="roiValueClass(channel.roi)">{{ formatRoi(channel.roi) }}</dd>
                                        </div>
                                    </dl>
                                </article>
                            </div>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <article
                                    v-for="item in goalPage.metrics.channel_performance.summary"
                                    :key="item.key"
                                    class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-4 py-3.5"
                                    :title="`飞书字段：${item.source_field}`"
                                >
                                    <span class="text-sm font-semibold text-slate-500">{{ item.label }}</span>
                                    <strong class="whitespace-nowrap text-lg font-semibold tabular-nums" :class="summaryToneClasses[item.tone]">{{ formatChannelSummary(item) }}</strong>
                                </article>
                            </div>
                        </div>

                        <div v-else class="border-t border-slate-100 px-6 py-12 text-center">
                            <div class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9m4 10V5m4 14v-7m4 7V3m4 16v-9"/></svg>
                            </div>
                            <p class="mt-3 text-sm font-semibold text-slate-700">{{ goalPage.metrics.channel_performance.message }}</p>
                        </div>
                    </section>

                    <GoalProgressCharts
                        :progress="goalPage.metrics.progress"
                        :currency="goalPage.metrics.currency"
                        :loading="metricsLoading"
                    />
                </section>
                <PersonalFacebookGoalTemplate
                    v-else-if="activeTabDefinition?.type === 'personal_facebook'"
                    :summary="goalPage.template_data.personal_facebook"
                    :loading="metricsLoading"
                />
                <GoogleAdsGoalTemplate v-else-if="activeTabDefinition?.type === 'google_ads'" />
                    </div>
                </div>

                <div v-if="regionRefreshing" class="absolute inset-0 z-20 flex items-start justify-center bg-slate-50/65 pt-20 backdrop-blur-[1px]" role="status" aria-live="polite">
                    <div class="inline-flex items-center gap-3 rounded-2xl border border-emerald-100 bg-white px-5 py-3.5 text-sm font-semibold text-slate-700 shadow-lg">
                        <svg class="h-5 w-5 animate-spin text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6v5h-5M4 18v-5h5"/><path d="M18.4 9A7 7 0 0 0 6.2 6.2L4 8m16 8-2.2 1.8A7 7 0 0 1 5.6 15"/></svg>
                        正在同步当前目标数据…
                    </div>
                </div>
            </section>
        </div>

        <div v-if="periodDialogOpen" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="presentation" @click.self="closePeriodDialog" @keydown.esc="closePeriodDialog">
            <section class="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="period-dialog-title">
                <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">数据筛选</p>
                        <h2 id="period-dialog-title" class="mt-1.5 text-xl font-semibold text-slate-950">选择统计时间</h2>
                        <p class="mt-1 text-sm text-slate-500">时间以 {{ goalPage.period.timezone }} 为准。</p>
                    </div>
                    <button type="button" :disabled="periodApplying" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50" aria-label="关闭弹窗" @click="closePeriodDialog">×</button>
                </header>

                <div class="space-y-5 px-5 py-6 sm:px-7">
                    <div class="grid grid-cols-2 rounded-xl bg-slate-100 p-1" role="radiogroup" aria-label="时间筛选方式">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="periodMode === 'month'"
                            class="rounded-lg px-3 py-2.5 text-sm font-semibold transition"
                            :class="periodMode === 'month' ? 'bg-white text-slate-950 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800'"
                            @click="periodMode = 'month'; periodError = ''"
                        >
                            按月份
                        </button>
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="periodMode === 'range'"
                            class="rounded-lg px-3 py-2.5 text-sm font-semibold transition"
                            :class="periodMode === 'range' ? 'bg-white text-slate-950 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800'"
                            @click="periodMode = 'range'; periodError = ''"
                        >
                            按日期范围
                        </button>
                    </div>

                    <label v-if="periodMode === 'month'" class="block">
                        <span class="text-sm font-semibold text-slate-800">月份</span>
                        <input v-model="periodMonth" type="month" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" @input="periodError = ''" />
                    </label>

                    <div v-else class="grid gap-4 sm:grid-cols-[1fr_auto_1fr] sm:items-end">
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">开始日期</span>
                            <input v-model="periodFrom" type="date" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" @input="periodError = ''" />
                        </label>
                        <span class="hidden pb-3 text-sm text-slate-400 sm:block">至</span>
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">结束日期</span>
                            <input v-model="periodTo" type="date" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" @input="periodError = ''" />
                        </label>
                    </div>

                    <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3 text-sm leading-6 text-emerald-900">
                        <span class="font-semibold">筛选说明：</span>应用后页面仅使用所选时间范围内的数据，日期范围最多 366 天。
                    </div>
                    <p v-if="periodError" class="text-sm font-medium text-rose-600" role="alert">{{ periodError }}</p>
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-7">
                    <button type="button" :disabled="periodApplying" class="text-sm font-semibold text-slate-500 transition hover:text-emerald-700 disabled:opacity-50" @click="resetPeriod">恢复本月</button>
                    <div class="flex gap-3">
                        <button type="button" :disabled="periodApplying" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50" @click="closePeriodDialog">取消</button>
                        <button type="button" :disabled="periodApplying" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" @click="applyPeriod">{{ periodApplying ? '应用中…' : '应用' }}</button>
                    </div>
                </footer>
            </section>
        </div>

        <div v-if="addDialogOpen" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="presentation" @click.self="closeAddDialog" @keydown.esc="closeAddDialog">
            <section class="max-h-[calc(100dvh-2rem)] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="add-goal-title">
                <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div>
                        <h2 id="add-goal-title" class="text-xl font-semibold text-slate-950">添加目标页签</h2>
                        <p class="mt-1 text-sm text-slate-500">保存后将添加到“总目标”后面，飞书数据每天自动同步一次。</p>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="关闭弹窗" @click="closeAddDialog">×</button>
                </header>

                <form class="space-y-6 px-5 py-6 sm:px-7" @submit.prevent="submitAdd">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">名字</span>
                        <input ref="nameInput" v-model="addForm.name" required maxlength="100" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="例如：黄智诚" />
                        <span v-if="addForm.errors.name" class="mt-1.5 block text-xs text-rose-600">{{ addForm.errors.name }}</span>
                    </label>

                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">目标类型</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <label v-for="option in goalPage.type_options" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="addForm.type === option.value ? 'border-emerald-500 bg-emerald-50/70 ring-2 ring-emerald-100' : 'border-slate-200 hover:border-slate-300'">
                                <input v-model="addForm.type" :value="option.value" type="radio" name="goal_type" required class="mt-0.5 h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                <span class="text-sm font-semibold text-slate-800">{{ option.label }}</span>
                            </label>
                        </div>
                        <span v-if="addForm.errors.type" class="mt-1.5 block text-xs text-rose-600">{{ addForm.errors.type }}</span>
                    </fieldset>

                    <section class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                        <div class="mb-5">
                            <h3 class="text-sm font-semibold text-slate-900">飞书多维表格</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">三项均为必填；配置值会加密保存，页面不会再回显。</p>
                        </div>
                        <div class="grid gap-4">
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-700">App Token</span>
                                <input v-model="addForm.feishu_app_token" required type="password" autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-mono text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="输入 App Token" />
                                <span v-if="addForm.errors.feishu_app_token" class="mt-1.5 block text-xs text-rose-600">{{ addForm.errors.feishu_app_token }}</span>
                            </label>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Table ID</span>
                                    <input v-model="addForm.feishu_table_id" required autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-mono text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="输入 Table ID" />
                                    <span v-if="addForm.errors.feishu_table_id" class="mt-1.5 block text-xs text-rose-600">{{ addForm.errors.feishu_table_id }}</span>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">View ID</span>
                                    <input v-model="addForm.feishu_view_id" required autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 font-mono text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="输入 View ID" />
                                    <span v-if="addForm.errors.feishu_view_id" class="mt-1.5 block text-xs text-rose-600">{{ addForm.errors.feishu_view_id }}</span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <footer class="flex flex-wrap justify-end gap-3 border-t border-slate-100 pt-5">
                        <button type="button" :disabled="addForm.processing" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50" @click="closeAddDialog">取消</button>
                        <button :disabled="addForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">{{ addForm.processing ? '添加中…' : '确认添加' }}</button>
                    </footer>
                </form>
            </section>
        </div>

        <div v-if="deleteTarget" class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" @click.self="closeDeleteDialog" @keydown.esc="closeDeleteDialog">
            <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="alertdialog" aria-modal="true" aria-labelledby="delete-goal-title">
                <div class="grid h-11 w-11 place-items-center rounded-xl bg-rose-50 text-rose-600 ring-1 ring-rose-100">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v5M14 11v5"/></svg>
                </div>
                <h2 id="delete-goal-title" class="mt-4 text-lg font-semibold text-slate-950">{{ deleteTarget.kind === 'overall' ? '清空总目标？' : `删除“${deleteTarget.label}”？` }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ deleteTarget.kind === 'overall'
                    ? '确认后将清除总目标的飞书配置、已同步数据和每日同步关联；总目标页签会保留并重新显示配置表单。'
                    : '确认后将永久删除该页签的飞书配置、已同步数据和每日同步关联，此操作无法撤销。' }}</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" :disabled="deleteProcessing" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50" @click="closeDeleteDialog">取消</button>
                    <button type="button" :disabled="deleteProcessing" class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50" @click="confirmDelete">{{ deleteProcessing ? (deleteTarget.kind === 'overall' ? '清除中…' : '删除中…') : (deleteTarget.kind === 'overall' ? '确认清除' : '确认删除') }}</button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
