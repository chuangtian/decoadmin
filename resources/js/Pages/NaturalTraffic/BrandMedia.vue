<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
type BrandDashboard = NaturalTrafficDashboardBase & {
    platforms: Array<Record<string, unknown>>;
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
    columns: string[];
    raw_rows: Array<Record<string, unknown>>;
};

const props = defineProps<{ store: { id: number; name: string; currency: string }; dashboard: BrandDashboard; configured: boolean; canSync: boolean }>();
const activeTab = ref('platforms');
const fileInput = ref<HTMLInputElement | null>(null);
const importForm = useForm<{ file: File | null }>({ file: null });
const postColumns = ['platform', 'source_mode', 'date', 'title', 'post_type', 'views', 'likes', 'comments', 'shares', 'aggregation_status', 'exclusion_metric', 'exclusion_value', 'permalink'];
const postLabels = { platform: '平台', source_mode: '来源', date: '发布日期', title: '内容', post_type: '类型', views: '播放 / 浏览', likes: '点赞', comments: '评论', shares: '分享', aggregation_status: '汇总状态', exclusion_metric: '判定指标', exclusion_value: '判定值', permalink: '链接' };
const platformMax = computed(() => Math.max(1, ...props.dashboard.platforms.map((row) => Number(row.views ?? 0))));
const funnelMax = computed(() => Math.max(1, ...props.dashboard.funnel.map((row) => row.value)));
const latestWeeklyReport = computed(() => props.dashboard.weekly_reports[props.dashboard.weekly_reports.length - 1]);

function compact(value: unknown): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value ?? 0));
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
</script>

<template>
    <Head title="品牌官媒" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '品牌官媒' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/brand-media" refresh-path="/natural-traffic/brand-media/refresh" :active-tab="activeTab" accent="blue" @tab="activeTab = $event" />

            <section class="grid gap-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm xl:grid-cols-[minmax(360px,.8fr)_minmax(0,1.2fr)]">
                <div>
                    <h2 class="text-lg font-black text-slate-950">导入 Instagram / Facebook</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">直接上传 Meta Business Suite 原始 CSV。按帖子编号去重；重复上传会更新记录，不删除历史数据。</p>
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
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5"><h2 class="text-lg font-black text-slate-950">内容表现漏斗</h2><p class="mt-1 text-xs text-slate-500">来自实际字段；已排除 IG 异常爆款</p></div>
                        <div class="grid gap-4 md:grid-cols-4">
                            <article v-for="item in dashboard.funnel" :key="item.key" class="rounded-2xl bg-slate-50 p-4">
                                <div class="flex items-center justify-between"><span class="text-xs font-black text-slate-500">{{ item.label }}</span><strong class="tabular-nums text-slate-900">{{ compact(item.value) }}</strong></div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-blue-500" :style="{ width: `${item.value > 0 ? Math.max(2, item.value / funnelMax * 100) : 0}%` }"></div></div>
                            </article>
                        </div>
                    </section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">筛选期内容明细</h2><p class="mt-1 text-xs text-slate-500">异常内容保留并标明判定指标与汇总状态</p></div><NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" /></section>
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
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">发布内容明细</h2><NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" /></section>
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
