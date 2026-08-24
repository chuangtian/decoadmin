<script setup lang="ts">
import { computed, ref } from 'vue';

interface CtrPoint {
    date: string;
    ctr: number;
    impressions: number;
    clicks: number;
}

const props = defineProps<{ points: CtrPoint[] }>();
const hoveredIndex = ref<number | null>(null);
const chart = { width: 660, height: 320, left: 62, right: 24, top: 42, bottom: 252 };
const plotWidth = chart.width - chart.left - chart.right;
const plotHeight = chart.bottom - chart.top;
const ticks = [0, 0.25, 0.5, 0.75, 1];
const xAt = (index: number) => chart.left + (props.points.length > 1 ? (plotWidth / (props.points.length - 1)) * index : plotWidth / 2);
const maximum = computed(() => {
    const highest = Math.max(0, ...props.points.map((point) => point.ctr));
    const step = highest <= 1 ? 0.25 : highest <= 2 ? 0.5 : 1;
    return Math.max(step, Math.ceil((highest * 1.12) / step) * step);
});
const yAt = (value: number) => chart.bottom - (Math.max(0, value) / maximum.value) * plotHeight;
const linePath = computed(() => props.points.map((point, index) => (
    (index === 0 ? 'M ' : 'L ') + xAt(index) + ' ' + yAt(point.ctr)
)).join(' '));
const areaPath = computed(() => linePath.value
    ? linePath.value + ' L ' + xAt(props.points.length - 1) + ' ' + chart.bottom + ' L ' + xAt(0) + ' ' + chart.bottom + ' Z'
    : '');
const activeIndex = computed(() => hoveredIndex.value ?? Math.max(0, props.points.length - 1));
const activePoint = computed(() => props.points[activeIndex.value] ?? null);
const activeX = computed(() => activePoint.value ? xAt(activeIndex.value) : 0);
const tooltipLeft = computed(() => Math.min(Math.max((activeX.value / chart.width) * 100, 5), 55));
const shortDate = (value: string) => value.slice(5).replace('-', '/');
const number = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value || 0);
</script>

<template>
    <section class="h-full min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Click-through rate</p>
            <h2 class="mt-2 text-lg font-semibold text-slate-950">每日 CTR 趋势</h2>
            <p class="mt-1 text-xs text-slate-400">点击数 ÷ 曝光量 · Criteo 系统时间（UTC）</p>
        </header>
        <div class="min-w-0 px-2 pb-3 pt-2 sm:px-4">
            <div class="relative mx-auto w-full" :style="{ maxWidth: chart.width + 'px', aspectRatio: chart.width + ' / ' + chart.height }">
                <svg :viewBox="'0 0 ' + chart.width + ' ' + chart.height" class="h-full w-full" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Criteo 最近 7 天每日点击率趋势">
                    <title>Criteo 最近 7 天每日 CTR 趋势</title>
                    <defs>
                        <linearGradient id="criteoCtrArea" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f97316" stop-opacity="0.24" />
                            <stop offset="100%" stop-color="#f97316" stop-opacity="0.02" />
                        </linearGradient>
                    </defs>
                    <g v-for="ratio in ticks" :key="ratio">
                        <line :x1="chart.left" :x2="chart.width - chart.right" :y1="chart.bottom - ratio * plotHeight" :y2="chart.bottom - ratio * plotHeight" class="stroke-slate-200" stroke-dasharray="4 5" />
                        <text :x="chart.left - 10" :y="chart.bottom - ratio * plotHeight + 4" text-anchor="end" class="fill-slate-400 text-[11px] tabular-nums">{{ (maximum * ratio).toFixed(2) }}%</text>
                    </g>
                    <path v-if="areaPath" :d="areaPath" fill="url(#criteoCtrArea)" />
                    <path v-if="linePath" :d="linePath" fill="none" stroke="#f97316" stroke-linecap="round" stroke-linejoin="round" stroke-width="3.5" />
                    <g v-for="(point, index) in points" :key="point.date">
                        <circle :cx="xAt(index)" :cy="yAt(point.ctr)" :r="activeIndex === index ? 6 : 4.5" fill="#f97316" stroke="white" stroke-width="2.5" />
                        <text :x="xAt(index)" :y="chart.bottom + 29" text-anchor="middle" class="fill-slate-500 text-[11px] tabular-nums">{{ shortDate(point.date) }}</text>
                        <rect :x="xAt(index) - plotWidth / Math.max(1, points.length) / 2" :y="chart.top" :width="plotWidth / Math.max(1, points.length)" :height="plotHeight + 44" fill="transparent" tabindex="0" :aria-label="point.date + '，CTR ' + point.ctr.toFixed(2) + '%'" @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null" />
                    </g>
                    <line v-if="activePoint" :x1="activeX" :x2="activeX" :y1="chart.top" :y2="chart.bottom" class="stroke-slate-400" stroke-dasharray="5 5" />
                </svg>
                <div v-if="activePoint" class="pointer-events-none absolute top-[18%] z-10 w-44 rounded-xl border border-slate-200 bg-white/95 p-3 text-sm shadow-xl backdrop-blur sm:w-48 sm:p-4" :style="{ left: tooltipLeft + '%' }">
                    <p class="font-semibold text-slate-900">{{ activePoint.date }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-orange-600">{{ activePoint.ctr.toFixed(2) }}%</p>
                    <p class="mt-2 text-xs text-slate-500">{{ number(activePoint.clicks) }} 次点击 / {{ number(activePoint.impressions) }} 次曝光</p>
                </div>
            </div>
        </div>
    </section>
</template>
