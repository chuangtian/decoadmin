<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

interface DailyMetric {
    date: string;
    ctr: number | null;
}

const props = defineProps<{
    daily: DailyMetric[];
    dateFrom: string;
    dateTo: string;
}>();

const width = 720;
const height = 280;
const plot = { left: 52, right: 24, top: 26, bottom: 42 };
const plotWidth = width - plot.left - plot.right;
const plotHeight = height - plot.top - plot.bottom;
const hoverIndex = ref<number | null>(null);
const gridLines = [0, 0.25, 0.5, 0.75, 1];

const rows = computed(() => {
    const byDate = new Map(props.daily.map((row) => [row.date, row]));

    return dateRange(props.dateFrom, props.dateTo).map((date) => ({
        date,
        ctr: byDate.get(date)?.ctr ?? null,
    }));
});

const yMax = computed(() => {
    const maximum = Math.max(0, ...rows.value.map((row) => row.ctr ?? 0));
    return Math.max(1, Math.ceil(maximum * 1.12 * 10) / 10);
});
const bandWidth = computed(() => plotWidth / Math.max(1, rows.value.length));
const validPoints = computed(() => rows.value
    .map((row, index) => row.ctr === null ? null : { index, x: x(index), y: y(row.ctr) })
    .filter((point): point is { index: number; x: number; y: number } => point !== null));
const linePoints = computed(() => validPoints.value.map((point) => `${point.x},${point.y}`).join(' '));
const areaPath = computed(() => {
    if (validPoints.value.length === 0) return '';
    const first = validPoints.value[0];
    const last = validPoints.value[validPoints.value.length - 1];
    const baseline = plot.top + plotHeight;
    const points = validPoints.value.map((point) => `L ${point.x} ${point.y}`).join(' ');

    return `M ${first.x} ${baseline} ${points} L ${last.x} ${baseline} Z`;
});
const hovered = computed(() => hoverIndex.value === null ? null : rows.value[hoverIndex.value] ?? null);
const tooltipStyle = computed(() => {
    if (hoverIndex.value === null) return {};
    const percent = (x(hoverIndex.value) / width) * 100;
    return { left: `${Math.min(84, Math.max(16, percent))}%` };
});

function dateRange(from: string, to: string): string[] {
    const result: string[] = [];
    const cursor = new Date(`${from}T00:00:00Z`);
    const end = new Date(`${to}T00:00:00Z`);

    while (cursor <= end && result.length < 367) {
        result.push(cursor.toISOString().slice(0, 10));
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return result;
}

function x(index: number): number {
    return plot.left + (bandWidth.value * (index + 0.5));
}

function y(value: number): number {
    return plot.top + plotHeight - ((value / yMax.value) * plotHeight);
}

function showDate(index: number): boolean {
    if (rows.value.length <= 7) return true;
    const interval = Math.ceil(rows.value.length / 6);
    return index === 0 || index === rows.value.length - 1 || index % interval === 0;
}

function shortDate(date: string): string {
    return date.slice(5).replace('-', '/');
}
</script>

<template>
    <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="每日 CTR 趋势图">
        <div>
            <h2 class="font-semibold text-slate-950">每日 CTR 趋势</h2>
            <p class="mt-1 text-xs text-slate-500">按日观察广告点击率变化</p>
        </div>

        <div v-if="validPoints.length" class="relative mt-4" @mouseleave="hoverIndex = null">
            <svg v-readable-chart class="h-[280px] w-full" :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="每日广告点击率折线趋势">
                <defs>
                    <linearGradient id="meta-ctr-area" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.28" />
                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.02" />
                    </linearGradient>
                </defs>

                <g v-for="line in gridLines" :key="line">
                    <line :x1="plot.left" :x2="width - plot.right" :y1="plot.top + plotHeight - (line * plotHeight)" :y2="plot.top + plotHeight - (line * plotHeight)" stroke="#e2e8f0" stroke-dasharray="4 5" />
                    <text :x="plot.left - 8" :y="plot.top + plotHeight - (line * plotHeight) + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ (yMax * line).toFixed(1) }}%</text>
                </g>

                <path v-if="areaPath" :d="areaPath" fill="url(#meta-ctr-area)" />
                <polyline v-if="linePoints" :points="linePoints" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />

                <g v-for="(row, index) in rows" :key="row.date">
                    <text v-if="showDate(index)" :x="x(index)" :y="height - 15" text-anchor="middle" fill="#94a3b8" font-size="12">{{ shortDate(row.date) }}</text>
                    <rect
                        :x="plot.left + (bandWidth * index)"
                        :y="plot.top"
                        :width="bandWidth"
                        :height="plotHeight"
                        fill="transparent"
                        tabindex="0"
                        :aria-label="`${row.date} CTR ${row.ctr === null ? '暂无' : `${row.ctr.toFixed(2)}%`}`"
                        :data-testid="`ctr-hover-${index}`"
                        @mouseenter="hoverIndex = index"
                        @focus="hoverIndex = index"
                        @blur="hoverIndex = null"
                    />
                </g>

                <template v-if="hoverIndex !== null && hovered">
                    <line :x1="x(hoverIndex)" :x2="x(hoverIndex)" :y1="plot.top" :y2="plot.top + plotHeight" stroke="#64748b" stroke-dasharray="3 4" />
                    <circle v-if="hovered.ctr !== null" :cx="x(hoverIndex)" :cy="y(hovered.ctr)" r="4" fill="#2563eb" stroke="white" stroke-width="2" />
                </template>
            </svg>

            <div v-if="hovered" class="pointer-events-none absolute top-2 z-10 w-40 -translate-x-1/2 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl" :style="tooltipStyle">
                <p class="font-semibold text-slate-900">{{ hovered.date }}</p>
                <div class="mt-2 flex items-center justify-between gap-3 text-slate-600"><span>CTR</span><strong class="text-blue-600">{{ hovered.ctr === null ? '—' : `${hovered.ctr.toFixed(2)}%` }}</strong></div>
            </div>
        </div>

        <div v-else class="flex min-h-[280px] items-center justify-center text-sm text-slate-400">所选时间范围暂无 CTR 数据</div>
    </article>
</template>
