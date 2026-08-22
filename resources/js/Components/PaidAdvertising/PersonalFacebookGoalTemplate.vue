<script setup lang="ts">
import { computed } from 'vue';

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
                    <p class="mt-1 text-[11px] text-slate-400">本月销售额 ÷ 本月花费</p>
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
                            <dt class="text-[11px] font-medium text-slate-400">目标完成率</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums text-blue-700">{{ percent(summary.facebook.values.completion_rate, 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-[11px] font-medium text-slate-400">时间进度差</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums" :class="facebookAhead ? 'text-emerald-700' : 'text-rose-600'">{{ signedPercent(summary.facebook.values.time_variance) }}</dd>
                        </div>
                        <div class="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-100">
                            <dt class="text-[11px] font-medium text-slate-400">时间进度</dt>
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
    </div>
</template>
