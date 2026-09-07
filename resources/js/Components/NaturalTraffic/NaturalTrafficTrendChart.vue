<script setup lang="ts">
import { computed, ref, watch } from 'vue';

type ValueFormat = 'number' | 'integer' | 'currency' | 'percent';
type Series = { key: string; label: string; color: string; format?: ValueFormat };
const props = withDefaults(defineProps<{ points: Array<Record<string, unknown>>; series: Series[]; xKey?: string; height?: number; fillArea?: boolean; interactive?: boolean; valueFormat?: ValueFormat; currency?: string; weeklyLabels?: boolean }>(), { xKey: 'date', height: 250, fillArea: false, interactive: false, valueFormat: 'number', currency: 'USD', weeklyLabels: false });
const activeIndex = ref<number | null>(null);
const activePoint = computed(() => activeIndex.value === null ? null : props.points[activeIndex.value]);
watch(() => props.points, () => { activeIndex.value = null; });
function formattedValue(value: unknown, format = props.valueFormat): string {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const number = Number(value);
    if (format === 'percent') return `${number.toFixed(2)}%`;
    return new Intl.NumberFormat('en-US', { ...(format === 'currency' ? { style: 'currency', currency: props.currency } : {}), minimumFractionDigits: format === 'integer' ? 0 : 2, maximumFractionDigits: format === 'integer' ? 0 : 2 }).format(number);
}
const tickIndices = computed(() => {
    const count = props.points.length;
    if (props.weeklyLabels || count <= 7) return Array.from({ length: count }, (_, i) => i);
    return Array.from({ length: 7 }, (_, i) => Math.round(i * (count - 1) / 6));
});
const width = 1000;
const padding = computed(() => ({ left: 58, right: 20, top: 24, bottom: props.weeklyLabels ? 60 : 42 }));
const values = computed(() => props.points.flatMap((point) => props.series.map((series) => Number(point[series.key] ?? 0))).filter(Number.isFinite));
const maxValue = computed(() => Math.max(1, ...values.value));
const plotWidth = computed(() => width - padding.value.left - padding.value.right);
const plotHeight = computed(() => props.height - padding.value.top - padding.value.bottom);

