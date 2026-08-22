<script setup lang="ts">
import { computed } from 'vue';

interface GoogleAdsSummary {
    schema: 'paid-advertising-google-ads-summary-v1';
    available: boolean;
    source: 'database_sync';
    source_sheet: string | null;
    currency: string;
    as_of_date: string | null;
    synced_at: string | null;
    missing_fields: string[];
    message: string | null;
    pace_status: 'ahead' | 'behind';
    values: {
        daily_sales: number | null;
        monthly_sales: number | null;
        monthly_target: number | null;
        daily_needed: number | null;
        daily_achievement_rate: number | null;
        completion_rate: number | null;
        time_variance: number | null;
        time_progress: number | null;
    };
    source_fields: Record<string, string>;
    efficiency: {
        schema: 'paid-advertising-google-efficiency-v1';
        available: boolean;
        message: string | null;
        values: {
            current_roas: number | null;
            target_roas: number | null;
            achievement_rate: number | null;
            monthly_spend: number | null;
        };
        source_fields: Record<string, string | null>;
    };
    details: {
        schema: 'paid-advertising-google-target-details-v1';
        period_label: string | null;
        columns: Array<{
            key: string;
            label: string;
            kind: 'date' | 'currency' | 'percentage' | 'roas' | 'text';
        }>;
        rows: Array<{
            key: string;
            date: string;
            values: Record<string, string | number | null>;
        }>;
        total: number;
    };
}

const props = defineProps<{
    summary: GoogleAdsSummary | null;
    loading?: boolean;
    boardName?: string;
}>();

const currencyFormatter = computed(() => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.summary?.currency || 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}));

const completionRate = computed(() => props.summary?.values.completion_rate ?? 0);
const completionWidth = computed(() => `${Math.min(Math.max(completionRate.value, 0), 100)}%`);
const complete = computed(() => completionRate.value >= 100);
const ahead = computed(() => props.summary?.pace_status === 'ahead');
const ringStyle = computed(() => {
    const degrees = Math.min(Math.max(completionRate.value, 0), 100) * 3.6;
    const color = complete.value ? 'rgb(16 185 129)' : 'rgb(37 99 235)';

    return { background: `conic-gradient(${color} ${degrees}deg, rgb(226 232 240) 0deg)` };
});

function money(value: number | null): string {
    return value === null ? '—' : currencyFormatter.value.format(value);
}

function percent(value: number | null, digits = 2): string {
    return value === null ? '—' : `${value.toFixed(digits)}%`;
}

function signedPercent(value: number | null): string {
    if (value === null) return '—';

    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
}

