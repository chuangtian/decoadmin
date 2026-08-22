<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CampaignEfficiencyBubbleChart from '../../Components/CampaignThemes/CampaignEfficiencyBubbleChart.vue';
import CampaignCalendarPanel from '../../Components/CampaignThemes/CampaignCalendarPanel.vue';
import CampaignPerformanceTrendChart from '../../Components/CampaignThemes/CampaignPerformanceTrendChart.vue';
import CampaignPlanningPanel from '../../Components/CampaignThemes/CampaignPlanningPanel.vue';
import CampaignQualityRanking from '../../Components/CampaignThemes/CampaignQualityRanking.vue';
import CampaignReviewPanel from '../../Components/CampaignThemes/CampaignReviewPanel.vue';
import type { CampaignThemePlanning } from '../../Components/CampaignThemes/planningTypes';
import type { CampaignThemeReview } from '../../Components/CampaignThemes/reviewTypes';
import DualMetricTrendChart from '../../Components/CampaignThemes/DualMetricTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type TabKey = 'overview' | 'review' | 'planning' | 'calendar';
type TimelineStatus = 'upcoming' | 'in_progress' | 'completed';

interface CampaignTimelineItem {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string | null;
    month: string;
    status: TimelineStatus;
    duration_days: number | null;
    gmv: number;
    roi: number | null;
}

interface CampaignTrendItem {
    id: number;
    name: string;
    starts_on: string;
    roi: number | null;
    conversion_rate_percent: number | null;
    daily_average_sales: number | null;
    daily_average_store_visits: number | null;
}

type CampaignJudgment = 'reusable' | 'scalable' | 'underperforming' | 'insufficient_data';

interface CampaignPerformanceItem {
    id: number;
    name: string;
    starts_on: string | null;
    ends_on: string | null;
    gmv: number;
    ad_spend: number | null;
    roi: number | null;
    conversion_rate_percent: number | null;
    store_visits: number | null;
    judgment: CampaignJudgment;
}

interface CampaignThemeOverview {
    schema: 'campaign-theme-overview-v1';
    metrics: {
        total_gmv: number;
        total_ad_spend: number;
        total_orders: number;
        overall_roi: number;
        average_cvr_percent: number;
    };
    coverage: {
        completed_campaigns: number;
        missing_conversion_rate: number;
    };
    performance_rules: {
        break_even_roi: number;
        reusable_roi: number;
    };
    timeline: CampaignTimelineItem[];
    trends: CampaignTrendItem[];
    performance: CampaignPerformanceItem[];
}

interface CampaignRefreshStatus {
    can_run: boolean;
    status: 'idle' | 'queued' | 'running' | 'completed' | 'failed';
    message: string | null;
    requested_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    last_synced_at: string | null;
}

const props = defineProps<{
    store: {
        id: number;
        name: string;
        currency: string;
        timezone: string;
    };
    activeTab: TabKey;
    overview: CampaignThemeOverview;
    planning: CampaignThemePlanning | null;
    review: CampaignThemeReview | null;
    reviewDetailOpen: boolean;
    refreshStatus: CampaignRefreshStatus;
}>();

const tabs: Array<{ key: TabKey; label: string; icon: string }> = [
    { key: 'overview', label: '总览', icon: '▥' },
    { key: 'review', label: '复盘分析', icon: '✎' },
    { key: 'planning', label: '活动策划', icon: '▤' },
    { key: 'calendar', label: '活动日历', icon: '17' },
];

const activeTab = ref<TabKey>(props.activeTab);
const timelineScroller = ref<HTMLElement | null>(null);
const refreshForm = useForm({});
let refreshPoller: ReturnType<typeof setInterval> | null = null;
let reportPoller: ReturnType<typeof setInterval> | null = null;

const refreshIsActive = computed(() => refreshForm.processing || ['queued', 'running'].includes(props.refreshStatus.status));
const refreshButtonTitle = computed(() => props.refreshStatus.message
    ?? (props.refreshStatus.last_synced_at ? `最近更新：${props.refreshStatus.last_synced_at}` : '从飞书更新活动数据'));

