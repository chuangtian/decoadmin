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
    date: string; reviews: number; reddit: number; threads: number;
    reddit_views: number; reddit_comments: number; reddit_upvotes: number;
    threads_likes: number; threads_replies: number; threads_reposts: number; threads_shares: number;
};
type WeeklyTrend = {
    week: string; label: string; posts: number; views: number; comments: number; upvotes: number;
    likes?: number; replies?: number; reposts?: number; shares?: number; interactions: number;
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
const socialWeeklyTrends = computed<WeeklyTrend[]>(() => {
    const weeks = new Map<string, WeeklyTrend>();
    props.dashboard.trends.forEach((row) => {
        const date = new Date(`${row.date}T00:00:00`);
        const weekday = (date.getDay() + 6) % 7;
        date.setDate(date.getDate() - weekday);
        const week = date.toISOString().slice(0, 10);
        const current = weeks.get(week) ?? { week, label: week.slice(5), posts: 0, views: 0, comments: 0, upvotes: 0, likes: 0, replies: 0, reposts: 0, shares: 0, interactions: 0 };
        if (filters.value.tab === 'reddit') {
            current.posts += row.reddit;
            current.views += row.reddit_views;
            current.comments += row.reddit_comments;
            current.upvotes += row.reddit_upvotes;
            current.interactions += row.reddit_comments + row.reddit_upvotes;
        } else {
            current.posts += row.threads;
            current.likes = (current.likes ?? 0) + row.threads_likes;
            current.replies = (current.replies ?? 0) + row.threads_replies;
            current.reposts = (current.reposts ?? 0) + row.threads_reposts;
            current.shares = (current.shares ?? 0) + row.threads_shares;
            current.interactions += row.threads_likes + row.threads_replies + row.threads_reposts + row.threads_shares;
        }
        weeks.set(week, current);
    });
    return [...weeks.values()].slice(-12);
});
const socialMetricMax = computed(() => Math.max(1, ...socialWeeklyTrends.value.flatMap((row) => (
    filters.value.tab === 'reddit'
        ? [row.views, row.comments, row.upvotes]
        : [row.likes ?? 0, row.replies ?? 0, row.interactions]
))));

const formatNumber = (value: number) => new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(value ?? 0);
const formatDate = (value: string | null, withTime = false) => value
    ? new Intl.DateTimeFormat('zh-CN', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(value))
    : '未标日期';
const contentPreview = (record: RecordItem) => record.title || record.content || '仅记录了指标数据';
const socialMetricKeys: Record<string, string[]> = {
    reddit: ['views', 'comments', 'upvotes'],
    threads: ['likes', 'replies', 'reposts', 'shares'],
};
const metricSummary = (record: RecordItem) => Object.entries(record.metrics || {})
    .filter(([key]) => !socialMetricKeys[record.source] || socialMetricKeys[record.source].includes(key.toLowerCase()))
    .slice(0, 4)
    .map(([key, value]) => `${key}: ${value}`)
    .join(' · ');
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
const trendPoints = (key: 'views' | 'comments' | 'upvotes' | 'likes' | 'replies' | 'interactions') => {
    const rows = socialWeeklyTrends.value;
    if (!rows.length) return '';
    return rows.map((row, index) => {
        const x = rows.length === 1 ? 360 : 44 + (index / (rows.length - 1)) * 632;
        const value = Number(row[key] ?? 0);
        const y = 174 - (value / socialMetricMax.value) * 132;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
};

function applyFilters(extra: Record<string, string> = {}) {
    filters.value = { ...filters.value, ...extra };
    router.get('/reputation/overview', filters.value, { preserveState: true, preserveScroll: true, replace: true });
}

function switchTab(tab: string) {
    applyFilters({ tab, source: '', rating: '', status: '', search: '' });
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
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-600">舆情监控 · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950">舆情总览</h1>
                    <p class="mt-2 text-sm text-slate-500">数据来自当前店铺数据库，并按所选日期范围现场汇总。</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500 shadow-sm">
                        <div class="font-semibold text-slate-700">数据库 {{ formatNumber(dashboard.freshness.database_total) }} 条</div>
                        <div class="mt-1">{{ dashboard.freshness.last_synced_at ? `更新于 ${formatDate(dashboard.freshness.last_synced_at, true)}` : '尚未完成首次同步' }}</div>
                    </div>
                    <button v-if="canManage" type="button" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50" @click="mentionOpen = true">人工录入评价</button>
                    <button v-if="canSync" type="button" class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!sourceReady || syncing" @click="requestSync">{{ syncing ? `同步中 ${sync?.progress_percent ?? 0}%` : '同步当前数据' }}</button>
                </div>
            </header>
            <div v-if="!sourceReady" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">当前店铺尚未配置舆情数据源，请先到店铺设置的飞书数据源中配置。</div>
            <div v-else-if="syncing" class="h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${sync?.progress_percent ?? 0}%` }" /></div>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-slate-100 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="tab in tabLabels" :key="tab.key" type="button" class="rounded-xl px-4 py-2.5 text-sm font-semibold transition" :class="filters.tab === tab.key ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" @click="switchTab(tab.key)">{{ tab.label }}</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <select v-model="filters.comparison" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" @change="filters.comparison !== 'custom' && applyFilters()"><option value="none">不对比</option><option value="previous">对比上一时段</option><option value="custom">自定义对比</option></select>
                        <template v-if="filters.comparison === 'custom'"><input v-model="filters.compare_date_from" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="对比开始日期"><input v-model="filters.compare_date_to" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="对比结束日期"></template>
                        <input v-model="filters.date_from" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="开始日期">
                        <input v-model="filters.date_to" type="date" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" aria-label="结束日期">
                        <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="applyFilters()">应用日期</button>
                    </div>
                </div>
                <div v-if="dashboard.comparison" class="border-b border-indigo-100 bg-indigo-50/60 px-5 py-2 text-xs text-indigo-700">当前对比区间：{{ dashboard.comparison.date_from }} 至 {{ dashboard.comparison.date_to }}</div>

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

            </section>

            <template v-if="filters.tab === 'reviews'">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"><div><h2 class="text-lg font-black text-slate-950">评论列表</h2><p class="mt-1 text-sm text-slate-500">评论记录已移至页面底部；来源、日期、订单和周数分别展示。</p></div><div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4"><select v-model="filters.source" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="applyFilters()"><option value="">全部平台</option><option v-for="(label, key) in sourceLabels" :key="key" :value="key">{{ label }}</option></select><select v-model="filters.rating" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="applyFilters()"><option value="">全部星级</option><option v-for="star in [5,4,3,2,1]" :key="star" :value="star">{{ star }} 星</option></select><select v-model="filters.status" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" @change="applyFilters()"><option value="">全部处理状态</option><option value="pending">待处理低分</option><option value="done">已处理</option></select><form class="flex" @submit.prevent="applyFilters()"><input v-model="filters.search" class="min-w-0 flex-1 rounded-l-xl border border-slate-200 px-3 py-2.5 text-sm" placeholder="搜索内容或车型"><button class="rounded-r-xl bg-slate-950 px-4 text-sm font-semibold text-white">搜索</button></form></div></div>
                    <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200"><table class="min-w-full divide-y divide-slate-200 text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">来源</th><th class="px-4 py-3">日期</th><th class="min-w-[300px] px-4 py-3">内容</th><th class="px-4 py-3">订单</th><th class="px-4 py-3">周数</th><th class="px-4 py-3">评分 / 指标</th><th class="px-4 py-3">跟进状态</th><th class="px-4 py-3">数据来源</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="record in dashboard.records.data" :key="record.uuid" class="align-top hover:bg-slate-50/70"><td class="whitespace-nowrap px-4 py-4"><span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">{{ sourceLabels[record.source] ?? record.source }}</span><div v-if="record.model_name" class="mt-2 text-xs text-slate-500">{{ record.model_name }}</div></td><td class="whitespace-nowrap px-4 py-4 text-xs font-mono text-slate-600">{{ formatDate(record.published_at) }}</td><td class="px-4 py-4"><p class="line-clamp-3 leading-6 text-slate-700">{{ contentPreview(record) }}</p><a v-if="record.url" :href="record.url" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex text-xs font-semibold text-indigo-600 hover:text-indigo-800">查看来源</a></td><td class="whitespace-nowrap px-4 py-4 font-mono text-xs text-slate-600">{{ record.order_reference_masked ?? '—' }}</td><td class="whitespace-nowrap px-4 py-4 text-xs text-slate-600">{{ record.week_number ? `W${record.week_number}` : '—' }}</td><td class="px-4 py-4"><div v-if="record.rating !== null" class="whitespace-nowrap font-bold text-amber-500">★ {{ record.rating.toFixed(1) }}</div><div v-if="metricSummary(record)" class="mt-1 max-w-xs text-xs leading-5 text-slate-500">{{ metricSummary(record) }}</div></td><td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="record.is_negative ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'">{{ record.processing_status || (record.is_negative ? '待跟进' : '正常') }}</span><p v-if="record.response_note" class="mt-2 max-w-48 text-xs leading-5 text-slate-500">{{ record.response_note }}</p><button v-if="canManage && record.rating !== null" type="button" class="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-800" @click="openFollowUp(record)">更新跟进</button></td><td class="px-4 py-4 text-xs text-slate-500"><span class="rounded-full px-2.5 py-1 font-bold" :class="record.origin === 'manual' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700'">{{ record.origin === 'manual' ? '人工导入' : '系统同步' }}</span><p class="mt-2 max-w-40 leading-5">{{ sourceSheetText(record) }}</p></td></tr><tr v-if="dashboard.records.data.length === 0"><td colspan="8" class="px-6 py-14 text-center text-slate-500">当前筛选条件下暂无数据库记录。</td></tr></tbody></table></div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><p class="mt-5 text-sm text-slate-500">共 {{ formatNumber(dashboard.records.total) }} 条；未标日期数据 {{ formatNumber(dashboard.summary.undated_records) }} 条</p><Pagination :links="dashboard.records.links" /></div>
                </section>
            </template>

            <template v-if="filters.tab === 'reddit' || filters.tab === 'threads'">
                <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <article v-for="item in filters.tab === 'reddit' ? [{label:'帖子',value:dashboard.summary.reddit.posts,compare:'reddit_posts'},{label:'曝光',value:dashboard.summary.reddit.views,compare:'reddit_views'},{label:'Upvotes',value:dashboard.summary.reddit.upvotes,compare:'reddit_upvotes'},{label:'评论',value:dashboard.summary.reddit.comments,compare:'reddit_comments'}] : [{label:'帖子',value:dashboard.summary.threads.posts,compare:'threads_posts'},{label:'点赞',value:dashboard.summary.threads.likes,compare:'threads_likes'},{label:'回复',value:dashboard.summary.threads.replies,compare:'threads_replies'},{label:'总互动',value:dashboard.summary.threads.engagement,compare:'threads_engagement'}]" :key="item.label" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold" :class="filters.tab === 'reddit' ? 'text-orange-700' : 'text-violet-700'">{{ item.label }}</p><p class="mt-2 text-3xl font-black text-slate-950">{{ formatNumber(item.value) }}</p><p class="mt-1 text-xs text-slate-400">{{ comparisonText(item.compare) }}</p></article>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div><h2 class="text-lg font-black text-slate-950">{{ filters.tab === 'reddit' ? 'Reddit' : 'Threads' }} 趋势分析</h2><p class="mt-1 text-sm text-slate-500">按周汇总平台原始互动指标。</p></div>
                    <article class="mt-6 rounded-2xl border border-slate-200 p-4"><h3 class="font-bold text-slate-900">发帖及互动趋势（周度）</h3><div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-500"><template v-if="filters.tab === 'reddit'"><span class="text-indigo-600">● 曝光</span><span class="text-emerald-600">● 评论</span><span class="text-amber-600">● Upvotes</span></template><template v-else><span class="text-violet-600">● 点赞</span><span class="text-emerald-600">● 回复</span><span class="text-amber-600">● 总互动</span></template></div><svg v-if="socialWeeklyTrends.length" class="mt-3 h-64 w-full" viewBox="0 0 720 210" role="img" aria-label="周度互动趋势"><line v-for="y in [42,86,130,174]" :key="y" x1="44" x2="696" :y1="y" :y2="y" stroke="#e2e8f0" stroke-width="1" /><template v-if="filters.tab === 'reddit'"><polyline :points="trendPoints('views')" fill="none" stroke="#4f46e5" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /><polyline :points="trendPoints('comments')" fill="none" stroke="#10b981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /><polyline :points="trendPoints('upvotes')" fill="none" stroke="#f59e0b" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /></template><template v-else><polyline :points="trendPoints('likes')" fill="none" stroke="#7c3aed" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /><polyline :points="trendPoints('replies')" fill="none" stroke="#10b981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /><polyline :points="trendPoints('interactions')" fill="none" stroke="#f59e0b" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /></template><text v-for="(row, index) in socialWeeklyTrends" :key="`x-${row.week}`" :x="socialWeeklyTrends.length === 1 ? 360 : 44 + (index / (socialWeeklyTrends.length - 1)) * 632" y="202" text-anchor="middle" fill="#64748b" font-size="11">{{ row.label || row.week }}</text></svg><div v-else class="mt-4 rounded-xl bg-slate-50 py-20 text-center text-sm text-slate-500">暂无周度趋势数据。</div></article>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div><h2 class="text-lg font-black text-slate-950">{{ filters.tab === 'reddit' ? 'Reddit' : 'Threads' }} 评论列表</h2><p class="mt-1 text-sm text-slate-500">列表已移至页面底部，仅保留平台相关字段。</p></div>
                    <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200"><table class="min-w-full divide-y divide-slate-200 text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">来源</th><th class="px-4 py-3">日期</th><th class="min-w-[360px] px-4 py-3">内容</th><th class="px-4 py-3">互动指标</th><th class="px-4 py-3">数据来源</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="record in dashboard.records.data" :key="record.uuid" class="align-top hover:bg-slate-50/70"><td class="whitespace-nowrap px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="filters.tab === 'reddit' ? 'bg-orange-100 text-orange-700' : 'bg-violet-100 text-violet-700'">{{ sourceLabels[record.source] ?? record.source }}</span></td><td class="whitespace-nowrap px-4 py-4 text-xs font-mono text-slate-600">{{ formatDate(record.published_at) }}</td><td class="px-4 py-4"><p class="line-clamp-3 leading-6 text-slate-700">{{ contentPreview(record) }}</p><a v-if="record.url" :href="record.url" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex text-xs font-semibold text-indigo-600 hover:text-indigo-800">查看来源</a></td><td class="max-w-sm px-4 py-4 text-xs leading-5 text-slate-500">{{ metricSummary(record) || '—' }}</td><td class="px-4 py-4 text-xs text-slate-500"><span class="rounded-full bg-sky-100 px-2.5 py-1 font-bold text-sky-700">{{ record.origin === 'manual' ? '人工导入' : '系统同步' }}</span><p class="mt-2 max-w-48 leading-5">{{ sourceSheetText(record) }}</p></td></tr><tr v-if="dashboard.records.data.length === 0"><td colspan="5" class="px-6 py-14 text-center text-slate-500">当前期间暂无 {{ filters.tab === 'reddit' ? 'Reddit' : 'Threads' }} 记录。</td></tr></tbody></table></div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><p class="mt-5 text-sm text-slate-500">共 {{ formatNumber(dashboard.records.total) }} 条</p><Pagination :links="dashboard.records.links" /></div>
                </section>
            </template>
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
