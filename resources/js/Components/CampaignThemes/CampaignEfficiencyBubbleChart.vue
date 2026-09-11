<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

type Judgment = 'reusable' | 'scalable' | 'underperforming' | 'insufficient_data';

interface PerformanceItem {
    id: number;
    name: string;
    gmv: number;
    ad_spend: number | null;
    roi: number | null;
    judgment: Judgment;
}

const props = defineProps<{
    currency: string;
    points: PerformanceItem[];
    breakEvenRoi: number;
}>();

const hoveredId = ref<number | null>(null);
const plot = { left: 78, right: 42, top: 48, bottom: 344 };
const chartHeight = 424;
const chartWidth = computed(() => Math.max(920, 160 + drawablePoints.value.length * 86));
const drawablePoints = computed(() => props.points.filter((point) => point.ad_spend !== null && point.roi !== null));

const niceMaximum = (maximum: number) => {
    if (maximum <= 0) return 1;
    const padded = maximum * 1.1;
    const magnitude = 10 ** Math.floor(Math.log10(padded));
    const normalized = padded / magnitude;
    const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((candidate) => normalized <= candidate) ?? 10;
    return step * magnitude;
};

const xMaximum = computed(() => niceMaximum(Math.max(0, ...drawablePoints.value.map((point) => point.ad_spend ?? 0))));
const yMaximum = computed(() => Math.max(10, niceMaximum(Math.max(0, ...drawablePoints.value.map((point) => point.roi ?? 0)))));
const gmvMaximum = computed(() => Math.max(1, ...drawablePoints.value.map((point) => point.gmv)));
const ticks = computed(() => Array.from({ length: 6 }, (_, index) => index / 5));

const xAt = (value: number) => plot.left + (value / xMaximum.value) * (chartWidth.value - plot.left - plot.right);
const yAt = (value: number) => plot.bottom - (value / yMaximum.value) * (plot.bottom - plot.top);
const radius = (gmv: number) => 19 + Math.sqrt(Math.max(0, gmv) / gmvMaximum.value) * 27;

const palette: Record<Judgment, { fill: string; stroke: string }> = {
    reusable: { fill: '#10b981', stroke: '#059669' },
    scalable: { fill: '#f97316', stroke: '#ea580c' },
    underperforming: { fill: '#f43f5e', stroke: '#e11d48' },
    insufficient_data: { fill: '#94a3b8', stroke: '#64748b' },
};

const hoveredPoint = computed(() => drawablePoints.value.find((point) => point.id === hoveredId.value) ?? null);
const tooltipStyle = computed(() => {
    if (!hoveredPoint.value || hoveredPoint.value.ad_spend === null || hoveredPoint.value.roi === null) return {};
    const width = 250;
    const left = Math.min(
        Math.max(xAt(hoveredPoint.value.ad_spend) + radius(hoveredPoint.value.gmv) + 12, plot.left + 8),
        chartWidth.value - width - 12,
    );
    const top = Math.min(Math.max(yAt(hoveredPoint.value.roi) - 70, 58), chartHeight - 174);
    return { left: `${left}px`, top: `${top}px`, width: `${width}px` };
});

const compactMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.currency || 'USD',
    currencyDisplay: 'narrowSymbol',
    notation: 'compact',
    maximumFractionDigits: 1,
}).format(value);

const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.currency || 'USD',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 2,
}).format(value);