const stopRefreshPolling = () => {
    if (refreshPoller !== null) {
        clearInterval(refreshPoller);
        refreshPoller = null;
    }
};

const startRefreshPolling = () => {
    if (refreshPoller !== null) return;

    refreshPoller = setInterval(() => {
        router.reload({
            only: ['overview', 'planning', 'refreshStatus'],
        });
    }, 2500);
};

const refreshFromFeishu = () => {
    refreshForm.post('/campaign-themes/refresh', {
        preserveScroll: true,
        onSuccess: () => {
            if (['queued', 'running'].includes(props.refreshStatus.status)) startRefreshPolling();
        },
    });
};

const reviewReportsPending = computed(() => Boolean(props.review && [
    props.review.daily_sales,
    props.review.traffic_cost_trend,
    props.review.funnel,
    props.review.channel_performance,
    props.review.model_sales,
].some((dataset) => dataset?.pending)));

const stopReportPolling = () => {
    if (reportPoller !== null) {
        clearInterval(reportPoller);
        reportPoller = null;
    }
};

const startReportPolling = () => {
    if (reportPoller !== null) return;
    reportPoller = setInterval(() => router.reload({ only: ['review'] }), 3000);
};

const switchTab = (tab: TabKey) => {
    if (tab === activeTab.value) return;

    if (tab === 'review' || tab === 'planning' || tab === 'calendar') {
        router.get('/campaign-themes', { tab }, {
            preserveScroll: true,
            replace: true,
        });
        return;
    }

    if (tab === 'overview' && props.activeTab !== 'overview') {
        router.get('/campaign-themes', {}, {
            preserveScroll: true,
            replace: true,
        });
        return;
    }

    activeTab.value = tab;
};

watch(() => props.refreshStatus.status, (status) => {
    if (['queued', 'running'].includes(status)) {
        startRefreshPolling();
    } else {
        stopRefreshPolling();
    }
});

watch(() => props.activeTab, (tab) => {
    activeTab.value = tab;
});

watch(reviewReportsPending, (pending) => {
    pending ? startReportPolling() : stopReportPolling();
});

onMounted(() => {
    if (['queued', 'running'].includes(props.refreshStatus.status)) startRefreshPolling();
    if (reviewReportsPending.value) startReportPolling();
});

onBeforeUnmount(() => {
    stopRefreshPolling();
    stopReportPolling();
});

const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.store.currency || 'USD',
    currencyDisplay: 'narrowSymbol',
    notation: 'compact',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(value);

const integer = (value: number) => new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0,
}).format(value);

const timelineMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.store.currency || 'USD',
    currencyDisplay: 'narrowSymbol',
    notation: 'compact',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
}).format(value);

const shortDate = (value: string | null) => value ? `${value.slice(5, 7)}/${value.slice(8, 10)}` : '--';

const timelineItems = computed(() => [...props.overview.timeline]
    .sort((left, right) => {
        const dateOrder = left.starts_on.localeCompare(right.starts_on);
        const idOrder = left.id - right.id;
        return -(dateOrder || idOrder);
    })
    .map((activity, index, activities) => ({
        ...activity,
        showMonth: index === 0 || activities[index - 1].month !== activity.month,
    })));

const roiTrendPoints = computed(() => props.overview.trends.map((activity) => ({
    id: activity.id,
    name: activity.name,
    startsOn: activity.starts_on,
    lineValue: activity.roi,
    barValue: activity.daily_average_sales,
})));

const cvrTrendPoints = computed(() => props.overview.trends.map((activity) => ({
    id: activity.id,
    name: activity.name,
    startsOn: activity.starts_on,
    lineValue: activity.conversion_rate_percent,
    barValue: activity.daily_average_store_visits,
})));

