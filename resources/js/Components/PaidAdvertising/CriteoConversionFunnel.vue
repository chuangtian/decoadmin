<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ impressions: number; clicks: number; conversions: number }>();
const centerX = 310;
const maximumHalfWidth = 250;
const stageHalfWidth = (value: number, total: number, minimum: number) => {
    if (total <= 0 || value <= 0) return minimum;
    return Math.max(minimum, maximumHalfWidth * Math.sqrt(value / total));
};
const clickHalfWidth = computed(() => stageHalfWidth(props.clicks, props.impressions, 92));
const conversionHalfWidth = computed(() => stageHalfWidth(props.conversions, props.impressions, 56));
const funnelStages = computed(() => [
    {
        key: 'impressions',
        label: '曝光',
        value: props.impressions,
        color: '#f97316',
        textColor: '#ffffff',
        points: (centerX - maximumHalfWidth) + ',42 ' + (centerX + maximumHalfWidth) + ',42 ' + (centerX + clickHalfWidth.value) + ',122 ' + (centerX - clickHalfWidth.value) + ',122',
    },
    {
        key: 'clicks',
        label: '点击',
        value: props.clicks,
        color: '#f59e0b',
        textColor: '#422006',
        points: (centerX - clickHalfWidth.value) + ',132 ' + (centerX + clickHalfWidth.value) + ',132 ' + (centerX + conversionHalfWidth.value) + ',210 ' + (centerX - conversionHalfWidth.value) + ',210',
    },
    {
        key: 'conversions',
        label: '转化',
        value: props.conversions,
        color: '#0f9f78',
        textColor: '#ffffff',
        points: (centerX - conversionHalfWidth.value) + ',220 ' + (centerX + conversionHalfWidth.value) + ',220 ' + (centerX + conversionHalfWidth.value * 0.58) + ',286 ' + (centerX - conversionHalfWidth.value * 0.58) + ',286',
    },
]);
const ctr = computed(() => props.impressions > 0 ? (props.clicks / props.impressions) * 100 : 0);
const clickConversionRate = computed(() => props.clicks > 0 ? (props.conversions / props.clicks) * 100 : 0);
const overallConversionRate = computed(() => props.impressions > 0 ? (props.conversions / props.impressions) * 100 : 0);
const number = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value || 0);
const percent = (value: number, precision = 2) => Number(value || 0).toFixed(precision) + '%';
const funnelLabel = computed(() => '曝光 ' + number(props.impressions) + '，点击 ' + number(props.clicks) + '，转化 ' + number(props.conversions));
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-teal-700">Conversion funnel</p>
                <h2 class="mt-2 text-lg font-semibold text-slate-950">转化漏斗</h2>
                <p class="mt-1 text-xs text-slate-400">曝光 → 点击 → 转化</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs font-medium text-slate-500">
                <span v-for="stage in funnelStages" :key="stage.key" class="inline-flex items-center gap-1.5">
                    <i class="h-2.5 w-2.5 rounded" :style="{ backgroundColor: stage.color }" />{{ stage.label }}
                </span>
            </div>
        </header>

        <div class="overflow-x-auto px-3 pt-2 sm:px-5">
            <svg viewBox="0 0 620 310" class="mx-auto block h-[310px] min-w-[540px] w-full max-w-[680px]" role="img" :aria-label="funnelLabel">
                <title>Criteo 曝光、点击与转化漏斗</title>
                <g v-for="(stage, index) in funnelStages" :key="stage.key">
                    <polygon :points="stage.points" :fill="stage.color" :fill-opacity="index === 0 ? 0.92 : 0.96" />
                    <text :x="centerX" :y="[74, 162, 244][index]" text-anchor="middle" :fill="stage.textColor" class="text-[13px] font-semibold">{{ stage.label }}</text>
                    <text :x="centerX" :y="[96, 185, 268][index]" text-anchor="middle" :fill="stage.textColor" class="text-[18px] font-bold tabular-nums">{{ number(stage.value) }}</text>
                </g>
            </svg>
        </div>

        <dl class="grid divide-y divide-slate-100 border-t border-slate-100 bg-slate-50/70 text-center sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            <div class="px-4 py-4">
                <dt class="text-xs font-medium text-slate-400">曝光 → 点击</dt>
                <dd class="mt-1 text-base font-semibold tabular-nums text-slate-900">{{ percent(ctr) }}</dd>
            </div>
            <div class="px-4 py-4">
                <dt class="text-xs font-medium text-slate-400">点击 → 转化</dt>
                <dd class="mt-1 text-base font-semibold tabular-nums text-slate-900">{{ percent(clickConversionRate) }}</dd>
            </div>
            <div class="px-4 py-4">
                <dt class="text-xs font-medium text-slate-400">曝光 → 转化</dt>
                <dd class="mt-1 text-base font-semibold tabular-nums text-slate-900">{{ percent(overallConversionRate, 4) }}</dd>
            </div>
        </dl>
    </section>
</template>
