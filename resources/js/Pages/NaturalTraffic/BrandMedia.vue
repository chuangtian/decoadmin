<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
import NaturalTrafficScatterPlot from '../../Components/NaturalTraffic/NaturalTrafficScatterPlot.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type SocialPost = Record<string, unknown> & {
    record_id: string;
    platform: string;
    date: string | null;
    title: string;
    post_type: string;
    posts: number;
    views: number;
    likes: number;
    comments: number;
    shares: number;
    engagement_rate: number;
    permalink: string;
    source_mode: string;
    source_section: string;
    source_table_key: string;
    content_origin: string;
    content_origin_label: string;
    is_hidden: boolean;
    visibility_status: string;
    aggregation_status: string;
    exclusion_metric: string;
    exclusion_value: number;
};
type WeeklyReport = Record<string, unknown> & {
    uuid: string | null;
    week: string;
    week_end: string;
    label: string;
    title: string;
    status: string;
    summary: string | null;
    all_posts: number;
    included_posts: number;
    excluded_posts: number;
    included_views: number;
    included_interactions: number;
    average_views: number;
    content_types: Array<Record<string, unknown>>;
    content_efficiency: Array<Record<string, unknown>>;
    scatter: Array<Record<string, unknown>>;
    top_views: SocialPost[];
    top_engagement: SocialPost[];
    excluded_content: SocialPost[];
    included_content: SocialPost[];
    post_performance: Array<Record<string, unknown>>;
    summary_items: string[];
};
type DailyReview = {
    uuid: string;
    review_date: string;
    status: 'draft' | 'published';
    core_data: string | null;
    top_content: string | null;
    low_content: string | null;
    recommendations: string | null;
    published_at: string | null;
};
type PlatformSource = {
    platform: string;
    mode: string;
    mode_label: string;
    available: boolean;
    configured: boolean;
    status: string;
    record_count: number;
    last_synced_at: string | null;
};
type PlatformSummary = Record<string, unknown> & {
    platform: string;
    available: boolean;
    posts: number;
    views: number;
    likes: number;
    comments: number;
    shares: number;
    engagement_rate: number;
};
type PlatformFunnelRow = {
    platform: string;
    views: number;
    interactions: number;
    engagementRate: number;
    viewBarClass: string;
    interactionBarClass: string;
    badgeClass: string;
};
type PostFilters = {
    keyword: string;
    platform: string;
    post_type: string;
    aggregation_status: string;
    visibility: string;
    origin: string;
    sort: string;
    direction: string;
    page: number;
    per_page: number;
};
type PostFilterOptions = {
    platforms: string[];
    post_types: string[];
    origins: Array<{ value: string; label: string }>;
    visibility_statuses: Array<{ value: string; label: string }>;
    aggregation_statuses: Array<{ value: string; label: string }>;
};
type PostsPagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};
type BrandDashboard = NaturalTrafficDashboardBase & {
    platforms: PlatformSummary[];
    platform_coverage: { available: string[]; missing: string[] };
    platform_sources: PlatformSource[];
    exclusion_policy: { platform: string; threshold: number; rule: string; behavior: string };
    exclusion_summary: { posts: number; views: number; content: SocialPost[] };
    funnel: Array<{ key: string; label: string; value: number }>;
    trends: Array<Record<string, unknown>>;
    platform_trends: Array<Record<string, unknown>>;
    daily: Array<Record<string, unknown>>;
    weekly: Array<Record<string, unknown>>;
    weekly_reports: Array<Record<string, unknown>>;
    weekly_options: Array<{ value: string; label: string; posts: number }>;
    selected_week: string | null;
    selected_weekly_report: WeeklyReport | null;
    weekly_comparison: Record<string, { current: number; previous: number; change: number | null }>;
    daily_review: { posting_days: number; engagements: number; reach: number; clicks: number; top_post: SocialPost | null; top_engagement_post: SocialPost | null };
    daily_reviews: DailyReview[];
    ai_insights: { platform_leader: PlatformSummary | null; top_post: SocialPost | null; top_engagement_post: SocialPost | null; views_change: number | null; engagements: number; generated_from: string };
    posts: SocialPost[];
    post_filters: PostFilters;
    post_filter_options: PostFilterOptions;
    posts_pagination: PostsPagination;
    columns: string[];
    raw_rows: Array<Record<string, unknown>>;
    hidden_posts_count: number;
};

