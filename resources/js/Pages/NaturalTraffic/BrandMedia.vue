<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import BrandMediaBarChart from '../../Components/NaturalTraffic/BrandMediaBarChart.vue';
import BrandMediaPlatformIcon from '../../Components/NaturalTraffic/BrandMediaPlatformIcon.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficContentEfficiencyChart from '../../Components/NaturalTraffic/NaturalTrafficContentEfficiencyChart.vue';
import NaturalTrafficScatterPlot from '../../Components/NaturalTraffic/NaturalTrafficScatterPlot.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import NaturalTrafficWeeklyComparisonChart from '../../Components/NaturalTraffic/NaturalTrafficWeeklyComparisonChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type SocialPost = Record<string, unknown> & {
    record_id: string;
    platform: string;
    date: string | null;
    account_handle: string;
    account_name: string;
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
    record_status: 'visible' | 'hidden' | 'deleted';
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
    content_efficiency: Array<{ type: string; posts: number; average_views: number; average_interactions: number }>;
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
type PlatformFunnelRow = { platform: string; views: number; interactions: number; engagementRate: number };
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
    post_visibility_counts: Record<string, number>;
};

const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: BrandDashboard; configured: boolean; canSync: boolean; canManage: boolean }>();
const activeTab = ref('platforms');
const trendMetric = ref<'views' | 'likes' | 'comments' | 'shares'>('views');
const weeklyTrendMetric = ref<'included_views' | 'included_interactions' | 'average_views' | 'included_posts'>('included_views');
const trendMetrics = [
    { key: 'views', label: '浏览量' },
    { key: 'likes', label: '赞' },
    { key: 'shares', label: '分享' },
    { key: 'comments', label: '评论' },
] as const;
const weeklyTrendMetrics = [
    { key: 'included_views', label: '浏览' },
    { key: 'included_interactions', label: '互动' },
    { key: 'average_views', label: '平均浏览' },
    { key: 'included_posts', label: '帖子数' },
] as const;
const fileInput = ref<HTMLInputElement | null>(null);
const reviewEditorOpen = ref(false);
const reviewEditorMode = ref<'create' | 'edit'>('create');
const reviewCombinedContent = ref('');
const reviewOriginalCombinedContent = ref('');
const weeklyEditorOpen = ref(false);
const reviewStatusFilter = ref<'all' | 'published' | 'draft'>('all');
const reviewStatuses = [
    { value: 'all', label: '全部' },
    { value: 'published', label: '已发布' },
    { value: 'draft', label: '草稿' },
] as const;
const importForm = useForm<{ file: File | null; platform: 'instagram' | 'facebook' | '' }>({ file: null, platform: '' });
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
const dateFilters = reactive({ ...props.dashboard.filters });
const importMenuOpen = ref(false);
const importMenu = ref<HTMLElement | null>(null);
const importTrigger = ref<HTMLButtonElement | null>(null);
async function toggleImportMenu(): Promise<void> {
    importMenuOpen.value = !importMenuOpen.value;
    if (importMenuOpen.value) {
        await nextTick();
        importMenu.value?.querySelector<HTMLElement>('[role="menuitem"]:not(:disabled)')?.focus();
    }
}
function closeImportMenu(returnFocus = false): void {
    importMenuOpen.value = false;
    if (returnFocus) importTrigger.value?.focus();
}
function importMenuKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeImportMenu(true);
        return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const items = Array.from(importMenu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]:not(:disabled)') ?? []);
    const index = items.indexOf(document.activeElement as HTMLElement);
    const target = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[target]?.focus();
}
function dismissImportMenu(event: Event): void {
    if (event.target instanceof Node && !importMenu.value?.contains(event.target)) closeImportMenu();
}
function importMenuFocusout(event: FocusEvent): void {
    if (event.relatedTarget instanceof Node && !importMenu.value?.contains(event.relatedTarget)) closeImportMenu();
}
watch(activeTab, () => closeImportMenu());
onMounted(() => document.addEventListener('pointerdown', dismissImportMenu));
onBeforeUnmount(() => document.removeEventListener('pointerdown', dismissImportMenu));
function choosePlatformImport(platform: 'instagram' | 'facebook'): void {
    if (!props.canSync || importForm.processing) return;
    importForm.platform = platform;
    importForm.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    closeImportMenu(true);
    fileInput.value?.click();
}
function importPlatformFile(event: Event): void {
    selectFile(event);
    if (importForm.file) uploadCsv();
}
function applyDateFilters(): void {
    if (!dateFilters.date_from || !dateFilters.date_to || dateFilters.date_from > dateFilters.date_to) return;
    router.get('/natural-traffic/brand-media', {
        ...contentQuery(1), date_from: dateFilters.date_from, date_to: dateFilters.date_to,
        comparison: dateFilters.comparison,
    }, { preserveScroll: true, preserveState: true, replace: true });
}
function setPlatformFilter(platform: string): void {
    contentFilters.platform = platform;
    loadContentPage(1);
}
function platformColor(platform: string): string {
    return ({ YouTube: '#ef4444', Instagram: '#e53986', Facebook: '#1877f2' } as Record<string, string>)[platform] ?? '#64748b';
}
watch(() => props.dashboard.filters, filters => { Object.assign(dateFilters, filters); });
const postColumns = ['platform', 'post_type', 'title', 'date', 'views', 'likes', 'comments', 'shares', 'engagement_rate'];
const postLabels: Record<string, string> = { platform: '平台', account_handle: '账号', account_name: '账户名称', content_origin_label: '内容来源', source_mode: '数据来源', date: '发布日期', title: '内容', post_type: '类型', views: '浏览量', likes: '赞', comments: '评论', shares: '分享', engagement_rate: 'ER', visibility_status: '显示状态', aggregation_status: '周报口径', permalink: '链接' };
const sortablePostColumns = new Set(['date', 'views', 'likes', 'comments', 'shares', 'engagement_rate']);
const selectedWeeklyReport = computed(() => props.dashboard.selected_weekly_report);
const filteredDailyReviews = computed(() => reviewStatusFilter.value === 'all'
    ? props.dashboard.daily_reviews
    : props.dashboard.daily_reviews.filter((review) => review.status === reviewStatusFilter.value));
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
const weeklyTrendSeries = computed(() => {
    const metric = weeklyTrendMetrics.find((item) => item.key === weeklyTrendMetric.value) ?? weeklyTrendMetrics[0];

    return [{ key: metric.key, label: metric.label, color: '#3b82f6' }];
});
const brandFunnel = computed(() => {
    const views = metricValue('views');
    const interactions = metricValue('engagements');

    return {
        views,
        interactions,
        engagementRate: views > 0 ? interactions / views * 100 : 0,
    };
});
const platformFunnelRows = computed<PlatformFunnelRow[]>(() => ['YouTube', 'Instagram', 'Facebook'].map((platform) => {
    const source = props.dashboard.platforms.find((row) => row.platform === platform);
    const views = nonNegative(source?.views);
    const interactions = nonNegative(source?.likes) + nonNegative(source?.comments) + nonNegative(source?.shares);
    return { platform, views, interactions, engagementRate: views > 0 ? interactions / views * 100 : 0 };
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
function selectWeeklyImportFile(event: Event): void {
    importForm.platform = '';
    selectFile(event);
    if (importForm.file) uploadCsv();
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
        content_platform: contentFilters.platform,
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
const postSaving = ref(false);
const postNotice = ref('');
const postError = ref('');
const postDeleteTarget = ref<SocialPost | null>(null);
const postDeleteDialog = ref<HTMLDialogElement | null>(null);
function requestPostDelete(post: SocialPost): void {
    if (!props.canManage || postSaving.value) return;
    postError.value = '';
    postDeleteTarget.value = post;
    postDeleteDialog.value?.showModal();
}
function closePostDelete(): void {
    if (postSaving.value) return;
    postDeleteDialog.value?.close();
    postDeleteTarget.value = null;
}
function updatePostState(post: SocialPost, status: 'visible' | 'hidden' | 'deleted'): void {
    if (!props.canManage || postSaving.value) return;
    postSaving.value = true;
    postError.value = '';
    postNotice.value = '';
    router.put('/natural-traffic/brand-media/posts/visibility', {
        source_section: post.source_section,
        source_table_key: post.source_table_key,
        source_record_id: post.record_id,
        status,
    }, { preserveScroll: true, preserveState: true,
        onSuccess: () => {
            postDeleteDialog.value?.close();
            postDeleteTarget.value = null;
            postNotice.value = status === 'hidden' ? '已隐藏，可在「已隐藏帖子」中查看并恢复。' : status === 'deleted' ? '已移入回收站，可随时恢复。' : '已恢复，可在「可见帖子」中查看。';
        },
        onError: () => { postError.value = '操作失败，请刷新页面后重试。'; },
        onFinish: () => { postSaving.value = false; },
    });
}
function selectWeek(event: Event): void {
    const week = (event.target as HTMLSelectElement).value;
    const query = contentQuery(contentFilters.page);
    query.weekly_week = week;
    router.get('/natural-traffic/brand-media', query, { preserveScroll: true, preserveState: true, replace: true });
}
function openDailyReview(review?: DailyReview): void {
    reviewForm.clearErrors();
    reviewEditorMode.value = review ? 'edit' : 'create';
    reviewForm.review_date = review?.review_date ?? props.dashboard.filters.date_to;
    reviewForm.status = review?.status ?? 'draft';
    reviewForm.core_data = review?.core_data ?? '';
    reviewForm.top_content = review?.top_content ?? '';
    reviewForm.low_content = review?.low_content ?? '';
    reviewForm.recommendations = review?.recommendations ?? '';
    reviewCombinedContent.value = [review?.top_content, review?.low_content]
        .filter((value): value is string => Boolean(value?.trim()))
        .join('\n');
    reviewOriginalCombinedContent.value = reviewCombinedContent.value;
    reviewEditorOpen.value = true;
}
function reviewDateLabel(value: string): string {
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) return value;
    const weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

    return `${year}/${String(month).padStart(2, '0')}/${String(day).padStart(2, '0')} ${weekdays[new Date(year, month - 1, day).getDay()]}`;
}
function saveDailyReview(status: 'draft' | 'published'): void {
    if (!props.canManage || reviewForm.processing) return;
    if (status === 'published' && !window.confirm('确认发布这份每日复盘？')) return;
    if (reviewCombinedContent.value !== reviewOriginalCombinedContent.value) {
        reviewForm.top_content = reviewCombinedContent.value;
        reviewForm.low_content = '';
    }
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
</script>

<template>
    <Head title="品牌官媒" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '品牌官媒' }]">
        <div class="social-page mx-auto w-full max-w-[1680px] space-y-4">
            <header class="social-page-header">
                <div><h1>每日官媒运营复盘台</h1><p>快速复盘每日发布与互动表现。</p></div>
                <form class="social-date-filters" @submit.prevent="applyDateFilters">
                    <div class="social-date-range">
                        <input v-model="dateFilters.date_from" type="date" aria-label="开始日期" :max="dateFilters.date_to" required @change="applyDateFilters" />
                        <span>—</span>
                        <input v-model="dateFilters.date_to" type="date" aria-label="结束日期" :min="dateFilters.date_from" required @change="applyDateFilters" />
                    </div>
                    <select v-model="dateFilters.comparison" aria-label="数据对比" @change="applyDateFilters"><option value="previous">对比上一时段</option><option value="none">无对比</option></select>
                </form>
            </header>
            <nav class="social-tabs" aria-label="官媒视图">
                <button v-for="tab in dashboard.tabs" :key="tab.key" type="button" :aria-current="activeTab === tab.key ? 'page' : undefined" :class="{ active: activeTab === tab.key }" @click="activeTab = tab.key">{{ tab.label }}</button>
            </nav>

            <template v-if="activeTab === 'platforms'">
                <div class="social-kpis">
                    <article v-for="kpi in dashboard.kpis" :key="kpi.key" class="social-card social-kpi">
                        <p>{{ kpi.label }}</p><strong>{{ kpi.key === 'posts' ? valueText(kpi.value) : compact(kpi.value) }}</strong>
                        <span v-if="dashboard.filters.comparison !== 'none'" :class="kpi.change === null || kpi.change === 0 ? 'social-muted' : kpi.change > 0 ? 'social-positive' : 'social-negative'" :title="kpi.change === null ? '上期无可比基数' : '较上一时段'">{{ changeText(kpi.change) }}</span>
                    </article>
                </div>
                <section class="social-card social-trend">
                    <div class="social-section-heading"><h2>数据变化趋势</h2><div class="social-segmented" role="group" aria-label="趋势指标"><button v-for="metric in trendMetrics" :key="metric.key" type="button" :aria-pressed="trendMetric === metric.key" :class="{ active: trendMetric === metric.key }" @click="trendMetric = metric.key">{{ metric.label }}</button></div></div>
                    <BrandMediaBarChart :points="dashboard.platform_trends" :series="platformTrendSeries" />
                </section>
                <section class="social-funnels" aria-label="内容表现漏斗">
                    <article class="social-card social-funnel-card">
                        <h2>品牌官媒漏斗</h2>
                        <div class="social-funnel-chart">
                            <svg viewBox="0 0 460 252" role="img" :aria-label="`浏览 ${valueText(brandFunnel.views)}，互动 ${valueText(brandFunnel.interactions)}`">
                                <polygon points="35,28 425,28 260,116 200,116" fill="#5874c6" />
                                <polygon points="200,120 260,120 249,212 211,212" fill="#91c875" />
                                <text x="230" y="72">浏览<tspan x="230" dy="17">{{ valueText(brandFunnel.views) }}</tspan></text>
                                <text x="230" y="159">互动<tspan x="230" dy="17">{{ valueText(brandFunnel.interactions) }}</tspan></text>
                            </svg>
                            <div class="social-funnel-legend"><span><i style="background:#5874c6"></i>浏览</span><span><i style="background:#91c875"></i>互动</span></div>
                        </div>
                    </article>
                    <article class="social-card social-funnel-card">
                        <h2>平台漏斗对比</h2>
                        <div class="social-platform-rows">
                            <div v-for="row in platformFunnelRows" :key="row.platform" :style="{ '--platform-color': platformColor(row.platform) }" class="social-platform-row">
                                <div class="social-platform-heading"><h3><BrandMediaPlatformIcon :platform="row.platform" />{{ row.platform }}</h3><span>互动率 {{ percent(row.engagementRate) }}</span></div>
                                <div v-for="metric in [{ label: '浏览', value: row.views }, { label: '互动', value: row.interactions }]" :key="metric.label" class="social-platform-bar"><span>{{ metric.label }}</span><div class="social-bar-track"><i :style="{ width: `${barWidth(metric.value, platformFunnelMax)}%` }"></i><b>{{ compact(metric.value) }}</b></div></div>
                            </div>
                        </div>
                        <div class="social-total-rate"><span>综合互动率</span><strong>{{ percent(brandFunnel.engagementRate) }}</strong></div>
                    </article>
                </section>
                <div class="social-post-toolbar">
                    <div class="social-segmented" role="group" aria-label="帖子平台筛选"><button v-for="platform in ['', 'Instagram', 'Facebook', 'YouTube']" :key="platform" type="button" :aria-pressed="contentFilters.platform === platform" :class="{ active: contentFilters.platform === platform }" @click="setPlatformFilter(platform)">{{ platform || '全部' }}</button></div>
                    <div ref="importMenu" class="social-import-menu" @keydown="importMenuKeydown" @focusout="importMenuFocusout">
                        <button ref="importTrigger" type="button" class="social-outline-button" aria-haspopup="menu" :aria-expanded="importMenuOpen" aria-controls="social-import-options" @click="toggleImportMenu">导入 / 下载模板</button>
                        <div v-if="importMenuOpen" id="social-import-options" role="menu" aria-label="导入与模板" class="social-import-options">
                            <button type="button" role="menuitem" :disabled="!canSync || importForm.processing" @click="choosePlatformImport('instagram')"><BrandMediaPlatformIcon platform="Instagram" class="social-instagram" />导入 Instagram CSV</button>
                            <button type="button" role="menuitem" :disabled="!canSync || importForm.processing" @click="choosePlatformImport('facebook')"><BrandMediaPlatformIcon platform="Facebook" class="social-facebook" />导入 Facebook CSV</button>
                            <hr role="separator" />
                            <a role="menuitem" href="/natural-traffic/brand-media/import-template?platform=instagram" @click="closeImportMenu(true)"><BrandMediaPlatformIcon platform="Instagram" class="social-instagram" />下载 IG 模板</a>
                            <a role="menuitem" href="/natural-traffic/brand-media/import-template?platform=facebook" @click="closeImportMenu(true)"><BrandMediaPlatformIcon platform="Facebook" class="social-facebook" />下载 FB 模板</a>
                        </div>
                        <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" :disabled="!canSync || importForm.processing" aria-label="导入平台 CSV" @change="importPlatformFile" />
                    </div>
                </div>
                <p v-if="importForm.processing" role="status" class="social-muted">正在导入 {{ importForm.platform === 'facebook' ? 'Facebook' : 'Instagram' }} CSV…</p>
                <p v-if="importForm.errors.file" role="alert" class="social-negative">{{ importForm.errors.file }}</p>
                <section class="social-card social-posts">
                    <div class="social-post-heading"><h2>帖子明细</h2><div class="social-visibility-tabs" role="group" aria-label="帖子显示状态"><button v-for="status in dashboard.post_filter_options.visibility_statuses" :key="status.value" type="button" :disabled="postSaving" :aria-pressed="contentFilters.visibility === status.value" :class="{ active: contentFilters.visibility === status.value }" @click="setVisibilityFilter(status.value)">{{ status.label }}<span>{{ dashboard.post_visibility_counts[status.value] ?? 0 }}</span></button></div><span class="social-muted">显示 {{ dashboard.posts.length }} / {{ dashboard.posts_pagination.total }} 条</span></div>
                    <p v-if="postNotice" role="status" class="social-notice">{{ postNotice }}</p>
                    <p v-if="postError && !postDeleteTarget" role="alert" class="social-negative">{{ postError }}</p>
                    <div class="social-table-scroll">
                        <table class="social-post-table" aria-label="帖子明细">
                            <thead><tr><th v-for="column in postColumns" :key="column" :aria-sort="sortablePostColumns.has(column) ? contentFilters.sort === column ? contentFilters.direction === 'desc' ? 'descending' : 'ascending' : 'none' : undefined" :class="`social-column-${column}`"><button v-if="sortablePostColumns.has(column)" type="button" @click="sortContent(column)">{{ postLabels[column] }}<svg class="social-sort-icon" viewBox="0 0 16 16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m4 6 4-4 4 4" :class="{ sorted: contentFilters.sort === column && contentFilters.direction === 'asc' }" /><path d="m4 10 4 4 4-4" :class="{ sorted: contentFilters.sort === column && contentFilters.direction === 'desc' }" /></svg></button><template v-else>{{ postLabels[column] }}</template></th><th>操作</th></tr></thead>
                            <tbody><tr v-for="post in dashboard.posts" :key="`${post.source_section}:${post.source_table_key}:${post.record_id}`">
                                <td><span class="social-platform-icon" :style="{ color: platformColor(post.platform) }" :title="post.platform" role="img" :aria-label="post.platform"><BrandMediaPlatformIcon :platform="post.platform" /></span></td>
                                <td>{{ post.post_type === 'YouTube 视频' ? 'Video' : valueText(post.post_type) }}</td>
                                <td class="social-post-title"><span :title="post.title">{{ post.title || '—' }}</span></td>
                                <td>{{ post.date || '—' }}</td><td class="social-views" :title="valueText(post.views)">{{ compact(post.views) }}</td><td>{{ valueText(post.likes) }}</td><td>{{ valueText(post.comments) }}</td><td>{{ valueText(post.shares) }}</td><td class="social-positive">{{ nonNegative(post.engagement_rate).toFixed(1) }}%</td>
                                <td><div class="social-post-actions"><a v-if="post.permalink" :href="post.permalink" target="_blank" rel="noopener noreferrer">查看</a><template v-if="canManage"><button type="button" :disabled="postSaving" @click="updatePostState(post, post.record_status === 'visible' ? 'hidden' : 'visible')">{{ post.record_status === 'visible' ? '隐藏' : '恢复' }}</button><button v-if="post.record_status !== 'deleted'" type="button" class="social-delete" :disabled="postSaving" @click="requestPostDelete(post)">删除</button></template><span v-if="!post.permalink && !canManage">—</span></div></td>
                            </tr><tr v-if="!dashboard.posts.length"><td :colspan="postColumns.length + 1" class="social-empty">当前筛选暂无帖子</td></tr></tbody>
                        </table>
                    </div>
                    <nav aria-label="内容明细分页" class="social-pagination"><span>共 {{ dashboard.posts_pagination.total }} 条数据</span><div><select v-model.number="contentFilters.per_page" aria-label="每页条数" @change="loadContentPage(1)"><option v-for="size in [10, 20, 50, 100]" :key="size" :value="size">{{ size }} 条/页</option></select><button type="button" aria-label="上一页" :disabled="dashboard.posts_pagination.current_page <= 1" @click="loadContentPage(dashboard.posts_pagination.current_page - 1)">‹</button><button v-for="page in contentPageNumbers" :key="page" type="button" :class="{ active: page === dashboard.posts_pagination.current_page }" :aria-current="page === dashboard.posts_pagination.current_page ? 'page' : undefined" @click="loadContentPage(page)">{{ page }}</button><button type="button" aria-label="下一页" :disabled="dashboard.posts_pagination.current_page >= dashboard.posts_pagination.last_page" @click="loadContentPage(dashboard.posts_pagination.current_page + 1)">›</button></div></nav>
                </section>
            </template>
            <NaturalTrafficEmptyState v-else-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <template v-if="activeTab === 'daily'">
                    <section class="flex flex-col gap-4 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Daily review</p><h2 class="mt-1 text-xl font-black text-slate-950">每日复盘记录</h2><p class="mt-1 text-sm text-slate-500">按日期保存复盘内容，可暂存草稿或发布。</p></div>
                        <button v-if="canManage" type="button" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-black text-white shadow-sm hover:bg-blue-700" @click="openDailyReview()">新增复盘</button>
                    </section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex flex-wrap gap-2">
                                <button v-for="status in reviewStatuses" :key="status.value" type="button" class="rounded-xl border px-4 py-2 text-xs font-black transition" :class="reviewStatusFilter === status.value ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400'" @click="reviewStatusFilter = status.value">{{ status.label }}</button>
                            </div>
                            <span class="text-xs font-bold tabular-nums text-slate-400">共 {{ filteredDailyReviews.length }} 条</span>
                        </div>
                        <div v-if="filteredDailyReviews.length" class="space-y-3">
                            <article v-for="review in filteredDailyReviews" :key="review.uuid" class="rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div><div class="flex items-center gap-2"><h3 class="font-black text-slate-950">{{ review.review_date }}</h3><span class="rounded-full px-2.5 py-1 text-[11px] font-black" :class="review.status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ review.status === 'published' ? '已发布' : '草稿' }}</span></div><p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-600">{{ review.core_data || '未填写核心数据说明' }}</p></div>
                                    <div v-if="canManage" class="flex gap-2"><button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700" @click="openDailyReview(review)">编辑</button><button type="button" class="rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-black text-rose-700" @click="deleteDailyReview(review)">删除</button></div>
                                </div>
                                <div class="mt-4 grid gap-3 text-sm lg:grid-cols-3"><div class="rounded-xl bg-white p-3"><p class="text-xs font-bold text-slate-400">Top 内容</p><p class="mt-1 line-clamp-3 text-slate-700">{{ review.top_content || '—' }}</p></div><div class="rounded-xl bg-white p-3"><p class="text-xs font-bold text-slate-400">低效内容</p><p class="mt-1 line-clamp-3 text-slate-700">{{ review.low_content || '—' }}</p></div><div class="rounded-xl bg-white p-3"><p class="text-xs font-bold text-slate-400">建议与执行</p><p class="mt-1 line-clamp-3 text-slate-700">{{ review.recommendations || '—' }}</p></div></div>
                            </article>
                        </div>
                        <p v-else class="rounded-2xl bg-slate-50 py-20 text-center text-sm font-semibold text-slate-400">暂无复盘记录</p>
                    </section>
                </template>

                <template v-else-if="activeTab === 'weekly'">
                    <section class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between">
                        <select :value="dashboard.selected_week ?? ''" class="min-w-72 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-800" @change="selectWeek">
                                <option v-for="option in dashboard.weekly_options" :key="option.value" :value="option.value">{{ option.label }}（{{ compact(option.posts) }} 篇）</option>
                        </select>
                        <div class="flex flex-wrap items-center gap-2">
                            <button v-if="canManage && selectedWeeklyReport" type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 hover:border-slate-500" @click="openWeeklyEditor">重命名</button>
                            <button v-if="canManage && selectedWeeklyReport" type="button" :disabled="!selectedWeeklyReport.uuid" :title="selectedWeeklyReport.uuid ? undefined : '请先保存这份周报'" class="rounded-xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-black text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40" @click="deleteWeeklyReport">删除</button>
                            <label v-if="canSync" class="cursor-pointer rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-blue-700" :class="importForm.processing ? 'pointer-events-none opacity-50' : ''">
                                {{ importForm.processing ? '导入中…' : '导入 CSV' }}
                                <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" :disabled="importForm.processing" @change="selectWeeklyImportFile" />
                            </label>
                        </div>
                    </section>
                    <p v-if="importForm.errors.file" class="text-xs font-bold text-rose-600">{{ importForm.errors.file }}</p>
                    <aside class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-6 text-blue-900">周报只统计 Instagram 官媒与合作内容，周期为周日到周六：Reels / 视频播放量或图片 / 轮播曝光量超过 100,000 时单独列出；Meta 文件无曝光量时以覆盖人数判定。互动为点赞 + 评论。</aside>
                    <template v-if="selectedWeeklyReport">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <article v-for="item in [{ label: '总浏览量', value: selectedWeeklyReport.included_views, hint: `${compact(selectedWeeklyReport.included_posts)} 篇计入` }, { label: '总互动数', value: selectedWeeklyReport.included_interactions, hint: `赞 ${compact(selectedWeeklyReport.likes)} + 评论 ${compact(selectedWeeklyReport.comments)}` }, { label: '帖子数', value: selectedWeeklyReport.included_posts, hint: `${compact(selectedWeeklyReport.excluded_posts)} 篇高浏览单列` }, { label: '平均浏览', value: selectedWeeklyReport.average_views, hint: '单帖均值' }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-3xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p><p class="mt-2 text-xs text-slate-400">{{ item.hint }}</p></article>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                                <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <h2 class="text-lg font-black text-slate-950">最近周报趋势</h2>
                                    <div class="flex flex-wrap rounded-xl bg-slate-100 p-1">
                                        <button v-for="metric in weeklyTrendMetrics" :key="metric.key" type="button" class="rounded-lg px-3 py-1.5 text-xs font-black transition" :class="weeklyTrendMetric === metric.key ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'" @click="weeklyTrendMetric = metric.key">{{ metric.label }}</button>
                                    </div>
                                </div>
                                <NaturalTrafficTrendChart :points="dashboard.weekly" x-key="week" :series="weeklyTrendSeries" :fill-area="true" :height="300" />
                            </section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 class="mb-5 text-lg font-black text-slate-950">本周 vs 上周</h2>
                                <NaturalTrafficWeeklyComparisonChart :items="weeklyComparisonCards" />
                            </section>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">内容形式效率对比</h2><NaturalTrafficContentEfficiencyChart :items="selectedWeeklyReport.content_efficiency" /></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">单帖表现四象限</h2><p class="mt-1 text-xs text-slate-500">横轴浏览量，纵轴互动量</p><NaturalTrafficScatterPlot :points="selectedWeeklyReport.scatter" x-label="浏览量" y-key="interactions" y-label="互动量" :log-scale="false" /></section>
                        </div>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">本周内容结构</h2><div class="space-y-4"><article v-for="row in selectedWeeklyReport.content_types" :key="String(row.type)"><div class="flex justify-between text-sm"><strong class="text-slate-700">{{ row.type }}</strong><span class="text-slate-500">{{ compact(row.posts) }} 篇（{{ Number(row.percentage ?? 0).toFixed(1) }}%）</span></div><div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-fuchsia-500" :style="{ width: `${Math.max(2, Number(row.percentage ?? 0))}%` }"></div></div></article></div></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">本周摘要</h2><ul class="space-y-3 text-sm leading-6 text-slate-700"><li v-for="item in selectedWeeklyReport.summary_items" :key="item" class="rounded-xl bg-slate-50 px-4 py-3">{{ item }}</li></ul><p v-if="selectedWeeklyReport.summary" class="mt-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900">{{ selectedWeeklyReport.summary }}</p></section>
                        </div>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-1 text-lg font-black text-slate-950">不计入周报的 10 万+ 内容</h2><p class="mb-5 text-xs text-slate-500">高波动内容单独列出；只从周报汇总和排行排除，平台拆解与每日数据仍保留</p><NaturalTrafficDataTable :columns="['account_handle', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.excluded_content" :labels="postLabels" empty-text="本周没有超过阈值的 Instagram 内容" /></section>
                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">浏览 TOP 5</h2><NaturalTrafficDataTable :columns="['account_handle', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.top_views" :labels="postLabels" /></section>
                            <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">互动 TOP 5</h2><NaturalTrafficDataTable :columns="['account_handle', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.top_engagement" :labels="postLabels" /></section>
                        </div>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">计入周报的帖子列表</h2><NaturalTrafficDataTable :columns="['post_type', 'account_handle', 'title', 'views', 'likes', 'comments', 'permalink']" :rows="selectedWeeklyReport.included_content" :labels="postLabels" :page-size="8" /></section>
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
                <section class="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-[28px] bg-white p-6 shadow-2xl sm:p-9">
                    <div class="flex items-start justify-between gap-4"><div><h2 class="text-2xl font-black text-slate-950">{{ reviewEditorMode === 'create' ? '新增复盘记录' : '编辑复盘记录' }}</h2><p class="mt-5 text-sm font-semibold text-slate-500">日期：{{ reviewDateLabel(reviewForm.review_date) }}</p></div><button type="button" aria-label="关闭" class="rounded-lg px-3 py-1 text-3xl font-light leading-none text-slate-400 hover:bg-slate-100" @click="reviewEditorOpen = false">×</button></div>
                    <div class="mt-7 space-y-6">
                        <label class="block text-base font-black text-slate-800">核心数据<textarea v-model="reviewForm.core_data" rows="4" maxlength="10000" placeholder="例：IG 浏览量 12.5K，帖子数 8，点赞量 1.2K，分享/评论 89/156..." class="mt-3 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm"></textarea></label>
                        <label class="block text-base font-black text-slate-800">Top 内容与低效内容<textarea v-model="reviewCombinedContent" rows="4" maxlength="10000" placeholder="例：Top：X7 开箱 Reels 表现最佳。低效：产品图文帖互动率偏低..." class="mt-3 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm"></textarea></label>
                        <label class="block text-base font-black text-slate-800">平台建议与执行记录<textarea v-model="reviewForm.recommendations" rows="4" maxlength="10000" placeholder="例：IG 继续复用高互动内容；已安排评论回复和素材二剪..." class="mt-3 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm"></textarea></label>
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
        <Teleport to="body">
            <dialog ref="postDeleteDialog" aria-labelledby="delete-post-title" aria-describedby="delete-post-description" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 bg-white p-6 text-slate-900 shadow-2xl backdrop:bg-slate-950/50" @cancel.prevent="closePostDelete" @click.self="closePostDelete">
                <h2 id="delete-post-title" class="text-[16px] leading-6 font-bold">是否删除该条数据？</h2>
                <p v-if="postDeleteTarget" class="mt-3 rounded-xl bg-slate-50 p-3 text-[14px] leading-5 font-medium break-words">{{ postDeleteTarget.title || '帖子记录' }}<span class="ml-2 font-normal text-slate-500">{{ postDeleteTarget.date }}</span></p>
                <p id="delete-post-description" class="mt-3 text-[14px] leading-6 text-slate-600">删除后将移入回收站，不再参与统计和对比，可在回收站恢复。</p>
                <p v-if="postError" role="alert" class="mt-3 text-[14px] text-red-600">{{ postError }}</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" autofocus :disabled="postSaving" class="h-11 rounded-xl border border-slate-200 px-5 text-[14px] font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="closePostDelete">取消</button>
                    <button type="button" :disabled="postSaving" class="h-11 rounded-xl bg-red-600 px-5 text-[14px] font-semibold text-white hover:bg-red-700 disabled:opacity-50" @click="postDeleteTarget && updatePostState(postDeleteTarget, 'deleted')">{{ postSaving ? '删除中…' : '确认删除' }}</button>
                </div>
            </dialog>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.social-page { color: #334155; font-size: 14px; line-height: 1.5; }
.social-page-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 3px 0 14px; }
.social-page-header h1 { font-size: 22px; font-weight: 650; color: #0f172a; line-height: 1.4; }
.social-page-header p { margin-top: 4px; font-size: 14px; color: #64748b; }
.social-date-filters, .social-date-range { display: flex; align-items: center; gap: 10px; }
.social-date-range { padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 5px; background: white; }
.social-date-range input { min-width: 125px; width: 130px; border: 0; padding: 6px 0; background: transparent; font-size: 13px; color: #334155; outline-offset: 3px; }
.social-date-range span { color: #94a3b8; }
.social-date-filters select, .social-pagination select { border: 1px solid #cbd5e1; border-radius: 5px; background: white; padding: 6px 10px; font-size: 13px; color: #475569; }
.social-tabs { display: flex; gap: 6px; border-bottom: 1px solid #dbe2ec; overflow-x: auto; }
.social-tabs button { flex: none; padding: 10px 16px; font-size: 14px; color: #64748b; border-bottom: 2px solid transparent; }
.social-tabs button.active { color: #2563eb; border-bottom-color: #2563eb; background: #eff6ff; border-radius: 5px 5px 0 0; }
.social-card { min-width: 0; border: 1px solid #dbe2ec; border-radius: 6px; background: white; }
.social-card h2 { font-size: 14px; font-weight: 650; color: #334155; }
.social-kpis { display: grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 12px; }
.social-kpi { padding: 16px 22px; border-top: 2px solid #3b82f6; min-height: 111px; }
.social-kpi p { color: #64748b; font-size: 14px; }
.social-kpi strong { display: block; margin-top: 5px; color: #0f172a; font-size: 23px; font-weight: 650; line-height: 1.3; font-variant-numeric: tabular-nums; }
.social-kpi > span { display: block; margin-top: 3px; font-size: 13px; font-variant-numeric: tabular-nums; }
.social-muted { color: #64748b; }
.social-positive { color: #15976d; }
.social-negative { color: #e5484d; }
.social-trend { padding: 16px 24px 12px; }
.social-section-heading { display: flex; flex-wrap: wrap; align-items: center; gap: 18px; margin-bottom: 20px; }
.social-segmented { display: inline-flex; flex-wrap: wrap; padding: 2px; border-radius: 5px; background: #f1f5f9; }
.social-segmented button { border-radius: 4px; padding: 5px 12px; font-size: 13px; color: #64748b; }
.social-segmented button.active { background: #e1edff; color: #2563eb; }
.social-funnels { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); align-items: stretch; gap: 16px; }
.social-funnel-card { padding: 20px 24px; }
.social-funnel-chart { display: flex; align-items: center; min-height: 275px; gap: 16px; }
.social-funnel-chart svg { flex: 1; min-width: 0; max-width: 500px; height: auto; }
.social-funnel-chart text { fill: white; font-size: 13px; text-anchor: middle; font-variant-numeric: tabular-nums; }
.social-funnel-legend { display: flex; flex-direction: column; flex: none; gap: 4px; color: #64748b; font-size: 12px; }
.social-funnel-legend span { display: flex; align-items: center; gap: 5px; }
.social-funnel-legend i { width: 22px; height: 12px; border-radius: 3px; }
.social-platform-rows { margin-top: 26px; display: grid; gap: 17px; }
.social-platform-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; }
.social-platform-heading h3 { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; color: var(--platform-color); }
.social-platform-heading > span { font-size: 12px; color: #64748b; font-variant-numeric: tabular-nums; }
.social-platform-bar { display: flex; align-items: center; gap: 8px; margin-top: 5px; }
.social-platform-bar > span { flex: none; width: 26px; font-size: 12px; color: #64748b; }
.social-bar-track { flex: 1; position: relative; height: 20px; overflow: hidden; border-radius: 5px; background: #f1f5f9; }
.social-bar-track i { position: absolute; left: 0; top: 0; bottom: 0; background: var(--platform-color); opacity: .16; border-radius: 4px; transition: width .2s; }
.social-bar-track b { position: relative; padding-left: 8px; font-size: 12px; font-weight: 600; color: var(--platform-color); font-variant-numeric: tabular-nums; }
.social-total-rate { display: flex; justify-content: space-between; margin-top: 20px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; }
.social-total-rate strong { font-size: 14px; color: #2563eb; font-variant-numeric: tabular-nums; }
.social-post-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.social-outline-button { padding: 5px 10px; border: 1px solid #93b9f6; border-radius: 4px; background: white; color: #2563eb; font-size: 13px; }
.social-posts { padding: 18px 24px; }
.social-post-heading { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.social-post-heading > span { font-size: 13px; }
.social-visibility-tabs { display: flex; flex-wrap: wrap; gap: 16px; }
.social-visibility-tabs button { padding: 4px 0; border-bottom: 2px solid transparent; color: #64748b; font-size: 14px; }
.social-visibility-tabs button.active { color: #2563eb; border-bottom-color: #2563eb; }
.social-visibility-tabs span { margin-left: 5px; font-size: 12px; }
.social-notice { margin: -8px 0 12px; color: #2563eb; font-size: 14px; }
.social-table-scroll { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 4px; }
.social-post-table { width: 100%; min-width: 1050px; border-collapse: collapse; text-align: left; font-size: 14px; }
.social-post-table th, .social-post-table td { padding: 10px 12px; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.social-post-table th { font-weight: 500; background: #f8fafc; color: #64748b; }
.social-post-table th button { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; }
.social-sort-icon { width: 12px; height: 16px; flex: none; color: #a6b2c3; }
.social-sort-icon .sorted { color: #2563eb; }
.social-post-table th:last-child, .social-post-table td:last-child { border-right: 0; }
.social-post-table tr:last-child td { border-bottom: 0; }
.social-post-table tbody tr:nth-child(even) { background: #fafbfd; }
.social-post-table tbody tr:hover { background: #f4f7fc; }
.social-column-title { width: 34%; }
.social-post-table td.social-post-title { white-space: normal; min-width: 220px; max-width: 430px; }
.social-post-title span { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.6; overflow-wrap: anywhere; }
.social-platform-icon { display: inline-flex; align-items: center; justify-content: center; width: 27px; height: 25px; border-radius: 4px; background: #f1f5f9; }
.social-views { font-weight: 650; }
.social-post-table td:not(.social-post-title) { font-variant-numeric: tabular-nums; }
.social-post-actions { display: flex; align-items: center; gap: 10px; font-size: 14px; color: #2563eb; }
.social-post-actions .social-delete { color: #e5484d; }
.social-post-actions a:hover, .social-post-actions button:hover { text-decoration: underline; }
.social-post-table td.social-empty { text-align: center; padding: 40px 16px; color: #94a3b8; }
.social-pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 16px; font-size: 14px; color: #64748b; }
.social-pagination > div { display: flex; align-items: center; gap: 10px; }
.social-pagination select { margin-right: 8px; }
.social-pagination button { min-width: 32px; height: 32px; border-radius: 4px; }
.social-pagination button.active { background: #3b82f6; color: white; }
.social-page button:disabled { opacity: .4; cursor: not-allowed; }
.social-page button:focus-visible, .social-page select:focus-visible { outline: 2px solid #60a5fa; outline-offset: 3px; }
.social-import-menu { position: relative; flex: none; }
.social-import-options { position: absolute; top: calc(100% + 6px); right: 0; z-index: 30; width: 210px; padding: 5px 0; border: 1px solid #dbe2ec; border-radius: 6px; background: white; box-shadow: 0 6px 20px #0f172a12; }
.social-import-options button, .social-import-options a { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 13px; font-size: 14px; line-height: 20px; text-align: left; color: #334155; white-space: nowrap; }
.social-import-options button:hover, .social-import-options a:hover, .social-import-options [role="menuitem"]:focus { background: #f1f5f9; outline: none; }
.social-import-options hr { margin: 4px 0; border: 0; border-top: 1px solid #e2e8f0; }
.social-import-options .social-instagram { color: #e53986; }
.social-import-options .social-facebook { color: #1877f2; }
@media (max-width: 1100px) { .social-page-header { align-items: flex-start; flex-direction: column; gap: 12px; } .social-funnel-card { padding: 18px; } .social-funnel-chart { gap: 8px; } }
@media (max-width: 760px) { .social-kpis { grid-template-columns: repeat(2,minmax(0,1fr)); } .social-funnels { grid-template-columns: minmax(0,1fr); } .social-date-filters { flex-wrap: wrap; } .social-date-range { gap: 5px; } .social-date-range input { min-width: 110px; width: 120px; } .social-trend, .social-posts { padding: 16px; } .social-post-toolbar { align-items: flex-start; flex-wrap: wrap; } .social-post-heading { gap: 10px; } }
</style>
