<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

const props = defineProps<{
    impressions: number;
    clicks: number;
    purchases: number;
}>();

const hoveredIndex = ref<number | null>(null);
const center = 250;
const yPositions = [28, 112, 198, 274];

const stages = computed(() => {
    const values = [props.impressions, props.clicks, props.purchases];
    const total = values.reduce((sum, value) => sum + value, 0);
    const labels = ['曝光', '点击', '购买'];
    const colors = ['#5b7bd5', '#8fce72', '#f8c44f'];

    return values.map((value, index) => ({
        label: labels[index],
        value,
        color: colors[index],
        share: total > 0 ? (value / total) * 100 : 0,
    }));
});

const widths = computed(() => {
    const maximum = Math.max(1, props.impressions);
    return [
        420,
        Math.max(82, 420 * Math.sqrt(Math.max(0, props.clicks) / maximum)),
        Math.max(48, 420 * Math.sqrt(Math.max(0, props.purchases) / maximum)),
        18,
    ];
});

const hovered = computed(() => hoveredIndex.value === null ? null : stages.value[hoveredIndex.value] ?? null);

function points(index: number): string {
    const top = widths.value[index];
    const bottom = widths.value[index + 1];
    const topY = yPositions[index];
    const bottomY = yPositions[index + 1];
    return `${center - (top / 2)},${topY} ${center + (top / 2)},${topY} ${center + (bottom / 2)},${bottomY} ${center - (bottom / 2)},${bottomY}`;
}

function labelY(index: number): number {
    return (yPositions[index] + yPositions[index + 1]) / 2;
}

function integer(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}
</script>

<template>
    <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="转化漏斗图">
        <div>
            <h2 class="font-semibold text-slate-950">转化漏斗</h2>
            <p class="mt-1 text-xs text-slate-500">从广告曝光到点击及购买的转化路径</p>
        </div>

        <div class="relative mt-4" @mouseleave="hoveredIndex = null">
            <svg v-readable-chart class="h-[300px] w-full" viewBox="0 0 620 300" role="img" aria-label="曝光、点击和购买转化漏斗">
                <g
                    v-for="(stage, index) in stages"
                    :key="stage.label"
                    class="cursor-default"
                    tabindex="0"
                    :aria-label="`${stage.label} ${integer(stage.value)}`"
                    :data-testid="`funnel-stage-${index}`"
                    @mouseenter="hoveredIndex = index"
                    @focus="hoveredIndex = index"
                    @blur="hoveredIndex = null"
                >
                    <polygon :points="points(index)" :fill="stage.color" :opacity="hoveredIndex === null || hoveredIndex === index ? 1 : 0.48" class="transition-opacity" />
                    <text :x="center" :y="labelY(index) - 4" text-anchor="middle" fill="white" font-size="13" font-weight="600">{{ stage.label }}</text>
                    <text :x="center" :y="labelY(index) + 14" text-anchor="middle" fill="white" font-size="13" font-weight="700">{{ integer(stage.value) }}</text>
                </g>
                <g transform="translate(500 90)">
                    <g v-for="(stage, index) in stages" :key="stage.label" :transform="`translate(0 ${index * 38})`">
                        <rect width="24" height="13" rx="4" :fill="stage.color" />
                        <text x="34" y="11" fill="#64748b" font-size="12">{{ stage.label }}</text>
                    </g>
                </g>
            </svg>

            <div v-if="hovered" class="pointer-events-none absolute right-5 top-4 z-10 min-w-48 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl">
                <p class="font-semibold text-slate-900">{{ hovered.label }}</p>
                <p class="mt-2 text-slate-600">{{ integer(hovered.value) }} <span class="text-slate-400">（{{ hovered.share.toFixed(2) }}%）</span></p>
            </div>
        </div>
    </article>
</template>
