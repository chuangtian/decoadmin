<script setup lang="ts">
interface CampaignMetric {
    id: string;
    name: string;
    spend: number;
    roas: number | null;
    impressions: number;
    clicks: number;
    ctr: number | null;
    cpc: number | null;
    frequency: number | null;
    purchases: number;
}

const props = defineProps<{
    campaigns: CampaignMetric[];
    total: number;
    truncated: boolean;
    limit: number;
    currency: string;
}>();

function money(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency', currency: props.currency, minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value);
}

function integer(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function number(value: number): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value);
}
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="font-semibold text-slate-950">广告系列明细</h2>
                <p class="mt-1 text-xs text-slate-500">按花费从高到低展示所选范围内的 campaign 级聚合数据</p>
            </div>
            <p class="text-xs font-medium text-slate-400">
                共 {{ total }} 个广告系列<span v-if="truncated">，当前显示前 {{ limit }} 个</span>
            </p>
        </header>

        <div v-if="campaigns.length" class="max-h-[680px] overflow-auto">
            <table class="min-w-[1120px] w-full border-separate border-spacing-0 text-sm">
                <thead class="sticky top-0 z-10 bg-slate-50 text-xs font-semibold text-slate-500 shadow-[0_1px_0_0_#e2e8f0]">
                    <tr>
                        <th class="min-w-64 px-5 py-3 text-left">广告系列</th>
                        <th class="px-4 py-3 text-right">花费</th>
                        <th class="px-4 py-3 text-right">ROAS</th>
                        <th class="px-4 py-3 text-right">曝光</th>
                        <th class="px-4 py-3 text-right">点击</th>
                        <th class="px-4 py-3 text-right">CTR</th>
                        <th class="px-4 py-3 text-right">CPC</th>
                        <th class="px-4 py-3 text-right">频次</th>
                        <th class="px-5 py-3 text-right">购买</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <tr v-for="campaign in campaigns" :key="campaign.id" class="hover:bg-slate-50/70">
                        <td class="max-w-80 px-5 py-3 font-medium text-slate-900"><span class="block truncate" :title="campaign.name">{{ campaign.name }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ money(campaign.spend) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold" :class="campaign.roas !== null && campaign.roas >= 4 ? 'text-emerald-600' : 'text-orange-500'">{{ campaign.roas === null ? '—' : `${campaign.roas.toFixed(2)}×` }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ integer(campaign.impressions) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ integer(campaign.clicks) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ campaign.ctr === null ? '—' : `${campaign.ctr.toFixed(2)}%` }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ campaign.cpc === null ? '—' : money(campaign.cpc) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ campaign.frequency === null ? '—' : campaign.frequency.toFixed(2) }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right">{{ number(campaign.purchases) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="flex min-h-52 items-center justify-center px-6 py-12 text-sm text-slate-400">所选时间范围暂无广告系列数据</div>
    </section>
</template>
