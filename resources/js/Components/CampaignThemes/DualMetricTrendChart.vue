<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

interface TrendPoint {
    id: number;
    name: string;
    startsOn: string;
    lineValue: number | null;
    barValue: number | null;
}

const props = defineProps<{
    title: string;
    lineLabel: string;
    barLabel: string;
    lineFormat: 'decimal' | 'percent';
    barFormat: 'currency' | 'number';
    currency: string;
    lineColor: string;
    barColor: string;
    points: TrendPoint[];
}>();

const hoveredIndex = ref<number | null>(null);
const plot = { left: 58, right: 68, top: 50, bottom: 300 };
const chartHeight = 380;
const slotWidth = 68;

const chartWidth = computed(() => Math.max(720, plot.left + plot.right + props.points.length * slotWidth));
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right);
const pointWidth = computed(() => props.points.length ? plotWidth.value / props.points.length : slotWidth);

const niceMaximum = (values: Array<number | null>) => {
    const maximum = Math.max(0, ...values.filter((value): value is number => value !== null));
    if (maximum === 0) return 1;

    const padded = maximum * 1.12;
    const magnitude = 10 ** Math.floor(Math.log10(padded));
    const normalized = padded / magnitude;
    const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((candidate) => normalized <= candidate) ?? 10;

    return step * magnitude;
};

const lineMaximum = computed(() => niceMaximum(props.points.map((point) => point.lineValue)));
const barMaximum = computed(() => niceMaximum(props.points.map((point) => point.barValue)));
const ticks = computed(() => Array.from({ length: 6 }, (_, index) => index / 5));

const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const yAt = (value: number, maximum: number) => plot.bottom - (value / maximum) * (plot.bottom - plot.top);
const lineYAt = (value: number) => yAt(value, lineMaximum.value);
const barYAt = (value: number) => yAt(value, barMaximum.value);

const linePath = computed(() => {
    let path = '';
    let previous: { x: number; y: number } | null = null;

    props.points.forEach((point, index) => {
        if (point.lineValue === null) {
            previous = null;
            return;
        }

        const current = { x: xAt(index), y: lineYAt(point.lineValue) };
        if (previous === null) {
            path += `M ${current.x} ${current.y}`;
        } else {
            const midpoint = (previous.x + current.x) / 2;
            path += ` C ${midpoint} ${previous.y}, ${midpoint} ${current.y}, ${current.x} ${current.y}`;
        }
        previous = current;
    });

    return path;
});

const hoveredPoint = computed(() => hoveredIndex.value === null ? null : props.points[hoveredIndex.value] ?? null);
const hoveredX = computed(() => hoveredIndex.value === null ? 0 : xAt(hoveredIndex.value));
const tooltipStyle = computed(() => {
    const width = 260;
    const preferred = hoveredX.value + 18;
    const left = Math.min(Math.max(preferred, plot.left + 8), chartWidth.value - width - 12);

    return { left: `${left}px`, top: '76px', width: `${width}px` };
});

