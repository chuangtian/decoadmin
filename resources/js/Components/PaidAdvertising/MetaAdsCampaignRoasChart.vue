<script setup lang="ts">
import { computed, ref } from 'vue';

interface CampaignMetric {
    id: string;
    name: string;
    spend: number;
    roas: number | null;
}

const props = defineProps<{
    campaigns: CampaignMetric[];
    currency: string;
}>();

const hoverIndex = ref<number | null>(null);
const rows = computed(() => props.campaigns.slice(0, 6));
const maximum = computed(() => Math.max(1, ...rows.value.map((campaign) => campaign.roas ?? 0)) * 1.06);
const hovered = computed(() => hoverIndex.value === null ? null : rows.value[hoverIndex.value] ?? null);

function width(value: number | null): string {
    if (value === null) return '0%';
    return `${Math.max(1.5, (value / maximum.value) * 100)}%`;
}

function tone(value: number | null): string {
    if (value === null) return 'bg-slate-300';
    if (value >= 5) return 'bg-emerald-500';
    if (value >= 4) return 'bg-teal-400';
    return 'bg-orange-400';
}

function money(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency', currency: props.currency, minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value);
}
</script>

<template>
    <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="广告系列 ROAS 对比图">
        <div>
            <h2 class="font-semibold text-slate-950">广告系列 ROAS 对比</h2>
            <p class="mt-1 text-xs text-slate-500">对比花费最高的主要广告系列回报表现</p>
        </div>

        <div v-if="rows.length" class="mt-6 space-y-4">
            <button
                v-for="(campaign, index) in rows"
                :key="campaign.id"
                type="button"
                class="grid w-full grid-cols-[minmax(8rem,0.65fr)_minmax(10rem,1.35fr)_3.75rem] items-center gap-3 rounded-xl px-2 py-1.5 text-left transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-200"
                :class="hoverIndex === index ? 'bg-slate-50' : ''"
                :aria-label="`${campaign.name}，ROAS ${campaign.roas === null ? '暂无' : `${campaign.roas.toFixed(2)} 倍`}`"
                :data-testid="`campaign-roas-${index}`"
                @mouseenter="hoverIndex = index"
                @mouseleave="hoverIndex = null"
                @focus="hoverIndex = index"
                @blur="hoverIndex = null"
            >
                <span class="truncate text-xs font-medium text-slate-500" :title="campaign.name">{{ campaign.name }}</span>
                <span class="h-7 overflow-hidden rounded-lg bg-slate-100">
                    <i class="block h-full rounded-lg transition-[width] duration-500" :class="tone(campaign.roas)" :style="{ width: width(campaign.roas) }" />
                </span>
                <strong class="text-right text-sm text-slate-900">{{ campaign.roas === null ? '—' : `${campaign.roas.toFixed(2)}×` }}</strong>
            </button>
        </div>

        <div v-else class="flex min-h-[280px] items-center justify-center text-sm text-slate-400">所选时间范围暂无广告系列 ROAS 数据</div>

        <div v-if="hovered" class="pointer-events-none absolute right-5 top-16 z-10 w-72 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl">
            <p class="break-words font-semibold leading-5 text-slate-900">{{ hovered.name }}</p>
            <dl class="mt-2 space-y-1.5 text-slate-600">
                <div class="flex justify-between gap-3"><dt>花费</dt><dd class="font-semibold text-slate-900">{{ money(hovered.spend) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>ROAS</dt><dd class="font-semibold text-emerald-600">{{ hovered.roas === null ? '—' : `${hovered.roas.toFixed(2)}×` }}</dd></div>
            </dl>
        </div>
    </article>
</template>
