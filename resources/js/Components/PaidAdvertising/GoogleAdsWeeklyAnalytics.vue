<script setup lang="ts">
import { computed, ref } from 'vue';

type MetricKey = 'spend' | 'revenue' | 'roi' | 'conversions' | 'add_to_cart' | 'checkout' | 'add_to_cart_cost' | 'checkout_cost' | 'cpa';
type WeekOption = { key: string; label: string; date_from: string; date_to: string };
type WeekRow = WeekOption & { values: Record<MetricKey, number> };
type WeeklyReport = {
    source_table: string | null;
    selected_week: WeekOption | null;
    values: Record<MetricKey, number> | null;
    history: WeekRow[];
};

const props = defineProps<{ report: WeeklyReport; currency: string }>();
const hoveredSpendIndex = ref<number | null>(null);
const hoveredCheckoutIndex = ref<number | null>(null);
const history = computed(() => props.report.history ?? []);
const tableRows = computed(() => [...history.value].reverse());

const spendChart = computed(() => {
    const width = Math.max(820, 120 + history.value.length * 28);
    const height = 340;
    const left = 66;
    const right = 58;
    const top = 48;
    const bottom = 286;
    const plotWidth = width - left - right;
    const plotHeight = bottom - top;
    const spendMax = Math.max(1, ...history.value.map((row) => row.values.spend)) * 1.12;
    const roiMax = Math.max(1, ...history.value.map((row) => row.values.roi)) * 1.12;
    const step = history.value.length ? plotWidth / history.value.length : plotWidth;
    const barWidth = Math.max(7, Math.min(17, step * 0.56));
    const labelEvery = Math.max(1, Math.ceil(history.value.length / 8));
    const points = history.value.map((row, index) => ({
        ...row,
        index,
        x: left + step * (index + 0.5),
        spendY: bottom - (row.values.spend / spendMax) * plotHeight,
        roiY: bottom - (row.values.roi / roiMax) * plotHeight,
        shortLabel: row.label.replace(/^\d+周\s*/, ''),
        showLabel: index % labelEvery === 0 || index === history.value.length - 1,
    }));

    return {
        width, height, left, right, top, bottom, plotHeight, step, barWidth, spendMax, roiMax, points,
        linePoints: points.map((point) => `${point.x},${point.roiY}`).join(' '),
        ticks: [0, 1, 2, 3, 4].map((index) => ({
            y: bottom - (index / 4) * plotHeight,
            spend: spendMax * (index / 4),
            roi: roiMax * (index / 4),
        })),
    };
});
const activeSpendPoint = computed(() => {
    const points = spendChart.value.points;
    const index = hoveredSpendIndex.value ?? points.length - 1;
    return points[index] ?? null;
});

const funnel = computed(() => {
    const values = props.report.values;
    const stages = [
        { key: 'add', label: '加购', value: values?.add_to_cart ?? 0, color: '#3b82f6' },
        { key: 'checkout', label: '结账', value: values?.checkout ?? 0, color: '#f59e0b' },
        { key: 'conversion', label: '成交', value: values?.conversions ?? 0, color: '#16a34a' },
    ];
    const max = Math.max(1, stages[0].value);
    const center = 300;
    const top = 45;
    const stageHeight = 92;
    const widths = stages.map((stage, index) => index === 0
        ? 500
        : Math.max(index === 1 ? 210 : 80, 500 * Math.sqrt(stage.value / max)));
    const bottomWidths = [widths[1], widths[2], 28];

    return stages.map((stage, index) => {
        const y1 = top + index * stageHeight;
        const y2 = y1 + stageHeight;
        const topWidth = widths[index];
        const bottomWidth = bottomWidths[index];

        return {
            ...stage,
            y1,
            y2,
            points: `${center - topWidth / 2},${y1} ${center + topWidth / 2},${y1} ${center + bottomWidth / 2},${y2} ${center - bottomWidth / 2},${y2}`,
            textY: y1 + stageHeight / 2 - 4,
        };
    });
});
const checkoutRate = computed(() => {
    const add = props.report.values?.add_to_cart ?? 0;
    return add > 0 ? ((props.report.values?.checkout ?? 0) / add) * 100 : 0;
});
const conversionRate = computed(() => {
    const checkout = props.report.values?.checkout ?? 0;
    return checkout > 0 ? ((props.report.values?.conversions ?? 0) / checkout) * 100 : 0;
});

