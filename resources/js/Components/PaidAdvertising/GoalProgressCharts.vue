<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

interface GoalProgressPoint {
    date: string;
    daily_sales: number;
    daily_refunds: number;
    cumulative_sales: number;
}

interface GoalProgress {
    schema: 'paid-advertising-goal-progress-v1';
    available: boolean;
    message: string | null;
    points: GoalProgressPoint[];
    monthly_target: number | null;
    completion: {
        cumulative_sales: number | null;
        monthly_refunds: number | null;
        target: number | null;
        rate: number | null;
        remaining: number | null;
    };
    source_fields: {
        daily_sales: string;
        daily_refunds: string;
        cumulative_sales: string;
        monthly_target: string;
        monthly_refunds: string;
    };
}

const props = defineProps<{
    progress: GoalProgress;
    currency: string;
    loading: boolean;
}>();

const hoveredIndex = ref<number | null>(null);
const plot = { left: 72, right: 82, top: 50, bottom: 278 };
const chartHeight = 338;
const slotWidth = 44;
const ticks = [0, 0.25, 0.5, 0.75, 1];

const chartWidth = computed(() => Math.max(780, plot.left + plot.right + props.progress.points.length * slotWidth));
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right);
const pointWidth = computed(() => props.progress.points.length ? plotWidth.value / props.progress.points.length : slotWidth);
const dailyMax = computed(() => Math.max(1, ...props.progress.points.flatMap((point) => [point.daily_sales, point.daily_refunds])) * 1.12);
const cumulativeMax = computed(() => Math.max(
    1,
    props.progress.monthly_target ?? 0,
    ...props.progress.points.map((point) => point.cumulative_sales),
) * 1.06);
const barWidth = computed(() => Math.max(5, Math.min(13, pointWidth.value * 0.28)));
const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const dailyY = (value: number) => plot.bottom - (value / dailyMax.value) * (plot.bottom - plot.top);
const cumulativeY = (value: number) => plot.bottom - (value / cumulativeMax.value) * (plot.bottom - plot.top);
const cumulativePath = computed(() => props.progress.points
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${xAt(index)} ${cumulativeY(point.cumulative_sales)}`)
    .join(' '));
const cumulativeAreaPath = computed(() => {
    if (!props.progress.points.length) return '';
    const firstX = xAt(0);
    const lastX = xAt(props.progress.points.length - 1);
    return `${cumulativePath.value} L ${lastX} ${plot.bottom} L ${firstX} ${plot.bottom} Z`;
});
const hoveredPoint = computed(() => hoveredIndex.value === null ? null : props.progress.points[hoveredIndex.value] ?? null);
const hoveredX = computed(() => hoveredIndex.value === null ? 0 : xAt(hoveredIndex.value));

const completionReady = computed(() => (props.progress.completion.target ?? 0) > 0 && props.progress.completion.rate !== null);
const completionPercent = computed(() => Math.min(100, Math.max(0, props.progress.completion.rate ?? 0)));
const donutStyle = computed(() => ({
    background: `conic-gradient(#10b981 0% ${completionPercent.value}%, #e2e8f0 ${completionPercent.value}% 100%)`,
}));
const rateClass = computed(() => {
    const rate = props.progress.completion.rate ?? 0;
    if (rate >= 100) return 'text-emerald-600';
    if (rate >= 60) return 'text-blue-700';
    return 'text-rose-600';
});

