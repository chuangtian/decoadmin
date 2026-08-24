<script setup lang="ts">
import { computed, ref } from 'vue';

interface TrendPoint {
    date: string;
    spend: number;
    roas: number;
}

const props = defineProps<{ currency: string; points: TrendPoint[] }>();
const hoveredIndex = ref<number | null>(null);
const plot = { left: 68, right: 62, top: 48, bottom: 252 };
const chartWidth = 680;
const chartHeight = 330;
const ticks = [0, 0.25, 0.5, 0.75, 1];
const plotWidth = chartWidth - plot.left - plot.right;
const pointWidth = computed(() => props.points.length ? plotWidth / props.points.length : plotWidth);
const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const niceMaximum = (value: number, step: number) => Math.max(step, Math.ceil(value / step) * step);
const spendMax = computed(() => niceMaximum(Math.max(0, ...props.points.map((point) => point.spend)) * 1.12, 100));
const roasMax = computed(() => niceMaximum(Math.max(0, ...props.points.map((point) => point.roas)) * 1.12, 5));
const spendY = (value: number) => plot.bottom - (Math.max(0, value) / spendMax.value) * (plot.bottom - plot.top);
const roasY = (value: number) => plot.bottom - (Math.max(0, value) / roasMax.value) * (plot.bottom - plot.top);
const barWidth = computed(() => Math.min(46, pointWidth.value * 0.54));
const roasPath = computed(() => props.points.map((point, index) => (
    (index === 0 ? 'M ' : 'L ') + xAt(index) + ' ' + roasY(point.roas)
)).join(' '));
const activeIndex = computed(() => hoveredIndex.value ?? Math.max(0, props.points.length - 1));
const activePoint = computed(() => props.points[activeIndex.value] ?? null);
const activeX = computed(() => activePoint.value ? xAt(activeIndex.value) : 0);
const tooltipLeft = computed(() => Math.min(Math.max(activeX.value + 14, plot.left), chartWidth - 222));
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.currency || 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(value || 0);
const axisMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.currency || 'USD',
    currencyDisplay: 'narrowSymbol',
    notation: 'compact',
    maximumFractionDigits: 1,
}).format(value || 0);
const shortDate = (value: string) => value.slice(5).replace('-', '/');
const pointLabel = (point: TrendPoint) => point.date + '，花费 ' + money(point.spend) + '，ROAS ' + point.roas.toFixed(2) + ' 倍';
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-orange-600">Performance trend</p>
                <h2 class="mt-2 text-lg font-semibold text-slate-950">花费与 ROAS 趋势</h2>
                <p class="mt-1 text-xs text-slate-400">Criteo 系统时间（UTC）· 最近 7 天</p>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-xs font-medium text-slate-500">
                <span class="inline-flex items-center gap-2"><i class="h-3 w-7 rounded bg-orange-500/80" />花费</span>
                <span class="inline-flex items-center gap-2"><i class="h-0.5 w-7 bg-emerald-500" /><i class="-ml-6 h-2.5 w-2.5 rounded-full bg-emerald-500" />ROAS</span>
            </div>
        </header>

        <div class="criteo-chart-scroll min-w-0 overflow-x-auto px-2 pb-3 pt-2 sm:px-4">
            <div class="relative mx-auto" :style="{ width: chartWidth + 'px', height: chartHeight + 'px' }">
                <svg :viewBox="'0 0 ' + chartWidth + ' ' + chartHeight" :width="chartWidth" :height="chartHeight" role="img" aria-label="Criteo 最近 7 天花费柱状图与 ROAS 折线图">
                    <title>Criteo 最近 7 天花费与 ROAS 趋势</title>
                    <text :x="plot.left" y="25" class="fill-slate-400 text-[11px] font-medium">花费</text>
                    <text :x="chartWidth - plot.right" y="25" text-anchor="end" class="fill-slate-400 text-[11px] font-medium">ROAS</text>
                    <g v-for="ratio in ticks" :key="ratio">
                        <line :x1="plot.left" :x2="chartWidth - plot.right" :y1="plot.bottom - ratio * (plot.bottom - plot.top)" :y2="plot.bottom - ratio * (plot.bottom - plot.top)" class="stroke-slate-200" stroke-dasharray="4 5" />
                        <text :x="plot.left - 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-[11px] tabular-nums">{{ axisMoney(spendMax * ratio) }}</text>
                        <text :x="chartWidth - plot.right + 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" class="fill-slate-400 text-[11px] tabular-nums">{{ (roasMax * ratio).toFixed(0) }}×</text>
                    </g>
                    <g v-for="(point, index) in points" :key="point.date">
                        <rect :x="xAt(index) - barWidth / 2" :y="spendY(point.spend)" :width="barWidth" :height="plot.bottom - spendY(point.spend)" rx="7" fill="#f97316" :fill-opacity="activeIndex === index ? 0.9 : 0.68" />
                    </g>
                    <path v-if="roasPath" :d="roasPath" fill="none" stroke="#10b981" stroke-linecap="round" stroke-linejoin="round" stroke-width="3.5" />
                    <g v-for="(point, index) in points" :key="'roas-' + point.date">
                        <circle :cx="xAt(index)" :cy="roasY(point.roas)" :r="activeIndex === index ? 6 : 4.5" fill="#10b981" stroke="white" stroke-width="2.5" />
                        <text :x="xAt(index)" :y="plot.bottom + 28" text-anchor="middle" class="fill-slate-500 text-[11px] tabular-nums">{{ shortDate(point.date) }}</text>
                        <rect :x="xAt(index) - pointWidth / 2" :y="plot.top" :width="pointWidth" :height="plot.bottom - plot.top + 44" fill="transparent" tabindex="0" :aria-label="pointLabel(point)" @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null" />
                    </g>
                    <line v-if="activePoint" :x1="activeX" :x2="activeX" :y1="plot.top" :y2="plot.bottom" class="stroke-slate-500" stroke-dasharray="5 5" />
                </svg>
                <div v-if="activePoint" class="pointer-events-none absolute top-14 z-10 w-52 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur" :style="{ left: tooltipLeft + 'px' }">
                    <p class="font-semibold text-slate-900">{{ activePoint.date }}</p>
                    <dl class="mt-3 space-y-2 text-slate-500">
                        <div class="flex items-center justify-between gap-4"><dt class="inline-flex items-center gap-2"><i class="h-2.5 w-2.5 rounded bg-orange-500" />花费</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(activePoint.spend) }}</dd></div>
                        <div class="flex items-center justify-between gap-4"><dt class="inline-flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-emerald-500" />ROAS</dt><dd class="font-semibold tabular-nums text-slate-900">{{ activePoint.roas.toFixed(2) }}×</dd></div>
                    </dl>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.criteo-chart-scroll { scrollbar-color: rgb(148 163 184 / 0.5) transparent; scrollbar-width: thin; }
.criteo-chart-scroll::-webkit-scrollbar { height: 7px; }
.criteo-chart-scroll::-webkit-scrollbar-track { background: transparent; }
.criteo-chart-scroll::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.5); }
</style>
