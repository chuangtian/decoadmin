<script setup lang="ts">
import { computed } from 'vue';
import MetaWeeklyTrendAndTable from './MetaWeeklyTrendAndTable.vue';

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
    criteo: {
        schema: 'paid-advertising-personal-criteo-channel-goal-v1';
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
    details: {
        schema: 'paid-advertising-personal-goal-details-v1';
        available: boolean;
        message: string | null;
        period_label: string;
        columns: Array<{
            key: string;
            label: string;
            kind: 'date' | 'month' | 'percentage' | 'number' | 'text';
            description: string | null;
        }>;
        rows: Array<{
            key: string;
            date: string;
            values: Record<string, string | number | null>;
        }>;
        total: number;
    };
    facebook_efficiency: {
        schema: 'paid-advertising-facebook-efficiency-v1';
        available: boolean;
        message: string | null;
        as_of_date: string | null;
        period_label: string;
        values: {
            current_roas: number | null;
            target_roas: number;
            achievement_rate: number | null;
            period_spend: number | null;
        };
        source_fields: {
            current_roas: string;
            target_roas: string;
            achievement_rate: string[];
            period_spend: string;
        };
    };
    meta_weekly: {
        schema: 'paid-advertising-meta-weekly-v1';
        available: boolean;
        message: string | null;
        latest_week: { label: string; date_from: string; date_to: string } | null;
        previous_week: { label: string; date_from: string; date_to: string } | null;
        values: {
            sales: number | null;
            spend: number | null;
            roi: number | null;
        };
        changes: {
            sales: number | null;
            spend: number | null;
            roi: number | null;
        };
        trend: {
            schema: 'paid-advertising-meta-weekly-trend-v1';
            available: boolean;
            points: Array<{
                week: string;
                date_from: string;
                date_to: string;
                sales: number;
                spend: number;
                roi: number | null;
            }>;
        };
        table: {
            schema: 'paid-advertising-meta-weekly-table-v1';
            available: boolean;
            period_label: string;
            columns: Array<{
                key: string;
                label: string;
                format: 'text' | 'integer' | 'percentage' | 'currency' | 'roi';
            }>;
            rows: Array<{
                key: string;
                date_from: string;
                date_to: string;
                values: Record<string, string | number | null>;
            }>;
            total: number;
        };
        source_fields: {
            week: string;
            sales: string;
            spend: string;
            roi: string[];
        };
        synced_at: string | null;
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

const props = defineProps<{
    summary: PersonalFacebookSummary | null;
    loading?: boolean;
    boardName?: string;
}>();

const currencyFormatter = computed(() => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.summary?.currency || 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}));

const completionWidth = computed(() => {
    const rate = props.summary?.values.completion_rate ?? 0;

    return `${Math.min(Math.max(rate, 0), 100)}%`;
});

const facebookCompletionWidth = computed(() => {
    const rate = props.summary?.facebook.values.completion_rate ?? 0;

    return `${Math.min(Math.max(rate, 0), 100)}%`;
});

const facebookRingStyle = computed(() => {
    const rate = props.summary?.facebook.values.completion_rate ?? 0;
    const degrees = Math.min(Math.max(rate, 0), 100) * 3.6;

    return { background: `conic-gradient(rgb(37 99 235) ${degrees}deg, rgb(226 232 240) 0deg)` };
});

const facebookAhead = computed(() => (props.summary?.facebook.values.time_variance ?? 0) >= 0);

const criteoCompletionWidth = computed(() => {
    const rate = props.summary?.criteo.values.completion_rate ?? 0;

    return `${Math.min(Math.max(rate, 0), 100)}%`;
});

const criteoComplete = computed(() => (props.summary?.criteo.values.completion_rate ?? 0) >= 100);

