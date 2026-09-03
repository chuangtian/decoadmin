<script setup lang="ts">
import { computed } from 'vue';

type Point = Record<string, unknown> & { name?: string; platform?: string };
const props = withDefaults(defineProps<{ points: Point[]; xKey?: string; yKey?: string; xLabel?: string; yLabel?: string; height?: number; logScale?: boolean }>(), {
    xKey: 'views',
    yKey: 'engagement_rate',
    xLabel: '浏览量（对数刻度）',
    yLabel: '互动率',
    height: 310,
    logScale: true,
});
const width = 760;
const padding = { left: 62, right: 24, top: 24, bottom: 48 };
const plotWidth = width - padding.left - padding.right;
const plotHeight = computed(() => props.height - padding.top - padding.bottom);
const maxViews = computed(() => Math.max(1, ...props.points.map((point) => Number(point[props.xKey] ?? 0))));
const maxRate = computed(() => Math.max(1, ...props.points.map((point) => Number(point[props.yKey] ?? 0))));
const colors: Record<string, string> = { Instagram: '#ec4899', Facebook: '#2563eb', YouTube: '#ef4444', TikTok: '#111827', IG: '#ec4899' };
function x(value: unknown): number {
    const numericValue = Math.max(0, Number(value ?? 0));
    const ratio = props.logScale
        ? Math.log10(numericValue + 1) / Math.log10(maxViews.value + 1)
        : numericValue / maxViews.value;

    return padding.left + ratio * plotWidth;
}
function xTick(tick: number): number {
    return props.logScale ? Math.pow(maxViews.value + 1, tick) - 1 : maxViews.value * tick;
}
function y(value: unknown): number { return padding.top + plotHeight.value - Math.max(0, Number(value ?? 0)) / maxRate.value * plotHeight.value; }
function color(platform: unknown): string { return colors[String(platform ?? '')] ?? '#7c3aed'; }
function compact(value: number): string { return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value); }
</script>

<template>
    <div v-if="points.length" class="overflow-hidden">
        <div class="mb-3 flex flex-wrap justify-end gap-4 text-xs font-bold text-slate-500">
            <span v-for="platform in [...new Set(points.map((point) => String(point.platform || '未分类')))]" :key="platform" class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full" :style="{ background: color(platform) }"></i>{{ platform }}</span>
        </div>
        <svg :viewBox="`0 0 ${width} ${height}`" class="block w-full" role="img" :aria-label="`${xLabel}与${yLabel}散点图`">
            <g v-for="tick in [0, .25, .5, .75, 1]" :key="`y-${tick}`">
                <line :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight * (1 - tick)" :y2="padding.top + plotHeight * (1 - tick)" stroke="#e2e8f0" stroke-dasharray="5 7" />
                <text :x="padding.left - 10" :y="padding.top + plotHeight * (1 - tick) + 4" text-anchor="end" fill="#94a3b8" font-size="11">{{ compact(maxRate * tick) }}</text>
            </g>
            <g v-for="tick in [0, .25, .5, .75, 1]" :key="`x-${tick}`">
                <text :x="padding.left + plotWidth * tick" :y="height - 14" text-anchor="middle" fill="#94a3b8" font-size="11">{{ compact(xTick(tick)) }}</text>
            </g>
            <g v-for="(point, index) in points" :key="`${point.name}-${index}`">
                <circle :cx="x(point[xKey])" :cy="y(point[yKey])" r="6" :fill="color(point.platform)" fill-opacity=".75" stroke="white" stroke-width="2"><title>{{ point.name || '未命名内容' }} · {{ compact(Number(point[xKey] ?? 0)) }} {{ xLabel }} · {{ compact(Number(point[yKey] ?? 0)) }} {{ yLabel }}</title></circle>
            </g>
            <text :x="width / 2" :y="height - 1" text-anchor="middle" fill="#64748b" font-size="11">{{ xLabel }}</text>
        </svg>
    </div>
    <div v-else class="flex items-center justify-center rounded-2xl bg-slate-50 text-sm font-semibold text-slate-400" :style="{ height: `${Math.max(160, height)}px` }">当前筛选暂无散点数据</div>
</template>