const shortName = (name: string) => name.length > 6 ? `${name.slice(0, 5)}…` : name;
const ariaLabel = (point: PerformanceItem) => `${point.name}，广告花费 ${money(point.ad_spend ?? 0)}，ROI ${(point.roi ?? 0).toFixed(2)}，GMV ${money(point.gmv)}`;
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between sm:px-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">规模 × 效率（预算分配视图）</h2>
                <p class="mt-1 text-xs text-slate-400">X=广告花费 · Y=ROI · 气泡大小=GMV · 虚线=保本 ROI {{ breakEvenRoi }}</p>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs font-medium text-slate-500">
                <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-full bg-emerald-500" />ROI ≥ 6 · 可复用</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-full bg-orange-500" />5–6 · 放量适销</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-full bg-rose-500" />ROI &lt; 5 · 承接不足</span>
            </div>
        </header>

        <div v-if="drawablePoints.length" class="chart-scrollbar overflow-x-auto px-3 pb-3 pt-2 sm:px-4">
            <div class="relative" :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }">
                <svg v-readable-chart :viewBox="`0 0 ${chartWidth} ${chartHeight}`" :width="chartWidth" :height="chartHeight" role="img" aria-label="活动预算分配气泡图">
                    <text :x="plot.left" y="24" class="fill-slate-400 text-xs font-medium">ROI</text>

                    <g v-for="ratio in ticks" :key="`grid-${ratio}`">
                        <line
                            :x1="plot.left"
                            :x2="chartWidth - plot.right"
                            :y1="plot.bottom - ratio * (plot.bottom - plot.top)"
                            :y2="plot.bottom - ratio * (plot.bottom - plot.top)"
                            class="stroke-slate-200"
                        />
                        <text :x="plot.left - 12" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-xs tabular-nums">
                            {{ (yMaximum * ratio).toFixed(1) }}
                        </text>
                        <line
                            :x1="plot.left + ratio * (chartWidth - plot.left - plot.right)"
                            :x2="plot.left + ratio * (chartWidth - plot.left - plot.right)"
                            :y1="plot.top"
                            :y2="plot.bottom"
                            class="stroke-slate-100"
                        />
                        <text :x="plot.left + ratio * (chartWidth - plot.left - plot.right)" :y="plot.bottom + 25" text-anchor="middle" class="fill-slate-400 text-xs tabular-nums">
                            {{ compactMoney(xMaximum * ratio) }}
                        </text>
                    </g>

                    <template v-if="breakEvenRoi <= yMaximum">
                        <line
                            :x1="plot.left"
                            :x2="chartWidth - plot.right"
                            :y1="yAt(breakEvenRoi)"
                            :y2="yAt(breakEvenRoi)"
                            stroke="#f43f5e"
                            stroke-dasharray="7 6"
                            stroke-width="2"
                        />
                        <text :x="chartWidth - plot.right" :y="yAt(breakEvenRoi) - 9" text-anchor="end" class="fill-rose-500 text-xs font-semibold">
                            保本线 ROI={{ breakEvenRoi }}
                        </text>
                    </template>

                    <g v-for="point in drawablePoints" :key="point.id">
                        <circle
                            :cx="xAt(point.ad_spend ?? 0)"
                            :cy="yAt(point.roi ?? 0)"
                            :r="radius(point.gmv)"
                            :fill="palette[point.judgment].fill"
                            :fill-opacity="hoveredId === point.id ? 0.88 : 0.72"
                            :stroke="palette[point.judgment].stroke"
                            stroke-width="2"
                        />
                        <text
                            v-if="radius(point.gmv) >= 25"
                            :x="xAt(point.ad_spend ?? 0)"
                            :y="yAt(point.roi ?? 0) + 4"
                            text-anchor="middle"
                            class="pointer-events-none fill-white text-xs font-semibold"
                        ><title>{{ point.name }}</title>{{ shortName(point.name) }}</text>
                        <circle
                            :cx="xAt(point.ad_spend ?? 0)"
                            :cy="yAt(point.roi ?? 0)"
                            :r="radius(point.gmv) + 4"
                            fill="transparent"
                            tabindex="0"
                            role="button"
                            :aria-label="ariaLabel(point)"
                            @mouseenter="hoveredId = point.id"
                            @mouseleave="hoveredId = null"
                            @focus="hoveredId = point.id"
                            @blur="hoveredId = null"
                        />
                    </g>

                    <text :x="(plot.left + chartWidth - plot.right) / 2" :y="chartHeight - 10" text-anchor="middle" class="fill-slate-500 text-[12px] font-medium">广告花费</text>
                </svg>

                <div v-if="hoveredPoint" class="pointer-events-none absolute z-10 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur" :style="tooltipStyle">
                    <p class="truncate text-base font-semibold text-slate-900" :title="hoveredPoint.name">{{ hoveredPoint.name }}</p>
                    <dl class="mt-3 space-y-2 text-slate-600">
                        <div class="flex justify-between gap-4"><dt>广告花费</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(hoveredPoint.ad_spend ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>ROI</dt><dd class="font-semibold tabular-nums text-slate-900">{{ hoveredPoint.roi?.toFixed(2) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>GMV</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(hoveredPoint.gmv) }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>
        <p v-else class="px-6 py-16 text-center text-sm text-slate-400">暂无可绘制的预算效率数据。</p>
        <footer v-if="drawablePoints.length" class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 sm:px-6">横向滑动查看完整图表，悬浮或聚焦气泡查看详情</footer>
    </section>
</template>

<style scoped>
.chart-scrollbar { scrollbar-color: rgb(148 163 184 / 0.55) transparent; scrollbar-width: thin; }
.chart-scrollbar::-webkit-scrollbar { height: 7px; }
.chart-scrollbar::-webkit-scrollbar-track { background: transparent; }
.chart-scrollbar::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.55); }
</style>
