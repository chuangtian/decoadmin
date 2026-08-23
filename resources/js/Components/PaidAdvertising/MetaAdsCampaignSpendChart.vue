<script setup lang="ts">
import { computed, ref } from 'vue';

interface CampaignSpend {
    id: string;
    name: string;
    spend: number;
    share: number | null;
}

const props = defineProps<{
    campaigns: CampaignSpend[];
    totalSpend: number;
    currency: string;
}>();

const colors = ['#2563eb', '#60a5fa', '#10b981', '#f97316', '#8b5cf6', '#f43f5e'];
const radius = 76;
const circumference = 2 * Math.PI * radius;
const hoverIndex = ref<number | null>(null);

const segments = computed(() => {
    let offset = 0;

    return props.campaigns.map((campaign, index) => {
        const share = Math.max(0, campaign.share ?? 0);
        const length = circumference * (share / 100);
        const segment = {
            ...campaign,
            color: colors[index % colors.length],
            dash: Math.max(0, length - 2),
            gap: circumference - Math.max(0, length - 2),
            offset: -offset,
        };
        offset += length;

        return segment;
    });
});
const hovered = computed(() => hoverIndex.value === null ? null : segments.value[hoverIndex.value] ?? null);

function money(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency', currency: props.currency, minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value);
}
</script>

<template>
    <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="广告系列花费占比图">
        <div>
            <h2 class="font-semibold text-slate-950">广告系列花费占比</h2>
            <p class="mt-1 text-xs text-slate-500">按所选时间范围汇总广告系列花费</p>
        </div>

        <div v-if="segments.length" class="mt-5 grid min-h-[280px] items-center gap-6 sm:grid-cols-[minmax(240px,0.85fr)_minmax(0,1.15fr)]">
            <div class="relative mx-auto h-60 w-60">
                <svg class="h-full w-full -rotate-90" viewBox="0 0 240 240" role="img" aria-label="广告系列花费占比圆环图" @mouseleave="hoverIndex = null">
                    <circle cx="120" cy="120" :r="radius" fill="none" stroke="#e2e8f0" stroke-width="48" />
                    <circle
                        v-for="(segment, index) in segments"
                        :key="segment.id"
                        cx="120"
                        cy="120"
                        :r="radius"
                        fill="none"
                        :stroke="segment.color"
                        stroke-width="48"
                        :stroke-dasharray="`${segment.dash} ${segment.gap}`"
                        :stroke-dashoffset="segment.offset"
                        class="cursor-pointer transition-opacity"
                        :class="hoverIndex !== null && hoverIndex !== index ? 'opacity-40' : 'opacity-100'"
                        tabindex="0"
                        :aria-label="`${segment.name}，花费 ${money(segment.spend)}，占比 ${(segment.share ?? 0).toFixed(2)}%`"
                        :data-testid="`campaign-spend-segment-${index}`"
                        @mouseenter="hoverIndex = index"
                        @focus="hoverIndex = index"
                        @blur="hoverIndex = null"
                    />
                </svg>
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                    <span class="text-xs font-medium text-slate-400">总花费</span>
                    <strong class="mt-1 max-w-32 truncate text-lg text-slate-950">{{ money(totalSpend) }}</strong>
                </div>
                <div v-if="hovered" class="pointer-events-none absolute left-1/2 top-1/2 z-10 w-64 -translate-x-1/2 translate-y-20 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl">
                    <p class="break-words font-semibold leading-5 text-slate-900">{{ hovered.name }}</p>
                    <dl class="mt-2 space-y-1.5 text-slate-600">
                        <div class="flex justify-between gap-3"><dt>花费</dt><dd class="font-semibold text-slate-900">{{ money(hovered.spend) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>占比</dt><dd class="font-semibold" :style="{ color: hovered.color }">{{ (hovered.share ?? 0).toFixed(2) }}%</dd></div>
                    </dl>
                </div>
            </div>

            <ul class="space-y-2.5">
                <li v-for="(segment, index) in segments" :key="segment.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        :class="hoverIndex === index ? 'bg-slate-50' : ''"
                        :title="segment.name"
                        @mouseenter="hoverIndex = index"
                        @mouseleave="hoverIndex = null"
                        @focus="hoverIndex = index"
                        @blur="hoverIndex = null"
                    >
                        <i class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: segment.color }" />
                        <span class="min-w-0 flex-1 truncate text-xs font-medium text-slate-600">{{ segment.name }}</span>
                        <span class="shrink-0 text-xs font-semibold text-slate-900">{{ (segment.share ?? 0).toFixed(1) }}%</span>
                    </button>
                </li>
            </ul>
        </div>

        <div v-else class="flex min-h-[280px] items-center justify-center text-sm text-slate-400">所选时间范围暂无广告系列花费数据</div>
    </article>
</template>