const money = (value: number | null, decimals = 2) => {
    if (value === null) return '—';

    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: props.currency || 'USD',
            currencyDisplay: 'narrowSymbol',
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(value);
    } catch {
        return `$${value.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
    }
};
const compactMoney = (value: number) => {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: props.currency || 'USD',
            currencyDisplay: 'narrowSymbol',
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(value);
    } catch {
        return `$${value.toLocaleString('en-US', { notation: 'compact', maximumFractionDigits: 1 })}`;
    }
};
const shortDate = (date: string) => date.slice(5);
</script>

<template>
    <div class="mt-6 grid min-w-0 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,0.9fr)]" :class="{ 'animate-pulse opacity-60': loading }">
        <section class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="goal-daily-progress-title">
            <header class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">数据库每日同步记录</p>
                    <h2 id="goal-daily-progress-title" class="mt-1.5 text-lg font-semibold text-slate-950">每日销售与累计进度</h2>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-slate-500">
                    <span class="inline-flex items-center gap-2"><i class="h-2.5 w-5 rounded-sm bg-blue-500/85" />每日完成</span>
                    <span class="inline-flex items-center gap-2"><i class="h-2.5 w-5 rounded-sm bg-rose-500/75" />每日退款</span>
                    <span class="inline-flex items-center gap-2"><i class="h-0.5 w-5 bg-emerald-500" />累计完成</span>
                    <span class="inline-flex items-center gap-2"><i class="w-5 border-t-2 border-dashed border-orange-500" />月目标</span>
                </div>
            </header>

            <div v-if="progress.points.length" class="goal-chart-scrollbar min-w-0 overflow-x-auto px-2 pb-3 pt-2 sm:px-4">
                <div class="relative" :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }">
                    <svg v-readable-chart :viewBox="`0 0 ${chartWidth} ${chartHeight}`" :width="chartWidth" :height="chartHeight" role="img" aria-label="每日销售、退款、累计销售和月目标趋势图">
                        <defs>
                            <linearGradient id="goal-progress-area" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#10b981" stop-opacity="0.18" />
                                <stop offset="100%" stop-color="#10b981" stop-opacity="0.02" />
                            </linearGradient>
                        </defs>
                        <text :x="plot.left" y="24" class="fill-slate-400 text-xs font-medium">每日金额</text>
                        <text :x="chartWidth - plot.right" y="24" text-anchor="end" class="fill-slate-400 text-xs font-medium">累计金额</text>

                        <g v-for="ratio in ticks" :key="ratio">
                            <line :x1="plot.left" :x2="chartWidth - plot.right" :y1="plot.bottom - ratio * (plot.bottom - plot.top)" :y2="plot.bottom - ratio * (plot.bottom - plot.top)" class="stroke-slate-200" stroke-dasharray="4 5" />
                            <text :x="plot.left - 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-xs tabular-nums">{{ compactMoney(dailyMax * ratio) }}</text>
                            <text :x="chartWidth - plot.right + 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" class="fill-slate-400 text-xs tabular-nums">{{ compactMoney(cumulativeMax * ratio) }}</text>
                        </g>

                        <path v-if="cumulativeAreaPath" :d="cumulativeAreaPath" fill="url(#goal-progress-area)" />
                        <g v-for="(point, index) in progress.points" :key="point.date">
                            <rect :x="xAt(index) - barWidth - 1.5" :y="dailyY(point.daily_sales)" :width="barWidth" :height="plot.bottom - dailyY(point.daily_sales)" rx="3" fill="#3b82f6" :fill-opacity="hoveredIndex === index ? 1 : 0.82" />
                            <rect :x="xAt(index) + 1.5" :y="dailyY(point.daily_refunds)" :width="barWidth" :height="plot.bottom - dailyY(point.daily_refunds)" rx="3" fill="#f43f5e" :fill-opacity="hoveredIndex === index ? 0.9 : 0.68" />
                        </g>

                        <path v-if="cumulativePath" :d="cumulativePath" fill="none" stroke="#10b981" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.7" />
                        <line v-if="progress.monthly_target !== null" :x1="plot.left" :x2="chartWidth - plot.right" :y1="cumulativeY(progress.monthly_target)" :y2="cumulativeY(progress.monthly_target)" stroke="#f97316" stroke-dasharray="10 7" stroke-width="2.2" />

                        <g v-for="(point, index) in progress.points" :key="`point-${point.date}`">
                            <circle :cx="xAt(index)" :cy="cumulativeY(point.cumulative_sales)" r="3.5" fill="#10b981" stroke="white" stroke-width="1.5" />
                            <text :x="xAt(index)" :y="plot.bottom + 25" text-anchor="middle" class="fill-slate-500 text-xs tabular-nums">{{ shortDate(point.date) }}</text>
                            <rect :x="xAt(index) - pointWidth / 2" :y="plot.top" :width="pointWidth" :height="plot.bottom - plot.top + 40" fill="transparent" tabindex="0" @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null" />
                        </g>
                        <line v-if="hoveredPoint" :x1="hoveredX" :x2="hoveredX" :y1="plot.top" :y2="plot.bottom" class="stroke-slate-400" stroke-dasharray="5 5" />
                    </svg>

                    <div v-if="hoveredPoint" class="pointer-events-none absolute top-14 z-10 w-60 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur" :style="{ left: `${Math.min(Math.max(hoveredX + 12, plot.left), chartWidth - 252)}px` }">
                        <p class="font-semibold text-slate-900">{{ hoveredPoint.date }}</p>
                        <dl class="mt-3 space-y-2 text-slate-500">
                            <div class="flex justify-between gap-4"><dt>每日完成</dt><dd class="font-semibold tabular-nums text-blue-700">{{ money(hoveredPoint.daily_sales) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>每日退款</dt><dd class="font-semibold tabular-nums text-rose-600">{{ money(-hoveredPoint.daily_refunds) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>累计完成</dt><dd class="font-semibold tabular-nums text-emerald-700">{{ money(hoveredPoint.cumulative_sales) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>月目标</dt><dd class="font-semibold tabular-nums text-orange-600">{{ money(progress.monthly_target) }}</dd></div>
                        </dl>
                    </div>
                </div>
            </div>

            <div v-else class="grid min-h-72 place-items-center px-6 py-12 text-center">
                <div>
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400">↗</span>
                    <p class="mt-3 text-sm font-semibold text-slate-700">{{ progress.message || '所选时间范围内暂无每日进度数据。' }}</p>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="goal-completion-title">
            <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">数据库最新有效记录</p>
                <h2 id="goal-completion-title" class="mt-1.5 text-lg font-semibold text-slate-950">目标完成率</h2>
            </header>

            <div v-if="completionReady" class="flex min-h-[348px] flex-col items-center justify-center px-6 py-7">
                <div class="grid h-48 w-48 place-items-center rounded-full p-5" :style="donutStyle">
                    <div class="grid h-full w-full place-items-center rounded-full bg-white text-center shadow-inner">
                        <div>
                            <strong class="text-3xl font-semibold tracking-tight tabular-nums" :class="rateClass">{{ progress.completion.rate?.toFixed(1) }}%</strong>
                            <p class="mt-1 text-xs font-medium text-slate-400">已完成</p>
                        </div>
                    </div>
                </div>

                <dl class="mt-7 w-full space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-4" :title="`数据库字段：${progress.source_fields.cumulative_sales}`"><dt class="text-slate-500">累计销售额</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(progress.completion.cumulative_sales) }}</dd></div>
                    <div class="flex items-center justify-between gap-4" :title="`数据库字段：${progress.source_fields.monthly_refunds}`"><dt class="text-slate-500">累计退款</dt><dd class="font-semibold tabular-nums text-rose-600">{{ money(progress.completion.monthly_refunds) }}</dd></div>
                    <div class="flex items-center justify-between gap-4" :title="`数据库字段：${progress.source_fields.monthly_target}`"><dt class="text-slate-500">月目标</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(progress.completion.target, 0) }}</dd></div>
                    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">距离目标</dt><dd class="font-semibold tabular-nums text-emerald-700">{{ money(progress.completion.remaining) }}</dd></div>
                </dl>
            </div>

            <div v-else class="grid min-h-[348px] place-items-center px-6 py-12 text-center">
                <div>
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400">%</span>
                    <p class="mt-3 text-sm font-semibold text-slate-700">暂无可用的月目标数据</p>
                    <p class="mt-1 text-xs leading-5 text-slate-400">需要数据库记录包含月销售额总和与月度目标销售额。</p>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.goal-chart-scrollbar { scrollbar-color: rgb(148 163 184 / 0.55) transparent; scrollbar-width: thin; }
.goal-chart-scrollbar::-webkit-scrollbar { height: 7px; }
.goal-chart-scrollbar::-webkit-scrollbar-track { background: transparent; }
.goal-chart-scrollbar::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.55); }
</style>
