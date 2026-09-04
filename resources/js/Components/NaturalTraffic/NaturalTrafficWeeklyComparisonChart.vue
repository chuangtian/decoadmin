<script setup lang="ts">
import { computed } from 'vue';

type ComparisonItem = {
    key: string;
    label: string;
    current: number;
    previous: number;
};

const props = defineProps<{ items: ComparisonItem[] }>();
const width = 1000;
const height = 300;
const padding = { left: 70, right: 24, top: 28, bottom: 50 };
const plotWidth = width - padding.left - padding.right;
const plotHeight = height - padding.top - padding.bottom;
const maxValue = computed(() => Math.max(1, ...props.items.flatMap((item) => [Number(item.current ?? 0), Number(item.previous ?? 0)])));
const groupWidth = computed(() => plotWidth / Math.max(1, props.items.length));

function compact(value: number): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}
function groupCenter(index: number): number {
    return padding.left + groupWidth.value * index + groupWidth.value / 2;
}
function barHeight(value: number): number {
    return Math.max(0, Number(value ?? 0)) / maxValue.value * plotHeight;
}
function barY(value: number): number {
    return padding.top + plotHeight - barHeight(value);
}
</script>

<template>
    <div v-if="items.length" class="w-full overflow-hidden">
        <div class="mb-3 flex justify-end gap-5 text-xs font-bold text-slate-500">
            <span class="flex items-center gap-2"><i class="h-3 w-7 rounded bg-blue-500"></i>选中周</span>
            <span class="flex items-center gap-2"><i class="h-3 w-7 rounded bg-slate-400"></i>上周</span>
        </div>
        <svg :viewBox="`0 0 ${width} ${height}`" class="block w-full" role="img" aria-label="本周与上周指标柱状对比图">
            <g v-for="tick in [0, .25, .5, .75, 1]" :key="tick">
                <line :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight * (1 - tick)" :y2="padding.top + plotHeight * (1 - tick)" stroke="#e2e8f0" stroke-dasharray="5 7" />
                <text :x="padding.left - 12" :y="padding.top + plotHeight * (1 - tick) + 4" text-anchor="end" fill="#94a3b8" font-size="11">{{ compact(maxValue * tick) }}</text>
            </g>
            <g v-for="(item, index) in items" :key="item.key">
                <rect :x="groupCenter(index) - 38" :y="barY(item.current)" width="32" :height="barHeight(item.current)" rx="5" fill="#3b82f6">
                    <title>{{ item.label }} · 选中周 {{ compact(item.current) }}</title>
                </rect>
                <rect :x="groupCenter(index) + 6" :y="barY(item.previous)" width="32" :height="barHeight(item.previous)" rx="5" fill="#94a3b8">
                    <title>{{ item.label }} · 上周 {{ compact(item.previous) }}</title>
                </rect>
                <text :x="groupCenter(index)" :y="height - 16" text-anchor="middle" fill="#64748b" font-size="12" font-weight="700">{{ item.label }}</text>
            </g>
        </svg>
    </div>
    <div v-else class="flex h-64 items-center justify-center rounded-2xl bg-slate-50 text-sm font-semibold text-slate-400">暂无周度对比数据</div>
</template>
