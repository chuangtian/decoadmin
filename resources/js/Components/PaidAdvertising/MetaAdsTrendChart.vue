<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

interface DailyMetric {
    date: string;
    spend: number;
    roas: number | null;
}

const props = defineProps<{
    current: DailyMetric[];
    previous: DailyMetric[];
    dateFrom: string;
    dateTo: string;
    previousDateFrom: string | null;
    comparisonEnabled: boolean;
    currency: string;
    title?: string;
    description?: string;
}>();

const width = 760;
const height = 300;
const plot = { left: 58, right: 58, top: 32, bottom: 44 };
const plotWidth = width - plot.left - plot.right;
const plotHeight = height - plot.top - plot.bottom;
const hoverIndex = ref<number | null>(null);

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

const rows = computed(() => {
    const dates = dateRange(props.dateFrom, props.dateTo);
    const currentByDate = new Map(props.current.map((row) => [row.date, row]));
    const previousByDate = new Map(props.previous.map((row) => [row.date, row]));
    const previousDates = props.previousDateFrom
        ? dateRange(props.previousDateFrom, addDays(props.previousDateFrom, Math.max(0, dates.length - 1)))
        : [];

    return dates.map((date, index) => {
        const current = currentByDate.get(date);
        const previous = previousByDate.get(previousDates[index] ?? '');

        return {
            date,
            spend: current?.spend ?? 0,
            roas: current?.roas ?? null,
            previousSpend: previous?.spend ?? 0,
            previousRoas: previous?.roas ?? null,
        };
    });
});

const spendMax = computed(() => Math.max(1, ...rows.value.flatMap((row) => [row.spend, row.previousSpend])) * 1.08);
const roasMax = computed(() => {
    const values = rows.value.flatMap((row) => [row.roas, row.previousRoas]).filter((value): value is number => value !== null);
    return Math.max(1, ...values) * 1.08;
});
const bandWidth = computed(() => plotWidth / Math.max(1, rows.value.length));
const barWidth = computed(() => Math.min(13, Math.max(2, bandWidth.value * (props.comparisonEnabled ? 0.3 : 0.5))));
const gridLines = [0, 0.25, 0.5, 0.75, 1];

const currentRoasPoints = computed(() => linePoints('roas'));
const previousRoasPoints = computed(() => linePoints('previousRoas'));
const hovered = computed(() => hoverIndex.value === null ? null : rows.value[hoverIndex.value] ?? null);
const tooltipStyle = computed(() => {
    if (hoverIndex.value === null) return {};
    const percent = (x(hoverIndex.value) / width) * 100;
    return { left: `${Math.min(84, Math.max(16, percent))}%` };
});

function addDays(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}

function x(index: number): number {
    return plot.left + (bandWidth.value * (index + 0.5));
}

function spendY(value: number): number {
    return plot.top + plotHeight - ((value / spendMax.value) * plotHeight);
}

function roasY(value: number): number {
    return plot.top + plotHeight - ((value / roasMax.value) * plotHeight);
}

function linePoints(key: 'roas' | 'previousRoas'): string {
    return rows.value
        .map((row, index) => row[key] === null ? null : `${x(index)},${roasY(row[key] as number)}`)
        .filter((value): value is string => value !== null)
        .join(' ');
}

function showDate(index: number): boolean {
    if (rows.value.length <= 7) return true;
    const interval = Math.ceil(rows.value.length / 6);
    return index === 0 || index === rows.value.length - 1 || index % interval === 0;
}

function shortDate(date: string): string {
    return date.slice(5).replace('-', '/');
}

function money(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency', currency: props.currency, minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value);
}

function compactMoney(value: number): string {
    if (value >= 1000) return `$${(value / 1000).toFixed(value >= 10000 ? 0 : 1)}k`;
    return `$${Math.round(value)}`;
}
</script>

