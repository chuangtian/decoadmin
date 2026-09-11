<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type Series = { key: string; label: string; color: string };
const props = defineProps<{ points: Array<Record<string, unknown>>; series: Series[] }>();
const container = ref<HTMLElement | null>(null);
const width = ref(640);
const active = ref<number | null>(null);
let observer: ResizeObserver | undefined;
onMounted(() => {
    observer = new ResizeObserver(([entry]) => { if (entry) width.value = Math.max(320, entry.contentRect.width); });
    if (container.value) observer.observe(container.value);
});
onBeforeUnmount(() => observer?.disconnect());
watch(() => [props.points, props.series], () => { active.value = null; });
const height = 244;
const padding = { left: 52, right: 16, top: 14, bottom: 32 };
const plotWidth = computed(() => width.value - padding.left - padding.right);
const plotHeight = height - padding.top - padding.bottom;
const groupWidth = computed(() => plotWidth.value / Math.max(1, props.points.length));
const barWidth = computed(() => Math.max(1, Math.min(14, groupWidth.value * .7 / Math.max(1, props.series.length))));
const maximum = computed(() => {
    const max = Math.max(1, ...props.points.flatMap(point => props.series.map(series => numeric(point[series.key]))));
    const magnitude = 10 ** Math.floor(Math.log10(max / 4));
    const step = ([1, 2, 2.5, 5, 10].find(value => value * magnitude >= max / 4) ?? 10) * magnitude;
    return Math.max(4, step * 4);
});
const ticks = computed(() => Array.from({ length: 5 }, (_, index) => maximum.value * index / 4));
const labelInterval = computed(() => Math.max(1, Math.ceil(props.points.length / Math.max(2, Math.floor(plotWidth.value / 70)))));
const activePoint = computed(() => active.value === null ? null : props.points[active.value]);
function numeric(value: unknown): number { const number = Number(value); return Number.isFinite(number) ? Math.max(0, number) : 0; }
function format(value: unknown): string { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(numeric(value)); }
function x(index: number): number { return padding.left + groupWidth.value * (index + .5); }
function y(value: unknown): number { return padding.top + plotHeight * (1 - numeric(value) / maximum.value); }
function hover(event: PointerEvent): void {
    const rect = (event.currentTarget as SVGSVGElement).getBoundingClientRect();
    const offset = (event.clientX - rect.left) * width.value / rect.width - padding.left;
    active.value = offset >= 0 && offset < plotWidth.value ? Math.min(props.points.length - 1, Math.floor(offset / groupWidth.value)) : null;
}
</script>

<template>
    <div ref="container" class="social-bars">
        <div class="social-bars-legend"><span v-for="item in series" :key="item.key"><i :style="{ background: item.color }"></i>{{ item.label }}</span></div>
        <svg v-readable-chart v-if="points.length" :viewBox="`0 0 ${width} ${height}`" role="group" aria-label="平台数据柱状趋势图" @pointermove="hover" @pointerleave="active = null" @keydown.esc="active = null">
            <g v-for="tick in ticks" :key="tick">
                <line :x1="padding.left" :x2="width - padding.right" :y1="y(tick)" :y2="y(tick)" stroke="#e2e8f0" :stroke-dasharray="tick ? '3 4' : undefined" />
                <text :x="padding.left - 8" :y="y(tick) + 4" text-anchor="end">{{ format(tick) }}</text>
            </g>
            <g v-for="(point, index) in points" :key="String(point.date)">
                <rect v-if="active === index" :x="padding.left + index * groupWidth" :y="padding.top" :width="groupWidth" :height="plotHeight" fill="#64748b" opacity=".05" />
                <rect v-for="(item, seriesIndex) in series" :key="item.key" :x="x(index) + (seriesIndex - series.length / 2) * barWidth" :y="y(point[item.key])" :width="barWidth" :height="Math.max(0, y(0) - y(point[item.key]))" :fill="item.color" rx="2" />
                <text v-if="index % labelInterval === 0 || index === points.length - 1" :x="x(index)" :y="height - 9" text-anchor="middle">{{ String(point.date).slice(5, 10) }}</text>
                <rect :x="padding.left + index * groupWidth" :y="padding.top" :width="groupWidth" :height="plotHeight" fill="transparent" tabindex="0" role="button" :aria-label="`${point.date}，${series.map(item => `${item.label} ${format(point[item.key])}`).join('，')}`" @focus="active = index" @blur="active = null" @click="active = index" />
            </g>
        </svg>
        <div v-else class="social-bars-empty">当前时段暂无数据</div>
        <div v-if="activePoint" role="tooltip" class="social-bars-tooltip">
            <strong>{{ activePoint.date }}</strong>
            <div v-for="item in series" :key="item.key"><span><i :style="{ background: item.color }"></i>{{ item.label }}</span><b>{{ format(activePoint[item.key]) }}</b></div>
        </div>
    </div>
</template>

<style scoped>
.social-bars { position: relative; min-width: 0; }
.social-bars-legend { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 12px; min-height: 32px; font-size: 12px; color: #64748b; }
.social-bars-legend span, .social-bars-tooltip span { display: flex; align-items: center; gap: 5px; }
i { display: inline-block; width: 18px; height: 9px; border-radius: 3px; }
svg { display: block; width: 100%; height: 244px; }
svg text { fill: #64748b; font-size: 12px; }
svg [tabindex] { cursor: crosshair; outline-color: #2563eb; }
.social-bars-empty { display: grid; place-items: center; height: 244px; color: #94a3b8; font-size: 14px; }
.social-bars-tooltip { position: absolute; top: 34px; right: 12px; pointer-events: none; width: 218px; max-width: calc(100% - 24px); padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: white; box-shadow: 0 4px 16px #0f172a12; font-size: 13px; }
.social-bars-tooltip strong { display: block; margin-bottom: 8px; color: #334155; }
.social-bars-tooltip div { display: flex; justify-content: space-between; margin-top: 4px; color: #64748b; }
.social-bars-tooltip b { font-weight: 600; color: #334155; font-variant-numeric: tabular-nums; }
</style>
