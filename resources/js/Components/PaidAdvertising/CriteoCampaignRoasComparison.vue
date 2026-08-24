<script setup lang="ts">
import { computed } from 'vue';

interface CampaignPerformance {
    id: string;
    name: string;
    spend: number;
    revenue: number;
    roas: number;
}

const props = defineProps<{ campaigns: CampaignPerformance[] }>();
const rows = computed(() => props.campaigns.slice(0, 8));
const maximum = computed(() => Math.max(1, ...rows.value.map((campaign) => campaign.roas)));
const width = (value: number) => Math.max(value > 0 ? 4 : 0, Math.min(100, (value / maximum.value) * 100)) + '%';
</script>

<template>
    <section class="h-full min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-600">Campaign efficiency</p>
            <h2 class="mt-2 text-lg font-semibold text-slate-950">广告系列 ROAS 对比</h2>
            <p class="mt-1 text-xs text-slate-400">归因收入 ÷ 花费 · 最近 7 天</p>
        </header>
        <div v-if="rows.length" class="flex min-h-[320px] flex-col justify-center gap-6 p-6 sm:p-8">
            <div v-for="campaign in rows" :key="campaign.id" class="grid min-w-0 grid-cols-[minmax(90px,0.9fr)_minmax(100px,1.8fr)_auto] items-center gap-3">
                <p class="truncate text-right text-xs font-medium text-slate-500 sm:text-sm" :title="campaign.name">{{ campaign.name }}</p>
                <div class="h-8 overflow-hidden rounded-lg bg-slate-100">
                    <div class="h-full rounded-lg bg-emerald-500 transition-[width] duration-500" :style="{ width: width(campaign.roas) }" />
                </div>
                <p class="min-w-14 text-right text-sm font-semibold tabular-nums text-emerald-700">{{ campaign.roas.toFixed(2) }}×</p>
            </div>
        </div>
        <div v-else class="grid min-h-[320px] place-items-center p-8 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2" /></svg>
                </span>
                <p class="mt-4 text-sm font-semibold text-slate-700">等待广告系列数据</p>
            </div>
        </div>
    </section>
</template>