const statusMeta: Record<TimelineStatus, { label: string; badge: string; dot: string }> = {
    upcoming: {
        label: '未开始',
        badge: 'bg-amber-50 text-amber-700 ring-amber-200',
        dot: 'border-amber-500 bg-amber-100',
    },
    in_progress: {
        label: '进行中',
        badge: 'bg-blue-50 text-blue-700 ring-blue-200',
        dot: 'border-blue-500 bg-blue-100',
    },
    completed: {
        label: '已完成',
        badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        dot: 'border-emerald-500 bg-emerald-100',
    },
};

const roiClass = (roi: number | null) => {
    if (roi === null) return 'text-slate-400';
    if (roi >= 7) return 'text-emerald-600';
    if (roi >= 5) return 'text-orange-500';
    return 'text-rose-500';
};

const cards = computed(() => [
    {
        label: '总 GMV',
        value: money(props.overview.metrics.total_gmv),
        icon: '$',
        accent: 'border-t-emerald-500',
        iconClass: 'bg-emerald-50 text-emerald-700',
        valueClass: 'text-emerald-700',
    },
    {
        label: '广告花费',
        value: money(props.overview.metrics.total_ad_spend),
        icon: '◖',
        accent: 'border-t-blue-500',
        iconClass: 'bg-blue-50 text-blue-700',
        valueClass: 'text-blue-700',
    },
    {
        label: '订单',
        value: integer(props.overview.metrics.total_orders),
        icon: '⌑',
        accent: 'border-t-orange-500',
        iconClass: 'bg-orange-50 text-orange-700',
        valueClass: 'text-orange-600',
    },
    {
        label: '整体 ROI',
        value: props.overview.metrics.overall_roi.toFixed(2),
        icon: '↗',
        accent: 'border-t-teal-500',
        iconClass: 'bg-teal-50 text-teal-700',
        valueClass: 'text-teal-700',
    },
    {
        label: '平均 CVR',
        value: `${props.overview.metrics.average_cvr_percent.toFixed(3)}%`,
        icon: '◎',
        accent: 'border-t-violet-500',
        iconClass: 'bg-violet-50 text-violet-700',
        valueClass: 'text-violet-700',
    },
]);
</script>