<template>
    <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="花费与 ROAS 趋势图">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-slate-950">{{ title || '花费与 ROAS 趋势' }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ description || '按日查看花费规模与广告回报变化' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-slate-500">
                <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-3 rounded-sm bg-blue-500" />花费</span>
                <span v-if="comparisonEnabled" class="inline-flex items-center gap-1.5"><i class="h-2.5 w-3 rounded-sm bg-blue-200" />上期花费</span>
                <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-4 bg-emerald-500" />ROAS</span>
                <span v-if="comparisonEnabled" class="inline-flex items-center gap-1.5"><i class="h-0.5 w-4 border-t border-dashed border-emerald-300" />上期 ROAS</span>
            </div>
        </div>

        <div class="relative mt-4" @mouseleave="hoverIndex = null">
            <svg v-readable-chart class="h-[300px] w-full" :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="每日花费和 ROAS 趋势">
                <g v-for="line in gridLines" :key="line">
                    <line :x1="plot.left" :x2="width - plot.right" :y1="plot.top + plotHeight - (line * plotHeight)" :y2="plot.top + plotHeight - (line * plotHeight)" stroke="#e2e8f0" stroke-dasharray="4 5" />
                    <text :x="plot.left - 8" :y="plot.top + plotHeight - (line * plotHeight) + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ compactMoney(spendMax * line) }}</text>
                    <text :x="width - plot.right + 8" :y="plot.top + plotHeight - (line * plotHeight) + 4" fill="#94a3b8" font-size="12">{{ (roasMax * line).toFixed(1) }}×</text>
                </g>

                <g v-for="(row, index) in rows" :key="row.date">
                    <rect v-if="comparisonEnabled" :x="x(index) + 1" :y="spendY(row.previousSpend)" :width="barWidth" :height="plot.top + plotHeight - spendY(row.previousSpend)" rx="2" fill="#bfdbfe" />
                    <rect :x="x(index) - (comparisonEnabled ? barWidth + 1 : barWidth / 2)" :y="spendY(row.spend)" :width="barWidth" :height="plot.top + plotHeight - spendY(row.spend)" rx="2" fill="#3b82f6" />
                    <text v-if="showDate(index)" :x="x(index)" :y="height - 16" text-anchor="middle" fill="#94a3b8" font-size="12">{{ shortDate(row.date) }}</text>
                    <rect
                        :x="plot.left + (bandWidth * index)"
                        :y="plot.top"
                        :width="bandWidth"
                        :height="plotHeight"
                        fill="transparent"
                        tabindex="0"
                        :aria-label="`${row.date} 趋势数据`"
                        :data-testid="`trend-hover-${index}`"
                        @mouseenter="hoverIndex = index"
                        @focus="hoverIndex = index"
                        @blur="hoverIndex = null"
                    />
                </g>

                <polyline v-if="comparisonEnabled && previousRoasPoints" :points="previousRoasPoints" fill="none" stroke="#6ee7b7" stroke-width="2" stroke-dasharray="6 5" stroke-linecap="round" stroke-linejoin="round" />
                <polyline v-if="currentRoasPoints" :points="currentRoasPoints" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <template v-if="hoverIndex !== null && hovered">
                    <line :x1="x(hoverIndex)" :x2="x(hoverIndex)" :y1="plot.top" :y2="plot.top + plotHeight" stroke="#64748b" stroke-dasharray="3 4" />
                    <circle v-if="hovered.roas !== null" :cx="x(hoverIndex)" :cy="roasY(hovered.roas)" r="4" fill="#10b981" stroke="white" stroke-width="2" />
                    <circle v-if="comparisonEnabled && hovered.previousRoas !== null" :cx="x(hoverIndex)" :cy="roasY(hovered.previousRoas)" r="4" fill="#6ee7b7" stroke="white" stroke-width="2" />
                </template>
            </svg>

            <div v-if="hovered" class="pointer-events-none absolute top-2 z-10 w-52 -translate-x-1/2 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl" :style="tooltipStyle">
                <p class="font-semibold text-slate-900">{{ hovered.date }}</p>
                <dl class="mt-2 space-y-1.5 text-slate-600">
                    <div class="flex justify-between gap-3"><dt>花费</dt><dd class="font-semibold text-slate-900">{{ money(hovered.spend) }}</dd></div>
                    <div v-if="comparisonEnabled" class="flex justify-between gap-3"><dt>上期花费</dt><dd>{{ money(hovered.previousSpend) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt>ROAS</dt><dd class="font-semibold text-emerald-600">{{ hovered.roas === null ? '—' : `${hovered.roas.toFixed(2)}×` }}</dd></div>
                    <div v-if="comparisonEnabled" class="flex justify-between gap-3"><dt>上期 ROAS</dt><dd>{{ hovered.previousRoas === null ? '—' : `${hovered.previousRoas.toFixed(2)}×` }}</dd></div>
                </dl>
            </div>
        </div>
    </article>
</template>
