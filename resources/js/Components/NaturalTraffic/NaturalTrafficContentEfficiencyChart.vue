<script setup lang="ts">
import { computed } from 'vue';

type ContentEfficiency = {
    type: string;
    posts: number;
    average_views: number;
    average_interactions: number;
};

const props = defineProps<{ items: ContentEfficiency[] }>();
const width = 1000;
const height = 300;
const padding = { left: 70, right: 24, top: 28, bottom: 58 };
const plotWidth = width - padding.left - padding.right;
const plotHeight = height - padding.top - padding.bottom;
const maxValue = computed(() => Math.max(1, ...props.items.flatMap((item) => [Number(item.average_views ?? 0), Number(item.average_interactions ?? 0)])));
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
function typeLabel(item: ContentEfficiency): string {
    const type = item.type.replace(/^Instagram\s+/i, '') || '未分类';

    return `${type}（${compact(item.posts)}篇）`;
}
</script>

<template>
    <div v-if="items.length" class="w-full overflow-hidden">
        <div class="mb-3 flex justify-end gap-5 text-xs font-bold text-slate-500">
            <span class="flex items-center gap-2"><i class="h-3 w-7 rounded bg-pink-500"></i>平均浏览</span>
            <span class="flex items-center gap-2"><i class="h-3 w-7 rounded bg-emerald-500"></i>平均互动</span>
        </div>
        <svg :viewBox="`0 0 ${width} ${height}`" class="block w-full" role="img" aria-label="内容形式平均浏览与平均互动柱状对比图">
            <g v-for="tick in [0, .25, .5, .75, 1]" :key="tick">
                <line :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight * (1 - tick)" :y2="padding.top + plotHeight * (1 - tick)" stroke="#e2e8f0" stroke-dasharray="5 7" />
                <text :x="padding.left - 12" :y="padding.top + plotHeight * (1 - tick) + 4" text-anchor="end" fill="#94a3b8" font-size="11">{{ compact(maxValue * tick) }}</text>
            </g>
            <g v-for="(item, index) in items" :key="item.type">
                <rect :x="groupCenter(index) - 38" :y="barY(item.average_views)" width="32" :height="barHeight(item.average_views)" rx="5" fill="#ec4899">
                    <title>{{ item.type }} · 平均浏览 {{ compact(item.average_views) }}</title>
                </rect>
                <rect :x="groupCenter(index) + 6" :y="barY(item.average_interactions)" width="32" :height="barHeight(item.average_interactions)" rx="5" fill="#10b981">
                    <title>{{ item.type }} · 平均互动 {{ compact(item.average_interactions) }}</title>
                </rect>
                <text :x="groupCenter(index)" :y="height - 18" text-anchor="middle" fill="#64748b" font-size="12" font-weight="700">{{ typeLabel(item) }}</text>
            </g>
        </svg>
    </div>
    <div v-else class="flex h-64 items-center justify-center rounded-2xl bg-slate-50 text-sm font-semibold text-slate-400">暂无内容形式数据</div>
</template>