function roas(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(2)}×`;
}

function detailValue(value: string | number | null, kind: string): string {
    if (value === null || value === '') return '—';
    if (kind === 'date') return String(value).replaceAll('-', '/');
    if (kind === 'currency') return typeof value === 'number' ? money(value) : String(value);
    if (kind === 'percentage') return typeof value === 'number' ? percent(value) : String(value);
    if (kind === 'roas') return typeof value === 'number' ? roas(value) : String(value);
    if (typeof value === 'number') return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value);

    return String(value);
}
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
        :class="{ 'animate-pulse opacity-60': loading }"
        aria-labelledby="google-goal-title"
    >
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-700">Google 广告目标 · {{ boardName || '当前页签' }}</p>
                <h2 id="google-goal-title" class="mt-1.5 text-lg font-semibold text-slate-950">月度销售目标进度</h2>
                <p class="mt-1 text-sm text-slate-500">按当前统计时间读取“销售目标”工作表中最后一条有效同步记录。</p>
            </div>
            <div v-if="summary?.as_of_date" class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                <span class="rounded-full bg-blue-50 px-3 py-1.5 text-blue-700">数据日期 {{ summary.as_of_date }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-slate-500">数据库同步记录</span>
            </div>
        </header>

        <div v-if="summary?.available" class="p-5 sm:p-6">
            <div class="grid gap-4 xl:grid-cols-[1.15fr_0.9fr_0.85fr]">
                <article class="relative overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50/80 via-white to-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1 bg-blue-500" />
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">销售额目标看板</p>
                            <p class="mt-1 text-xs text-slate-400">今日销售额</p>
                        </div>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-100 text-blue-700 ring-1 ring-blue-200">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 19V9m7 10V5m7 14v-7M3 19h18"/></svg>
                        </span>
                    </div>

                    <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums text-blue-700 2xl:text-[1.7rem]">{{ money(summary.values.daily_sales) }}</p>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-slate-100 bg-white/90 p-3">
                            <p class="text-xs font-medium text-slate-400">本月已完成</p>
                            <p class="mt-1.5 whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800">{{ money(summary.values.monthly_sales) }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-white/90 p-3">
                            <p class="text-xs font-medium text-slate-400">本月销售目标</p>
                            <p class="mt-1.5 whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800">{{ money(summary.values.monthly_target) }}</p>
                        </div>
                    </div>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200" aria-label="目标完成进度">
                        <div class="h-full rounded-full transition-all duration-500" :class="complete ? 'bg-emerald-500' : 'bg-blue-500'" :style="{ width: completionWidth }" />
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-2">
                        <div class="rounded-xl bg-slate-950 px-3 py-3 text-white">
                            <p class="text-[11px] text-slate-400">目标完成率</p>
                            <p class="mt-1.5 text-sm font-semibold tabular-nums" :class="complete ? 'text-emerald-400' : 'text-blue-400'">{{ percent(summary.values.completion_rate) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-950 px-3 py-3 text-white">
                            <p class="text-[11px] text-slate-400">时间对比</p>
                            <p class="mt-1.5 text-sm font-semibold tabular-nums" :class="ahead ? 'text-emerald-400' : 'text-rose-400'">{{ signedPercent(summary.values.time_variance) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-950 px-3 py-3 text-white">
                            <p class="text-[11px] text-slate-400">时间进度</p>
                            <p class="mt-1.5 text-sm font-semibold tabular-nums text-slate-100">{{ percent(summary.values.time_progress) }}</p>
                        </div>
                    </div>
                </article>

                <article class="relative overflow-hidden rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50/70 via-white to-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1 bg-orange-500" />
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">日均还需完成</p>
                            <p class="mt-1 text-xs text-slate-400">按当前目标余额与剩余天数</p>
                        </div>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-orange-100 text-orange-700 ring-1 ring-orange-200">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v18M17 7.5c0-1.7-2.2-3-5-3s-5 1.3-5 3 2.2 3 5 3 5 1.3 5 3-2.2 3-5 3-5-1.3-5-3"/></svg>
                        </span>
                    </div>

                    <p class="mt-6 whitespace-nowrap text-2xl font-semibold tracking-tight tabular-nums text-orange-600 2xl:text-[1.7rem]">{{ money(summary.values.daily_needed) }}</p>
                    <div class="mt-5 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3">
                        <p class="text-xs font-medium text-emerald-700">日均达成率</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-700">{{ percent(summary.values.daily_achievement_rate) }}</p>
                    </div>

                    <div class="mt-5 rounded-xl bg-slate-950 p-4 text-white">
                        <p class="text-sm font-semibold" :class="ahead ? 'text-emerald-400' : 'text-rose-400'">
                            {{ ahead ? '销售进度超前' : '销售进度落后' }}
                        </p>
                        <p class="mt-1.5 text-xs leading-5 text-slate-400">
                            {{ ahead ? '当前完成率高于时间进度，继续保持投放节奏。' : '当前完成率低于时间进度，建议关注投放效率与转化。' }}
                        </p>
                    </div>
                </article>

                <article class="relative flex min-h-[24rem] flex-col overflow-hidden rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50/60 via-white to-white p-5">
                    <span class="absolute inset-x-0 top-0 h-1" :class="complete ? 'bg-emerald-500' : 'bg-blue-500'" />
                    <div>
                        <p class="text-sm font-semibold text-slate-800">月目标当前完成率</p>
                        <p class="mt-1 text-xs text-slate-400">与销售额目标看板使用同一指标</p>
                    </div>

                    <div class="flex flex-1 items-center justify-center py-8">
                        <div class="grid h-48 w-48 place-items-center rounded-full p-4" :style="ringStyle">
                            <div class="grid h-full w-full place-items-center rounded-full bg-white text-center shadow-inner">
                                <div>
                                    <p class="text-3xl font-semibold tabular-nums" :class="complete ? 'text-emerald-600' : 'text-blue-600'">{{ percent(summary.values.completion_rate) }}</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-600">目标完成率</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="text-center text-xs font-medium" :class="complete ? 'text-emerald-700' : ahead ? 'text-blue-700' : 'text-slate-500'">
                        {{ complete ? '本月目标已完成' : ahead ? '进度领先，继续保持' : '稳步推进，关注剩余目标' }}
                    </p>
                </article>
            </div>

            <div v-if="summary.message" class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
                <span>{{ summary.message }}</span>
            </div>

            <section class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5" aria-labelledby="google-efficiency-title">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.15em] text-violet-600">Efficiency</p>
                        <h3 id="google-efficiency-title" class="mt-1 text-base font-semibold text-slate-900">广告效率目标</h3>
                    </div>
                    <span class="rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200">取当前统计周期最后一条记录</span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-rose-100 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium text-slate-500">当前 ROAS</p>
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400" />
                        </div>
                        <p class="mt-3 text-xl font-semibold tabular-nums" :class="summary.efficiency.values.current_roas === null ? 'text-slate-300' : 'text-rose-600'">
                            {{ roas(summary.efficiency.values.current_roas) }}
                        </p>
                        <p class="mt-1 text-[11px] text-slate-400">销售目标表最新记录</p>
                    </article>

                    <article class="rounded-xl border border-blue-100 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium text-slate-500">目标 ROAS</p>
                            <span class="h-2.5 w-2.5 rounded-full bg-blue-500" />
                        </div>
                        <p class="mt-3 text-xl font-semibold tabular-nums text-blue-700">{{ roas(summary.efficiency.values.target_roas) }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">字段缺失时默认 3.70×</p>
                    </article>

                    <article class="rounded-xl border border-orange-100 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium text-slate-500">ROAS 达成率</p>
                            <span class="h-2.5 w-2.5 rounded-full bg-orange-500" />
                        </div>
                        <p class="mt-3 text-xl font-semibold tabular-nums" :class="summary.efficiency.values.achievement_rate === null ? 'text-slate-300' : 'text-orange-600'">
                            {{ percent(summary.efficiency.values.achievement_rate) }}
                        </p>
                        <p class="mt-1 text-[11px] text-slate-400">当前 ROAS ÷ 目标 ROAS</p>
                    </article>

                    <article class="rounded-xl border border-emerald-100 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium text-slate-500">本月花费</p>
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                        </div>
                        <p class="mt-3 whitespace-nowrap text-xl font-semibold tabular-nums" :class="summary.efficiency.values.monthly_spend === null ? 'text-slate-300' : 'text-emerald-700'">
                            {{ money(summary.efficiency.values.monthly_spend) }}
                        </p>
                        <p class="mt-1 text-[11px] text-slate-400">花费或消耗字段</p>
                    </article>
                </div>

                <div v-if="summary.efficiency.message" class="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 px-3.5 py-3 text-xs leading-5 text-amber-800 ring-1 ring-amber-200">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
                    <span>{{ summary.efficiency.message }}</span>
                </div>
            </section>

            <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="google-details-title">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-4 sm:px-5">
                    <div>
                        <h3 id="google-details-title" class="text-base font-semibold text-slate-900">目标明细（销售目标）</h3>
                        <p class="mt-1 text-xs text-slate-400">直接展示飞书公式计算后同步入库的字段值，按日期从新到旧排列。</p>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-medium">
                        <span v-if="summary.details.period_label" class="rounded-full bg-blue-50 px-3 py-1.5 text-blue-700">{{ summary.details.period_label }}</span>
                        <span class="rounded-full bg-slate-100 px-3 py-1.5 text-slate-500">{{ summary.details.total }} 条</span>
                    </div>
                </div>

                <div v-if="summary.details.rows.length" class="overflow-x-auto">
                    <table class="min-w-full border-separate border-spacing-0 text-left text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500">
                                <th
                                    v-for="(column, columnIndex) in summary.details.columns"
                                    :key="column.key"
                                    class="whitespace-nowrap border-b border-slate-200 px-4 py-3 font-semibold"
                                    :class="columnIndex === 0 ? 'sticky left-0 z-10 bg-slate-50' : ''"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in summary.details.rows" :key="row.key" class="group hover:bg-blue-50/40">
                                <td
                                    v-for="(column, columnIndex) in summary.details.columns"
                                    :key="column.key"
                                    class="whitespace-nowrap border-b border-slate-100 px-4 py-3 tabular-nums text-slate-700"
                                    :class="columnIndex === 0 ? 'sticky left-0 bg-white font-semibold text-slate-900 group-hover:bg-blue-50' : ''"
                                >
                                    {{ detailValue(row.values[column.key] ?? null, column.kind) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-6 py-12 text-center text-sm text-slate-400">当前统计时间内没有销售目标明细。</div>
            </section>

            <footer class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4 text-xs text-slate-400">
                <span>数据来源 · 当前页签同步数据库{{ summary.source_sheet ? ` / ${summary.source_sheet}` : '' }}</span>
                <span v-if="summary.synced_at">最近同步 · {{ summary.synced_at }}</span>
            </footer>
        </div>

        <div v-else class="px-6 py-14 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-500 ring-1 ring-blue-100">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 19V9m7 10V5m7 14v-7M3 19h18"/></svg>
            </div>
            <h3 class="mt-4 text-base font-semibold text-slate-800">暂无 Google 目标数据</h3>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">{{ summary?.message || '请先同步当前页签，并确认飞书中包含“销售目标”工作表及对应字段。' }}</p>
        </div>
    </section>
</template>
