<script setup lang="ts">
import { computed, ref } from 'vue';

interface PerformanceItem {
    id: number;
    name: string;
    starts_on: string | null;
    gmv: number;
    ad_spend: number | null;
    roi: number | null;
}

const props = defineProps<{ currency: string; points: PerformanceItem[] }>();
const hoveredId = ref<number | null>(null);
const plot = { left: 72, right: 62, top: 48, bottom: 332 };
const chartHeight = 418;
const slotWidth = 104;

const orderedPoints = computed(() => [...props.points]
    .filter((point) => point.starts_on !== null)
    .sort((left, right) => {
        const dateOrder = (right.starts_on ?? '').localeCompare(left.starts_on ?? '');
        return dateOrder || right.id - left.id;
    }));
const chartWidth = computed(() => Math.max(920, plot.left + plot.right + orderedPoints.value.length * slotWidth));
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right);
const pointWidth = computed(() => orderedPoints.value.length ? plotWidth.value / orderedPoints.value.length : slotWidth);

const niceMaximum = (maximum: number) => {
    if (maximum <= 0) return 1;
    const padded = maximum * 1.12;
    const magnitude = 10 ** Math.floor(Math.log10(padded));
    const normalized = padded / magnitude;
    const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((candidate) => normalized <= candidate) ?? 10;
    return step * magnitude;
};

const amountMaximum = computed(() => niceMaximum(Math.max(0, ...orderedPoints.value.flatMap((point) => [point.gmv, point.ad_spend ?? 0]))));
const roiMaximum = computed(() => Math.max(10, niceMaximum(Math.max(0, ...orderedPoints.value.map((point) => point.roi ?? 0)))));
const ticks = computed(() => Array.from({ length: 6 }, (_, index) => index / 5));
const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const amountYAt = (value: number) => plot.bottom - (value / amountMaximum.value) * (plot.bottom - plot.top);
const roiYAt = (value: number) => plot.bottom - (value / roiMaximum.value) * (plot.bottom - plot.top);

const roiPath = computed(() => {
    let path = '';
    let previous: { x: number; y: number } | null = null;
    orderedPoints.value.forEach((point, index) => {
        if (point.roi === null) {
            previous = null;
            return;
        }
        const current = { x: xAt(index), y: roiYAt(point.roi) };
        if (previous === null) path += `M ${current.x} ${current.y}`;
        else {
            const midpoint = (previous.x + current.x) / 2;
            path += ` C ${midpoint} ${previous.y}, ${midpoint} ${current.y}, ${current.x} ${current.y}`;
        }
        previous = current;
    });
    return path;
});

const hoveredIndex = computed(() => orderedPoints.value.findIndex((point) => point.id === hoveredId.value));
const hoveredPoint = computed(() => hoveredIndex.value < 0 ? null : orderedPoints.value[hoveredIndex.value]);
const tooltipStyle = computed(() => {
    if (hoveredIndex.value < 0) return {};
    const width = 260;
    const preferred = xAt(hoveredIndex.value) + 22;
    return { left: `${Math.min(Math.max(preferred, plot.left + 8), chartWidth.value - width - 12)}px`, top: '68px', width: `${width}px` };
});

const compactMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', notation: 'compact', maximumFractionDigits: 1,
}).format(value);
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', maximumFractionDigits: 2,
}).format(value);
const shortName = (name: string) => name.length > 6 ? `${name.slice(0, 5)}…` : name;
const shortDate = (date: string | null) => date ? date.slice(5).replace('-', '/') : '--';
const ariaLabel = (point: PerformanceItem) => `${point.name}，销售额 ${money(point.gmv)}，广告花费 ${point.ad_spend === null ? '--' : money(point.ad_spend)}，ROI ${point.roi === null ? '--' : point.roi.toFixed(2)}`;
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">销售额 &amp; 费用 × ROI 数据变化趋势</h2>
                <p class="mt-1 text-xs text-slate-400">活动按开始日期倒序排列</p>
            </div>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs font-medium text-slate-500">
                <span class="inline-flex items-center gap-2"><i class="h-4 w-8 rounded bg-emerald-600/75" />销售额</span>
                <span class="inline-flex items-center gap-2"><i class="h-4 w-8 rounded bg-blue-600/75" />广告花费</span>
                <span class="inline-flex items-center gap-2"><i class="relative h-0.5 w-8 bg-violet-500"><i class="absolute left-1/2 top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-violet-500" /></i>ROI</span>
            </div>
        </header>

        <div v-if="orderedPoints.length" class="chart-scrollbar overflow-x-auto px-3 pb-3 pt-2 sm:px-4">
            <div class="relative" :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }">
                <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" :width="chartWidth" :height="chartHeight" role="img" aria-label="销售额广告花费与 ROI 趋势图">
                    <text :x="plot.left" y="24" class="fill-slate-400 text-[11px] font-medium">金额</text>
                    <text :x="chartWidth - plot.right" y="24" text-anchor="end" class="fill-slate-400 text-[11px] font-medium">ROI</text>

                    <g v-for="ratio in ticks" :key="ratio">
                        <line :x1="plot.left" :x2="chartWidth - plot.right" :y1="plot.bottom - ratio * (plot.bottom - plot.top)" :y2="plot.bottom - ratio * (plot.bottom - plot.top)" class="stroke-slate-200" />
                        <text :x="plot.left - 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-[11px] tabular-nums">{{ compactMoney(amountMaximum * ratio) }}</text>
                        <text :x="chartWidth - plot.right + 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" class="fill-slate-400 text-[11px] tabular-nums">{{ (roiMaximum * ratio).toFixed(1) }}</text>
                    </g>

                    <g v-for="(point, index) in orderedPoints" :key="`bars-${point.id}`">
                        <rect :x="xAt(index) - 27" :y="amountYAt(point.gmv)" width="25" :height="plot.bottom - amountYAt(point.gmv)" rx="5" fill="#059669" :fill-opacity="hoveredId === point.id ? 0.88 : 0.68" />
                        <rect v-if="point.ad_spend !== null" :x="xAt(index) + 2" :y="amountYAt(point.ad_spend)" width="25" :height="plot.bottom - amountYAt(point.ad_spend)" rx="5" fill="#2563eb" :fill-opacity="hoveredId === point.id ? 0.88 : 0.68" />
                    </g>

                    <path v-if="roiPath" :d="roiPath" fill="none" stroke="#a855f7" stroke-linecap="round" stroke-linejoin="round" stroke-width="4" />

                    <g v-for="(point, index) in orderedPoints" :key="`point-${point.id}`">
                        <template v-if="point.roi !== null">
                            <circle :cx="xAt(index)" :cy="roiYAt(point.roi)" r="5" fill="#a855f7" stroke="white" stroke-width="2" />
                            <text :x="xAt(index)" :y="roiYAt(point.roi) - 12" text-anchor="middle" class="fill-violet-600 text-[11px] font-semibold tabular-nums">{{ point.roi.toFixed(2) }}</text>
                        </template>
                        <text :x="xAt(index)" :y="plot.bottom + 27" text-anchor="middle" class="fill-slate-600 text-[11px] font-medium"><title>{{ point.name }}</title>{{ shortName(point.name) }}</text>
                        <text :x="xAt(index)" :y="plot.bottom + 45" text-anchor="middle" class="fill-slate-400 text-[10px] tabular-nums">{{ shortDate(point.starts_on) }}</text>
                        <rect :x="xAt(index) - pointWidth / 2" :y="plot.top" :width="pointWidth" :height="plot.bottom - plot.top + 52" fill="transparent" tabindex="0" role="button" :aria-label="ariaLabel(point)" @mouseenter="hoveredId = point.id" @mouseleave="hoveredId = null" @focus="hoveredId = point.id" @blur="hoveredId = null" />
                    </g>

                    <line v-if="hoveredIndex >= 0" :x1="xAt(hoveredIndex)" :x2="xAt(hoveredIndex)" :y1="plot.top" :y2="plot.bottom" class="stroke-slate-400" stroke-dasharray="5 5" stroke-width="1.5" />
                </svg>

                <div v-if="hoveredPoint" class="pointer-events-none absolute z-10 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur" :style="tooltipStyle">
                    <p class="truncate text-base font-semibold text-slate-900" :title="hoveredPoint.name">{{ hoveredPoint.name }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ hoveredPoint.starts_on }}</p>
                    <dl class="mt-3 space-y-2 text-slate-600">
                        <div class="flex justify-between gap-4"><dt>销售额</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(hoveredPoint.gmv) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>广告花费</dt><dd class="font-semibold tabular-nums text-slate-900">{{ hoveredPoint.ad_spend === null ? '--' : money(hoveredPoint.ad_spend) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>ROI</dt><dd class="font-semibold tabular-nums text-slate-900">{{ hoveredPoint.roi === null ? '--' : hoveredPoint.roi.toFixed(2) }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>
        <p v-else class="px-6 py-16 text-center text-sm text-slate-400">暂无活动趋势数据。</p>
        <footer v-if="orderedPoints.length" class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 sm:px-6">横向滑动查看全部活动，悬浮或聚焦数据点查看详情</footer>
    </section>
</template>

<style scoped>
.chart-scrollbar { scrollbar-color: rgb(148 163 184 / 0.55) transparent; scrollbar-width: thin; }
.chart-scrollbar::-webkit-scrollbar { height: 7px; }
.chart-scrollbar::-webkit-scrollbar-track { background: transparent; }
.chart-scrollbar::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.55); }
</style>