const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: BrandDashboard; configured: boolean; canSync: boolean; canManage: boolean }>();
const activeTab = ref('platforms');
const trendMetric = ref<'views' | 'likes' | 'comments' | 'shares'>('views');
const trendMetrics = [
    { key: 'views', label: '浏览量' },
    { key: 'likes', label: '赞' },
    { key: 'shares', label: '分享' },
    { key: 'comments', label: '评论' },
] as const;
const fileInput = ref<HTMLInputElement | null>(null);
const reviewEditorOpen = ref(false);
const weeklyEditorOpen = ref(false);
const importForm = useForm<{ file: File | null }>({ file: null });
const reviewForm = useForm({
    review_date: props.dashboard.filters.date_to,
    status: 'draft' as 'draft' | 'published',
    core_data: '',
    top_content: '',
    low_content: '',
    recommendations: '',
});
const weeklyForm = useForm({
    week_start: props.dashboard.selected_week ?? '',
    title: props.dashboard.selected_weekly_report?.title ?? '',
    status: (props.dashboard.selected_weekly_report?.status === 'published' ? 'published' : 'draft') as 'draft' | 'published',
    summary: props.dashboard.selected_weekly_report?.summary ?? '',
});
const contentFilters = reactive<PostFilters>({ ...props.dashboard.post_filters });
const postColumns = ['platform', 'content_origin_label', 'source_mode', 'date', 'title', 'post_type', 'views', 'likes', 'comments', 'shares', 'visibility_status', 'aggregation_status', 'permalink'];
const postLabels: Record<string, string> = { platform: '平台', content_origin_label: '内容来源', source_mode: '数据来源', date: '发布日期', title: '内容', post_type: '类型', views: '播放 / 浏览', likes: '点赞', comments: '评论', shares: '分享', visibility_status: '显示状态', aggregation_status: '周报口径', permalink: '链接' };
const sortablePostColumns = new Set(['platform', 'date', 'views', 'likes', 'comments', 'shares']);
const platformMax = computed(() => Math.max(1, ...props.dashboard.platforms.map((row) => Number(row.views ?? 0))));
const selectedWeeklyReport = computed(() => props.dashboard.selected_weekly_report);
const platformTrendSeries = computed(() => [
    { key: `instagram_${trendMetric.value}`, label: 'Instagram', color: '#ec4899' },
    { key: `facebook_${trendMetric.value}`, label: 'Facebook', color: '#2563eb' },
    { key: `youtube_${trendMetric.value}`, label: 'YouTube', color: '#ef4444' },
]);
const weeklyComparisonCards = computed(() => [
    ['included_views', '总浏览量'],
    ['included_interactions', '总互动数'],
    ['average_views', '平均浏览'],
    ['included_posts', '帖子数'],
].map(([key, label]) => ({ key, label, ...props.dashboard.weekly_comparison[key] })));
const brandFunnel = computed(() => {
    const views = metricValue('views');
    const interactions = metricValue('engagements');

    return {
        views,
        interactions,
        engagementRate: views > 0 ? interactions / views * 100 : 0,
    };
});
const platformFunnelRows = computed<PlatformFunnelRow[]>(() => [
    {
        platform: 'YouTube',
        viewBarClass: 'bg-gradient-to-r from-red-500 to-rose-400',
        interactionBarClass: 'bg-red-300',
        badgeClass: 'bg-red-50 text-red-700 ring-red-100',
    },
    {
        platform: 'Instagram',
        viewBarClass: 'bg-gradient-to-r from-fuchsia-500 via-rose-500 to-orange-400',
        interactionBarClass: 'bg-fuchsia-300',
        badgeClass: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-100',
    },
    {
        platform: 'Facebook',
        viewBarClass: 'bg-gradient-to-r from-blue-600 to-sky-400',
        interactionBarClass: 'bg-blue-300',
        badgeClass: 'bg-blue-50 text-blue-700 ring-blue-100',
    },
].map((config) => {
    const source = props.dashboard.platforms.find((row) => row.platform === config.platform);
    const views = nonNegative(source?.views);
    const interactions = nonNegative(source?.likes) + nonNegative(source?.comments) + nonNegative(source?.shares);

    return {
        ...config,
        views,
        interactions,
        engagementRate: views > 0 ? interactions / views * 100 : 0,
    };
}));
const platformFunnelMax = computed(() => Math.max(1, ...platformFunnelRows.value.map((row) => row.views)));
const contentPageNumbers = computed(() => {
    const current = props.dashboard.posts_pagination.current_page;
    const last = props.dashboard.posts_pagination.last_page;
    const start = Math.max(1, Math.min(current - 2, last - 4));
    const end = Math.min(last, start + 4);

    return Array.from({ length: Math.max(0, end - start + 1) }, (_, index) => start + index);
});

watch(() => props.dashboard.post_filters, (filters) => {
    Object.assign(contentFilters, filters);
});
watch(() => props.dashboard.selected_weekly_report, (report) => {
    if (weeklyEditorOpen.value) return;
    weeklyForm.week_start = report?.week ?? '';
    weeklyForm.title = report?.title ?? '';
    weeklyForm.status = report?.status === 'published' ? 'published' : 'draft';
    weeklyForm.summary = report?.summary ?? '';
});

