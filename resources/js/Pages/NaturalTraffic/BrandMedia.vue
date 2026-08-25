<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
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
    aggregation_status: string;
    exclusion_metric: string;
    exclusion_value: number;
};
type WeeklyReport = Record<string, unknown> & {
    week: string;
    included_posts: number;
    excluded_posts: number;
    included_views: number;
    included_interactions: number;
    average_views: number;
    content_types: Array<Record<string, unknown>>;
    top_views: SocialPost[];
    top_engagement: SocialPost[];
    excluded_content: SocialPost[];
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
    page: number;
    per_page: number;
};
type PostFilterOptions = {
    platforms: string[];
    post_types: string[];
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
    daily: Array<Record<string, unknown>>;
    weekly: Array<Record<string, unknown>>;
    weekly_reports: WeeklyReport[];
    daily_review: { posting_days: number; engagements: number; reach: number; clicks: number; top_post: SocialPost | null; top_engagement_post: SocialPost | null };
    posts: SocialPost[];
    post_filters: PostFilters;
    post_filter_options: PostFilterOptions;
    posts_pagination: PostsPagination;
    columns: string[];
    raw_rows: Array<Record<string, unknown>>;
};

const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: BrandDashboard; configured: boolean; canSync: boolean }>();
const activeTab = ref('platforms');
const fileInput = ref<HTMLInputElement | null>(null);
const importForm = useForm<{ file: File | null }>({ file: null });
const contentFilters = reactive<PostFilters>({ ...props.dashboard.post_filters });
const postColumns = ['platform', 'source_mode', 'date', 'title', 'post_type', 'views', 'likes', 'comments', 'shares', 'aggregation_status', 'exclusion_metric', 'exclusion_value', 'permalink'];
const postLabels = { platform: '平台', source_mode: '来源', date: '发布日期', title: '内容', post_type: '类型', views: '播放 / 浏览', likes: '点赞', comments: '评论', shares: '分享', aggregation_status: '汇总状态', exclusion_metric: '判定指标', exclusion_value: '判定值', permalink: '链接' };
const platformMax = computed(() => Math.max(1, ...props.dashboard.platforms.map((row) => Number(row.views ?? 0))));
const latestWeeklyReport = computed(() => props.dashboard.weekly_reports[props.dashboard.weekly_reports.length - 1]);
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
    };
    const optional = {
        content_keyword: contentFilters.keyword.trim(),
        content_platform: contentFilters.platform,
        content_type: contentFilters.post_type,
        content_status: contentFilters.aggregation_status,
    };

    Object.entries(optional).forEach(([key, value]) => {
        if (value) query[key] = value;
    });

    return query;
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
                            <div class="mb-5"><h2 class="text-lg font-black text-slate-950">跨平台表现趋势</h2><p class="mt-1 text-xs text-slate-500">按发布日期汇总；已排除 IG 异常爆款</p></div>
                            <NaturalTrafficTrendChart :points="dashboard.trends" :series="[{ key: 'views', label: '播放 / 浏览', color: '#2563eb' }, { key: 'likes', label: '点赞', color: '#8b5cf6' }, { key: 'comments', label: '评论', color: '#10b981' }, { key: 'shares', label: '分享', color: '#f59e0b' }]" />
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
                                <p class="mt-1 text-xs leading-5 text-slate-500">当前筛选期汇总；已排除 IG 异常爆款</p>
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

                        <form class="mt-5 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-[minmax(220px,1.5fr)_minmax(140px,.8fr)_minmax(150px,.9fr)_minmax(150px,.9fr)_110px_auto]" @submit.prevent="applyContentFilters">
                            <label class="text-xs font-bold text-slate-500">
                                搜索内容
                                <input v-model="contentFilters.keyword" type="search" maxlength="100" placeholder="标题或帖子编号" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100" />
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
                            <div class="flex items-end gap-2">
                                <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-black text-white transition hover:bg-slate-800">筛选</button>
                                <button type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:border-slate-400" @click="clearContentFilters">重置</button>
                            </div>
                        </form>

                        <div class="mt-5">
                            <NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" :page-size="Math.max(1, dashboard.posts.length)" />
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
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="item in [{ label: '活跃发布天数', value: dashboard.daily_review.posting_days }, { label: '总互动', value: dashboard.daily_review.engagements }, { label: '总触达', value: dashboard.daily_review.reach }, { label: '总点击', value: dashboard.daily_review.clicks }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p></article>
                    </div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <article class="rounded-[24px] border border-blue-100 bg-blue-50/70 p-6"><p class="text-xs font-black uppercase tracking-wider text-blue-600">常规内容浏览最佳</p><h2 class="mt-3 line-clamp-2 text-lg font-black text-slate-950">{{ dashboard.daily_review.top_post?.title || '暂无内容标题' }}</h2><p class="mt-3 text-sm text-slate-600">{{ compact(dashboard.daily_review.top_post?.views) }} 播放 / 浏览 · {{ dashboard.daily_review.top_post?.platform || '未分类平台' }}</p></article>
                        <article class="rounded-[24px] border border-emerald-100 bg-emerald-50/70 p-6"><p class="text-xs font-black uppercase tracking-wider text-emerald-600">常规内容互动最佳</p><h2 class="mt-3 line-clamp-2 text-lg font-black text-slate-950">{{ dashboard.daily_review.top_engagement_post?.title || '暂无内容标题' }}</h2><p class="mt-3 text-sm text-slate-600">赞 {{ compact(dashboard.daily_review.top_engagement_post?.likes) }} · 评 {{ compact(dashboard.daily_review.top_engagement_post?.comments) }} · 分享 {{ compact(dashboard.daily_review.top_engagement_post?.shares) }}</p></article>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">每日指标</h2><p class="mt-1 text-xs text-slate-500">按发布日期归集，已排除 IG 异常爆款</p></div><NaturalTrafficDataTable :columns="['date', 'posts', 'views', 'likes', 'comments', 'shares', 'engagements', 'engagement_rate']" :rows="dashboard.daily" :labels="{ date: '日期', posts: '帖子数', views: '播放 / 浏览', likes: '点赞', comments: '评论', shares: '分享', engagements: '总互动', engagement_rate: '互动率 %' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">发布内容明细</h2><p class="mt-1 text-xs text-slate-500">沿用“平台拆解”页签中的明细筛选与分页条件</p></div><NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" :page-size="Math.max(1, dashboard.posts.length)" /></section>
                </template>

                <template v-else>
                    <aside class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-6 text-blue-900">周报口径仅对 Instagram 生效：Reels / 视频播放量或图片 / 轮播曝光量超过 100,000 时单独列出；Meta 文件无曝光量时以覆盖人数判定。互动为点赞 + 评论。</aside>
                    <div v-if="latestWeeklyReport" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <article v-for="item in [{ label: '纳入帖子', value: latestWeeklyReport.included_posts }, { label: 'IG 异常单列', value: latestWeeklyReport.excluded_posts }, { label: '纳入浏览', value: latestWeeklyReport.included_views }, { label: '平均浏览', value: latestWeeklyReport.average_views }, { label: '纳入互动', value: latestWeeklyReport.included_interactions }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p></article>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">周度表现</h2><p class="mt-1 mb-5 text-xs text-slate-500">按周一归集已纳入的帖子与互动指标</p><NaturalTrafficTrendChart :points="dashboard.weekly" x-key="week" :series="[{ key: 'views', label: '播放 / 浏览', color: '#2563eb' }, { key: 'posts', label: '帖子数', color: '#f59e0b' }]" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">周报数据表</h2><NaturalTrafficDataTable :columns="['week', 'all_posts', 'included_posts', 'excluded_posts', 'all_views', 'included_views', 'average_views', 'included_interactions', 'likes', 'comments', 'shares']" :rows="dashboard.weekly" :labels="{ week: '周起始日', all_posts: '全部帖子', included_posts: '纳入帖子', excluded_posts: 'IG 异常单列', all_views: '全部浏览', included_views: '纳入浏览', average_views: '平均浏览', included_interactions: '纳入互动', likes: '纳入点赞', comments: '纳入评论', shares: '纳入分享' }" /></section>
                    <div v-if="latestWeeklyReport" class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">内容类型分布</h2><NaturalTrafficDataTable :columns="['type', 'posts', 'views']" :rows="latestWeeklyReport.content_types" :labels="{ type: '内容类型', posts: '帖子数', views: '播放 / 浏览' }" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">浏览 TOP 5</h2><NaturalTrafficDataTable :columns="postColumns" :rows="latestWeeklyReport.top_views" :labels="postLabels" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">互动 TOP 5</h2><NaturalTrafficDataTable :columns="postColumns" :rows="latestWeeklyReport.top_engagement" :labels="postLabels" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-1 text-lg font-black text-slate-950">Instagram 异常爆款（单列）</h2><p class="mb-5 text-xs text-slate-500">保留原始数据，不进入任何经营汇总</p><NaturalTrafficDataTable :columns="postColumns" :rows="latestWeeklyReport.excluded_content" :labels="postLabels" empty-text="本周没有超过阈值的 Instagram 内容" /></section>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">完整源数据</h2><p class="mt-1 text-xs text-slate-500">保留当前项目数据库内已同步的全部非敏感业务字段</p></div><NaturalTrafficDataTable :columns="['table_name', ...dashboard.columns]" :rows="dashboard.raw_rows" :labels="{ table_name: '来源表' }" /></section>
                </template>
            </template>
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
