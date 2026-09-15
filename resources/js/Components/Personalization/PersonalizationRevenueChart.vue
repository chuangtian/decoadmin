<script setup lang="ts">
import { computed } from 'vue';
import vReadableChart from '../../directives/readableChart';

interface RevenuePoint {
    date: string;
    revenue: string;
}

const props = defineProps<{
    points: RevenuePoint[];
    currency: string;
}>();

const width = 900;
const height = 300;
const padding = { top: 24, right: 24, bottom: 42, left: 76 };
const plotWidth = width - padding.left - padding.right;
const plotHeight = height - padding.top - padding.bottom;
const values = computed(() => props.points.map(point => Math.max(0, Number(point.revenue) || 0)));
const maximum = computed(() => Math.max(...values.value, 1));
const coordinates = computed(() => props.points.map((point, index) => ({
    ...point,
    value: values.value[index],
    x: padding.left + (props.points.length <= 1 ? plotWidth / 2 : index * plotWidth / (props.points.length - 1)),
    y: padding.top + plotHeight - (values.value[index] / maximum.value) * plotHeight,
})));
const line = computed(() => coordinates.value.map(point => `${point.x},${point.y}`).join(' '));
const area = computed(() => coordinates.value.length
    ? `${padding.left},${padding.top + plotHeight} ${line.value} ${padding.left + plotWidth},${padding.top + plotHeight}`
    : '');
const grid = computed(() => Array.from({ length: 5 }, (_, index) => ({
    y: padding.top + index * plotHeight / 4,
    value: maximum.value * (4 - index) / 4,
})));
const labelStep = computed(() => Math.max(1, Math.ceil(props.points.length / 7)));
const formatMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency, maximumFractionDigits: value >= 100 ? 0 : 2,
}).format(value);
</script>

<template>
    <div v-if="points.length" class="overflow-x-auto">
        <svg v-readable-chart class="min-w-[720px]" :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="Personalization attributed revenue trend">
            <defs>
                <linearGradient id="personalizationRevenueArea" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#2563eb" stop-opacity=".22" />
                    <stop offset="1" stop-color="#2563eb" stop-opacity="0" />
                </linearGradient>
            </defs>
            <g v-for="row in grid" :key="row.y">
                <line :x1="padding.left" :x2="padding.left + plotWidth" :y1="row.y" :y2="row.y" stroke="#dbe4f0" stroke-dasharray="5 6" />
                <text :x="padding.left - 12" :y="row.y + 4" text-anchor="end" fill="#64748b" font-size="12">{{ formatMoney(row.value) }}</text>
            </g>
            <polygon v-if="coordinates.length > 1" :points="area" fill="url(#personalizationRevenueArea)" />
            <polyline v-if="coordinates.length > 1" :points="line" fill="none" stroke="#2563eb" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
            <g v-for="(point, index) in coordinates" :key="point.date">
                <circle :cx="point.x" :cy="point.y" r="4.5" fill="white" stroke="#2563eb" stroke-width="3">
                    <title>{{ point.date }} · {{ formatMoney(point.value) }}</title>
                </circle>
                <text v-if="index % labelStep === 0 || index === coordinates.length - 1" :x="point.x" :y="height - 12" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text>
            </g>
        </svg>
    </div>
    <div v-else class="flex h-64 items-center justify-center text-sm text-slate-500">No revenue data is available for this date range.</div>
</template>
