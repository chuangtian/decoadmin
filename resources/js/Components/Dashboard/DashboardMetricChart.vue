<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed } from 'vue';

interface Point { label: string; [key: string]: string | number }
const props = defineProps<{
    current: Point[];
    previous: Point[];
    metric: string;
    label: string;
    format: string;
    currency: string;
    value: number | string;
    currentRange: string;
    previousRange: string;
}>();

const width = 920;
const height = 300;
const inset = { left: 58, right: 22, top: 24, bottom: 42 };
const values = computed(() => [...props.current, ...props.previous].map((point) => Number(point[props.metric] || 0)));
const max = computed(() => Math.max(...values.value, 1));
const x = (index: number, count: number) => inset.left + (count <= 1 ? 0 : index / (count - 1)) * (width - inset.left - inset.right);
const y = (value: number) => inset.top + (1 - value / max.value) * (height - inset.top - inset.bottom);
const path = (points: Point[]) => points.map((point, index) => `${index ? 'L' : 'M'} ${x(index, points.length).toFixed(2)} ${y(Number(point[props.metric] || 0)).toFixed(2)}`).join(' ');
const ticks = computed(() => [max.value, max.value * .5, 0]);
const visibleEvery = computed(() => Math.max(1, Math.ceil(props.current.length / 7)));
const formatted = (value: number) => props.format === 'currency'
    ? new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.currency, maximumFractionDigits: 2 }).format(value)
    : new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(value);
</script>

<template>
    <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div><p class="text-sm font-semibold text-slate-500">{{ label }}随时间的变化</p><p class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">{{ formatted(Number(value || 0)) }}</p></div>
            <div class="flex flex-wrap gap-4 text-xs font-semibold text-slate-500"><span class="flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-emerald-500" />{{ currentRange }}</span><span v-if="previous.length" class="flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-sky-300" />{{ previousRange }}</span></div>
        </div>
        <div class="mt-6 overflow-x-auto">
            <svg v-readable-chart :viewBox="`0 0 ${width} ${height}`" class="min-w-[700px] w-full" role="img" :aria-label="`${label}趋势图`">
                <g v-for="(tick,index) in ticks" :key="tick">
                    <line :x1="inset.left" :x2="width-inset.right" :y1="y(tick)" :y2="y(tick)" stroke="#e2e8f0" stroke-width="1" />
                    <text :x="inset.left-10" :y="y(tick)+4" text-anchor="end" fill="#94a3b8" font-size="12">{{ formatted(tick) }}</text>
                </g>
                <path v-if="previous.length" :d="path(previous)" fill="none" stroke="#7dd3fc" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="5 5" />
                <path v-if="current.length" :d="path(current)" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" />
                <g v-for="(point,index) in current" :key="`${point.label}-${index}`">
                    <circle :cx="x(index,current.length)" :cy="y(Number(point[metric] || 0))" r="3.5" fill="#10b981"><title>{{ point.label }} · {{ formatted(Number(point[metric] || 0)) }}</title></circle>
                    <text v-if="index % visibleEvery === 0 || index === current.length-1" :x="x(index,current.length)" :y="height-14" text-anchor="middle" fill="#94a3b8" font-size="12">{{ point.label }}</text>
                </g>
            </svg>
        </div>
    </article>
</template>