<template>
    <Head title="活动主题" />

    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '活动主题' }]">
        <main class="mx-auto w-full min-w-0 max-w-[1680px] overflow-x-clip">
            <header>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">{{ store.name }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">活动主题</h1>
                <p class="mt-2 text-sm text-slate-500 sm:text-base">Campaign Themes · 活动主题管理与复盘分析</p>
            </header>

            <nav class="mt-8 min-w-0 border-b border-slate-200" aria-label="活动主题功能">
                <div class="flex min-w-0 flex-col gap-3 pb-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex min-w-0 gap-2 overflow-x-auto" role="tablist">
                        <button
                            v-for="tab in tabs"
                            :id="`campaign-tab-${tab.key}`"
                            :key="tab.key"
                            type="button"
                            role="tab"
                            class="inline-flex shrink-0 items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
                            :class="activeTab === tab.key
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-800 shadow-sm ring-2 ring-emerald-100'
                                : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800'"
                            :aria-selected="activeTab === tab.key"
                            :aria-controls="`campaign-panel-${tab.key}`"
                            :tabindex="activeTab === tab.key ? 0 : -1"
                            @click="switchTab(tab.key)"
                        >
                            <span
                                class="grid h-6 w-6 place-items-center rounded-lg text-xs"
                                :class="activeTab === tab.key ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500'"
                                aria-hidden="true"
                            >{{ tab.icon }}</span>
                            {{ tab.label }}
                        </button>
                    </div>

                    <button
                        v-if="refreshStatus.can_run"
                        type="button"
                        class="inline-flex shrink-0 items-center justify-center gap-2 self-end rounded-xl border border-emerald-200 bg-white px-4 py-2.5 text-sm font-semibold text-emerald-700 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-60 sm:self-auto"
                        :disabled="refreshIsActive"
                        :title="refreshButtonTitle"
                        @click="refreshFromFeishu"
                    >
                        <svg
                            class="h-4 w-4"
                            :class="refreshIsActive ? 'animate-spin' : ''"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5" />
                            <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5" />
                        </svg>
                        {{ refreshIsActive ? '正在更新…' : '刷新飞书数据' }}
                    </button>
                </div>
            </nav>

            <section
                v-if="activeTab === 'overview'"
                id="campaign-panel-overview"
                class="mt-6 min-w-0"
                role="tabpanel"
                aria-labelledby="campaign-tab-overview"
            >
                <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <article
                        v-for="card in cards"
                        :key="card.label"
                        class="min-h-40 min-w-0 rounded-2xl border border-slate-200 border-t-[3px] bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                        :class="card.accent"
                    >
                        <div class="flex items-center gap-3">
                            <span class="grid h-10 w-10 place-items-center rounded-xl font-semibold" :class="card.iconClass" aria-hidden="true">{{ card.icon }}</span>
                            <span class="min-w-0 truncate text-sm font-medium text-slate-500">{{ card.label }}</span>
                        </div>
                        <strong class="mt-7 block text-3xl font-semibold tracking-tight tabular-nums" :class="card.valueClass">{{ card.value }}</strong>
                    </article>
                </div>

                <section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">活动节奏时间线</h2>
                            <p class="mt-1 text-xs text-slate-400">左右滑动查看全部活动</p>
                        </div>
                        <span class="inline-flex w-fit items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-sm font-semibold text-slate-600">
                            <span aria-hidden="true">↓</span>
                            时间倒序
                        </span>
                    </div>

                    <div
                        v-if="timelineItems.length"
                        ref="timelineScroller"
                        class="timeline-scrollbar touch-pan-x snap-x snap-mandatory overflow-x-auto overscroll-x-contain scroll-smooth px-5 py-6 sm:px-6"
                        aria-label="活动时间线，可左右滑动"
                        tabindex="0"
                    >
                        <div class="flex min-w-max">
                            <div
                                v-for="activity in timelineItems"
                                :key="activity.id"
                                class="w-64 shrink-0 snap-start sm:w-72"
                            >
                                <div class="h-8 px-1">
                                    <span v-if="activity.showMonth" class="text-base font-semibold tabular-nums text-slate-800">{{ activity.month }}</span>
                                </div>
                                <div class="flex h-5 items-center">
                                    <span class="h-3.5 w-3.5 shrink-0 rounded-full border-[3px]" :class="statusMeta[activity.status].dot" />
                                    <span class="h-px flex-1 bg-slate-300" />
                                </div>

                                <article class="mr-4 mt-3 flex min-h-64 flex-col rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                    <h3 class="truncate text-base font-semibold text-slate-900" :title="activity.name">{{ activity.name }}</h3>
                                    <p class="mt-2 text-sm tabular-nums text-slate-500">{{ shortDate(activity.starts_on) }}–{{ shortDate(activity.ends_on) }}</p>
                                    <span class="mt-4 w-fit rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusMeta[activity.status].badge">
                                        {{ statusMeta[activity.status].label }}
                                    </span>
                                    <p class="mt-5 text-sm tabular-nums text-slate-500">
                                        {{ activity.duration_days === null ? '--' : `${activity.duration_days}天` }}
                                        <span class="mx-2 text-slate-300">·</span>
                                        {{ timelineMoney(activity.gmv) }}
                                    </p>
                                    <div class="mt-5 border-t border-slate-200 pt-4">
                                        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ROI</p>
                                        <strong class="mt-2 block text-2xl font-semibold tabular-nums" :class="roiClass(activity.roi)">
                                            {{ activity.roi === null ? '--' : activity.roi.toFixed(2) }}
                                        </strong>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </div>
                    <p v-else class="px-6 py-12 text-center text-sm text-slate-400">暂无活动时间线数据。</p>
                </section>

                <div class="mt-6 grid min-w-0 gap-6 2xl:grid-cols-2">
                    <DualMetricTrendChart
                        title="ROI 与日均销售额趋势"
                        line-label="ROI"
                        bar-label="日均销售额"
                        line-format="decimal"
                        bar-format="currency"
                        :currency="store.currency"
                        line-color="#16a36d"
                        bar-color="#15805f"
                        :points="roiTrendPoints"
                    />
                    <DualMetricTrendChart
                        title="CVR 转化率与日均店铺访问"
                        line-label="CVR 转化率"
                        bar-label="日均店铺访问"
                        line-format="percent"
                        bar-format="number"
                        :currency="store.currency"
                        line-color="#f97316"
                        bar-color="#c65d18"
                        :points="cvrTrendPoints"
                    />
                </div>

                <div class="mt-6 space-y-6">
                    <CampaignEfficiencyBubbleChart
                        :currency="store.currency"
                        :points="overview.performance"
                        :break-even-roi="overview.performance_rules.break_even_roi"
                    />
                    <CampaignPerformanceTrendChart
                        :currency="store.currency"
                        :points="overview.performance"
                    />
                    <CampaignQualityRanking
                        :currency="store.currency"
                        :activities="overview.performance"
                    />
                </div>
            </section>

            <section
                v-else-if="activeTab === 'review'"
                id="campaign-panel-review"
                class="mt-6"
                role="tabpanel"
                aria-labelledby="campaign-tab-review"
            >
                <CampaignReviewPanel v-if="review" :review="review" :currency="store.currency" :initial-detail-open="reviewDetailOpen" />
                <div v-else class="grid min-h-80 place-items-center rounded-3xl border border-slate-200 bg-white text-sm text-slate-400 shadow-sm">正在载入复盘分析…</div>
            </section>

            <section
                v-else-if="activeTab === 'planning'"
                id="campaign-panel-planning"
                class="mt-6 min-w-0"
                role="tabpanel"
                aria-labelledby="campaign-tab-planning"
            >
                <CampaignPlanningPanel v-if="planning" :planning="planning" :currency="store.currency" />
                <div v-else class="grid min-h-80 place-items-center rounded-3xl border border-slate-200 bg-white text-sm text-slate-400 shadow-sm">正在载入活动策划…</div>
                <CampaignReviewPanel
                    v-if="review && reviewDetailOpen"
                    :review="review"
                    :currency="store.currency"
                    :initial-detail-open="true"
                    detail-only
                />
            </section>

            <section
                v-else-if="activeTab === 'calendar'"
                id="campaign-panel-calendar"
                class="mt-6 min-w-0"
                role="tabpanel"
                aria-labelledby="campaign-tab-calendar"
            >
                <CampaignCalendarPanel v-if="planning" :planning="planning" />
                <div v-else class="grid min-h-80 place-items-center rounded-3xl border border-slate-200 bg-white text-sm text-slate-400 shadow-sm">正在载入活动日历…</div>
                <CampaignReviewPanel
                    v-if="review && reviewDetailOpen"
                    :review="review"
                    :currency="store.currency"
                    :initial-detail-open="true"
                    detail-only
                />
            </section>

            <section
                v-else
                :id="`campaign-panel-${activeTab}`"
                class="min-h-80"
                role="tabpanel"
                :aria-labelledby="`campaign-tab-${activeTab}`"
            />
        </main>
    </AppLayout>
</template>

<style scoped>
.timeline-scrollbar {
    scrollbar-color: rgb(148 163 184 / 0.7) transparent;
    scrollbar-width: thin;
}

.timeline-scrollbar::-webkit-scrollbar {
    height: 8px;
}

.timeline-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.timeline-scrollbar::-webkit-scrollbar-thumb {
    border: 2px solid white;
    border-radius: 999px;
    background: rgb(148 163 184 / 0.65);
}
</style>