function compact(value: unknown): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value ?? 0));
}
function nonNegative(value: unknown): number {
    const number = Number(value ?? 0);

    return Number.isFinite(number) ? Math.max(0, number) : 0;
}
function metricValue(key: string): number {
    return nonNegative(props.dashboard.funnel.find((item) => item.key === key)?.value);
}
function percent(value: unknown): string {
    return `${nonNegative(value).toFixed(2)}%`;
}
function changeText(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
}
function valueText(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'number') return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value);

    return String(value);
}
function barWidth(value: number, maximum: number): number {
    if (value <= 0 || maximum <= 0) return 0;

    return Math.min(100, Math.max(2, value / maximum * 100));
}
function selectFile(event: Event): void {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function uploadCsv(): void {
    if (!importForm.file || !props.canSync) return;
    importForm.post('/natural-traffic/brand-media/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}
function contentQuery(page: number): Record<string, string | number> {
    const query: Record<string, string | number> = {
        date_from: props.dashboard.filters.date_from,
        date_to: props.dashboard.filters.date_to,
        comparison: props.dashboard.filters.comparison,
        content_page: page,
        content_per_page: contentFilters.per_page,
        content_visibility: contentFilters.visibility,
        content_sort: contentFilters.sort,
        content_direction: contentFilters.direction,
    };
    const optional = {
        content_keyword: contentFilters.keyword.trim(),
        content_platform: contentFilters.platform,
        content_type: contentFilters.post_type,
        content_status: contentFilters.aggregation_status,
        content_origin: contentFilters.origin,
        weekly_week: props.dashboard.selected_week ?? '',
    };

    Object.entries(optional).forEach(([key, value]) => {
        if (value) query[key] = value;
    });

    return query;
}
function sortContent(column: string): void {
    if (!sortablePostColumns.has(column)) return;
    if (contentFilters.sort === column) {
        contentFilters.direction = contentFilters.direction === 'desc' ? 'asc' : 'desc';
    } else {
        contentFilters.sort = column;
        contentFilters.direction = 'desc';
    }
    loadContentPage(1);
}
function setVisibilityFilter(visibility: string): void {
    contentFilters.visibility = visibility;
    loadContentPage(1);
}
function updatePostVisibility(post: SocialPost): void {
    if (!props.canManage) return;
    const nextHidden = !post.is_hidden;
    if (!window.confirm(nextHidden ? '确认隐藏这条帖子？隐藏后所有统计都会排除它。' : '确认恢复这条帖子并重新计入统计？')) return;
    router.put('/natural-traffic/brand-media/posts/visibility', {
        source_section: post.source_section,
        source_table_key: post.source_table_key,
        source_record_id: post.record_id,
        hidden: nextHidden,
    }, { preserveScroll: true, preserveState: true });
}
function selectWeek(event: Event): void {
    const week = (event.target as HTMLSelectElement).value;
    const query = contentQuery(contentFilters.page);
    query.weekly_week = week;
    router.get('/natural-traffic/brand-media', query, { preserveScroll: true, preserveState: true, replace: true });
}
function openDailyReview(review?: DailyReview): void {
    reviewForm.clearErrors();
    reviewForm.review_date = review?.review_date ?? props.dashboard.filters.date_to;
    reviewForm.status = review?.status ?? 'draft';
    reviewForm.core_data = review?.core_data ?? '';
    reviewForm.top_content = review?.top_content ?? '';
    reviewForm.low_content = review?.low_content ?? '';
    reviewForm.recommendations = review?.recommendations ?? '';
    reviewEditorOpen.value = true;
}
function saveDailyReview(status: 'draft' | 'published'): void {
    if (!props.canManage || reviewForm.processing) return;
    if (status === 'published' && !window.confirm('确认发布这份每日复盘？')) return;
    reviewForm.status = status;
    reviewForm.put('/natural-traffic/brand-media/daily-reviews', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { reviewEditorOpen.value = false; },
    });
}
function deleteDailyReview(review: DailyReview): void {
    if (!props.canManage || !window.confirm(`确认删除 ${review.review_date} 的复盘记录？`)) return;
    router.delete(`/natural-traffic/brand-media/daily-reviews/${review.uuid}`, { preserveScroll: true, preserveState: true });
}
function openWeeklyEditor(): void {
    const report = selectedWeeklyReport.value;
    if (!report) return;
    weeklyForm.clearErrors();
    weeklyForm.week_start = report.week;
    weeklyForm.title = report.title || report.label;
    weeklyForm.status = report.status === 'published' ? 'published' : 'draft';
    weeklyForm.summary = report.summary ?? '';
    weeklyEditorOpen.value = true;
}
function saveWeeklyReport(status: 'draft' | 'published'): void {
    if (!props.canManage || weeklyForm.processing) return;
    if (status === 'published' && !window.confirm('确认发布并保存当前周报统计快照？')) return;
    weeklyForm.status = status;
    weeklyForm.put('/natural-traffic/brand-media/weekly-reports', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { weeklyEditorOpen.value = false; },
    });
}
function deleteWeeklyReport(): void {
    const report = selectedWeeklyReport.value;
    if (!props.canManage || !report?.uuid || !window.confirm('确认删除这份周报记录？原始帖子不会被删除。')) return;
    router.delete(`/natural-traffic/brand-media/weekly-reports/${report.uuid}`, { preserveScroll: true, preserveState: true });
}
function loadContentPage(page: number): void {
    const lastPage = Math.max(1, props.dashboard.posts_pagination.last_page);
    const target = Math.min(Math.max(1, page), lastPage);
    router.get('/natural-traffic/brand-media', contentQuery(target), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
function applyContentFilters(): void {
    loadContentPage(1);
}
function clearContentFilters(): void {
    Object.assign(contentFilters, {
        keyword: '',
        platform: '',
        post_type: '',
        aggregation_status: '',
        visibility: 'visible',
        origin: '',
        sort: 'date',
        direction: 'desc',
        page: 1,
        per_page: 20,
    });
    loadContentPage(1);
}
</script>

<template>
    <Head title="品牌官媒" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '品牌官媒' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/brand-media" refresh-path="/natural-traffic/brand-media/refresh" :active-tab="activeTab" accent="blue" @tab="activeTab = $event" />

            <section class="grid gap-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm xl:grid-cols-[minmax(360px,.8fr)_minmax(0,1.2fr)]">
                <div>
                    <h2 class="text-lg font-black text-slate-950">导入 Instagram / Facebook</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">直接上传 Meta Business Suite 原始 CSV。按平台和帖子编号增量合并；相同帖子保留最新快照，旧文件不会覆盖新数据，未出现在本次文件中的历史记录继续保留。</p>
                    <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="uploadCsv">
                        <input ref="fileInput" type="file" accept=".csv,text/csv" :disabled="!canSync || importForm.processing" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white disabled:opacity-50" @change="selectFile" />
                        <button type="submit" :disabled="!canSync || !importForm.file || importForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-black text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ importForm.processing ? '导入中…' : '导入 CSV' }}
                        </button>
                        <a href="/natural-traffic/brand-media/import-template" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-center text-sm font-black text-slate-700 hover:border-slate-400">下载模板</a>
                    </form>
                    <p v-if="importForm.errors.file" class="mt-2 text-xs font-bold text-rose-600">{{ importForm.errors.file }}</p>
                    <p v-if="!canSync" class="mt-2 text-xs font-bold text-amber-700">当前账号没有数据同步权限，无法导入。</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <article v-for="source in dashboard.platform_sources" :key="source.platform" class="rounded-2xl border p-4" :class="source.available ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-slate-50'">
                        <div class="flex items-center justify-between gap-2"><h3 class="font-black text-slate-950">{{ source.platform }}</h3><span class="h-2.5 w-2.5 rounded-full" :class="source.available ? 'bg-emerald-500' : source.configured ? 'bg-amber-400' : 'bg-slate-300'"></span></div>
                        <p class="mt-2 text-xs font-bold text-slate-500">{{ source.mode_label }}</p>
                        <p class="mt-4 text-sm font-black" :class="source.available ? 'text-emerald-700' : 'text-slate-700'">{{ source.status }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ compact(source.record_count) }} 条记录</p>
                    </article>
                </div>
            </section>

            <aside class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950">
                <strong>IG 异常爆款口径：</strong>{{ dashboard.exclusion_policy.rule }} 超过 {{ compact(dashboard.exclusion_policy.threshold) }} 时，{{ dashboard.exclusion_policy.behavior }}
                <span v-if="dashboard.exclusion_summary.posts > 0" class="ml-2 font-black">当前筛选期已单列 {{ compact(dashboard.exclusion_summary.posts) }} 帖。</span>
            </aside>

            <NaturalTrafficEmptyState v-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <NaturalTrafficKpiGrid :kpis="dashboard.kpis" :currency="store.currency" :columns="5" />

                <template v-if="activeTab === 'platforms'">
                    <aside v-if="dashboard.platform_coverage.missing.length" class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-900">
                        {{ dashboard.platform_coverage.missing.join('、') }} 当前尚无数据；平台入口仍保留，完成 CSV 导入或官方 API 同步后自动展示表现。
                    </aside>
                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,.65fr)]">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div><h2 class="text-lg font-black text-slate-950">跨平台表现趋势</h2><p class="mt-1 text-xs text-slate-500">按发布日期和平台汇总，包含周报中单独列出的高浏览内容</p></div>
                                <div class="flex rounded-xl bg-slate-100 p-1">
                                    <button v-for="metric in trendMetrics" :key="metric.key" type="button" class="rounded-lg px-3 py-1.5 text-xs font-black transition" :class="trendMetric === metric.key ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'" @click="trendMetric = metric.key">{{ metric.label }}</button>
                                </div>
                            </div>
                            <NaturalTrafficTrendChart :points="dashboard.platform_trends" :series="platformTrendSeries" />
                        </section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-950">平台拆解</h2><p class="mt-1 text-xs text-slate-500">IG / FB / YouTube 固定展示</p>
                            <div class="mt-6 space-y-5">
                                <article v-for="row in dashboard.platforms" :key="String(row.platform)" class="rounded-2xl bg-slate-50 p-4" :class="{ 'opacity-60': !row.available }">
                                    <div class="flex items-start justify-between gap-3"><div><h3 class="font-black text-slate-900">{{ row.platform }}</h3><p class="mt-1 text-xs text-slate-500">{{ row.available ? `${compact(row.posts)} 帖 · ER ${Number(row.engagement_rate ?? 0).toFixed(2)}%` : '当前无数据' }}</p></div><strong class="text-lg tabular-nums text-slate-950">{{ row.available ? compact(row.views) : '—' }}</strong></div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-blue-500" :style="{ width: `${row.available && Number(row.views ?? 0) > 0 ? Math.max(2, Number(row.views ?? 0) / platformMax * 100) : 0}%` }"></div></div>
                                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-slate-500"><span>赞 {{ row.available ? compact(row.likes) : '—' }}</span><span>评 {{ row.available ? compact(row.comments) : '—' }}</span><span>分享 {{ row.available ? compact(row.shares) : '—' }}</span></div>
                                </article>
                            </div>
                        </section>
                    </div>
                    <section aria-label="内容表现漏斗" class="grid gap-6 xl:grid-cols-[minmax(0,.85fr)_minmax(0,1.15fr)]">
                        <article class="overflow-hidden rounded-[26px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <div>
                                <h2 class="text-lg font-black text-slate-950">品牌官媒漏斗</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-500">当前筛选期全部可见内容；10 万以上内容只在周报中单列</p>
                            </div>

                            <div class="mx-auto mt-7 max-w-[580px]">
                                <div class="brand-funnel-layer brand-funnel-layer--views relative mx-auto flex min-h-28 w-full items-center justify-center overflow-hidden bg-gradient-to-r from-blue-700 via-blue-600 to-sky-500 px-12 py-5 text-center text-white shadow-[0_18px_40px_-24px_rgba(37,99,235,.9)] sm:min-h-32">
                                    <div class="relative z-10">
                                        <p class="text-xs font-black tracking-[0.2em] text-blue-100">浏览 / 播放</p>
                                        <p class="mt-2 text-3xl font-black tabular-nums sm:text-4xl">{{ compact(brandFunnel.views) }}</p>
                                    </div>
                                </div>
                                <div class="relative z-10 mx-auto -my-1 flex w-fit items-center gap-2 rounded-full border border-blue-100 bg-white px-3 py-1.5 text-[11px] font-black text-blue-700 shadow-sm">
                                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                    互动率 {{ percent(brandFunnel.engagementRate) }}
                                </div>
                                <div class="brand-funnel-layer brand-funnel-layer--interactions relative mx-auto flex min-h-24 w-[74%] items-center justify-center overflow-hidden bg-gradient-to-r from-violet-600 via-purple-500 to-fuchsia-500 px-10 py-5 text-center text-white shadow-[0_18px_40px_-24px_rgba(147,51,234,.85)] sm:min-h-28">
                                    <div class="relative z-10">
                                        <p class="text-xs font-black tracking-[0.2em] text-violet-100">互动</p>
                                        <p class="mt-2 text-2xl font-black tabular-nums sm:text-3xl">{{ compact(brandFunnel.interactions) }}</p>
                                    </div>
                                </div>
                            </div>

                            <p class="mt-6 text-center text-[11px] leading-5 text-slate-400">互动为点赞、评论与分享之和；漏斗层宽仅表示阶段结构</p>
                        </article>

                        <article class="rounded-[26px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <div>
                                <h2 class="text-lg font-black text-slate-950">平台漏斗对比</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-500">浏览与互动共用同一刻度；没有数据的平台按 0 展示</p>
                            </div>

                            <div class="mt-6 space-y-5">
                                <article v-for="row in platformFunnelRows" :key="row.platform" class="rounded-2xl border border-slate-100 bg-slate-50/80 p-4 sm:p-5">
                                    <div class="flex items-center justify-between gap-3">
                                        <h3 class="font-black text-slate-900">{{ row.platform }}</h3>
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-black tabular-nums ring-1 ring-inset" :class="row.badgeClass">互动率 {{ percent(row.engagementRate) }}</span>
                                    </div>

                                    <div class="mt-4">
                                        <div class="mb-2 flex items-center justify-between gap-3 text-xs">
                                            <span class="font-bold text-slate-500">浏览 / 播放</span>
                                            <strong class="tabular-nums text-slate-800">{{ compact(row.views) }}</strong>
                                        </div>
                                        <div class="h-3 overflow-hidden rounded-full bg-slate-200/80">
                                            <div class="h-full rounded-full transition-[width] duration-500" :class="row.viewBarClass" :style="{ width: `${barWidth(row.views, platformFunnelMax)}%` }"></div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <div class="mb-2 flex items-center justify-between gap-3 text-xs">
                                            <span class="font-bold text-slate-500">互动</span>
                                            <strong class="tabular-nums text-slate-800">{{ compact(row.interactions) }}</strong>
                                        </div>
                                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-200/80">
                                            <div class="h-full rounded-full transition-[width] duration-500" :class="row.interactionBarClass" :style="{ width: `${barWidth(row.interactions, platformFunnelMax)}%` }"></div>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <div class="mt-5 flex flex-col gap-2 rounded-2xl border border-blue-100 bg-blue-50/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-black text-blue-700">综合互动率</p>
                                    <p class="mt-0.5 text-[11px] text-blue-600/80">全部平台互动 ÷ 浏览 / 播放</p>
                                </div>
                                <strong class="text-2xl font-black tabular-nums text-blue-700">{{ percent(brandFunnel.engagementRate) }}</strong>
                            </div>
                        </article>
                    </section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="text-lg font-black text-slate-950">筛选期内容明细</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-500">异常内容保留并标明判定指标与汇总状态；筛选只影响明细，不改变上方汇总口径</p>
                            </div>
                            <span class="text-xs font-bold tabular-nums text-slate-500">共 {{ dashboard.posts_pagination.total }} 条</span>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <button v-for="status in dashboard.post_filter_options.visibility_statuses" :key="status.value" type="button" class="rounded-xl border px-3 py-2 text-xs font-black transition" :class="contentFilters.visibility === status.value ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400'" @click="setVisibilityFilter(status.value)">
                                {{ status.label }}<span v-if="status.value === 'hidden'" class="ml-1 opacity-75">{{ dashboard.hidden_posts_count }}</span>
                            </button>
                        </div>

                        <form class="mt-4 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyContentFilters">
                            <label class="text-xs font-bold text-slate-500">
                                搜索内容
                                <input v-model="contentFilters.keyword" type="search" maxlength="100" placeholder="标题或帖子编号" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100" />
                            </label>
                            <label class="text-xs font-bold text-slate-500">
                                内容来源
                                <select v-model="contentFilters.origin" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800">
                                    <option value="">全部来源</option>
                                    <option v-for="origin in dashboard.post_filter_options.origins" :key="origin.value" :value="origin.value">{{ origin.label }}</option>
                                </select>
                            </label>
                            <label class="text-xs font-bold text-slate-500">
                                平台
                                <select v-model="contentFilters.platform" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800">
                                    <option value="">全部平台</option>
                                    <option v-for="platform in dashboard.post_filter_options.platforms" :key="platform" :value="platform">{{ platform }}</option>
                                </select>
                            </label>
                            <label class="text-xs font-bold text-slate-500">
                                内容类型
                                <select v-model="contentFilters.post_type" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800">
                                    <option value="">全部类型</option>
                                    <option v-for="postType in dashboard.post_filter_options.post_types" :key="postType" :value="postType">{{ postType }}</option>
                                </select>
                            </label>
                            <label class="text-xs font-bold text-slate-500">
                                汇总状态
                                <select v-model="contentFilters.aggregation_status" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800">
                                    <option value="">全部状态</option>
                                    <option v-for="status in dashboard.post_filter_options.aggregation_statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                                </select>
                            </label>
                            <label class="text-xs font-bold text-slate-500">
                                每页
                                <select v-model.number="contentFilters.per_page" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800">
                                    <option :value="10">10 条</option>
                                    <option :value="20">20 条</option>
                                    <option :value="50">50 条</option>
                                    <option :value="100">100 条</option>
                                </select>
                            </label>
                            <div class="flex items-end gap-2 xl:col-span-2 xl:justify-end">
                                <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-black text-white transition hover:bg-slate-800">筛选</button>
                                <button type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:border-slate-400" @click="clearContentFilters">重置</button>
                            </div>
                        </form>

                        <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                            <table class="w-full min-w-[1320px] text-left text-sm">
                                <thead class="bg-slate-50 text-[11px] font-black uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th v-for="column in postColumns" :key="column" class="whitespace-nowrap px-4 py-3.5">
                                            <button v-if="sortablePostColumns.has(column)" type="button" class="inline-flex items-center gap-1 hover:text-slate-900" @click="sortContent(column)">
                                                {{ postLabels[column] }}
                                                <span v-if="contentFilters.sort === column">{{ contentFilters.direction === 'desc' ? '↓' : '↑' }}</span>
                                            </button>
                                            <span v-else>{{ postLabels[column] }}</span>
                                        </th>
                                        <th v-if="canManage" class="whitespace-nowrap px-4 py-3.5 text-right">操作</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr v-for="post in dashboard.posts" :key="`${post.source_section}:${post.source_table_key}:${post.record_id}`" class="hover:bg-slate-50/80" :class="{ 'opacity-60': post.is_hidden }">
                                        <td v-for="column in postColumns" :key="column" class="max-w-[360px] whitespace-nowrap px-4 py-3 text-slate-700">
                                            <a v-if="column === 'permalink' && post.permalink" :href="post.permalink" target="_blank" rel="noopener noreferrer" class="font-bold text-blue-600 hover:underline">查看</a>
                                            <span v-else class="block max-w-[340px] truncate" :title="valueText(post[column])">{{ valueText(post[column]) }}</span>
                                        </td>
                                        <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
                                            <button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-black" :class="post.is_hidden ? 'border-emerald-200 text-emerald-700' : 'border-amber-200 text-amber-700'" @click="updatePostVisibility(post)">{{ post.is_hidden ? '恢复' : '隐藏' }}</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!dashboard.posts.length"><td :colspan="postColumns.length + (canManage ? 1 : 0)" class="px-5 py-14 text-center text-sm font-semibold text-slate-400">当前筛选暂无数据</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <nav v-if="dashboard.posts_pagination.total > 0" aria-label="内容明细分页" class="mt-4 flex flex-col gap-3 text-xs font-semibold text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                            <span>显示 {{ dashboard.posts_pagination.from }}–{{ dashboard.posts_pagination.to }} 条 · 第 {{ dashboard.posts_pagination.current_page }} / {{ dashboard.posts_pagination.last_page }} 页</span>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 transition hover:border-slate-400 disabled:cursor-not-allowed disabled:opacity-40" :disabled="dashboard.posts_pagination.current_page <= 1" @click="loadContentPage(dashboard.posts_pagination.current_page - 1)">上一页</button>
                                <button v-for="page in contentPageNumbers" :key="page" type="button" class="min-w-9 rounded-lg border px-3 py-2 tabular-nums transition" :class="page === dashboard.posts_pagination.current_page ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400'" :aria-current="page === dashboard.posts_pagination.current_page ? 'page' : undefined" @click="loadContentPage(page)">{{ page }}</button>
                                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 transition hover:border-slate-400 disabled:cursor-not-allowed disabled:opacity-40" :disabled="dashboard.posts_pagination.current_page >= dashboard.posts_pagination.last_page" @click="loadContentPage(dashboard.posts_pagination.current_page + 1)">下一页</button>
                            </div>
                        </nav>
                    </section>
                </template>

                <template v-else-if="activeTab === 'daily'">
                    <section class="flex flex-col gap-4 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Daily review</p><h2 class="mt-1 text-xl font-black text-slate-950">每日复盘记录</h2><p class="mt-1 text-sm text-slate-500">指标自动计算，复盘结论按日期保存，可暂存草稿或发布。</p></div>
                        <button v-if="canManage" type="button" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-black text-white shadow-sm hover:bg-blue-700" @click="openDailyReview()">新增复盘</button>
                    </section>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="item in [{ label: '活跃发布天数', value: dashboard.daily_review.posting_days }, { label: '总互动', value: dashboard.daily_review.engagements }, { label: '总触达', value: dashboard.daily_review.reach }, { label: '总点击', value: dashboard.daily_review.clicks }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p></article>
                    </div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <article class="rounded-[24px] border border-blue-100 bg-blue-50/70 p-6"><p class="text-xs font-black uppercase tracking-wider text-blue-600">常规内容浏览最佳</p><h2 class="mt-3 line-clamp-2 text-lg font-black text-slate-950">{{ dashboard.daily_review.top_post?.title || '暂无内容标题' }}</h2><p class="mt-3 text-sm text-slate-600">{{ compact(dashboard.daily_review.top_post?.views) }} 播放 / 浏览 · {{ dashboard.daily_review.top_post?.platform || '未分类平台' }}</p></article>
                        <article class="rounded-[24px] border border-emerald-100 bg-emerald-50/70 p-6"><p class="text-xs font-black uppercase tracking-wider text-emerald-600">常规内容互动最佳</p><h2 class="mt-3 line-clamp-2 text-lg font-black text-slate-950">{{ dashboard.daily_review.top_engagement_post?.title || '暂无内容标题' }}</h2><p class="mt-3 text-sm text-slate-600">赞 {{ compact(dashboard.daily_review.top_engagement_post?.likes) }} · 评 {{ compact(dashboard.daily_review.top_engagement_post?.comments) }} · 分享 {{ compact(dashboard.daily_review.top_engagement_post?.shares) }}</p></article>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">每日指标</h2><p class="mt-1 text-xs text-slate-500">按发布日期归集全部可见内容；周报异常规则不影响每日数据</p></div><NaturalTrafficDataTable :columns="['date', 'posts', 'views', 'likes', 'comments', 'shares', 'engagements', 'engagement_rate']" :rows="dashboard.daily" :labels="{ date: '日期', posts: '帖子数', views: '播放 / 浏览', likes: '点赞', comments: '评论', shares: '分享', engagements: '总互动', engagement_rate: '互动率 %' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex items-center justify-between gap-4"><div><h2 class="text-lg font-black text-slate-950">复盘档案</h2><p class="mt-1 text-xs text-slate-500">当前筛选期内保存的复盘记录</p></div><span class="text-xs font-bold text-slate-400">{{ dashboard.daily_reviews.length }} 条</span></div>
                        <div v-if="dashboard.daily_reviews.length" class="space-y-3">
                            <article v-for="review in dashboard.daily_reviews" :key="review.uuid" class="rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div><div class="flex items-center gap-2"><h3 class="font-black text-slate-950">{{ review.review_date }}</h3><span class="rounded-full px-2.5 py-1 text-[11px] font-black" :class="review.status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ review.status === 'published' ? '已发布' : '草稿' }}</span></div><p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-600">{{ review.core_data || '未填写核心数据说明' }}</p></div>
                                    <div v-if="canManage" class="flex gap-2"><button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700" @click="openDailyReview(review)">编辑</button><button type="button" class="rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-black text-rose-700" @click="deleteDailyReview(review)">删除</button></div>
                                </div>
                                <div class="mt-4 grid gap-3 text-sm lg:grid-cols-3"><div class="rounded-xl bg-white p-3"><p class="text-xs font-bold text-slate-400">Top 内容</p><p class="mt-1 line-clamp-3 text-slate-700">{{ review.top_content || '—' }}</p></div><div class="rounded-xl bg-white p-3"><p class="text-xs font-bold text-slate-400">低效内容</p><p class="mt-1 line-clamp-3 text-slate-700">{{ review.low_content || '—' }}</p></div><div class="rounded-xl bg-white p-3"><p class="text-xs font-bold text-slate-400">建议与执行</p><p class="mt-1 line-clamp-3 text-slate-700">{{ review.recommendations || '—' }}</p></div></div>
                            </article>
                        </div>
                        <p v-else class="rounded-2xl bg-slate-50 py-12 text-center text-sm font-semibold text-slate-400">当前筛选期暂无复盘记录</p>
                    </section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">发布内容明细</h2><p class="mt-1 text-xs text-slate-500">沿用“平台拆解”页签中的明细筛选与分页条件</p></div><NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" :page-size="Math.max(1, dashboard.posts.length)" /></section>
                </template>

                <template v-else-if="activeTab === 'weekly'">
                    <section class="flex flex-col gap-4 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                        <div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Weekly report</p><h2 class="mt-1 text-xl font-black text-slate-950">周报分析</h2><p class="mt-1 text-sm text-slate-500">Instagram 专属周报，按周日到周六归档，不受顶部日期筛选限制。</p></div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select :value="dashboard.selected_week ?? ''" class="min-w-64 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-800" @change="selectWeek">
                                <option v-for="option in dashboard.weekly_options" :key="option.value" :value="option.value">{{ option.label }}（{{ compact(option.posts) }} 篇）</option>
                            </select>
                            <button v-if="canManage && selectedWeeklyReport" type="button" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-black text-white" @click="openWeeklyEditor">{{ selectedWeeklyReport.uuid ? '编辑周报' : '保存周报' }}</button>
                            <button v-if="canManage && selectedWeeklyReport?.uuid" type="button" class="rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-black text-rose-700" @click="deleteWeeklyReport">删除</button>
                        </div>
                    </section>
                    <aside class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-6 text-blue-900">周报只统计 Instagram 官媒与合作内容，周期为周日到周六：Reels / 视频播放量或图片 / 轮播曝光量超过 100,000 时单独列出；Meta 文件无曝光量时以覆盖人数判定。互动为点赞 + 评论。</aside>
                    <template v-if="selectedWeeklyReport">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <article v-for="item in [{ label: '总浏览量', value: selectedWeeklyReport.included_views, hint: `${compact(selectedWeeklyReport.included_posts)} 篇计入` }, { label: '总互动数', value: selectedWeeklyReport.included_interactions, hint: `赞 ${compact(selectedWeeklyReport.likes)} + 评论 ${compact(selectedWeeklyReport.comments)}` }, { label: '帖子数', value: selectedWeeklyReport.included_posts, hint: `${compact(selectedWeeklyReport.excluded_posts)} 篇高浏览单列` }, { label: '平均浏览', value: selectedWeeklyReport.average_views, hint: '单帖均值' }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-3xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p><p class="mt-2 text-xs text-slate-400">{{ item.hint }}</p></article>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">最近周报趋势</h2><p class="mt-1 mb-5 text-xs text-slate-500">所有可用周次的计入口径数据</p><NaturalTrafficTrendChart :points="dashboard.weekly" x-key="week" :series="[{ key: 'included_views', label: '总浏览量', color: '#2563eb' }, { key: 'included_interactions', label: '总互动', color: '#8b5cf6' }]" /></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">本周 vs 上周</h2><div class="mt-5 space-y-5"><article v-for="item in weeklyComparisonCards" :key="item.key"><div class="flex items-center justify-between text-sm"><span class="font-bold text-slate-600">{{ item.label }}</span><span class="font-black" :class="(item.change ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ changeText(item.change) }}</span></div><div class="mt-2 grid grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 text-xs"><span class="text-blue-600">选中周</span><div class="h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-500" :style="{ width: `${barWidth(Number(item.current ?? 0), Math.max(1, Number(item.current ?? 0), Number(item.previous ?? 0)))}%` }"></div></div><strong>{{ compact(item.current) }}</strong><span class="text-slate-400">上周</span><div class="h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-slate-400" :style="{ width: `${barWidth(Number(item.previous ?? 0), Math.max(1, Number(item.current ?? 0), Number(item.previous ?? 0)))}%` }"></div></div><strong>{{ compact(item.previous) }}</strong></div></article></div></section>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">内容形式效率对比</h2><NaturalTrafficDataTable :columns="['type', 'posts', 'average_views', 'average_interactions']" :rows="selectedWeeklyReport.content_efficiency" :labels="{ type: '内容类型', posts: '帖子数', average_views: '平均浏览', average_interactions: '平均互动' }" /></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">单帖表现四象限</h2><p class="mt-1 text-xs text-slate-500">横轴浏览量，纵轴互动量</p><NaturalTrafficScatterPlot :points="selectedWeeklyReport.scatter" y-key="interactions" y-label="互动量" /></section>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">本周内容结构</h2><div class="space-y-4"><article v-for="row in selectedWeeklyReport.content_types" :key="String(row.type)"><div class="flex justify-between text-sm"><strong class="text-slate-700">{{ row.type }}</strong><span class="text-slate-500">{{ compact(row.posts) }} 篇（{{ Number(row.percentage ?? 0).toFixed(1) }}%）</span></div><div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-fuchsia-500" :style="{ width: `${Math.max(2, Number(row.percentage ?? 0))}%` }"></div></div></article></div></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">本周摘要</h2><ul class="space-y-3 text-sm leading-6 text-slate-700"><li v-for="item in selectedWeeklyReport.summary_items" :key="item" class="rounded-xl bg-slate-50 px-4 py-3">{{ item }}</li></ul><p v-if="selectedWeeklyReport.summary" class="mt-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900">{{ selectedWeeklyReport.summary }}</p></section>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">浏览 TOP 5</h2><NaturalTrafficDataTable :columns="['platform', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.top_views" :labels="postLabels" /></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">互动 TOP 5</h2><NaturalTrafficDataTable :columns="['platform', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.top_engagement" :labels="postLabels" /></section>
                        </div>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-1 text-lg font-black text-slate-950">不计入周报的 10 万+ 内容</h2><p class="mb-5 text-xs text-slate-500">只从周报汇总和排行排除，平台拆解与每日数据仍保留</p><NaturalTrafficDataTable :columns="['content_origin_label', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.excluded_content" :labels="postLabels" empty-text="本周没有超过阈值的 Instagram 内容" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">计入周报的帖子列表</h2><NaturalTrafficDataTable :columns="['post_type', 'content_origin_label', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.included_content" :labels="postLabels" :page-size="30" /></section>
                        <div class="grid gap-6 xl:grid-cols-2"><section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">单帖浏览趋势</h2><NaturalTrafficTrendChart :points="selectedWeeklyReport.post_performance" x-key="label" :series="[{ key: 'views', label: '浏览量', color: '#2563eb' }]" /></section><section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">单帖互动趋势</h2><NaturalTrafficTrendChart :points="selectedWeeklyReport.post_performance" x-key="label" :series="[{ key: 'interactions', label: '互动量', color: '#7c3aed' }]" /></section></div>
                    </template>
                    <p v-else class="rounded-[26px] border border-slate-200 bg-white py-16 text-center text-sm font-semibold text-slate-400">暂无可生成周报的内容数据</p>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">周报数据表</h2><NaturalTrafficDataTable :columns="['week', 'all_posts', 'included_posts', 'excluded_posts', 'all_views', 'included_views', 'average_views', 'included_interactions', 'likes', 'comments', 'shares']" :rows="dashboard.weekly" :labels="{ week: '周起始日', all_posts: '全部帖子', included_posts: '纳入帖子', excluded_posts: 'IG 异常单列', all_views: '全部浏览', included_views: '纳入浏览', average_views: '平均浏览', included_interactions: '纳入互动', likes: '纳入点赞', comments: '纳入评论', shares: '纳入分享' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">完整源数据</h2><p class="mt-1 text-xs text-slate-500">保留当前项目数据库内已同步的全部非敏感业务字段</p></div><NaturalTrafficDataTable :columns="['table_name', ...dashboard.columns]" :rows="dashboard.raw_rows" :labels="{ table_name: '来源表' }" /></section>
                </template>

                <template v-else>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-black uppercase tracking-[0.18em] text-violet-600">Data analysis</p><h2 class="mt-1 text-xl font-black text-slate-950">基于当前数据的自动分析</h2><p class="mt-2 text-sm leading-6 text-slate-500">只读取当前项目 MySQL 的可见数据，不向外部模型发送内容。</p></section>
                    <div class="grid gap-6 lg:grid-cols-3">
                        <article class="rounded-[24px] border border-blue-100 bg-blue-50/70 p-6"><p class="text-xs font-black text-blue-600">浏览领先平台</p><h3 class="mt-3 text-2xl font-black text-slate-950">{{ dashboard.ai_insights.platform_leader?.platform || '暂无' }}</h3><p class="mt-2 text-sm text-slate-600">{{ compact(dashboard.ai_insights.platform_leader?.views) }} 浏览</p></article>
                        <article class="rounded-[24px] border border-fuchsia-100 bg-fuchsia-50/70 p-6"><p class="text-xs font-black text-fuchsia-600">浏览最佳内容</p><h3 class="mt-3 line-clamp-2 font-black text-slate-950">{{ dashboard.ai_insights.top_post?.title || '暂无' }}</h3><p class="mt-2 text-sm text-slate-600">{{ compact(dashboard.ai_insights.top_post?.views) }} 浏览</p></article>
                        <article class="rounded-[24px] border border-emerald-100 bg-emerald-50/70 p-6"><p class="text-xs font-black text-emerald-600">对比上一时段</p><h3 class="mt-3 text-2xl font-black" :class="(dashboard.ai_insights.views_change ?? 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ changeText(dashboard.ai_insights.views_change) }}</h3><p class="mt-2 text-sm text-slate-600">总互动 {{ compact(dashboard.ai_insights.engagements) }}</p></article>
                    </div>
                </template>
            </template>

            <div v-if="reviewEditorOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" @click.self="reviewEditorOpen = false">
                <section class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-[28px] bg-white p-6 shadow-2xl sm:p-8">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Daily review</p><h2 class="mt-1 text-2xl font-black text-slate-950">编辑每日复盘</h2></div><button type="button" class="rounded-lg px-3 py-2 text-slate-400 hover:bg-slate-100" @click="reviewEditorOpen = false">关闭</button></div>
                    <div class="mt-6 space-y-5">
                        <label class="block text-sm font-bold text-slate-700">日期<input v-model="reviewForm.review_date" type="date" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" /></label>
                        <label class="block text-sm font-bold text-slate-700">核心数据<textarea v-model="reviewForm.core_data" rows="3" maxlength="10000" placeholder="例：IG 浏览量、帖子数、点赞、分享与评论" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></textarea></label>
                        <div class="grid gap-4 md:grid-cols-2"><label class="block text-sm font-bold text-slate-700">Top 内容<textarea v-model="reviewForm.top_content" rows="4" maxlength="10000" placeholder="表现最佳的内容及原因" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></textarea></label><label class="block text-sm font-bold text-slate-700">低效内容<textarea v-model="reviewForm.low_content" rows="4" maxlength="10000" placeholder="表现偏低的内容及原因" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></textarea></label></div>
                        <label class="block text-sm font-bold text-slate-700">平台建议与执行记录<textarea v-model="reviewForm.recommendations" rows="4" maxlength="10000" placeholder="下一步动作、负责人和执行结果" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></textarea></label>
                        <p v-if="Object.keys(reviewForm.errors).length" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">请检查必填项和字段长度。</p>
                    </div>
                    <div class="mt-7 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-black text-slate-700" @click="reviewEditorOpen = false">取消</button><button type="button" :disabled="reviewForm.processing" class="rounded-xl border border-blue-200 px-5 py-3 text-sm font-black text-blue-700 disabled:opacity-40" @click="saveDailyReview('draft')">暂存草稿</button><button type="button" :disabled="reviewForm.processing" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-black text-white disabled:opacity-40" @click="saveDailyReview('published')">发布</button></div>
                </section>
            </div>

            <div v-if="weeklyEditorOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" @click.self="weeklyEditorOpen = false">
                <section class="w-full max-w-2xl rounded-[28px] bg-white p-6 shadow-2xl sm:p-8">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Weekly report</p><h2 class="mt-1 text-2xl font-black text-slate-950">保存周报记录</h2><p class="mt-2 text-sm text-slate-500">{{ selectedWeeklyReport?.label }}</p></div><button type="button" class="rounded-lg px-3 py-2 text-slate-400 hover:bg-slate-100" @click="weeklyEditorOpen = false">关闭</button></div>
                    <div class="mt-6 space-y-5"><label class="block text-sm font-bold text-slate-700">周报名称<input v-model="weeklyForm.title" type="text" maxlength="160" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" /></label><label class="block text-sm font-bold text-slate-700">周报补充说明<textarea v-model="weeklyForm.summary" rows="6" maxlength="20000" placeholder="本周结论、异常说明和下周动作" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"></textarea></label><p v-if="Object.keys(weeklyForm.errors).length" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">请检查周报名称和说明内容。</p></div>
                    <div class="mt-7 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-black text-slate-700" @click="weeklyEditorOpen = false">取消</button><button type="button" :disabled="weeklyForm.processing" class="rounded-xl border border-blue-200 px-5 py-3 text-sm font-black text-blue-700 disabled:opacity-40" @click="saveWeeklyReport('draft')">保存草稿</button><button type="button" :disabled="weeklyForm.processing" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-black text-white disabled:opacity-40" @click="saveWeeklyReport('published')">发布</button></div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.brand-funnel-layer--views {
    clip-path: polygon(4% 0, 96% 0, 84% 100%, 16% 100%);
}

.brand-funnel-layer--interactions {
    clip-path: polygon(4% 0, 96% 0, 76% 100%, 24% 100%);
}
</style>
