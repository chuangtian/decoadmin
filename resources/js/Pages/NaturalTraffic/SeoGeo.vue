<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SeoGa4SourcePanel from '../../Components/NaturalTraffic/SeoGa4SourcePanel.vue';
import SeoGscSourcePanel from '../../Components/NaturalTraffic/SeoGscSourcePanel.vue';
import SeoOverviewPanel from '../../Components/NaturalTraffic/SeoOverviewPanel.vue';

type MetricStatus = 'leading' | 'achievable' | 'catch_up' | 'high_risk' | 'no_data';
type MetricCategory = 'outcome' | 'traffic' | 'work';
type Metric = {
    id: string;
    name: string;
    category: MetricCategory;
    unit: string;
    source: string;
    current: number;
    target: number;
    completion: number;
    expected: number;
    forecast: number;
    forecast_rate: number;
    gap: number;
    pace: number;
    pace_kind: 'data' | 'calendar';
    status: MetricStatus;
};
type Dashboard = {
    schema: 'seo-goal-dashboard-v1';
    month: string;
    months: string[];
    target_origin: 'monthly' | 'default';
    generated_at: string;
    last_synced_at: string | null;
    data_through: string | null;
    data_range: { from: string | null; through: string | null; days: number };
    progress: { data: number; calendar: number };
    summary: { overall: 'catch_up' | 'manageable' | 'normal' | 'no_data'; expected_count: number; total_count: number; catch_up_count: number };
    metrics: Metric[];
    data_ready: boolean;
};
type SourceStatus = { configured: boolean; has_configuration: boolean; missing_fields: string[] };

const props = defineProps<{
    store: { id: number; name: string };
    activeTab: 'goals' | 'overview' | 'gsc' | 'ga4';
    dashboard: Dashboard | null;
    overview: any | null;
    overviewSyncStatus: any;
    overviewSourceStatus: { gsc: boolean; ga4: boolean; configured: boolean; missing: string[] };
    sourceStatus: { daily: SourceStatus; work: SourceStatus };
    canSync: boolean;
}>();

