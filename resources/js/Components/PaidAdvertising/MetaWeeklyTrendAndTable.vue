<script setup lang="ts">
import { computed, ref } from 'vue';

interface MetaWeeklyPoint {
    week: string;
    date_from: string;
    date_to: string;
    sales: number;
    spend: number;
    roi: number | null;
}

interface MetaWeeklyData {
    trend: {
        available: boolean;
        points: MetaWeeklyPoint[];
    };
    table: {
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
    synced_at: string | null;
}

const props = defineProps<{
    weekly: MetaWeeklyData;
    currency: string;
    boardName?: string;
    loading?: boolean;
}>();

const hoveredIndex = ref<number | null>(null);
const plot = { left: 68, right: 62, top: 54, bottom: 276 };
const chartHeight = 332;
const slotWidth = 112;
const ticks = [0, 0.25, 0.5, 0.75, 1];
const points = computed(() => props.weekly.trend.points);
const chartWidth = computed(() => Math.max(760, plot.left + plot.right + points.value.length * slotWidth));
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right);
const pointWidth = computed(() => points.value.length ? plotWidth.value / points.value.length : slotWidth);
const amountMax = computed(() => Math.max(1, ...points.value.flatMap((point) => [point.sales, point.spend])) * 1.12);
const roiMax = computed(() => Math.max(1, ...points.value.map((point) => point.roi ?? 0)) * 1.12);
const barWidth = computed(() => Math.max(12, Math.min(24, pointWidth.value * 0.25)));
const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const amountY = (value: number) => plot.bottom - (value / amountMax.value) * (plot.bottom - plot.top);
const roiY = (value: number) => plot.bottom - (value / roiMax.value) * (plot.bottom - plot.top);
const roiPath = computed(() => points.value
    .map((point, index) => point.roi === null ? '' : `${index === 0 ? 'M' : 'L'} ${xAt(index)} ${roiY(point.roi)}`)
    .filter(Boolean)
    .join(' '));
const hoveredPoint = computed(() => hoveredIndex.value === null ? null : points.value[hoveredIndex.value] ?? null);
const hoveredX = computed(() => hoveredIndex.value === null ? 0 : xAt(hoveredIndex.value));