const checkoutChart = computed(() => {
    const width = Math.max(1040, 130 + history.value.length * 42);
    const height = 410;
    const left = 70;
    const right = 66;
    const top = 58;
    const bottom = 324;
    const plotWidth = width - left - right;
    const plotHeight = bottom - top;
    const countMax = Math.max(1, ...history.value.flatMap((row) => [row.values.add_to_cart, row.values.checkout])) * 1.12;
    const costMax = Math.max(1, ...history.value.flatMap((row) => [row.values.add_to_cart_cost, row.values.checkout_cost])) * 1.12;
    const step = history.value.length ? plotWidth / history.value.length : plotWidth;
    const barWidth = Math.max(7, Math.min(15, step * 0.3));
    const points = history.value.map((row, index) => ({
        ...row,
        index,
        x: left + step * (index + 0.5),
        addY: bottom - (row.values.add_to_cart / countMax) * plotHeight,
        checkoutY: bottom - (row.values.checkout / countMax) * plotHeight,
        addCostY: bottom - (row.values.add_to_cart_cost / costMax) * plotHeight,
        checkoutCostY: bottom - (row.values.checkout_cost / costMax) * plotHeight,
        shortLabel: row.label.replace(/^\d+周\s*/, ''),
    }));

    return {
        width, height, left, right, top, bottom, plotHeight, step, barWidth, countMax, costMax, points,
        addCostLine: points.map((point) => `${point.x},${point.addCostY}`).join(' '),
        checkoutCostLine: points.map((point) => `${point.x},${point.checkoutCostY}`).join(' '),
        ticks: [0, 1, 2, 3, 4].map((index) => ({
            y: bottom - (index / 4) * plotHeight,
            count: countMax * (index / 4),
            cost: costMax * (index / 4),
        })),
    };
});
const activeCheckoutPoint = computed(() => hoveredCheckoutIndex.value === null
    ? null
    : checkoutChart.value.points[hoveredCheckoutIndex.value] ?? null);

const columns: Array<{ key: MetricKey | 'label'; label: string; format: 'text' | 'money' | 'number' | 'ratio' }> = [
    { key: 'label', label: '周', format: 'text' },
    { key: 'spend', label: '花费', format: 'money' },
    { key: 'revenue', label: '转化价值', format: 'money' },
    { key: 'roi', label: 'ROI', format: 'ratio' },
    { key: 'add_to_cart', label: '加购', format: 'number' },
    { key: 'checkout', label: '结账', format: 'number' },
    { key: 'conversions', label: '成交', format: 'number' },
    { key: 'add_to_cart_cost', label: '加购成本', format: 'money' },
    { key: 'checkout_cost', label: '结账成本', format: 'money' },
    { key: 'cpa', label: 'CPA', format: 'money' },
];