function x(index: number): number {
    return padding.value.left + (props.points.length <= 1 ? plotWidth.value / 2 : index / (props.points.length - 1) * plotWidth.value);
}
function y(value: unknown): number {
    return padding.value.top + plotHeight.value - Math.max(0, Number(value ?? 0)) / maxValue.value * plotHeight.value;
}
function path(key: string): string {
    return props.points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index).toFixed(2)} ${y(point[key]).toFixed(2)}`).join(' ');
}
function areaPath(key: string): string {
    if (!props.points.length) return '';
    const baseline = padding.value.top + plotHeight.value;

    return `${path(key)} L ${x(props.points.length - 1).toFixed(2)} ${baseline.toFixed(2)} L ${x(0).toFixed(2)} ${baseline.toFixed(2)} Z`;
}
function label(value: unknown): string {
    const text = String(value ?? '');
    return /^\d{4}-\d{2}-\d{2}/.test(text) ? text.slice(5) : text.length > 12 ? `${text.slice(0, 12)}…` : text;
}
function compact(value: number): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}
function weekLabel(value: unknown): string {
    const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
    if (!Number.isFinite(+d)) return label(value);
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
    const start = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return `W${Math.ceil((((+d - +start) / 86400000) + 1) / 7)}`;
}
function axisValue(value: number): string {
    return props.valueFormat === 'currency' ? `${new Intl.NumberFormat('en-US', {style:'currency', currency:props.currency, maximumFractionDigits:0}).format(0).replace(/0/g, '')}${compact(value)}` : props.valueFormat === 'percent' ? `${compact(value)}%` : compact(value);
}
</script>


<template>
    <div v-if="points.length" class="w-full overflow-hidden">
        <div class="mb-3 flex flex-wrap justify-end gap-4 text-xs font-bold text-slate-500"><span v-for="item in series" :key="item.key" class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full" :style="{ background: item.color }"></i>{{ item.label }}</span></div>
        <div class="overflow-x-auto"><div class="relative" :style="weeklyLabels ? { minWidth: `${Math.max(900, points.length * 60)}px` } : undefined" @mouseleave="activeIndex = null">
        <svg :viewBox="`0 0 ${width} ${height}`" class="block w-full" role="img" aria-label="趋势图">
            <g v-for="tick in [0, .25, .5, .75, 1]" :key="tick">
                <line :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight * (1 - tick)" :y2="padding.top + plotHeight * (1 - tick)" stroke="#e2e8f0" stroke-dasharray="5 7" />
                <text :x="padding.left - 10" :y="padding.top + plotHeight * (1 - tick) + 4" text-anchor="end" fill="#94a3b8" font-size="11">{{ axisValue(maxValue * tick) }}</text>
            </g>
            <path v-for="item in fillArea ? series : []" :key="`area-${item.key}`" :d="areaPath(item.key)" :fill="item.color" fill-opacity=".1" stroke="none" />
            <path v-for="item in series" :key="item.key" :d="path(item.key)" fill="none" :stroke="item.color" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <g v-for="(point, index) in points" :key="index">
                <circle v-for="item in series" :key="item.key" :cx="x(index)" :cy="y(point[item.key])" :r="activeIndex === index ? 6 : 3.5" :fill="activeIndex === index ? item.color : 'white'" :stroke="item.color" stroke-width="2" />
                <text v-if="tickIndices.includes(index)" :x="x(index)" :y="height - (weeklyLabels ? 30 : 12)" :text-anchor="weeklyLabels ? 'middle' : index === 0 && points.length > 1 ? 'start' : index === points.length - 1 && points.length > 1 ? 'end' : 'middle'" fill="#64748b" font-size="11">{{ weeklyLabels ? weekLabel(point[xKey]) : label(point[xKey]) }}<tspan v-if="weeklyLabels" :x="x(index)" dy="16">{{ label(point[xKey]) }}</tspan></text>
            </g>
            <line v-if="interactive && activeIndex !== null" :x1="x(activeIndex)" :x2="x(activeIndex)" :y1="padding.top" :y2="height - padding.bottom" stroke="#94a3b8" stroke-dasharray="4 4" pointer-events="none" />
            <g v-if="interactive">
                <rect v-for="(point, index) in points" :key="`hit-${index}`" :x="index === 0 ? padding.left : (x(index - 1) + x(index)) / 2" :y="padding.top" :width="points.length === 1 ? plotWidth : (index === 0 || index === points.length - 1 ? .5 : 1) * plotWidth / (points.length - 1)" :height="plotHeight" fill="transparent" tabindex="0" role="button" :aria-label="`${point[xKey]}，${series.map(item => `${item.label} ${formattedValue(point[item.key], item.format)}`).join('，')}`" class="cursor-crosshair focus:outline-none" @mouseenter="activeIndex = index" @focus="activeIndex = index" @blur="activeIndex = null" @click="activeIndex = index" @keydown.esc="activeIndex = null" />
            </g>
        </svg>
        <div v-if="interactive && activePoint && activeIndex !== null" role="tooltip" class="pointer-events-none absolute top-0 z-10 w-52 max-w-full -translate-x-1/2 rounded-xl border border-slate-200 bg-white/95 p-3 text-xs shadow-lg" :style="{ left: `${Math.max(28, Math.min(72, x(activeIndex) / width * 100))}%` }">
            <p class="mb-2 font-bold text-slate-900">{{ activePoint[xKey] }}</p>
            <p v-if="activePoint.date" class="mb-2 text-slate-500">{{ activePoint.date }}<template v-if="activePoint.date_to"> — {{ activePoint.date_to }}</template></p>
            <div v-for="item in series" :key="item.key" class="mt-1 flex items-center justify-between gap-3"><span class="flex items-center gap-1.5 text-slate-500"><i class="h-2 w-2 rounded-full" :style="{ background: item.color }"></i>{{ item.label }}</span><strong class="tabular-nums text-slate-900">{{ formattedValue(activePoint[item.key], item.format) }}</strong></div>
        </div>
        </div></div>
    </div>
    <div v-else class="flex items-center justify-center rounded-2xl bg-slate-50 text-sm font-semibold text-slate-400" :style="{ height: `${Math.max(160, height)}px` }">当前筛选暂无趋势数据</div>
</template>