const activeCategory = ref<'all' | MetricCategory>('all');
const syncing = ref(false);
const tabs = [
    { key: 'goals', label: '目标看板', enabled: true },
    { key: 'overview', label: '综合看板', enabled: true },
    { key: 'gsc', label: 'GSC 源数据', enabled: true },
    { key: 'ga4', label: 'GA 源数据', enabled: true },
];
const categoryTabs: Array<{ id: 'all' | MetricCategory; label: string }> = [
    { id: 'all', label: '全部' },
    { id: 'outcome', label: '结果' },
    { id: 'traffic', label: '流量' },
    { id: 'work', label: '动作' },
];
const statusMeta: Record<MetricStatus, { label: string; badge: string; bar: string; accent: string }> = {
    no_data: { label: '暂无数据', badge: 'bg-slate-50 text-slate-600 ring-slate-200', bar: 'bg-slate-300', accent: 'border-t-slate-300' },
    leading: { label: '领先', badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200', bar: 'bg-emerald-500', accent: 'border-t-emerald-500' },
    achievable: { label: '可达成', badge: 'bg-teal-50 text-teal-700 ring-teal-200', bar: 'bg-teal-500', accent: 'border-t-teal-500' },
    catch_up: { label: '需追赶', badge: 'bg-amber-50 text-amber-700 ring-amber-200', bar: 'bg-amber-400', accent: 'border-t-amber-400' },
    high_risk: { label: '高风险', badge: 'bg-rose-50 text-rose-700 ring-rose-200', bar: 'bg-rose-500', accent: 'border-t-rose-500' },
};
const advice: Record<string, { text: string; owner: string }> = {
    seo_gmv: { text: '推进高转化页面和购物流量，优先补足高意向入口的承接。', owner: '营收' },
    industry_clicks: { text: '处理排名下滑和 CTR 偏低页面，优先修复核心行业词流量。', owner: 'SEO' },
    blog_clicks: { text: '优先处理下钻文章、新文章及内链承接，提升博客页有效点击。', owner: '内容 + SEO' },
    new_blog: { text: '关注排期与上线节奏，提前清理发布前依赖和阻塞项。', owner: '内容' },
    old_blog: { text: '关注发布质量和上线前的数据回滚，按影响优先级推进优化。', owner: '内容 + SEO' },
    backlinks: { text: '确认实际合作上线记录并及时回填，保证当月统计完整。', owner: '外链' },
    ai_automation: { text: '关注本月计入标准和有效产出，避免重复流程被重复计算。', owner: '自动化' },
};

const filteredMetrics = computed(() => activeCategory.value === 'all'
    ? (props.dashboard?.metrics ?? [])
    : (props.dashboard?.metrics ?? []).filter((metric) => metric.category === activeCategory.value));
const focusActions = computed(() => (props.dashboard?.metrics ?? [])
    .filter((metric) => metric.status !== 'no_data' && metric.forecast_rate < 1.2)
    .sort((a, b) => a.forecast_rate - b.forecast_rate));
const sourcesConfigured = computed(() => props.sourceStatus.daily.configured && props.sourceStatus.work.configured);
const noMonthData = computed(() => props.dashboard?.summary.overall === 'no_data');
const overallMeta = computed(() => ({
    no_data: { title: '暂无数据', note: '所选月份尚无同步记录，暂不判断目标进度', color: 'text-slate-600', line: 'bg-slate-300' },
    catch_up: { title: '需追赶', note: '多项指标落后，建议集中补进度', color: 'text-amber-700', line: 'bg-amber-400' },
    manageable: { title: '基本可控', note: '少量指标需要重点跟进', color: 'text-blue-700', line: 'bg-blue-500' },
    normal: { title: '节奏正常', note: '当前指标整体符合进度预期', color: 'text-emerald-700', line: 'bg-emerald-500' },
}[props.dashboard?.summary.overall ?? 'normal']));

function switchTab(key: string): void {
    if (!tabs.find((tab) => tab.key === key)?.enabled || key === props.activeTab) return;
    const filters = key !== 'goals' && props.overview?.filters ? props.overview.filters : {};
    router.get('/natural-traffic/seo-geo', { tab: key, ...filters }, { preserveScroll: true });
}

function changeMonth(event: Event): void {
    const month = (event.target as HTMLSelectElement).value;
    router.get('/natural-traffic/seo-geo', { tab: 'goals', month }, { preserveScroll: true, preserveState: true });
}

function refreshData(): void {
    if (!props.canSync || syncing.value || !sourcesConfigured.value) return;
    syncing.value = true;
    router.post('/natural-traffic/seo-geo/refresh', {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}

function syncAnalytics(): void {
    if (!props.canSync || syncing.value || !props.overviewSourceStatus.configured) return;
    syncing.value = true;
    router.post('/natural-traffic/seo-geo/overview/refresh', {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}

function formatMonth(month: string): string {
    const [year, number] = month.split('-');
    return `${year} 年 ${number} 月目标`;
}

function formatValue(value: number, metric: Metric): string {
    const formatted = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Math.round(value));
    return metric.unit === '$' ? `$${formatted}` : `${formatted}${metric.unit}`;
}

function formatPercent(value: number, digits = 1): string {
    return `${(value * 100).toFixed(digits)}%`;
}

function formatDateTime(value: string | null): string {
    if (!value) return '尚未同步';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
        hour12: false, timeZone: 'Asia/Shanghai',
    }).format(parsed);
}

function shortDate(value: string | null): string {
    return value ? value.slice(5).replace('-', '/') : '--/--';
}

function barWidth(metric: Metric): string {
    return `${Math.min(100, Math.max(0, metric.completion * 100))}%`;
}

function paceLeft(metric: Metric): string {
    return `${Math.min(100, Math.max(0, metric.pace * 100))}%`;
}
</script>

<template>
    <Head :title="activeTab === 'overview' ? 'SEO / GEO 综合看板' : activeTab === 'gsc' ? 'GSC 源数据' : activeTab === 'ga4' ? 'GA 源数据' : 'SEO / GEO 目标看板'" />
    <AppLayout :breadcrumbs="[{ label: '自然流量' }, { label: 'SEO / GEO' }]">
        <div class="mx-auto w-full max-w-[1680px] space-y-6">
            <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-6 border-b border-slate-100 px-6 py-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                    <div>
                        <div class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.22em] text-blue-600">
                            <span class="h-2 w-2 rounded-full bg-blue-500"></span> Organic growth cockpit
                        </div>
                        <h1 class="text-3xl font-black tracking-tight text-slate-950">SEO / GEO</h1>
                        <p class="mt-2 text-sm text-slate-500">{{ store.name }} · 目标、实际值与进度统一按本地数据库口径计算</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs text-slate-500">
                            <span class="font-semibold text-slate-700">数据截至</span>
                            <span class="ml-2 tabular-nums">{{ activeTab === 'goals' ? (dashboard?.data_through || '等待数据') : (overview?.data_through || '等待同步') }}</span>
                        </div>
                        <div v-if="activeTab !== 'goals'" class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-2.5 text-xs text-blue-700" title="同步任务在后台执行，页面始终读取本地数据库">
                            <span class="font-bold">最近 7 天优先</span>
                            <span class="ml-2">{{ overviewSyncStatus.status === 'running' || overviewSyncStatus.status === 'queued' ? `同步 ${overviewSyncStatus.progress_percent || 0}%` : '按月后台回填' }}</span>
                        </div>
                        <button
                            v-if="canSync && activeTab === 'goals'"
                            type="button"
                            class="inline-flex h-11 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                            :disabled="syncing || !sourcesConfigured"
                            :title="sourcesConfigured ? '同步 SEO 日表和 4 张工作推进表' : '请先完整配置 SEO 数据源'"
                            @click="refreshData"
                        >
                            <svg class="h-4 w-4" :class="syncing ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 11M5.5 15A7 7 0 0 0 18 17.5l2-4.5"/></svg>
                            {{ syncing ? '同步中…' : '刷新最新数据' }}
                        </button>
                        <button
                            v-else-if="canSync && activeTab !== 'goals'"
                            type="button"
                            class="inline-flex h-11 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                            :disabled="syncing || !overviewSourceStatus.configured"
                            :title="overviewSourceStatus.configured ? '提交后台同步；最近 7 天优先，历史数据按月回填' : `缺少配置：${overviewSourceStatus.missing.join('、')}`"
                            @click="syncAnalytics"
                        >
                            <svg class="h-4 w-4" :class="syncing ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 11M5.5 15A7 7 0 0 0 18 17.5l2-4.5"/></svg>
                            {{ syncing ? '提交中…' : '后台同步' }}
                        </button>
                    </div>
                </div>
                <nav class="flex gap-1 overflow-x-auto px-5 pt-3 lg:px-8" aria-label="SEO 数据视图">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="relative whitespace-nowrap px-4 py-3 text-sm font-bold"
                        :class="tab.key === activeTab ? 'text-blue-700' : tab.enabled ? 'text-slate-500 hover:text-slate-900' : 'cursor-default text-slate-300'"
                        :disabled="!tab.enabled"
                        @click="switchTab(tab.key)"
                    >
                        {{ tab.label }}
                        <span v-if="tab.key === activeTab" class="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-blue-600"></span>
                    </button>
                </nav>
            </section>

            <template v-if="activeTab === 'goals' && dashboard">
            <div v-if="!sourcesConfigured" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                SEO 日表或 4 张工作推进表配置不完整。已有归档数据仍可查看，补齐“店铺设置 → 数据来源”后可刷新。
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <span class="absolute inset-y-0 left-0 w-1" :class="overallMeta.line"></span>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">总体判断</p>
                    <p class="mt-3 text-3xl font-black" :class="overallMeta.color">{{ overallMeta.title }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ overallMeta.note }}</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">预计达标</p>
                    <p class="mt-3 text-3xl font-black tabular-nums text-slate-950">{{ noMonthData ? '—' : `${dashboard.summary.expected_count}/${dashboard.summary.total_count}` }}</p>
                    <p class="mt-1 text-sm text-slate-500">月底预计可达标数</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">需要追赶</p>
                    <p class="mt-3 text-3xl font-black tabular-nums text-rose-600">{{ noMonthData ? '—' : dashboard.summary.catch_up_count }}</p>
                    <p class="mt-1 text-sm text-slate-500">低于进度线且预计不达标</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">数据范围</p>
                    <p class="mt-3 text-3xl font-black tabular-nums text-slate-950">{{ shortDate(dashboard.data_through) }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ dashboard.data_range.days }} 个有效日 · 最新可用日期</p>
                </article>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-bold text-slate-900">数据库目标快照</p>
                        <p class="mt-1 text-xs text-slate-500">最近同步：{{ formatDateTime(dashboard.last_synced_at) }} · 本次计算：{{ formatDateTime(dashboard.generated_at) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="rounded-full bg-blue-50 px-3 py-1.5 text-blue-700">数据进度 {{ formatPercent(dashboard.progress.data) }}</span>
                        <span class="rounded-full bg-slate-100 px-3 py-1.5 text-slate-600">自然进度 {{ formatPercent(dashboard.progress.calendar) }}</span>
                        <span class="rounded-full bg-violet-50 px-3 py-1.5 text-violet-700">{{ dashboard.target_origin === 'default' ? '默认月目标' : '月度专属目标' }}</span>
                    </div>
                </div>
            </section>

            <section>
                <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <h2 class="text-xl font-black tracking-tight text-slate-950">核心目标进度</h2>
                        <select class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 shadow-sm outline-none focus:border-blue-500" :value="dashboard.month" @change="changeMonth">
                            <option v-for="month in dashboard.months" :key="month" :value="month">{{ formatMonth(month) }}</option>
                        </select>
                    </div>
                    <div class="inline-flex w-fit rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
                        <button v-for="tab in categoryTabs" :key="tab.id" type="button" class="rounded-lg px-4 py-1.5 text-xs font-bold transition" :class="activeCategory === tab.id ? 'bg-slate-950 text-white' : 'text-slate-500 hover:bg-slate-50'" @click="activeCategory = tab.id">{{ tab.label }}</button>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <article v-for="metric in filteredMetrics" :key="metric.id" class="rounded-2xl border border-t-4 border-slate-200 bg-white p-6 shadow-sm" :class="statusMeta[metric.status].accent">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-black text-slate-900">{{ metric.name }}</h3>
                                <p class="mt-1 text-xs text-slate-400">{{ metric.source }} · {{ metric.pace_kind === 'data' ? '按数据进度' : '按自然进度' }}</p>
                            </div>
                            <span class="rounded-lg px-2.5 py-1 text-xs font-black ring-1 ring-inset" :class="statusMeta[metric.status].badge">{{ statusMeta[metric.status].label }}</span>
                        </div>
                        <div class="mt-7 flex items-end gap-2">
                            <strong class="text-4xl font-black tracking-tight tabular-nums text-slate-950">{{ metric.status === 'no_data' ? '—' : formatValue(metric.current, metric) }}</strong>
                            <span class="pb-1 text-sm font-bold text-slate-400">/ {{ formatValue(metric.target, metric) }}</span>
                        </div>
                        <div class="relative mt-6 h-2.5 rounded-full bg-slate-100">
                            <span class="absolute inset-y-0 left-0 rounded-full" :class="statusMeta[metric.status].bar" :style="{ width: barWidth(metric) }"></span>
                            <span class="absolute -top-1 h-4 w-0.5 rounded-full bg-slate-700" :style="{ left: paceLeft(metric) }" title="当前进度线"></span>
                        </div>
                        <div class="mt-5 grid grid-cols-3 gap-2">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[11px] font-bold text-slate-400">完成度</p>
                                <p class="mt-1 text-sm font-black tabular-nums text-slate-800">{{ formatPercent(metric.completion) }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[11px] font-bold text-slate-400">应完成</p>
                                <p class="mt-1 text-sm font-black tabular-nums text-slate-800">{{ formatValue(metric.expected, metric) }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <p class="text-[11px] font-bold text-slate-400">月底预估</p>
                                <p class="mt-1 text-sm font-black tabular-nums text-slate-800">{{ metric.status === 'no_data' ? '—' : formatValue(metric.forecast, metric) }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-xs">
                            <span class="text-slate-500">预计达成 <strong class="text-slate-800">{{ metric.status === 'no_data' ? '—' : formatPercent(metric.forecast_rate, 1) }}</strong></span>
                            <span v-if="metric.status !== 'no_data' && metric.gap > 0" class="text-slate-400">差距 {{ formatValue(metric.gap, metric) }}</span>
                            <span v-else-if="metric.status !== 'no_data'" class="font-bold text-emerald-600">已超目标</span>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm lg:p-7">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-xl font-black tracking-tight text-slate-950">本周重点动作</h2>
                        <p class="mt-1 text-sm text-slate-500">只展示月底预计达成率低于 120% 的指标，按风险从高到低排序。</p>
                    </div>
                    <span class="text-xs font-bold text-slate-400">{{ focusActions.length }} 项需要关注</span>
                </div>
                <div v-if="focusActions.length" class="mt-5 divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200">
                    <div v-for="metric in focusActions" :key="metric.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-black" :class="metric.forecast_rate >= 1 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'">{{ metric.forecast_rate >= 1 ? '✓' : '−' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-black text-slate-900">{{ metric.name }} · 预估 {{ Math.round(metric.forecast_rate * 100) }}%</p>
                            <p class="mt-1 text-sm leading-relaxed text-slate-500">{{ advice[metric.id]?.text }} 当前完成 {{ formatPercent(metric.completion) }}，月底预计 {{ formatPercent(metric.forecast_rate) }}。</p>
                        </div>
                        <span class="w-fit shrink-0 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-500">{{ advice[metric.id]?.owner || 'SEO' }}</span>
                    </div>
                </div>
                <div v-else-if="noMonthData" class="mt-5 rounded-2xl bg-slate-50 p-6 text-sm font-semibold text-slate-600">所选月份尚无同步记录，数据到达后再生成进度判断与建议。</div>
                <div v-else class="mt-5 rounded-2xl bg-emerald-50 p-6 text-sm font-semibold text-emerald-700">当前所有指标的月底预计达成率均不低于 120%，暂无重点追赶项。</div>
            </section>
            </template>

            <SeoOverviewPanel
                v-else-if="activeTab === 'overview' && overview"
                :overview="overview"
                :sync-status="overviewSyncStatus"
                :source-status="overviewSourceStatus"
                :can-sync="canSync"
            />
            <SeoGscSourcePanel v-else-if="activeTab === 'gsc' && overview" :data="overview" />
            <SeoGa4SourcePanel v-else-if="activeTab === 'ga4' && overview" :data="overview" />
        </div>
    </AppLayout>
</template>
