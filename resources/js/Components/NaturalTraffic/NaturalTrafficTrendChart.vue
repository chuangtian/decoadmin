<script setup lang="ts">
import { computed } from 'vue';

type Series = { key: string; label: string; color: string };
const props = withDefaults(defineProps<{ points: Array<Record<string, unknown>>; series: Series[]; xKey?: string; height?: number; fillArea?: boolean }>(), { xKey: 'date', height: 250, fillArea: false });
const width = 1000;
const padding = { left: 58, right: 20, top: 24, bottom: 42 };
const values = computed(() => props.points.flatMap((point) => props.series.map((series) => Number(point[series.key] ?? 0))).filter(Number.isFinite));
const maxValue = computed(() => Math.max(1, ...values.value));
const plotWidth = computed(() => width - padding.left - padding.right);
const plotHeight = computed(() => props.height - padding.top - padding.bottom);

function x(index: number): number {
    return padding.left + (props.points.length <= 1 ? plotWidth.value / 2 : index / (props.points.length - 1) * plotWidth.value);
}
function y(value: unknown): number {
    return padding.top + plotHeight.value - Math.max(0, Number(value ?? 0)) / maxValue.value * plotHeight.value;
}
function path(key: string): string {
    return props.points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index).toFixed(2)} ${y(point[key]).toFixed(2)}`).join(' ');
}
function areaPath(key: string): string {
    if (!props.points.length) return '';
    const baseline = padding.top + plotHeight.value;

    return `${path(key)} L ${x(props.points.length - 1).toFixed(2)} ${baseline.toFixed(2)} L ${x(0).toFixed(2)} ${baseline.toFixed(2)} Z`;
}
function label(value: unknown): string {
    const text = String(value ?? '');
    return /^\d{4}-\d{2}-\d{2}/.test(text) ? text.slice(5) : text.length > 12 ? `${text.slice(0, 12)}…` : text;
}
function compact(value: number): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}
</script>

<template>
    <div v-if="points.length" class="w-full overflow-hidden">
        <div class="mb-3 flex flex-wrap justify-end gap-4 text-xs font-bold text-slate-500"><span v-for="item in series" :key="item.key" class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full" :style="{ background: item.color }"></i>{{ item.label }}</span></div>
        <svg :viewBox="`0 0 ${width} ${height}`" class="block w-full" role="img" aria-label="趋势图">
            <g v-for="tick in [0, .25, .5, .75, 1]" :key="tick">
                <line :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight * (1 - tick)" :y2="padding.top + plotHeight * (1 - tick)" stroke="#e2e8f0" stroke-dasharray="5 7" />
                <text :x="padding.left - 10" :y="padding.top + plotHeight * (1 - tick) + 4" text-anchor="end" fill="#94a3b8" font-size="11">{{ compact(maxValue * tick) }}</text>
            </g>
            <path v-for="item in fillArea ? series : []" :key="`area-${item.key}`" :d="areaPath(item.key)" :fill="item.color" fill-opacity=".1" stroke="none" />
            <path v-for="item in series" :key="item.key" :d="path(item.key)" fill="none" :stroke="item.color" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <g v-for="(point, index) in points" :key="index">
                <circle v-for="item in series" :key="item.key" :cx="x(index)" :cy="y(point[item.key])" r="3.5" fill="white" :stroke="item.color" stroke-width="2" />
                <text v-if="points.length <= 14 || index % Math.ceil(points.length / 10) === 0 || index === points.length - 1" :x="x(index)" :y="height - 12" text-anchor="middle" fill="#64748b" font-size="11">{{ label(point[xKey]) }}</text>
            </g>
        </svg>
    </div>
    <div v-else class="flex h-56 items-center justify-center rounded-2xl bg-slate-50 text-sm font-semibold text-slate-400">当前筛选暂无趋势数据</div>
</template>
