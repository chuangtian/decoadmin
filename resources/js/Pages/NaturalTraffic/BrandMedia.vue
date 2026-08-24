<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import NaturalTrafficDataTable from '../../Components/NaturalTraffic/NaturalTrafficDataTable.vue';
import NaturalTrafficEmptyState from '../../Components/NaturalTraffic/NaturalTrafficEmptyState.vue';
import NaturalTrafficKpiGrid from '../../Components/NaturalTraffic/NaturalTrafficKpiGrid.vue';
import NaturalTrafficPageHeader from '../../Components/NaturalTraffic/NaturalTrafficPageHeader.vue';
import NaturalTrafficTrendChart from '../../Components/NaturalTraffic/NaturalTrafficTrendChart.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

type SocialPost = Record<string, unknown> & { record_id: string; platform: string; date: string | null; title: string; post_type: string; posts: number; views: number; likes: number; comments: number; shares: number; engagement_rate: number; permalink: string };
type WeeklyReport = Record<string, unknown> & { week: string; included_posts: number; excluded_posts: number; included_views: number; included_interactions: number; average_views: number; content_types: Array<Record<string, unknown>>; top_views: SocialPost[]; top_engagement: SocialPost[]; excluded_content: SocialPost[] };
type BrandDashboard = NaturalTrafficDashboardBase & {
    platforms: Array<Record<string, unknown>>;
    platform_coverage: { available: string[]; missing: string[] };
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
const postColumns = ['platform', 'date', 'title', 'post_type', 'views', 'likes', 'comments', 'shares', 'engagement_rate', 'permalink'];
const postLabels = { platform: '平台', date: '发布日期', title: '内容', post_type: '类型', views: '浏览', likes: '点赞', comments: '评论', shares: '分享', engagement_rate: '互动率 %', permalink: '链接' };
const platformMax = computed(() => Math.max(1, ...props.dashboard.platforms.map((row) => Number(row.views ?? 0))));
const funnelMax = computed(() => Math.max(1, ...props.dashboard.funnel.map((row) => row.value)));
const latestWeeklyReport = computed(() => props.dashboard.weekly_reports[props.dashboard.weekly_reports.length - 1]);
function compact(value: unknown): string { return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value ?? 0)); }
</script>