const money = (value: number | null, digits = 2) => {
    if (value === null) return '—';

    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: props.currency || 'USD',
            currencyDisplay: 'narrowSymbol',
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(value);
    } catch {
        return `$${value.toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;
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
const tableValue = (value: string | number | null, format: string) => {
    if (value === null || value === '') return '—';
    if (format === 'text') return String(value);
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);
    if (format === 'percentage') return `${(number * 100).toFixed(2)}%`;
    if (format === 'currency') return money(number);
    if (format === 'roi') return `${number.toFixed(2)}×`;

    return Math.round(number).toLocaleString('en-US');
};
</script>

<template>
    <div class="space-y-6" :class="{ 'animate-pulse opacity-60': loading }">
        <section class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="meta-weekly-trend-title">
            <header class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Meta · {{ boardName || '个人目标' }}</p>
                    <h2 id="meta-weekly-trend-title" class="mt-1.5 text-lg font-semibold text-slate-950">销售额与费用变化趋势</h2>
                    <p class="mt-1 text-xs text-slate-400">最近 8 个完整周 · 数据来自已同步的 Meta 周表</p>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-slate-500">
                    <span class="inline-flex items-center gap-2"><i class="h-2.5 w-5 rounded-sm bg-emerald-500/80" />销售额</span>
                    <span class="inline-flex items-center gap-2"><i class="h-2.5 w-5 rounded-sm bg-blue-500/85" />花费</span>
                    <span class="inline-flex items-center gap-2"><i class="h-0.5 w-5 bg-orange-500" />ROI</span>
                </div>
            </header>

            <div v-if="weekly.trend.available" class="meta-chart-scrollbar min-w-0 overflow-x-auto px-2 pb-3 pt-2 sm:px-4">
                <div class="relative" :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }">
                    <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" :width="chartWidth" :height="chartHeight" role="img" aria-label="Meta 周销售额、花费与 ROI 趋势图">
                        <text :x="plot.left" y="25" class="fill-slate-400 text-[11px] font-medium">销售额 / 花费</text>
                        <text :x="chartWidth - plot.right" y="25" text-anchor="end" class="fill-slate-400 text-[11px] font-medium">ROI</text>

                        <g v-for="ratio in ticks" :key="ratio">
                            <line :x1="plot.left" :x2="chartWidth - plot.right" :y1="plot.bottom - ratio * (plot.bottom - plot.top)" :y2="plot.bottom - ratio * (plot.bottom - plot.top)" class="stroke-slate-200" stroke-dasharray="4 5" />
                            <text :x="plot.left - 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-[10px] tabular-nums">{{ compactMoney(amountMax * ratio) }}</text>
                            <text :x="chartWidth - plot.right + 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" class="fill-slate-400 text-[10px] tabular-nums">{{ (roiMax * ratio).toFixed(1) }}×</text>
                        </g>

                        <g v-for="(point, index) in points" :key="point.week">
                            <rect :x="xAt(index) - barWidth - 2" :y="amountY(point.sales)" :width="barWidth" :height="plot.bottom - amountY(point.sales)" rx="4" fill="#10b981" :fill-opacity="hoveredIndex === index ? 0.95 : 0.74" />
                            <rect :x="xAt(index) + 2" :y="amountY(point.spend)" :width="barWidth" :height="plot.bottom - amountY(point.spend)" rx="4" fill="#3b82f6" :fill-opacity="hoveredIndex === index ? 1 : 0.82" />
                        </g>

                        <path v-if="roiPath" :d="roiPath" fill="none" stroke="#f97316" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.8" />
                        <g v-for="(point, index) in points" :key="`roi-${point.week}`">
                            <circle v-if="point.roi !== null" :cx="xAt(index)" :cy="roiY(point.roi)" r="4" fill="#f97316" stroke="white" stroke-width="1.5" />
                            <text :x="xAt(index)" :y="plot.bottom + 25" text-anchor="middle" class="fill-slate-500 text-[10px] tabular-nums">{{ point.week }}</text>
                            <rect :x="xAt(index) - pointWidth / 2" :y="plot.top" :width="pointWidth" :height="plot.bottom - plot.top + 40" fill="transparent" tabindex="0" @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null" />
                        </g>
                        <line v-if="hoveredPoint" :x1="hoveredX" :x2="hoveredX" :y1="plot.top" :y2="plot.bottom" class="stroke-slate-400" stroke-dasharray="5 5" />
                    </svg>

                    <div v-if="hoveredPoint" class="pointer-events-none absolute top-14 z-10 w-60 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur" :style="{ left: `${Math.min(Math.max(hoveredX + 12, plot.left), chartWidth - 252)}px` }">
                        <p class="font-semibold text-slate-900">{{ hoveredPoint.week }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ hoveredPoint.date_from }} 至 {{ hoveredPoint.date_to }}</p>
                        <dl class="mt-3 space-y-2 text-slate-500">
                            <div class="flex justify-between gap-4"><dt>销售额</dt><dd class="font-semibold tabular-nums text-emerald-700">{{ money(hoveredPoint.sales) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>花费</dt><dd class="font-semibold tabular-nums text-blue-700">{{ money(hoveredPoint.spend) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>ROI</dt><dd class="font-semibold tabular-nums text-orange-600">{{ hoveredPoint.roi === null ? '—' : `${hoveredPoint.roi.toFixed(2)}×` }}</dd></div>
                        </dl>
                    </div>
                </div>
            </div>
            <div v-else class="grid min-h-72 place-items-center px-6 py-12 text-center">
                <p class="text-sm font-semibold text-slate-700">暂无已同步的 Meta 周趋势数据。</p>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="meta-weekly-table-title">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">飞书周表同步记录</p>
                    <h2 id="meta-weekly-table-title" class="mt-1.5 text-lg font-semibold text-slate-950">数据汇总表</h2>
                    <p class="mt-1 text-xs text-slate-400">{{ weekly.table.period_label }} · 与上方趋势使用相同的完整周</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ weekly.table.total }} 周</span>
            </header>

            <div v-if="weekly.table.available" class="p-4 sm:p-5">
                <div class="meta-table-scrollbar max-h-[30rem] overflow-auto rounded-xl border border-slate-200">
                    <table class="min-w-max border-separate border-spacing-0 text-left text-xs">
                        <thead class="sticky top-0 z-20 bg-slate-50/95 backdrop-blur">
                            <tr>
                                <th v-for="(column, index) in weekly.table.columns" :key="column.key" class="min-w-32 border-b border-r border-slate-200 px-4 py-3 font-semibold whitespace-nowrap text-slate-500 last:border-r-0" :class="{ 'sticky left-0 z-30 bg-slate-50': index === 0 }">{{ column.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in weekly.table.rows" :key="row.key" class="group even:bg-slate-50/45 hover:bg-blue-50/35">
                                <td v-for="(column, index) in weekly.table.columns" :key="`${row.key}-${column.key}`" class="border-b border-r border-slate-100 px-4 py-3 whitespace-nowrap last:border-r-0 group-last:border-b-0 tabular-nums text-slate-700" :class="index === 0 ? 'sticky left-0 z-10 bg-white font-semibold group-even:bg-slate-50 group-hover:bg-blue-50' : column.format === 'roi' ? 'font-semibold text-orange-600' : ''">
                                    {{ tableValue(row.values[column.key], column.format) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 px-1 text-xs text-slate-400">
                    <span>字段来自配置的 Meta 周数据飞书表格</span>
                    <span>按周从早到晚排列</span>
                </div>
            </div>
            <div v-else class="px-6 py-12 text-center">
                <p class="text-sm font-semibold text-slate-700">暂无可展示的 Meta 周汇总记录。</p>
            </div>
        </section>
    </div>
</template>

<style scoped>
.meta-chart-scrollbar,
.meta-table-scrollbar { scrollbar-color: rgb(148 163 184 / 0.55) transparent; scrollbar-width: thin; }
.meta-chart-scrollbar::-webkit-scrollbar,
.meta-table-scrollbar::-webkit-scrollbar { width: 7px; height: 7px; }
.meta-chart-scrollbar::-webkit-scrollbar-track,
.meta-table-scrollbar::-webkit-scrollbar-track { background: transparent; }
.meta-chart-scrollbar::-webkit-scrollbar-thumb,
.meta-table-scrollbar::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.55); }
</style>
