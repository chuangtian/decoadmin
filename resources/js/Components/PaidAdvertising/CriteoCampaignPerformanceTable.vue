<script setup lang="ts">
import { computed, ref } from 'vue';

interface CampaignPerformance {
    id: string;
    name: string;
    spend: number;
    revenue: number;
    roas: number;
    impressions: number;
    clicks: number;
    ctr: number;
    cpc: number;
    conversions: number;
    cpa: number;
}

const props = defineProps<{ currency: string; campaigns: CampaignPerformance[] }>();
const search = ref('');
const filtered = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    return query === ''
        ? props.campaigns
        : props.campaigns.filter((campaign) => campaign.name.toLocaleLowerCase().includes(query) || campaign.id.toLocaleLowerCase().includes(query));
});
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2,
}).format(value || 0);
const number = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value || 0);
</script>

<template>
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 lg:flex-row lg:items-end lg:justify-between sm:px-8">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Campaign detail</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">广告系列明细</h2>
                <p class="mt-1 text-sm text-slate-500">固定展示 Criteo 系统时间（UTC）最近 7 天的 Campaign 汇总。</p>
            </div>
            <label class="relative block w-full max-w-sm">
                <span class="sr-only">搜索广告系列</span>
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
                <input v-model="search" type="search" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:bg-white focus:ring-4 focus:ring-blue-50" placeholder="搜索广告系列名称或 ID…" />
            </label>
        </header>
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/60 px-6 py-3 text-xs text-slate-500 sm:px-8">
            <span>共 {{ filtered.length }} 个广告系列</span>
            <span>币种 {{ currency }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[1080px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="min-w-72 px-6 py-3.5 font-semibold sm:px-8">广告系列</th>
                        <th class="px-5 py-3.5 text-right font-semibold">花费</th>
                        <th class="px-5 py-3.5 text-right font-semibold">ROAS</th>
                        <th class="px-5 py-3.5 text-right font-semibold">曝光</th>
                        <th class="px-5 py-3.5 text-right font-semibold">点击</th>
                        <th class="px-5 py-3.5 text-right font-semibold">CTR</th>
                        <th class="px-5 py-3.5 text-right font-semibold">CPC</th>
                        <th class="px-6 py-3.5 text-right font-semibold sm:px-8">转化</th>
                    </tr>
                </thead>
                <tbody v-if="filtered.length" class="divide-y divide-slate-100 text-slate-700">
                    <tr v-for="campaign in filtered" :key="campaign.id" class="transition hover:bg-slate-50/70">
                        <td class="px-6 py-4 sm:px-8">
                            <p class="font-semibold text-slate-900">{{ campaign.name }}</p>
                            <p class="mt-1 text-xs tabular-nums text-slate-400">ID {{ campaign.id }}</p>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ money(campaign.spend) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-emerald-700">{{ campaign.roas.toFixed(2) }}×</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ number(campaign.impressions) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ number(campaign.clicks) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ campaign.ctr.toFixed(2) }}%</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ money(campaign.cpc) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right font-semibold tabular-nums text-teal-700 sm:px-8">{{ number(campaign.conversions) }}</td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr><td colspan="8" class="px-6 py-14 text-center text-sm text-slate-400">没有匹配的广告系列。</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