const compact = (value: number, currency = false) => new Intl.NumberFormat('en-US', {
    ...(currency ? { style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol' } : {}),
    notation: 'compact',
    maximumFractionDigits: 1,
}).format(value);

const lineText = (value: number) => props.lineFormat === 'percent' ? `${value.toFixed(2)}%` : value.toFixed(2);
const barText = (value: number) => new Intl.NumberFormat('en-US', {
    ...(props.barFormat === 'currency'
        ? { style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol' }
        : {}),
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(value);

const lineAxisText = (value: number) => props.lineFormat === 'percent'
    ? `${value.toFixed(2)}%`
    : Number.isInteger(value) ? value.toFixed(0) : value.toFixed(1);
const barAxisText = (value: number) => compact(value, props.barFormat === 'currency');
const shortName = (name: string) => name.length > 5 ? `${name.slice(0, 4)}…` : name;
const shortDate = (date: string) => date ? date.slice(5).replace('-', '/') : '--';

const pointLabel = (point: TrendPoint) => [
    point.name,
    point.lineValue === null ? `${props.lineLabel} --` : `${props.lineLabel} ${lineText(point.lineValue)}`,
    point.barValue === null ? `${props.barLabel} --` : `${props.barLabel} ${barText(point.barValue)}`,
].join('，');
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
            <h2 class="text-lg font-semibold text-slate-950">{{ title }}</h2>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs font-medium text-slate-500 sm:justify-end">
                <span class="inline-flex items-center gap-2">
                    <i class="relative block h-0.5 w-8" :style="{ backgroundColor: lineColor }">
                        <i class="absolute left-1/2 top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white" :style="{ backgroundColor: lineColor }" />
                    </i>
                    {{ lineLabel }}（左轴）
                </span>
                <span class="inline-flex items-center gap-2">
                    <i class="block h-4 w-8 rounded" :style="{ backgroundColor: barColor, opacity: 0.28 }" />
                    {{ barLabel }}（右轴）
                </span>
            </div>
        </header>

        <div v-if="points.length" class="trend-scrollbar overflow-x-auto px-3 pb-3 pt-2 sm:px-4">
            <div class="relative" :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }">
                <svg v-readable-chart
                    class="block"
                    :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                    :width="chartWidth"
                    :height="chartHeight"
                    role="img"
                    :aria-label="title"
                >
                    <text :x="plot.left" y="25" class="fill-slate-400 text-xs font-medium">{{ lineLabel }}</text>
                    <text :x="chartWidth - plot.right" y="25" text-anchor="end" class="fill-slate-400 text-xs font-medium">{{ barLabel }}</text>

                    <g v-for="ratio in ticks" :key="ratio">
                        <line
                            :x1="plot.left"
                            :x2="chartWidth - plot.right"
                            :y1="plot.bottom - ratio * (plot.bottom - plot.top)"
                            :y2="plot.bottom - ratio * (plot.bottom - plot.top)"
                            class="stroke-slate-200"
                            stroke-width="1"
                        />
                        <text
                            :x="plot.left - 10"
                            :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4"
                            text-anchor="end"
                            class="fill-slate-400 text-xs tabular-nums"
                        >{{ lineAxisText(lineMaximum * ratio) }}</text>
                        <text
                            :x="chartWidth - plot.right + 10"
                            :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4"
                            class="fill-slate-400 text-xs tabular-nums"
                        >{{ barAxisText(barMaximum * ratio) }}</text>
                    </g>

                    <g v-for="(point, index) in points" :key="`bar-${point.id}`">
                        <rect
                            v-if="point.barValue !== null"
                            :x="xAt(index) - Math.min(19, pointWidth * 0.3)"
                            :y="barYAt(point.barValue)"
                            :width="Math.min(38, pointWidth * 0.6)"
                            :height="plot.bottom - barYAt(point.barValue)"
                            rx="5"
                            :fill="barColor"
                            :fill-opacity="hoveredIndex === index ? 0.46 : 0.25"
                            class="transition-opacity"
                        />
                    </g>

                    <path
                        v-if="linePath"
                        :d="linePath"
                        fill="none"
                        :stroke="lineColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="4"
                    />

                    <g v-for="(point, index) in points" :key="`point-${point.id}`">
                        <template v-if="point.lineValue !== null">
                            <circle
                                :cx="xAt(index)"
                                :cy="lineYAt(point.lineValue)"
                                :r="hoveredIndex === index ? 6 : 4.5"
                                :fill="lineColor"
                                stroke="white"
                                stroke-width="2"
                            />
                            <text
                                :x="xAt(index)"
                                :y="lineYAt(point.lineValue) - 12"
                                text-anchor="middle"
                                class="text-xs font-semibold tabular-nums"
                                :fill="lineColor"
                            >{{ lineText(point.lineValue) }}</text>
                        </template>

                        <text
                            :x="xAt(index)"
                            :y="plot.bottom + 28"
                            text-anchor="middle"
                            class="fill-slate-600 text-xs font-medium"
                        >
                            <title>{{ point.name }}</title>
                            {{ shortName(point.name) }}
                        </text>
                        <text
                            :x="xAt(index)"
                            :y="plot.bottom + 46"
                            text-anchor="middle"
                            class="fill-slate-400 text-xs tabular-nums"
                        >{{ shortDate(point.startsOn) }}</text>

                        <rect
                            :x="xAt(index) - pointWidth / 2"
                            :y="plot.top"
                            :width="pointWidth"
                            :height="plot.bottom - plot.top + 54"
                            fill="transparent"
                            tabindex="0"
                            role="button"
                            :aria-label="pointLabel(point)"
                            @mouseenter="hoveredIndex = index"
                            @mouseleave="hoveredIndex = null"
                            @focus="hoveredIndex = index"
                            @blur="hoveredIndex = null"
                        />
                    </g>

                    <template v-if="hoveredPoint && hoveredIndex !== null">
                        <line
                            :x1="hoveredX"
                            :x2="hoveredX"
                            :y1="plot.top"
                            :y2="plot.bottom"
                            class="stroke-slate-400"
                            stroke-dasharray="5 5"
                            stroke-width="1.5"
                        />
                        <line
                            v-if="hoveredPoint.lineValue !== null"
                            :x1="plot.left"
                            :x2="chartWidth - plot.right"
                            :y1="lineYAt(hoveredPoint.lineValue)"
                            :y2="lineYAt(hoveredPoint.lineValue)"
                            class="stroke-slate-300"
                            stroke-dasharray="5 5"
                            stroke-width="1"
                        />
                    </template>
                </svg>

                <div
                    v-if="hoveredPoint"
                    class="pointer-events-none absolute z-10 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur"
                    :style="tooltipStyle"
                >
                    <p class="truncate text-base font-semibold text-slate-900" :title="hoveredPoint.name">{{ hoveredPoint.name }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ hoveredPoint.startsOn }}</p>
                    <div class="mt-3 space-y-2.5">
                        <div class="flex items-center justify-between gap-4">
                            <span class="inline-flex min-w-0 items-center gap-2 text-slate-600">
                                <i class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: lineColor }" />
                                <span class="truncate">{{ lineLabel }}</span>
                            </span>
                            <strong class="shrink-0 tabular-nums text-slate-900">{{ hoveredPoint.lineValue === null ? '--' : lineText(hoveredPoint.lineValue) }}</strong>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="inline-flex min-w-0 items-center gap-2 text-slate-600">
                                <i class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: barColor, opacity: 0.55 }" />
                                <span class="truncate">{{ barLabel }}</span>
                            </span>
                            <strong class="shrink-0 tabular-nums text-slate-900">{{ hoveredPoint.barValue === null ? '--' : barText(hoveredPoint.barValue) }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="px-6 py-16 text-center text-sm text-slate-400">暂无趋势数据。</p>

        <footer v-if="points.length" class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 sm:px-6">
            横向滑动查看全部活动，悬浮或聚焦数据点查看详情
        </footer>
    </section>
</template>

<style scoped>
.trend-scrollbar {
    scrollbar-color: rgb(148 163 184 / 0.55) transparent;
    scrollbar-width: thin;
}

.trend-scrollbar::-webkit-scrollbar {
    height: 7px;
}

.trend-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.trend-scrollbar::-webkit-scrollbar-thumb {
    border: 2px solid white;
    border-radius: 999px;
    background: rgb(148 163 184 / 0.55);
}
</style>