const criteoRingStyle = computed(() => {
    const rate = props.summary?.criteo.values.completion_rate ?? 0;
    const degrees = Math.min(Math.max(rate, 0), 100) * 3.6;
    const color = rate >= 100 ? 'rgb(16 185 129)' : 'rgb(249 115 22)';

    return { background: `conic-gradient(${color} ${degrees}deg, rgb(226 232 240) 0deg)` };
});

const criteoAhead = computed(() => (props.summary?.criteo.values.time_variance ?? 0) >= 0);

function money(value: number | null): string {
    return value === null ? '—' : currencyFormatter.value.format(value);
}

function percent(value: number | null, digits = 1): string {
    return value === null ? '—' : `${value.toFixed(digits)}%`;
}

function roas(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(2)}×`;
}

function signedPercent(value: number | null): string {
    if (value === null) return '—';

    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
}

function trend(value: number | null): string {
    if (value === null) return '暂无环比';
    if (value === 0) return '与前一周持平';

    return `${value > 0 ? '↑' : '↓'} ${Math.abs(value).toFixed(1)}%`;
}

function trendClass(value: number | null): string {
    if (value === null || value === 0) return 'text-slate-400';

    return value > 0 ? 'text-emerald-600' : 'text-rose-600';
}

function detailValue(value: string | number | null, kind: string): string {
    if (value === null || value === '') return '—';
    if (kind === 'percentage' && typeof value === 'number') return `${value.toFixed(2)}%`;
    if (kind === 'number' && typeof value === 'number') {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
            maximumFractionDigits: 2,
        }).format(value);
    }

    return String(value);
}

function detailValueClass(value: string | number | null, kind: string): string {
    if (value === null || value === '') return 'text-slate-300';
    if (kind !== 'percentage' || typeof value !== 'number') return 'text-slate-700';
    if (value < 0) return 'text-rose-600';
    if (value >= 100) return 'text-emerald-700';

    return 'text-slate-700';
}
</script>

<template>
    <div class="space-y-6">
        <section
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            :class="{ 'animate-pulse opacity-60': loading }"
            aria-labelledby="personal-summary-title"
        >
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">个人目标 · Facebook 组合数据</p>
                <h2 id="personal-summary-title" class="mt-1.5 text-lg font-semibold text-slate-950">综合目标汇总</h2>
            </div>
            <span v-if="summary?.as_of_date" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">
                截至 {{ summary.as_of_date }} · 数据库同步记录
            </span>
        </header>

        <div v-if="summary?.available" class="px-5 py-6 sm:px-6">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <article class="relative overflow-hidden rounded-xl border border-blue-100 bg-blue-50/45 p-4" :title="`同步字段：${summary.source_fields.daily_sales}`">
                    <span class="absolute inset-x-0 top-0 h-0.5 bg-blue-500" />
                    <p class="text-xs font-semibold text-slate-500">{{ summary.day_label }}总销售额</p>
                    <p class="mt-3 whitespace-nowrap text-xl font-semibold tracking-tight tabular-nums text-blue-700">{{ money(summary.values.daily_sales) }}</p>
                </article>

                <article class="relative overflow-hidden rounded-xl border border-emerald-100 bg-emerald-50/45 p-4" :title="`同步字段：${summary.source_fields.monthly_sales}`">
                    <span class="absolute inset-x-0 top-0 h-0.5 bg-emerald-500" />
                    <p class="text-xs font-semibold text-slate-500">本月已完成</p>
                    <p class="mt-3 whitespace-nowrap text-xl font-semibold tracking-tight tabular-nums text-emerald-700">{{ money(summary.values.monthly_sales) }}</p>
                </article>

                <article class="relative overflow-hidden rounded-xl border border-violet-100 bg-violet-50/45 p-4" :title="`同步字段：${summary.source_fields.monthly_target}`">
                    <span class="absolute inset-x-0 top-0 h-0.5 bg-violet-500" />
                    <p class="text-xs font-semibold text-slate-500">月总目标</p>
                    <p class="mt-3 whitespace-nowrap text-xl font-semibold tracking-tight tabular-nums text-violet-700">{{ money(summary.values.monthly_target) }}</p>
                </article>

                <article class="relative overflow-hidden rounded-xl border border-amber-100 bg-amber-50/45 p-4" :title="`计算字段：${summary.source_fields.completion_rate.join(' ÷ ')}`">
                    <span class="absolute inset-x-0 top-0 h-0.5 bg-amber-500" />
                    <p class="text-xs font-semibold text-slate-500">综合完成率</p>
                    <p class="mt-3 whitespace-nowrap text-xl font-semibold tracking-tight tabular-nums text-amber-700">{{ percent(summary.values.completion_rate) }}</p>
                </article>

                <article class="relative overflow-hidden rounded-xl border border-teal-100 bg-teal-50/45 p-4" :title="`计算来源：${summary.source_fields.roas.join('、')}`">
                    <span class="absolute inset-x-0 top-0 h-0.5 bg-teal-500" />
                    <p class="text-xs font-semibold text-slate-500">综合 ROAS</p>
                    <p class="mt-3 whitespace-nowrap text-xl font-semibold tracking-tight tabular-nums text-teal-700">{{ roas(summary.values.roas) }}</p>
                    <p class="mt-1 text-xs text-slate-400">本月销售额 ÷ 本月花费</p>
                </article>
            </div>

            <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-4 sm:px-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-slate-600">
                        本月总花费
                        <span class="ml-1 tabular-nums text-slate-950">{{ money(summary.values.monthly_spend) }}</span>
                    </p>
                    <p class="text-sm font-semibold tabular-nums text-emerald-700">目标进度 {{ percent(summary.values.completion_rate) }}</p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="本月目标完成进度" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="summary.values.completion_rate ?? 0">
                    <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-[width] duration-500" :style="{ width: completionWidth }" />
                </div>
                <p class="mt-2 truncate text-xs text-slate-400" :title="summary.source_fields.monthly_spend.join(' ＋ ')">
                    花费来源 · {{ summary.source_fields.monthly_spend.join(' ＋ ') }}
                </p>
            </div>
        </div>

        <div v-else class="px-6 py-14 text-center">
            <div class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
            </div>
            <p class="mt-3 text-sm font-semibold text-slate-700">{{ summary?.message ?? '所选时间范围内暂无可用的个人目标数据。' }}</p>
        </div>

        <div v-if="summary?.available && summary.message" class="mx-5 mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900 sm:mx-6">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
            <p>{{ summary.message }}</p>
        </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            :class="{ 'animate-pulse opacity-60': loading }"
            aria-labelledby="facebook-goal-title"
        >
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-lg font-bold text-blue-600 ring-1 ring-inset ring-blue-100">f</span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Facebook Ads</p>
                        <h2 id="facebook-goal-title" class="mt-1 text-lg font-semibold text-slate-950">Facebook 销售目标</h2>
                    </div>
                </div>
                <span v-if="summary?.facebook.as_of_date" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                    截至 {{ summary.facebook.as_of_date }}
                </span>
            </header>

            <div v-if="summary?.facebook.available" class="grid gap-4 px-5 py-6 lg:grid-cols-3 sm:px-6">
                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                    <span class="absolute inset-x-0 top-0 h-1 bg-blue-500" />
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">销售额目标看板</p>
                            <p class="mt-1 text-xs text-slate-400">{{ summary.day_label }} Facebook 销售额</p>
                        </div>
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-inset ring-blue-100">
                            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
                        </span>
                    </div>
                    <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums text-slate-950">{{ money(summary.facebook.values.daily_sales) }}</p>

                    <dl class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">所选期间已完成</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums text-slate-800">{{ money(summary.facebook.values.period_sales) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">月目标</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums text-slate-800">{{ money(summary.facebook.values.target) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Facebook 销售目标完成进度" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="summary.facebook.values.completion_rate ?? 0">
                        <div class="h-full rounded-full bg-blue-500 transition-[width] duration-500" :style="{ width: facebookCompletionWidth }" />
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-2">
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">目标完成率</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums text-blue-700">{{ percent(summary.facebook.values.completion_rate, 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">时间进度差</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums" :class="facebookAhead ? 'text-emerald-700' : 'text-rose-600'">{{ signedPercent(summary.facebook.values.time_variance) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">时间进度</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums text-slate-700">{{ percent(summary.facebook.values.time_progress, 2) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 truncate text-xs text-slate-400" :title="`${summary.facebook.source_fields.period_sales}、${summary.facebook.source_fields.target}`">
                        数据来源 · {{ summary.facebook.source_fields.period_sales }}、{{ summary.facebook.source_fields.target }}
                    </p>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1 bg-orange-400" />
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">日均还需完成</p>
                            <p class="mt-1 text-xs text-slate-400">按当前筛选截止日计算</p>
                        </div>
                        <span class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700">剩余 {{ summary.facebook.values.remaining_days }} 天</span>
                    </div>
                    <p class="mt-7 text-3xl font-semibold tracking-tight tabular-nums" :class="summary.facebook.values.daily_needed === 0 ? 'text-emerald-700' : 'text-orange-600'">
                        {{ money(summary.facebook.values.daily_needed) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">剩余目标 ÷ 剩余自然日</p>

                    <div class="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-4">
                        <p class="text-xs font-medium text-slate-400">日均达成率</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums" :class="(summary.facebook.values.daily_achievement_rate ?? 0) >= 100 ? 'text-emerald-700' : 'text-orange-600'">
                            {{ percent(summary.facebook.values.daily_achievement_rate, 2) }}
                        </p>
                    </div>

                    <div class="mt-4 rounded-xl px-4 py-3" :class="facebookAhead ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                        <p class="text-sm font-semibold">{{ facebookAhead ? '销售进度超前' : '销售进度落后' }}</p>
                        <p class="mt-1 text-xs opacity-80">{{ facebookAhead ? '当前完成率高于时间进度，继续保持。' : '当前完成率低于时间进度，需要提升投放与转化。' }}</p>
                    </div>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1 bg-indigo-500" />
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">月目标完成率</p>
                            <p class="mt-1 text-xs text-slate-400">Facebook 销售目标进度</p>
                        </div>
                        <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">已过 {{ summary.facebook.values.elapsed_days }} 天</span>
                    </div>

                    <div class="mx-auto mt-7 grid h-48 w-48 place-items-center rounded-full p-4" :style="facebookRingStyle">
                        <div class="grid h-full w-full place-items-center rounded-full bg-white text-center shadow-inner">
                            <div>
                                <p class="text-3xl font-semibold tracking-tight tabular-nums text-blue-600">{{ percent(summary.facebook.values.completion_rate, 2) }}</p>
                                <p class="mt-1 text-sm font-semibold text-slate-500">目标完成率</p>
                            </div>
                        </div>
                    </div>
                    <p class="mt-6 text-center text-sm font-medium" :class="facebookAhead ? 'text-emerald-700' : 'text-slate-500'">
                        {{ (summary.facebook.values.completion_rate ?? 0) >= 100 ? '已完成月度目标' : facebookAhead ? '进度领先，继续保持' : '稳步推进，追赶时间进度' }}
                    </p>
                </article>
            </div>

            <div v-else class="px-6 py-14 text-center">
                <div class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-400">
                    <span class="text-lg font-bold">f</span>
                </div>
                <p class="mt-3 text-sm font-semibold text-slate-700">{{ summary?.facebook.message ?? '所选时间范围内暂无可用的 Facebook 目标数据。' }}</p>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            :class="{ 'animate-pulse opacity-60': loading }"
            aria-labelledby="criteo-goal-title"
        >
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-orange-50 text-base font-bold text-orange-600 ring-1 ring-inset ring-orange-100">C</span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-orange-600">Criteo</p>
                        <h2 id="criteo-goal-title" class="mt-1 text-lg font-semibold text-slate-950">Criteo 销售目标</h2>
                    </div>
                </div>
                <span v-if="summary?.criteo.as_of_date" class="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700">
                    截至 {{ summary.criteo.as_of_date }}
                </span>
            </header>

            <div v-if="summary?.criteo.available" class="grid gap-4 px-5 py-6 lg:grid-cols-3 sm:px-6">
                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                    <span class="absolute inset-x-0 top-0 h-1" :class="criteoComplete ? 'bg-emerald-500' : 'bg-orange-500'" />
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">销售额目标看板</p>
                            <p class="mt-1 text-xs text-slate-400">{{ summary.day_label }} Criteo 销售额</p>
                        </div>
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-orange-50 text-orange-600 ring-1 ring-inset ring-orange-100">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 17V7m4 10v-4m5 4V4m5 13V9"/></svg>
                        </span>
                    </div>
                    <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums text-slate-950">{{ money(summary.criteo.values.daily_sales) }}</p>

                    <dl class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">所选期间已完成</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums text-slate-800">{{ money(summary.criteo.values.period_sales) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">月目标</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums text-slate-800">{{ money(summary.criteo.values.target) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Criteo 销售目标完成进度" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="summary.criteo.values.completion_rate ?? 0">
                        <div class="h-full rounded-full transition-[width] duration-500" :class="criteoComplete ? 'bg-emerald-500' : 'bg-orange-500'" :style="{ width: criteoCompletionWidth }" />
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-2">
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">目标完成率</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums" :class="criteoComplete ? 'text-emerald-700' : 'text-orange-700'">{{ percent(summary.criteo.values.completion_rate, 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">时间进度差</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums" :class="criteoAhead ? 'text-emerald-700' : 'text-rose-600'">{{ signedPercent(summary.criteo.values.time_variance) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-xs font-medium text-slate-400">时间进度</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums text-slate-700">{{ percent(summary.criteo.values.time_progress, 2) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 truncate text-xs text-slate-400" :title="`${summary.criteo.source_fields.period_sales}、${summary.criteo.source_fields.target}`">
                        数据来源 · {{ summary.criteo.source_fields.period_sales }}、{{ summary.criteo.source_fields.target }}
                    </p>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1" :class="criteoComplete ? 'bg-emerald-500' : 'bg-orange-400'" />
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">日均还需完成</p>
                            <p class="mt-1 text-xs text-slate-400">按当前筛选截止日计算</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="criteoComplete ? 'bg-emerald-50 text-emerald-700' : 'bg-orange-50 text-orange-700'">
                            {{ criteoComplete ? '目标已完成' : `剩余 ${summary.criteo.values.remaining_days} 天` }}
                        </span>
                    </div>
                    <p class="mt-7 text-3xl font-semibold tracking-tight tabular-nums" :class="summary.criteo.values.daily_needed === 0 ? 'text-emerald-700' : 'text-orange-600'">
                        {{ money(summary.criteo.values.daily_needed) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">剩余目标 ÷ 剩余自然日</p>

                    <div class="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-4">
                        <p class="text-xs font-medium text-slate-400">日均达成率</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums" :class="(summary.criteo.values.daily_achievement_rate ?? 0) >= 100 ? 'text-emerald-700' : 'text-orange-600'">
                            {{ percent(summary.criteo.values.daily_achievement_rate, 2) }}
                        </p>
                    </div>

                    <div class="mt-4 rounded-xl px-4 py-3" :class="criteoAhead ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                        <p class="text-sm font-semibold">{{ criteoAhead ? '销售进度超前' : '销售进度落后' }}</p>
                        <p class="mt-1 text-xs opacity-80">{{ criteoAhead ? '当前完成率高于时间进度，继续保持。' : '当前完成率低于时间进度，需要提升投放与转化。' }}</p>
                    </div>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1" :class="criteoComplete ? 'bg-emerald-500' : 'bg-orange-500'" />
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">月目标完成率</p>
                            <p class="mt-1 text-xs text-slate-400">Criteo 销售目标进度</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="criteoComplete ? 'bg-emerald-50 text-emerald-700' : 'bg-orange-50 text-orange-700'">已过 {{ summary.criteo.values.elapsed_days }} 天</span>
                    </div>

                    <div class="mx-auto mt-7 grid h-48 w-48 place-items-center rounded-full p-4" :style="criteoRingStyle">
                        <div class="grid h-full w-full place-items-center rounded-full bg-white text-center shadow-inner">
                            <div>
                                <p class="text-3xl font-semibold tracking-tight tabular-nums" :class="criteoComplete ? 'text-emerald-600' : 'text-orange-600'">{{ percent(summary.criteo.values.completion_rate, 2) }}</p>
                                <p class="mt-1 text-sm font-semibold text-slate-500">目标完成率</p>
                            </div>
                        </div>
                    </div>
                    <p class="mt-6 text-center text-sm font-medium" :class="criteoComplete || criteoAhead ? 'text-emerald-700' : 'text-slate-500'">
                        {{ criteoComplete ? '已超额完成月度目标' : criteoAhead ? '进度领先，继续保持' : '稳步推进，追赶时间进度' }}
                    </p>
                </article>
            </div>

            <div v-else class="px-6 py-14 text-center">
                <div class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-orange-50 text-sm font-bold text-orange-400">C</div>
                <p class="mt-3 text-sm font-semibold text-slate-700">{{ summary?.criteo.message ?? '所选时间范围内暂无可用的 Criteo 目标数据。' }}</p>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            :class="{ 'animate-pulse opacity-60': loading }"
            aria-labelledby="personal-goal-details-title"
        >
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5.5h16v13H4zM4 10h16M9 5.5v13"/></svg>
                    </span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">数据库同步记录</p>
                        <h2 id="personal-goal-details-title" class="mt-1 text-lg font-semibold text-slate-950">
                            目标明细<span v-if="boardName" class="font-medium text-slate-500">（{{ boardName }}目标数据）</span>
                        </h2>
                        <p class="mt-1 text-xs text-slate-400">{{ summary?.details.period_label || '当前筛选时间' }}</p>
                    </div>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                    {{ summary?.details.total ?? 0 }} 条记录
                </span>
            </header>

            <div v-if="summary?.details.available" class="p-4 sm:p-5">
                <div class="max-h-[34rem] overflow-auto rounded-xl border border-slate-200">
                    <table class="min-w-max border-separate border-spacing-0 text-left text-xs">
                        <thead class="sticky top-0 z-20 bg-slate-50/95 backdrop-blur">
                            <tr>
                                <th
                                    v-for="(column, columnIndex) in summary.details.columns"
                                    :key="column.key"
                                    class="min-w-36 border-b border-r border-slate-200 px-4 py-3 font-semibold whitespace-nowrap text-slate-500 last:border-r-0"
                                    :class="{ 'sticky left-0 z-30 bg-slate-50': columnIndex === 0 }"
                                    :title="column.description ?? column.label"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in summary.details.rows" :key="row.key" class="group even:bg-slate-50/45 hover:bg-emerald-50/35">
                                <td
                                    v-for="(column, columnIndex) in summary.details.columns"
                                    :key="`${row.key}-${column.key}`"
                                    class="border-b border-r border-slate-100 px-4 py-3 whitespace-nowrap last:border-r-0 group-last:border-b-0 tabular-nums"
                                    :class="[
                                        detailValueClass(row.values[column.key], column.kind),
                                        columnIndex === 0 ? 'sticky left-0 z-10 bg-white font-semibold group-even:bg-slate-50 group-hover:bg-emerald-50' : '',
                                    ]"
                                >
                                    {{ detailValue(row.values[column.key], column.kind) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 px-1 text-xs text-slate-400">
                    <span>表头来自已同步的飞书字段配置</span>
                    <span>已按日期从新到旧排列</span>
                </div>
            </div>

            <div v-else class="px-6 py-14 text-center">
                <div class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5.5h16v13H4zM4 10h16M9 5.5v13"/></svg>
                </div>
                <p class="mt-3 text-sm font-semibold text-slate-700">{{ summary?.details.message ?? '所选时间范围内暂无目标明细记录。' }}</p>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            :class="{ 'animate-pulse opacity-60': loading }"
            aria-labelledby="facebook-efficiency-title"
        >
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-100">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 18V8m5 10V4m5 14v-7m5 7V6"/><path d="m4 7 5-4 5 7 5-5"/></svg>
                    </span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-600">Facebook Ads</p>
                        <h2 id="facebook-efficiency-title" class="mt-1 text-lg font-semibold text-slate-950">广告效率目标</h2>
                        <p class="mt-1 text-xs text-slate-400">最新日 ROAS 与所选时段投放花费</p>
                    </div>
                </div>
                <span v-if="summary?.facebook_efficiency.as_of_date" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                    截至 {{ summary.facebook_efficiency.as_of_date }}
                </span>
            </header>

            <div v-if="summary?.facebook_efficiency.available" class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-4">
                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                    <span class="absolute inset-y-0 left-0 w-1 bg-rose-500" />
                    <p class="text-sm font-medium text-slate-500">当前 ROAS</p>
                    <p class="mt-1 text-xs text-slate-400">最新日转化价值 ÷ 花费</p>
                    <p class="mt-5 text-2xl font-semibold tracking-tight tabular-nums" :class="(summary.facebook_efficiency.values.achievement_rate ?? 0) >= 100 ? 'text-emerald-700' : 'text-rose-600'">
                        {{ roas(summary.facebook_efficiency.values.current_roas) }}
                    </p>
                    <p class="mt-4 truncate text-xs text-slate-400" :title="summary.facebook_efficiency.source_fields.current_roas">字段 · {{ summary.facebook_efficiency.source_fields.current_roas }}</p>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                    <span class="absolute inset-y-0 left-0 w-1 bg-slate-400" />
                    <p class="text-sm font-medium text-slate-500">目标 ROAS</p>
                    <p class="mt-1 text-xs text-slate-400">Facebook 效率基准</p>
                    <p class="mt-5 text-2xl font-semibold tracking-tight tabular-nums text-slate-900">{{ roas(summary.facebook_efficiency.values.target_roas) }}</p>
                    <p class="mt-4 text-xs text-slate-400">目标规则 · 固定基准</p>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                    <span class="absolute inset-y-0 left-0 w-1 bg-orange-500" />
                    <p class="text-sm font-medium text-slate-500">ROAS 达成率</p>
                    <p class="mt-1 text-xs text-slate-400">当前 ROAS ÷ 目标 ROAS</p>
                    <p class="mt-5 text-2xl font-semibold tracking-tight tabular-nums" :class="(summary.facebook_efficiency.values.achievement_rate ?? 0) >= 100 ? 'text-emerald-700' : 'text-orange-600'">
                        {{ percent(summary.facebook_efficiency.values.achievement_rate, 2) }}
                    </p>
                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-orange-500" :style="{ width: `${Math.min(Math.max(summary.facebook_efficiency.values.achievement_rate ?? 0, 0), 100)}%` }" />
                    </div>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                    <span class="absolute inset-y-0 left-0 w-1 bg-blue-500" />
                    <p class="text-sm font-medium text-slate-500">所选时段花费</p>
                    <p class="mt-1 truncate text-xs text-slate-400" :title="summary.facebook_efficiency.period_label">{{ summary.facebook_efficiency.period_label }}</p>
                    <p class="mt-5 text-2xl font-semibold tracking-tight tabular-nums text-slate-900">{{ money(summary.facebook_efficiency.values.period_spend) }}</p>
                    <p class="mt-4 truncate text-xs text-slate-400" :title="summary.facebook_efficiency.source_fields.period_spend">字段 · {{ summary.facebook_efficiency.source_fields.period_spend }}</p>
                </article>
            </div>

            <div v-else class="px-6 py-12 text-center">
                <p class="text-sm font-semibold text-slate-700">{{ summary?.facebook_efficiency.message ?? '所选时间范围内暂无 Facebook 效率数据。' }}</p>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            :class="{ 'animate-pulse opacity-60': loading }"
            aria-labelledby="meta-weekly-title"
        >
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-sm font-bold text-blue-600 ring-1 ring-inset ring-blue-100">M</span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Meta · {{ boardName || '个人目标' }}</p>
                        <h2 id="meta-weekly-title" class="mt-1 text-lg font-semibold text-slate-950">最近完整周表现</h2>
                        <p class="mt-1 text-xs text-slate-400">自动排除尚未结束的本周数据</p>
                    </div>
                </div>
                <span v-if="summary?.meta_weekly.latest_week" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                    {{ summary.meta_weekly.latest_week.label }}
                </span>
            </header>

            <div v-if="summary?.meta_weekly.available" class="grid gap-4 p-5 sm:p-6 lg:grid-cols-3">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_-24px_rgba(15,23,42,0.45)]">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-500">上周销售额</p>
                            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-slate-950">{{ money(summary.meta_weekly.values.sales) }}</p>
                        </div>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 19V9m7 10V5m7 14v-7"/></svg>
                        </span>
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs">
                        <span class="text-slate-400">较前一完整周</span>
                        <span class="font-semibold tabular-nums" :class="trendClass(summary.meta_weekly.changes.sales)">{{ trend(summary.meta_weekly.changes.sales) }}</span>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_-24px_rgba(15,23,42,0.45)]">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-500">上周花费</p>
                            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-blue-600">{{ money(summary.meta_weekly.values.spend) }}</p>
                        </div>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8"/><path d="M15 9.5c-.7-.7-1.7-1-3-1-1.7 0-3 .8-3 2s1 1.8 3 2 3 1 3 2-1.3 2-3 2c-1.3 0-2.4-.4-3.2-1.2M12 6.5v11"/></svg>
                        </span>
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs">
                        <span class="text-slate-400">较前一完整周</span>
                        <span class="font-semibold tabular-nums" :class="trendClass(summary.meta_weekly.changes.spend)">{{ trend(summary.meta_weekly.changes.spend) }}</span>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_-24px_rgba(15,23,42,0.45)]">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-500">上周 ROI</p>
                            <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums text-emerald-700">{{ roas(summary.meta_weekly.values.roi) }}</p>
                        </div>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-50 text-violet-600">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 16 4-4 3 3 7-8"/><path d="M14 7h5v5"/></svg>
                        </span>
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs">
                        <span class="text-slate-400">销售额 ÷ 花费</span>
                        <span class="font-semibold tabular-nums" :class="trendClass(summary.meta_weekly.changes.roi)">{{ trend(summary.meta_weekly.changes.roi) }}</span>
                    </div>
                </article>
            </div>

            <div v-else class="px-6 py-12 text-center">
                <p class="text-sm font-semibold text-slate-700">{{ summary?.meta_weekly.message ?? '所选时间范围内暂无已结束的 Meta 周数据。' }}</p>
            </div>
        </section>

        <MetaWeeklyTrendAndTable
            v-if="summary?.meta_weekly"
            :weekly="summary.meta_weekly"
            :currency="summary.currency"
            :board-name="boardName"
            :loading="loading"
        />
    </div>
</template>