function money(value: number): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol',
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(value);
    } catch {
        return `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
}
function compact(value: number): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value || 0);
}
function integer(value: number): string {
    return value.toLocaleString('en-US', { maximumFractionDigits: 0 });
}
function tableValue(row: WeekRow, key: MetricKey | 'label', format: 'text' | 'money' | 'number' | 'ratio'): string {
    if (key === 'label') return row.label;
    const value = row.values[key];
    if (format === 'money') return money(value);
    if (format === 'ratio') return `${value.toFixed(2)}×`;
    return integer(value);
}
function tableClass(key: MetricKey | 'label', row: WeekRow): string {
    if (key === 'revenue' || key === 'conversions') return 'text-emerald-700';
    if (key === 'roi') return row.values.roi >= 6 ? 'text-teal-700' : 'text-orange-600';
    return key === 'label' && row.key === props.report.selected_week?.key ? 'text-blue-700' : 'text-slate-700';
}
</script>

<template>
    <div class="space-y-5">
        <div class="grid gap-5 xl:grid-cols-2">
            <article class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="weekly-spend-roi-title">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:px-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">历史趋势 · {{ history.length }} 周</p>
                        <h3 id="weekly-spend-roi-title" class="mt-1.5 text-lg font-semibold text-slate-950">周花费 &amp; ROI 趋势</h3>
                        <p class="mt-1 text-xs text-slate-400">截至 {{ report.selected_week?.label }}，与上方周报使用同一数据表</p>
                    </div>
                    <div class="flex gap-3 text-xs font-medium text-slate-500">
                        <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-blue-500" />花费</span>
                        <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 bg-emerald-500" />ROI</span>
                    </div>
                </header>
                <div class="relative overflow-x-auto p-3 sm:p-4" @mouseleave="hoveredSpendIndex = null">
                    <div class="relative min-w-[760px]" :style="{ width: `${spendChart.width}px` }">
                        <svg class="block" :width="spendChart.width" :height="spendChart.height" :viewBox="`0 0 ${spendChart.width} ${spendChart.height}`" role="img" aria-label="Google Ads 周花费与 ROI 趋势">
                            <text :x="spendChart.left" y="24" fill="#94a3b8" font-size="11">花费</text>
                            <text :x="spendChart.width - spendChart.right" y="24" text-anchor="end" fill="#94a3b8" font-size="11">ROI</text>
                            <g v-for="tick in spendChart.ticks" :key="`spend-tick-${tick.y}`">
                                <line :x1="spendChart.left" :x2="spendChart.width - spendChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5" />
                                <text :x="spendChart.left - 8" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="10">{{ compact(tick.spend) }}</text>
                                <text :x="spendChart.width - spendChart.right + 8" :y="tick.y + 4" fill="#94a3b8" font-size="10">{{ tick.roi.toFixed(1) }}×</text>
                            </g>
                            <g v-for="point in spendChart.points" :key="point.key" @mouseenter="hoveredSpendIndex = point.index">
                                <rect :x="point.x - spendChart.barWidth / 2" :y="point.spendY" :width="spendChart.barWidth" :height="spendChart.bottom - point.spendY" rx="3" :fill="point.index === spendChart.points.length - 1 ? '#2563eb' : '#3b82f6'" :opacity="hoveredSpendIndex === null || hoveredSpendIndex === point.index ? 0.92 : 0.38" />
                                <rect :x="point.x - spendChart.step / 2" :y="spendChart.top" :width="spendChart.step" :height="spendChart.bottom - spendChart.top + 34" fill="transparent" />
                                <text v-if="point.showLabel" :x="point.x" :y="spendChart.bottom + 22" text-anchor="middle" fill="#64748b" font-size="10">{{ point.shortLabel }}</text>
                            </g>
                            <polyline :points="spendChart.linePoints" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle v-for="point in spendChart.points" :key="`roi-${point.key}`" :cx="point.x" :cy="point.roiY" :r="point.index === spendChart.points.length - 1 ? 4.5 : 3" fill="#10b981" stroke="white" stroke-width="1.5" />
                            <line v-if="activeSpendPoint" :x1="activeSpendPoint.x" :x2="activeSpendPoint.x" :y1="spendChart.top" :y2="spendChart.bottom" stroke="#64748b" stroke-dasharray="5 5" opacity="0.7" />
                        </svg>
                        <div v-if="activeSpendPoint" class="pointer-events-none absolute right-3 top-9 w-52 rounded-xl border border-slate-200 bg-white/95 p-3 text-xs shadow-xl backdrop-blur">
                            <p class="font-semibold text-slate-900">{{ activeSpendPoint.label }}</p>
                            <p class="mt-2 flex justify-between gap-4 text-slate-500"><span>花费</span><strong class="text-blue-700">{{ money(activeSpendPoint.values.spend) }}</strong></p>
                            <p class="mt-1 flex justify-between gap-4 text-slate-500"><span>ROI</span><strong class="text-emerald-700">{{ activeSpendPoint.values.roi.toFixed(2) }}×</strong></p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="weekly-funnel-title">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-600">当前周转化路径</p>
                    <h3 id="weekly-funnel-title" class="mt-1.5 text-lg font-semibold text-slate-950">转化漏斗 · {{ report.selected_week?.label }}</h3>
                    <p class="mt-1 text-xs text-slate-400">加购 → 结账 → 成交，均来自当前所选周记录</p>
                </header>
                <div class="grid min-h-[372px] items-center gap-3 p-4 sm:grid-cols-[minmax(360px,1fr)_auto] sm:p-5">
                    <svg class="h-auto w-full" viewBox="0 0 600 340" role="img" :aria-label="`${report.selected_week?.label} 加购、结账与成交漏斗`">
                        <g v-for="stage in funnel" :key="stage.key">
                            <polygon :points="stage.points" :fill="stage.color" opacity="0.94" stroke="white" stroke-width="2" />
                            <text x="300" :y="stage.textY" text-anchor="middle" fill="white" font-size="16" font-weight="700">{{ stage.label }}</text>
                            <text x="300" :y="stage.textY + 23" text-anchor="middle" fill="white" font-size="19" font-weight="800">{{ integer(stage.value) }}</text>
                        </g>
                    </svg>
                    <div class="space-y-3 text-xs text-slate-500 sm:w-32">
                        <div v-for="stage in funnel" :key="`legend-${stage.key}`" class="flex items-center justify-between gap-3">
                            <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded" :style="{ backgroundColor: stage.color }" />{{ stage.label }}</span>
                            <strong class="tabular-nums text-slate-800">{{ integer(stage.value) }}</strong>
                        </div>
                        <div class="border-t border-slate-100 pt-3 leading-6">
                            <p>加购→结账 <strong class="float-right text-slate-800">{{ checkoutRate.toFixed(1) }}%</strong></p>
                            <p>结账→成交 <strong class="float-right text-slate-800">{{ conversionRate.toFixed(1) }}%</strong></p>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <article class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="weekly-checkout-title">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600">数量与成本双轴</p>
                    <h3 id="weekly-checkout-title" class="mt-1.5 text-lg font-semibold text-slate-950">加购 &amp; 结账趋势</h3>
                    <p class="mt-1 text-xs text-slate-400">截至 {{ report.selected_week?.label }} · 柱状为数量，折线为单次成本</p>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs font-medium text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-blue-500" />加购数</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-lime-500" />结账数</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 bg-violet-500" />加购成本</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 bg-amber-500" />结账成本</span>
                </div>
            </header>
            <div class="overflow-x-auto p-3 sm:p-4" @mouseleave="hoveredCheckoutIndex = null">
                <div class="relative" :style="{ width: `${checkoutChart.width}px`, height: `${checkoutChart.height}px` }">
                    <svg :width="checkoutChart.width" :height="checkoutChart.height" :viewBox="`0 0 ${checkoutChart.width} ${checkoutChart.height}`" role="img" aria-label="Google Ads 周加购、结账与成本趋势">
                        <text :x="checkoutChart.left" y="28" fill="#94a3b8" font-size="11">数量</text>
                        <text :x="checkoutChart.width - checkoutChart.right" y="28" text-anchor="end" fill="#94a3b8" font-size="11">成本</text>
                        <g v-for="tick in checkoutChart.ticks" :key="`checkout-tick-${tick.y}`">
                            <line :x1="checkoutChart.left" :x2="checkoutChart.width - checkoutChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5" />
                            <text :x="checkoutChart.left - 9" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="10">{{ compact(tick.count) }}</text>
                            <text :x="checkoutChart.width - checkoutChart.right + 9" :y="tick.y + 4" fill="#94a3b8" font-size="10">${{ tick.cost.toFixed(0) }}</text>
                        </g>
                        <g v-for="point in checkoutChart.points" :key="`bars-${point.key}`" @mouseenter="hoveredCheckoutIndex = point.index">
                            <rect :x="point.x - checkoutChart.barWidth - 1.5" :y="point.addY" :width="checkoutChart.barWidth" :height="checkoutChart.bottom - point.addY" rx="2.5" fill="#3b82f6" :opacity="hoveredCheckoutIndex === null || hoveredCheckoutIndex === point.index ? 0.84 : 0.34" />
                            <rect :x="point.x + 1.5" :y="point.checkoutY" :width="checkoutChart.barWidth" :height="checkoutChart.bottom - point.checkoutY" rx="2.5" fill="#84cc16" :opacity="hoveredCheckoutIndex === null || hoveredCheckoutIndex === point.index ? 0.84 : 0.34" />
                            <rect :x="point.x - checkoutChart.step / 2" :y="checkoutChart.top" :width="checkoutChart.step" :height="checkoutChart.bottom - checkoutChart.top + 58" fill="transparent" />
                            <text :x="point.x" :y="checkoutChart.bottom + 22" text-anchor="end" fill="#64748b" font-size="9.5" :transform="`rotate(-34 ${point.x} ${checkoutChart.bottom + 22})`">{{ point.shortLabel }}</text>
                        </g>
                        <polyline :points="checkoutChart.addCostLine" fill="none" stroke="#8b5cf6" stroke-width="2.8" stroke-dasharray="8 5" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline :points="checkoutChart.checkoutCostLine" fill="none" stroke="#f59e0b" stroke-width="2.8" stroke-dasharray="8 5" stroke-linecap="round" stroke-linejoin="round" />
                        <circle v-for="point in checkoutChart.points" :key="`add-cost-${point.key}`" :cx="point.x" :cy="point.addCostY" r="3.2" fill="#8b5cf6" stroke="white" stroke-width="1.2" />
                        <circle v-for="point in checkoutChart.points" :key="`checkout-cost-${point.key}`" :cx="point.x" :cy="point.checkoutCostY" r="3.2" fill="#f59e0b" stroke="white" stroke-width="1.2" />
                        <line v-if="activeCheckoutPoint" :x1="activeCheckoutPoint.x" :x2="activeCheckoutPoint.x" :y1="checkoutChart.top" :y2="checkoutChart.bottom" stroke="#64748b" stroke-dasharray="5 5" />
                    </svg>
                    <div v-if="activeCheckoutPoint" class="pointer-events-none absolute top-16 z-10 w-64 rounded-xl border border-slate-200 bg-white/95 p-4 text-xs shadow-xl backdrop-blur" :style="{ left: `${Math.min(Math.max(activeCheckoutPoint.x + 12, checkoutChart.left), checkoutChart.width - 272)}px` }">
                        <p class="font-semibold text-slate-900">{{ activeCheckoutPoint.label }}</p>
                        <div class="mt-3 space-y-1.5 text-slate-500">
                            <p class="flex justify-between gap-5"><span>加购数</span><strong class="text-blue-700">{{ integer(activeCheckoutPoint.values.add_to_cart) }}</strong></p>
                            <p class="flex justify-between gap-5"><span>结账数</span><strong class="text-lime-700">{{ integer(activeCheckoutPoint.values.checkout) }}</strong></p>
                            <p class="flex justify-between gap-5"><span>加购成本</span><strong class="text-violet-700">{{ money(activeCheckoutPoint.values.add_to_cart_cost) }}</strong></p>
                            <p class="flex justify-between gap-5"><span>结账成本</span><strong class="text-amber-700">{{ money(activeCheckoutPoint.values.checkout_cost) }}</strong></p>
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="weekly-detail-title">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-600">本地 MySQL 归档</p>
                    <h3 id="weekly-detail-title" class="mt-1.5 text-lg font-semibold text-slate-950">周报明细</h3>
                    <p class="mt-1 text-xs text-slate-400">共 {{ history.length }} 周 · 数据来源：{{ report.source_table || 'Google周数据' }}</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">截至 {{ report.selected_week?.label }}</span>
            </header>
            <div class="p-4 sm:p-5">
                <div class="max-h-[38rem] overflow-auto rounded-2xl border border-slate-200">
                    <table class="w-full min-w-[1500px] border-separate border-spacing-0 text-left text-sm">
                        <thead class="sticky top-0 z-20 bg-slate-50/95 text-xs font-semibold text-slate-500 backdrop-blur">
                            <tr>
                                <th v-for="(column, index) in columns" :key="column.key" class="whitespace-nowrap border-b border-r border-slate-200 px-4 py-3.5 last:border-r-0" :class="index === 0 ? 'sticky left-0 z-30 min-w-48 bg-slate-50' : 'min-w-32'">{{ column.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in tableRows" :key="`table-${row.key}`" class="group" :class="row.key === report.selected_week?.key ? 'bg-blue-50/60' : 'even:bg-slate-50/45 hover:bg-blue-50/35'">
                                <td v-for="(column, index) in columns" :key="`${row.key}-${column.key}`" class="whitespace-nowrap border-b border-r border-slate-100 px-4 py-3.5 font-medium tabular-nums last:border-r-0" :class="[
                                    tableClass(column.key, row),
                                    index === 0 ? (row.key === report.selected_week?.key ? 'sticky left-0 z-10 bg-blue-50 font-semibold' : 'sticky left-0 z-10 bg-white font-semibold group-even:bg-slate-50 group-hover:bg-blue-50') : '',
                                ]">
                                    {{ tableValue(row, column.key, column.format) }}
                                </td>
                            </tr>
                            <tr v-if="!tableRows.length"><td :colspan="columns.length" class="px-5 py-14 text-center text-sm text-slate-400">暂无周报明细</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    </div>
</template>
