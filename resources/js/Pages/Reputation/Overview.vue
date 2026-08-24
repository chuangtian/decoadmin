<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type SyncState = { uuid: string; status: string; progress_percent: number; processed_rows: number; message: string | null } | null;
type RecordItem = {
    uuid: string; source: string; url: string | null; title: string | null; content: string | null;
    rating: number | null; week_number: number | null; published_at: string | null; model_name: string | null;
    processing_status: string | null; response_note: string | null; metrics: Record<string, string | number>; is_negative: boolean;
    origin: string; source_sheets: string[]; order_reference_masked: string | null;
};
type Paginator<T> = { data: T[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number; from: number | null; to: number | null };
type ComparisonMetric = { current: number; previous: number; difference: number; change_percent: number | null };
type TrendRow = {
    date: string; reviews: number; satisfied: number; negative: number; reddit: number; threads: number;
    star_1: number; star_2: number; star_3: number; star_4: number; star_5: number;
    reddit_views: number; reddit_upvotes: number; reddit_comments: number; reddit_spend: number;
    threads_likes: number; threads_replies: number; threads_reposts: number; threads_shares: number;
};

const props = defineProps<{
    store: { id: number; name: string; timezone: string };
    canSync: boolean;
    canManage: boolean;
    dashboard: {
        filters: { date_from: string; date_to: string; tab: string; source: string; rating: string; status: string; search: string; comparison: string; compare_date_from: string; compare_date_to: string };
        summary: {
            total: number; reviews: number; average_rating: number; satisfied_reviews: number; low_rating_reviews: number;
            neutral_reviews: number; positive_rate: number; negative_rate: number; pending_low_rating: number; negative_records: number; undated_records: number;
            reddit: { posts: number; views: number; upvotes: number; comments: number; spend: number };
            threads: { posts: number; likes: number; replies: number; reposts: number; shares: number; engagement: number };
        };
        comparison: null | { mode: string; date_from: string; date_to: string; metrics: Record<string, ComparisonMetric> };
        source_breakdown: Array<{ source: string; total: number; average_rating: number | null; negative_count: number }>;
        star_distribution: Array<{ rating: number; count: number; percent: number }>;
        trends: TrendRow[];
        models: Array<{ model: string; count: number; average_rating: number }>;
        goals: Array<{ metric: string; label: string; actual: number; target: number; completion_percent: number | null; gap: number | null; status: string }>;
        records: Paginator<RecordItem>;
        freshness: { last_synced_at: string | null; database_total: number; sync: SyncState };
        source_status: { configured: boolean; has_configuration: boolean; missing_fields: string[] };
    };
}>();

const sourceLabels: Record<string, string> = { trustpilot: 'Trustpilot', website: '官网评论', facebook: 'Facebook', reddit: 'Reddit', threads: 'Threads', multiple: '综合风险' };
const tabLabels = [{ key: 'targets', label: '目标看板' }, { key: 'reviews', label: '评论管理' }, { key: 'reddit', label: 'Reddit' }, { key: 'threads', label: 'Threads' }];
const filters = ref({ ...props.dashboard.filters });
const goalOpen = ref(false);
const mentionOpen = ref(false);
const followUpOpen = ref(false);
const selectedRecord = ref<RecordItem | null>(null);
const goalForm = useForm({
    month: props.dashboard.filters.date_to.slice(0, 7),
    targets: Object.fromEntries(props.dashboard.goals.map((goal) => [goal.metric, goal.target])) as Record<string, number>,
});
const mentionForm = useForm({
    source: 'trustpilot', content: '', title: '', rating: 5, published_at: props.dashboard.filters.date_to,
    week_number: null as number | null, model_name: '', order_reference: '', processing_status: '', response_note: '', url: '',
});
const followUpForm = useForm({ processing_status: '' as string, response_note: '' });
let pollTimer: number | null = null;

const sync = computed(() => props.dashboard.freshness.sync);
const syncing = computed(() => ['queued', 'running'].includes(sync.value?.status ?? ''));
const sourceReady = computed(() => props.dashboard.source_status.has_configuration);
const recentTrends = computed(() => props.dashboard.trends.slice(-14));
const trendMax = computed(() => Math.max(1, ...recentTrends.value.map((row) => row.reviews + row.reddit + row.threads)));
const satisfactionRate = computed(() => props.dashboard.summary.reviews > 0
    ? (props.dashboard.summary.satisfied_reviews / props.dashboard.summary.reviews) * 100 : 0);

const formatNumber = (value: number) => new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(value ?? 0);
const formatDate = (value: string | null, withTime = false) => value
    ? new Intl.DateTimeFormat('zh-CN', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(value))
    : '未标日期';
const contentPreview = (record: RecordItem) => record.title || record.content || '仅记录了指标数据';
const metricSummary = (record: RecordItem) => Object.entries(record.metrics || {}).slice(0, 4).map(([key, value]) => `${key}: ${value}`).join(' · ');
const comparisonMetric = (key: string) => props.dashboard.comparison?.metrics[key] ?? null;
const comparisonText = (key: string, digits = 0) => {
    const metric = comparisonMetric(key);
    if (!metric) return '未启用对比';
    const change = metric.change_percent === null ? '无可比基数' : `${metric.change_percent >= 0 ? '+' : ''}${metric.change_percent.toFixed(1)}%`;
    return `上期 ${metric.previous.toFixed(digits)} · ${change}`;
};
const goalStatusLabel = (status: string) => ({ achieved: '已达成', near: '接近目标', behind: '需追赶', risk: '高风险', not_configured: '未设置' }[status] ?? status);
const goalTrendMetric = (metric: string) => comparisonMetric({ satisfied_reviews: 'satisfied_reviews', reddit_views: 'reddit_views', reddit_comments: 'reddit_comments' }[metric] ?? '');
const sourceSheetText = (record: RecordItem) => record.origin === 'manual' ? '人工录入' : (record.source_sheets.join('、') || '当前项目同步');
const starPercent = (row: TrendRow, star: number) => row.reviews > 0 ? (row[`star_${star}` as keyof TrendRow] as number / row.reviews) * 100 : 0;

function applyFilters(extra: Record<string, string> = {}) {
    filters.value = { ...filters.value, ...extra };
    router.get('/reputation/overview', filters.value, { preserveState: true, preserveScroll: true, replace: true });
}

function requestSync() {
    if (!props.canSync || !sourceReady.value || syncing.value) return;
    router.post('/reputation/sync', {}, { preserveScroll: true });
}

async function pollSync() {
    if (!props.canSync || !sync.value?.uuid || !syncing.value) return;
    try {
        const response = await fetch(`/reputation/sync/${sync.value.uuid}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        if (['completed', 'failed'].includes(payload.data?.status)) {
            stopPolling();
            router.reload({ only: ['dashboard'] });
        }
    } catch {
        // A temporary polling failure should not interrupt the page.
    }
}

function startPolling() {
    stopPolling();
    if (syncing.value) pollTimer = window.setInterval(pollSync, 1800);
}

function stopPolling() {
    if (pollTimer !== null) window.clearInterval(pollTimer);
    pollTimer = null;
}

function saveGoals() {
    goalForm.put('/reputation/goals', { preserveScroll: true, onSuccess: () => { goalOpen.value = false; } });
}

function saveMention() {
    mentionForm.post('/reputation/mentions', {
        preserveScroll: true,
        onSuccess: () => {
            mentionOpen.value = false;
            mentionForm.reset();
            mentionForm.published_at = props.dashboard.filters.date_to;
            mentionForm.rating = 5;
            mentionForm.source = 'trustpilot';
        },
    });
}

function openFollowUp(record: RecordItem) {
    selectedRecord.value = record;
    followUpForm.processing_status = ['resolved', '已处理', '完成'].includes(record.processing_status ?? '') ? 'resolved' : (record.is_negative ? 'pending' : '');
    followUpForm.response_note = record.response_note ?? '';
    followUpOpen.value = true;
}

function saveFollowUp() {
    if (!selectedRecord.value) return;
    followUpForm.patch(`/reputation/mentions/${selectedRecord.value.uuid}`, {
        preserveScroll: true,
        onSuccess: () => { followUpOpen.value = false; selectedRecord.value = null; },
    });
}

watch(() => props.dashboard.filters, (value) => { filters.value = { ...value }; }, { deep: true });
watch(() => sync.value?.status, startPolling, { immediate: true });
onBeforeUnmount(stopPolling);
</script>

<template>
    <AppLayout>
        <Head title="舆情总览" />
        <div class="mx-auto max-w-[1680px] space-y-6 p-4 sm:p-6 lg:p-8">
            <section class="overflow-hidden rounded-3xl bg-slate-950 px-6 py-7 text-white shadow-xl shadow-slate-200/60 sm:px-8">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-300">
                            <span>舆情监控</span><span class="text-slate-600">/</span><span>{{ store.name }}</span>
                        </div>
                        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">舆情总览</h1>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">从当前项目已同步到数据库的数据汇总评论、Reddit 与 Threads 表现。页面只读取本项目数据库，不会实时请求外部平台。</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-300">
                            <div>数据库 {{ formatNumber(dashboard.freshness.database_total) }} 条</div>
                            <div class="mt-1 text-slate-500">{{ dashboard.freshness.last_synced_at ? `更新于 ${formatDate(dashboard.freshness.last_synced_at, true)}` : '尚未完成首次同步' }}</div>
                        </div>
                        <button v-if="canManage" type="button" class="rounded-2xl border border-white/20 bg-white/10 px-5 py-3 text-sm font-bold text-white transition hover:bg-white/15" @click="mentionOpen = true">人工录入评价</button>
                        <button v-if="canSync" type="button" class="rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!sourceReady || syncing" @click="requestSync">
                            {{ syncing ? `同步中 ${sync?.progress_percent ?? 0}%` : '同步当前数据' }}
                        </button>
                    </div>
                </div>
                <div v-if="!sourceReady" class="mt-6 rounded-2xl border border-amber-400/30 bg-amber-300/10 px-4 py-3 text-sm text-amber-100">当前店铺尚未配置舆情数据源，请先到店铺设置的飞书数据源中配置。</div>
                <div v-else-if="syncing" class="mt-6 h-1.5 overflow-hidden rounded-full bg-white/10"><div class="h-full rounded-full bg-emerald-400 transition-all" :style="{ width: `${sync?.progress_percent ?? 0}%` }" /></div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">综合评分</p><p class="mt-3 text-3xl font-bold text-amber-500">{{ dashboard.summary.reviews ? `${dashboard.summary.average_rating.toFixed(2)}★` : '—' }}</p><p class="mt-2 text-xs text-slate-500">{{ comparisonText('average_rating', 2) }}</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">正面占比</p><p class="mt-3 text-3xl font-bold text-emerald-600">{{ dashboard.summary.positive_rate.toFixed(1) }}%</p><p class="mt-2 text-xs text-slate-500">{{ formatNumber(dashboard.summary.satisfied_reviews) }} 条 4–5 星 · {{ comparisonText('positive_rate', 1) }}</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">负面占比</p><p class="mt-3 text-3xl font-bold text-rose-600">{{ dashboard.summary.negative_rate.toFixed(1) }}%</p><p class="mt-2 text-xs text-slate-500">{{ formatNumber(dashboard.summary.low_rating_reviews) }} 条 1–2 星 · {{ comparisonText('negative_rate', 1) }}</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">待跟进低分</p><p class="mt-3 text-3xl font-bold text-rose-600">{{ formatNumber(dashboard.summary.pending_low_rating) }}</p><p class="mt-2 text-xs text-slate-500">{{ comparisonText('pending_low_rating') }}</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">期间评价</p><p class="mt-3 text-3xl font-bold text-indigo-600">{{ formatNumber(dashboard.summary.reviews) }}</p><p class="mt-2 text-xs text-slate-500">{{ comparisonText('reviews') }} · 全部有日期记录 {{ formatNumber(dashboard.summary.total) }}</p></article>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-slate-100 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="tab in tabLabels" :key="tab.key" type="button" class="rounded-xl px-4 py-2.5 text-sm font-semibold transition" :class="filters.tab === tab.key ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" @click="applyFilters({ tab: tab.key })">{{ tab.label }}</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <select v-model="filters.comparison" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" @change="filters.comparison !== 'custom' && applyFilters()"><option value="none">不对比</option><option value="previous">对比上一时段</option><option value="custom">自定义对比</option></select>
                        <template v-if="filters.comparison === 'custom'"><input v-model="filters.compare_date_from" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="对比开始日期"><input v-model="filters.compare_date_to" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="对比结束日期"></template>
                        <input v-model="filters.date_from" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="开始日期">
                        <input v-model="filters.date_to" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="结束日期">
                        <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="applyFilters()">应用日期</button>
                    </div>
                </div>
                <div v-if="dashboard.comparison" class="border-b border-indigo-100 bg-indigo-50/60 px-5 py-2 text-xs text-indigo-700">当前对比区间：{{ dashboard.comparison.date_from }} 至 {{ dashboard.comparison.date_to }}，全部变化值由本项目数据库现场计算。</div>

                <div v-if="filters.tab === 'targets'" class="p-5 sm:p-6">
                    <div class="mb-5 flex items-center justify-between"><div><h2 class="text-lg font-bold text-slate-950">月度目标进度</h2><p class="mt-1 text-sm text-slate-500">只使用明确的评价数、曝光数和评论数计算完成率。</p></div><button v-if="canManage" type="button" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="goalOpen = true">设置目标</button></div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <article v-for="goal in dashboard.goals" :key="goal.metric" class="rounded-2xl border border-slate-200 p-5">
                            <div class="flex items-start justify-between gap-3"><div><h3 class="font-bold text-slate-900">{{ goal.label }}</h3><p class="mt-1 text-xs text-slate-500">实际 / 目标</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="goal.status === 'achieved' ? 'bg-emerald-100 text-emerald-700' : goal.status === 'risk' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'">{{ goal.completion_percent === null ? '未设置' : `${goal.completion_percent}%` }}</span></div>
                            <p class="mt-5 text-3xl font-bold text-slate-950">{{ formatNumber(goal.actual) }} <span class="text-base font-medium text-slate-400">/ {{ formatNumber(goal.target) }}</span></p>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, goal.completion_percent ?? 0)}%` }" /></div>
                        </article>
                    </div>
                    <div class="mb-3 mt-7"><h3 class="font-bold text-slate-950">舆情目标完成率排名</h3><p class="mt-1 text-xs text-slate-500">按完成率排序，趋势与所选对比区间保持一致。</p></div>
                    <div class="overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">排名</th><th class="px-4 py-3">目标指标</th><th class="px-4 py-3 text-right">实际值</th><th class="px-4 py-3 text-right">目标值</th><th class="px-4 py-3 text-right">完成率</th><th class="min-w-40 px-4 py-3">进度</th><th class="px-4 py-3 text-right">差距</th><th class="px-4 py-3">状态</th><th class="px-4 py-3 text-right">趋势</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(goal, index) in dashboard.goals" :key="`rank-${goal.metric}`">
                                    <td class="px-4 py-3 font-bold text-slate-500">#{{ index + 1 }}</td><td class="px-4 py-3 font-semibold text-slate-900">{{ goal.label }}</td><td class="px-4 py-3 text-right font-mono">{{ formatNumber(goal.actual) }}</td><td class="px-4 py-3 text-right font-mono">{{ formatNumber(goal.target) }}</td><td class="px-4 py-3 text-right font-mono">{{ goal.completion_percent === null ? '—' : `${goal.completion_percent}%` }}</td>
                                    <td class="px-4 py-3"><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, goal.completion_percent ?? 0)}%` }" /></div></td><td class="px-4 py-3 text-right font-mono" :class="(goal.gap ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ goal.gap === null ? '—' : `${goal.gap > 0 ? '+' : ''}${formatNumber(goal.gap)}` }}</td><td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ goalStatusLabel(goal.status) }}</span></td><td class="px-4 py-3 text-right font-mono" :class="(goalTrendMetric(goal.metric)?.change_percent ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ goalTrendMetric(goal.metric)?.change_percent === null || !goalTrendMetric(goal.metric) ? '—' : `${(goalTrendMetric(goal.metric)?.change_percent ?? 0) >= 0 ? '+' : ''}${goalTrendMetric(goal.metric)?.change_percent?.toFixed(1)}%` }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else class="p-5 sm:p-6">
                    <div v-if="filters.tab === 'reddit'" class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div v-for="item in [{label:'帖子',value:dashboard.summary.reddit.posts,compare:'reddit_posts'},{label:'曝光',value:dashboard.summary.reddit.views,compare:'reddit_views'},{label:'Upvotes',value:dashboard.summary.reddit.upvotes,compare:'reddit_upvotes'},{label:'评论',value:dashboard.summary.reddit.comments,compare:'reddit_comments'},{label:'记录花费',value:dashboard.summary.reddit.spend,compare:'reddit_spend'}]" :key="item.label" class="rounded-2xl bg-orange-50 p-4"><p class="text-xs font-semibold text-orange-700">{{ item.label }}</p><p class="mt-2 text-2xl font-bold text-orange-950">{{ formatNumber(item.value) }}</p><p class="mt-1 text-[11px] text-orange-700/70">{{ comparisonText(item.compare) }}</p></div>
                    </div>
                    <div v-if="filters.tab === 'threads'" class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <div v-for="item in [{label:'帖子',value:dashboard.summary.threads.posts,compare:'threads_posts'},{label:'点赞',value:dashboard.summary.threads.likes,compare:'threads_likes'},{label:'回复',value:dashboard.summary.threads.replies,compare:'threads_replies'},{label:'转发',value:dashboard.summary.threads.reposts,compare:'threads_reposts'},{label:'分享',value:dashboard.summary.threads.shares,compare:'threads_shares'},{label:'总互动',value:dashboard.summary.threads.engagement,compare:'threads_engagement'}]" :key="item.label" class="rounded-2xl bg-violet-50 p-4"><p class="text-xs font-semibold text-violet-700">{{ item.label }}</p><p class="mt-2 text-2xl font-bold text-violet-950">{{ formatNumber(item.value) }}</p><p class="mt-1 text-[11px] text-violet-700/70">{{ comparisonText(item.compare) }}</p></div>
                    </div>
                    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <select v-model="filters.source" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="applyFilters()"><option value="">全部平台</option><option v-for="(label, key) in sourceLabels" :key="key" :value="key">{{ label }}</option></select>
                        <select v-model="filters.rating" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="applyFilters()"><option value="">全部星级</option><option v-for="star in [5,4,3,2,1]" :key="star" :value="star">{{ star }} 星</option></select>
                        <select v-model="filters.status" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="applyFilters()"><option value="">全部处理状态</option><option value="pending">待处理低分</option><option value="done">已处理</option></select>
                        <form class="flex" @submit.prevent="applyFilters()"><input v-model="filters.search" class="min-w-0 flex-1 rounded-l-xl border border-slate-200 px-3 py-2.5 text-sm" placeholder="搜索内容或车型"><button class="rounded-r-xl bg-slate-950 px-4 text-sm font-semibold text-white">搜索</button></form>
                    </div>
                    <div class="overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">来源 / 日期</th><th class="min-w-[320px] px-4 py-3">内容</th><th class="px-4 py-3">订单 / 周数</th><th class="px-4 py-3">评分 / 指标</th><th class="px-4 py-3">跟进状态</th><th class="px-4 py-3">数据来源</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="record in dashboard.records.data" :key="record.uuid" class="align-top hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-4 py-4"><span class="font-semibold text-slate-900">{{ sourceLabels[record.source] ?? record.source }}</span><div class="mt-1 text-xs text-slate-500">{{ formatDate(record.published_at) }}</div><div v-if="record.model_name" class="mt-1 text-xs text-slate-500">{{ record.model_name }}</div></td>
                                    <td class="px-4 py-4"><p class="line-clamp-3 leading-6 text-slate-700">{{ contentPreview(record) }}</p><a v-if="record.url" :href="record.url" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex text-xs font-semibold text-indigo-600 hover:text-indigo-800">查看来源</a></td>
                                    <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-600"><div class="font-mono">{{ record.order_reference_masked ?? '—' }}</div><div class="mt-2">{{ record.week_number ? `W${record.week_number}` : '未标周数' }}</div></td>
                                    <td class="px-4 py-4"><div v-if="record.rating !== null" class="whitespace-nowrap font-bold text-amber-500">★ {{ record.rating.toFixed(1) }}</div><div v-if="metricSummary(record)" class="mt-1 max-w-xs text-xs leading-5 text-slate-500">{{ metricSummary(record) }}</div></td>
                                    <td class="px-4 py-4"><span v-if="record.is_negative" class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">需关注</span><span v-else class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">正常</span><div v-if="record.processing_status" class="mt-2 text-xs text-slate-500">{{ record.processing_status }}</div><p v-if="record.response_note" class="mt-2 max-w-48 text-xs leading-5 text-slate-500">{{ record.response_note }}</p><button v-if="canManage && record.rating !== null" type="button" class="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-800" @click="openFollowUp(record)">更新跟进</button></td>
                                    <td class="px-4 py-4 text-xs text-slate-500"><span class="rounded-full bg-slate-100 px-2 py-1 font-semibold text-slate-700">{{ record.origin === 'manual' ? '人工' : '同步' }}</span><p class="mt-2 max-w-40 leading-5">{{ sourceSheetText(record) }}</p></td>
                                </tr>
                                <tr v-if="dashboard.records.data.length === 0"><td colspan="6" class="px-6 py-14 text-center text-slate-500">当前筛选条件下暂无数据库记录。</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><p class="mt-5 text-sm text-slate-500">共 {{ formatNumber(dashboard.records.total) }} 条；未标日期数据 {{ formatNumber(dashboard.summary.undated_records) }} 条</p><Pagination :links="dashboard.records.links" /></div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.25fr_.75fr]">
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div class="flex items-end justify-between"><div><h2 class="text-lg font-bold text-slate-950">近 14 日内容量</h2><p class="mt-1 text-sm text-slate-500">评价、Reddit 和 Threads 已入库记录</p></div><span class="text-xs text-slate-400">按发布日期</span></div><div class="mt-8 flex h-48 items-end gap-2"><div v-for="row in recentTrends" :key="row.date" class="group flex min-w-0 flex-1 flex-col items-center justify-end gap-2"><div class="relative flex h-36 w-full items-end justify-center"><div class="w-full max-w-8 rounded-t-md bg-indigo-500 transition group-hover:bg-indigo-400" :style="{ height: `${Math.max(3, ((row.reviews + row.reddit + row.threads) / trendMax) * 100)}%` }" :title="`${row.date}: ${row.reviews + row.reddit + row.threads} 条`" /></div><span class="text-[10px] text-slate-400">{{ row.date.slice(5) }}</span></div></div></article>
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-bold text-slate-950">星级分布</h2><p class="mt-1 text-sm text-slate-500">Trustpilot 与官网评价</p><div class="mt-6 space-y-4"><div v-for="row in dashboard.star_distribution" :key="row.rating" class="grid grid-cols-[44px_1fr_72px] items-center gap-3 text-sm"><span class="font-semibold text-amber-500">{{ row.rating }} ★</span><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-amber-400" :style="{ width: `${row.percent}%` }" /></div><span class="text-right text-slate-500">{{ row.count }} · {{ row.percent }}%</span></div></div></article>
            </section>

            <section v-if="filters.tab === 'reviews'" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div><h2 class="text-lg font-bold text-slate-950">每日星级分布变化</h2><p class="mt-1 text-sm text-slate-500">按发布日期展示最近 14 日各星级占比；没有评价的日期仍保留，避免趋势断档。</p></div>
                <div class="mt-6 space-y-3"><div v-for="row in recentTrends" :key="`stars-${row.date}`" class="grid grid-cols-[70px_1fr_52px] items-center gap-3"><span class="text-xs font-mono text-slate-500">{{ row.date.slice(5) }}</span><div class="flex h-5 overflow-hidden rounded-md bg-slate-100" :title="`${row.date}：${row.reviews} 条评价`"><div class="bg-rose-600" :style="{ width: `${starPercent(row, 1)}%` }" /><div class="bg-rose-400" :style="{ width: `${starPercent(row, 2)}%` }" /><div class="bg-amber-400" :style="{ width: `${starPercent(row, 3)}%` }" /><div class="bg-emerald-400" :style="{ width: `${starPercent(row, 4)}%` }" /><div class="bg-emerald-600" :style="{ width: `${starPercent(row, 5)}%` }" /></div><span class="text-right text-xs font-semibold text-slate-600">{{ row.reviews }} 条</span></div></div>
                <div class="mt-5 flex flex-wrap gap-4 text-xs text-slate-500"><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-rose-600" />1 星</span><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-rose-400" />2 星</span><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-amber-400" />3 星</span><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-400" />4 星</span><span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-600" />5 星</span></div>
            </section>

            <section v-if="filters.tab === 'reddit' || filters.tab === 'threads'" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-bold text-slate-950">{{ filters.tab === 'reddit' ? 'Reddit 每日表现' : 'Threads 每日表现' }}</h2><p class="mt-1 text-sm text-slate-500">所有指标均来自当前项目数据库中的原始数值字段。</p>
                <div class="mt-5 overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">日期</th><template v-if="filters.tab === 'reddit'"><th class="px-3 py-2 text-right">帖子</th><th class="px-3 py-2 text-right">曝光</th><th class="px-3 py-2 text-right">Upvotes</th><th class="px-3 py-2 text-right">评论</th><th class="px-3 py-2 text-right">花费</th></template><template v-else><th class="px-3 py-2 text-right">帖子</th><th class="px-3 py-2 text-right">点赞</th><th class="px-3 py-2 text-right">回复</th><th class="px-3 py-2 text-right">转发</th><th class="px-3 py-2 text-right">分享</th></template></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="row in recentTrends" :key="`social-${row.date}`"><td class="px-3 py-2 font-mono text-slate-600">{{ row.date }}</td><template v-if="filters.tab === 'reddit'"><td class="px-3 py-2 text-right">{{ row.reddit }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.reddit_views) }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.reddit_upvotes) }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.reddit_comments) }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.reddit_spend) }}</td></template><template v-else><td class="px-3 py-2 text-right">{{ row.threads }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.threads_likes) }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.threads_replies) }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.threads_reposts) }}</td><td class="px-3 py-2 text-right">{{ formatNumber(row.threads_shares) }}</td></template></tr></tbody></table></div>
            </section>

            <section class="grid gap-6 lg:grid-cols-3">
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-bold text-slate-950">平台数据</h2><div class="mt-5 space-y-3"><div v-for="row in dashboard.source_breakdown" :key="row.source" class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3"><div><p class="font-semibold text-slate-800">{{ sourceLabels[row.source] ?? row.source }}</p><p class="mt-1 text-xs text-slate-500">负面 {{ row.negative_count }} · 均分 {{ row.average_rating ?? '—' }}</p></div><strong class="text-lg text-slate-950">{{ row.total }}</strong></div></div></article>
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-bold text-slate-950">Reddit 表现</h2><div class="mt-5 grid grid-cols-2 gap-3"><div v-for="item in [{label:'帖子',value:dashboard.summary.reddit.posts},{label:'曝光',value:dashboard.summary.reddit.views},{label:'赞同',value:dashboard.summary.reddit.upvotes},{label:'评论',value:dashboard.summary.reddit.comments}]" :key="item.label" class="rounded-xl bg-orange-50 p-4"><p class="text-xs font-medium text-orange-700">{{ item.label }}</p><p class="mt-2 text-2xl font-bold text-orange-950">{{ formatNumber(item.value) }}</p></div></div><p class="mt-3 text-xs text-slate-500">记录花费 {{ formatNumber(dashboard.summary.reddit.spend) }}</p></article>
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-bold text-slate-950">Threads 表现</h2><div class="mt-5 grid grid-cols-2 gap-3"><div v-for="item in [{label:'帖子',value:dashboard.summary.threads.posts},{label:'点赞',value:dashboard.summary.threads.likes},{label:'回复',value:dashboard.summary.threads.replies},{label:'转发',value:dashboard.summary.threads.reposts},{label:'分享',value:dashboard.summary.threads.shares},{label:'总互动',value:dashboard.summary.threads.engagement}]" :key="item.label" class="rounded-xl bg-violet-50 p-4"><p class="text-xs font-medium text-violet-700">{{ item.label }}</p><p class="mt-2 text-2xl font-bold text-violet-950">{{ formatNumber(item.value) }}</p></div></div></article>
            </section>

            <section v-if="dashboard.models.length" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-bold text-slate-950">车型评价表现</h2><div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><div v-for="model in dashboard.models" :key="model.model" class="rounded-2xl border border-slate-200 p-4"><p class="font-semibold text-slate-900">{{ model.model }}</p><p class="mt-3 text-2xl font-bold text-slate-950">{{ model.count }} <span class="text-xs font-medium text-slate-400">条</span></p><p class="mt-1 text-sm text-amber-500">★ {{ model.average_rating.toFixed(2) }}</p></div></div></section>
        </div>

        <div v-if="goalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" @click.self="goalOpen = false">
            <form class="w-full max-w-xl rounded-3xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="saveGoals">
                <div class="flex items-start justify-between"><div><h2 class="text-xl font-bold text-slate-950">设置月度目标</h2><p class="mt-1 text-sm text-slate-500">目标保存在当前项目数据库，按店铺与月份隔离。</p></div><button type="button" class="text-2xl text-slate-400" @click="goalOpen = false">×</button></div>
                <label class="mt-6 block text-sm font-semibold text-slate-700">月份<input v-model="goalForm.month" type="month" required class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
                <div class="mt-5 grid gap-4 sm:grid-cols-3"><label v-for="goal in dashboard.goals" :key="goal.metric" class="text-sm font-semibold text-slate-700">{{ goal.label }}<input v-model.number="goalForm.targets[goal.metric]" type="number" min="0" step="1" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div>
                <p v-if="goalForm.hasErrors" class="mt-4 text-sm text-rose-600">请检查月份和目标数值。</p>
                <div class="mt-7 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold" @click="goalOpen = false">取消</button><button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="goalForm.processing">保存目标</button></div>
            </form>
        </div>

        <div v-if="mentionOpen" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/55 p-4" @click.self="mentionOpen = false">
            <form class="my-6 w-full max-w-3xl rounded-3xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="saveMention">
                <div class="flex items-start justify-between"><div><h2 class="text-xl font-bold text-slate-950">人工录入评价</h2><p class="mt-1 text-sm text-slate-500">数据只写入当前店铺数据库，并在后续来源同步时保留。</p></div><button type="button" class="text-2xl text-slate-400" @click="mentionOpen = false">×</button></div>
                <div class="mt-6 grid gap-4 sm:grid-cols-3"><label class="text-sm font-semibold text-slate-700">平台<select v-model="mentionForm.source" required class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option value="trustpilot">Trustpilot</option><option value="website">官网评论</option><option value="facebook">Facebook</option></select></label><label class="text-sm font-semibold text-slate-700">星级<input v-model.number="mentionForm.rating" type="number" min="1" max="5" step="1" required class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm font-semibold text-slate-700">发布日期<input v-model="mentionForm.published_at" type="date" required class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div>
                <label class="mt-4 block text-sm font-semibold text-slate-700">评价内容<textarea v-model="mentionForm.content" required maxlength="20000" rows="5" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="输入评价原文" /></label>
                <div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold text-slate-700">标题（可选）<input v-model="mentionForm.title" maxlength="500" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm font-semibold text-slate-700">来源链接（可选）<input v-model="mentionForm.url" type="url" maxlength="2048" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm font-semibold text-slate-700">车型（可选）<input v-model="mentionForm.model_name" maxlength="120" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm font-semibold text-slate-700">订单号（可选，仅加密保存）<input v-model="mentionForm.order_reference" maxlength="255" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm font-semibold text-slate-700">周数（可选）<input v-model.number="mentionForm.week_number" type="number" min="1" max="53" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm font-semibold text-slate-700">处理状态<select v-model="mentionForm.processing_status" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option value="">未设置</option><option value="pending">待处理</option><option value="resolved">已处理</option></select></label></div>
                <label class="mt-4 block text-sm font-semibold text-slate-700">客服回复说明（可选）<textarea v-model="mentionForm.response_note" maxlength="5000" rows="3" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <p v-if="mentionForm.hasErrors" class="mt-4 text-sm text-rose-600">请检查必填项、日期、星级和链接格式。</p>
                <div class="mt-7 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold" @click="mentionOpen = false">取消</button><button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="mentionForm.processing">保存评价</button></div>
            </form>
        </div>

        <div v-if="followUpOpen && selectedRecord" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" @click.self="followUpOpen = false">
            <form class="w-full max-w-xl rounded-3xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="saveFollowUp">
                <div class="flex items-start justify-between"><div><h2 class="text-xl font-bold text-slate-950">更新评价跟进</h2><p class="mt-1 text-sm text-slate-500">{{ sourceLabels[selectedRecord.source] ?? selectedRecord.source }} · {{ selectedRecord.rating }} 星</p></div><button type="button" class="text-2xl text-slate-400" @click="followUpOpen = false">×</button></div>
                <label class="mt-6 block text-sm font-semibold text-slate-700">处理状态<select v-model="followUpForm.processing_status" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option value="">未设置</option><option value="pending">待处理</option><option value="resolved">已处理</option></select></label>
                <label class="mt-4 block text-sm font-semibold text-slate-700">客服回复说明<textarea v-model="followUpForm.response_note" maxlength="5000" rows="5" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label>
                <div class="mt-7 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold" @click="followUpOpen = false">取消</button><button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="followUpForm.processing">保存跟进</button></div>
            </form>
        </div>
    </AppLayout>
</template>