<template>
    <Head title="品牌官媒" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: '品牌官媒' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <NaturalTrafficPageHeader :dashboard="dashboard" :store="store" :configured="configured" :can-sync="canSync" route-path="/natural-traffic/brand-media" refresh-path="/natural-traffic/brand-media/refresh" :active-tab="activeTab" accent="blue" @tab="activeTab = $event" />
            <NaturalTrafficEmptyState v-if="!dashboard.source.ready" :configured="configured" :can-sync="canSync" />
            <template v-else>
                <NaturalTrafficKpiGrid :kpis="dashboard.kpis" :currency="store.currency" :columns="5" />

                <template v-if="activeTab === 'platforms'">
                    <aside v-if="dashboard.platform_coverage.missing.length" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                        当前项目已同步 {{ dashboard.platform_coverage.available.join('、') || '暂无平台' }}；{{ dashboard.platform_coverage.missing.join('、') }} 尚未配置数据源，因此不显示虚构的 0 或估算值。
                    </aside>
                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,.65fr)]">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="mb-5"><h2 class="text-lg font-black text-slate-950">跨平台表现趋势</h2><p class="mt-1 text-xs text-slate-500">按发布日期汇总当前筛选范围</p></div>
                            <NaturalTrafficTrendChart :points="dashboard.trends" :series="[{ key: 'views', label: '浏览量', color: '#2563eb' }, { key: 'likes', label: '点赞', color: '#8b5cf6' }, { key: 'comments', label: '评论', color: '#10b981' }, { key: 'shares', label: '分享', color: '#f59e0b' }]" />
                        </section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-950">平台拆解</h2><p class="mt-1 text-xs text-slate-500">帖子、互动与流量统一口径</p>
                            <div class="mt-6 space-y-5">
                                <article v-for="row in dashboard.platforms" :key="String(row.platform)" class="rounded-2xl bg-slate-50 p-4">
                                    <div class="flex items-start justify-between gap-3"><div><h3 class="font-black text-slate-900">{{ row.platform }}</h3><p class="mt-1 text-xs text-slate-500">{{ compact(row.posts) }} 帖 · ER {{ Number(row.engagement_rate ?? 0).toFixed(2) }}%</p></div><strong class="text-lg tabular-nums text-slate-950">{{ compact(row.views) }}</strong></div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-blue-500" :style="{ width: `${Math.max(2, Number(row.views ?? 0) / platformMax * 100)}%` }"></div></div>
                                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-slate-500"><span>赞 {{ compact(row.likes) }}</span><span>评 {{ compact(row.comments) }}</span><span>分享 {{ compact(row.shares) }}</span></div>
                                </article>
                            </div>
                        </section>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5"><h2 class="text-lg font-black text-slate-950">内容表现漏斗</h2><p class="mt-1 text-xs text-slate-500">只展示当前源表中实际存在的浏览、触达、互动与点击字段</p></div>
                        <div class="grid gap-4 md:grid-cols-4">
                            <article v-for="item in dashboard.funnel" :key="item.key" class="rounded-2xl bg-slate-50 p-4">
                                <div class="flex items-center justify-between"><span class="text-xs font-black text-slate-500">{{ item.label }}</span><strong class="tabular-nums text-slate-900">{{ compact(item.value) }}</strong></div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-blue-500" :style="{ width: `${item.value > 0 ? Math.max(2, item.value / funnelMax * 100) : 0}%` }"></div></div>
                            </article>
                        </div>
                    </section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">筛选期内容明细</h2><p class="mt-1 text-xs text-slate-500">用于核对平台汇总的逐条内容数据</p></div><NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" /></section>
                </template>

                <template v-else-if="activeTab === 'daily'">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="item in [{ label: '活跃发布天数', value: dashboard.daily_review.posting_days }, { label: '总互动', value: dashboard.daily_review.engagements }, { label: '总触达', value: dashboard.daily_review.reach }, { label: '总点击', value: dashboard.daily_review.clicks }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p></article>
                    </div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <article class="rounded-[24px] border border-blue-100 bg-blue-50/70 p-6"><p class="text-xs font-black uppercase tracking-wider text-blue-600">浏览表现最佳</p><h2 class="mt-3 line-clamp-2 text-lg font-black text-slate-950">{{ dashboard.daily_review.top_post?.title || '暂无内容标题' }}</h2><p class="mt-3 text-sm text-slate-600">{{ compact(dashboard.daily_review.top_post?.views) }} 浏览 · {{ dashboard.daily_review.top_post?.platform || '未分类平台' }}</p></article>
                        <article class="rounded-[24px] border border-emerald-100 bg-emerald-50/70 p-6"><p class="text-xs font-black uppercase tracking-wider text-emerald-600">互动表现最佳</p><h2 class="mt-3 line-clamp-2 text-lg font-black text-slate-950">{{ dashboard.daily_review.top_engagement_post?.title || '暂无内容标题' }}</h2><p class="mt-3 text-sm text-slate-600">赞 {{ compact(dashboard.daily_review.top_engagement_post?.likes) }} · 评 {{ compact(dashboard.daily_review.top_engagement_post?.comments) }} · 分享 {{ compact(dashboard.daily_review.top_engagement_post?.shares) }}</p></article>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">每日指标</h2><p class="mt-1 text-xs text-slate-500">按发布日期归集，便于逐日复盘</p></div><NaturalTrafficDataTable :columns="['date', 'posts', 'views', 'likes', 'comments', 'shares', 'engagements', 'engagement_rate']" :rows="dashboard.daily" :labels="{ date: '日期', posts: '帖子数', views: '浏览', likes: '点赞', comments: '评论', shares: '分享', engagements: '总互动', engagement_rate: '互动率 %' }" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">发布内容明细</h2><NaturalTrafficDataTable :columns="postColumns" :rows="dashboard.posts" :labels="postLabels" /></section>
                </template>

                <template v-else>
                    <aside class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-6 text-blue-900">周报核心口径：浏览量超过 100,000 的爆款内容单独列出，不计入常规内容均值与互动汇总；互动为点赞 + 评论。</aside>
                    <div v-if="latestWeeklyReport" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <article v-for="item in [{ label: '纳入帖子', value: latestWeeklyReport.included_posts }, { label: '爆款单列', value: latestWeeklyReport.excluded_posts }, { label: '纳入浏览', value: latestWeeklyReport.included_views }, { label: '平均浏览', value: latestWeeklyReport.average_views }, { label: '纳入互动', value: latestWeeklyReport.included_interactions }]" :key="item.label" class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-black tabular-nums text-slate-950">{{ compact(item.value) }}</p></article>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-black text-slate-950">周度表现</h2><p class="mt-1 mb-5 text-xs text-slate-500">按周一归集帖子与互动指标</p><NaturalTrafficTrendChart :points="dashboard.weekly" x-key="week" :series="[{ key: 'views', label: '浏览量', color: '#2563eb' }, { key: 'posts', label: '帖子数', color: '#f59e0b' }]" /></section>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">周报数据表</h2><NaturalTrafficDataTable :columns="['week', 'posts', 'included_posts', 'excluded_posts', 'views', 'included_views', 'average_views', 'included_interactions', 'likes', 'comments', 'shares']" :rows="dashboard.weekly" :labels="{ week: '周起始日', posts: '总帖子', included_posts: '纳入帖子', excluded_posts: '爆款单列', views: '总浏览', included_views: '纳入浏览', average_views: '平均浏览', included_interactions: '纳入互动', likes: '点赞', comments: '评论', shares: '分享' }" /></section>
                    <div v-if="latestWeeklyReport" class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">内容类型分布</h2><NaturalTrafficDataTable :columns="['type', 'posts', 'views']" :rows="latestWeeklyReport.content_types" :labels="{ type: '内容类型', posts: '帖子数', views: '浏览' }" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">浏览 TOP 5</h2><NaturalTrafficDataTable :columns="postColumns" :rows="latestWeeklyReport.top_views" :labels="postLabels" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-black text-slate-950">互动 TOP 5</h2><NaturalTrafficDataTable :columns="postColumns" :rows="latestWeeklyReport.top_engagement" :labels="postLabels" /></section>
                        <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-1 text-lg font-black text-slate-950">爆款内容（单列）</h2><p class="mb-5 text-xs text-slate-500">浏览量超过 100,000，不进入常规内容均值</p><NaturalTrafficDataTable :columns="postColumns" :rows="latestWeeklyReport.excluded_content" :labels="postLabels" empty-text="本周没有超过 100,000 浏览的内容" /></section>
                    </div>
                    <section class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5"><h2 class="text-lg font-black text-slate-950">完整源数据</h2><p class="mt-1 text-xs text-slate-500">保留当前项目数据库内已同步的全部非敏感业务字段</p></div><NaturalTrafficDataTable :columns="['table_name', ...dashboard.columns]" :rows="dashboard.raw_rows" :labels="{ table_name: '来源表' }" /></section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
