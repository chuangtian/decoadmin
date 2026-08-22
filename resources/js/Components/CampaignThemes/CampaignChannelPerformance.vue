<script setup lang="ts">
interface ChannelPerformanceItem {
    key: string;
    name: string;
    ad_spend: number;
    attributed_sales: number;
    spend_share_percent: number;
    sales_share_percent: number;
    efficiency_roi: number | null;
}

const props = defineProps<{
    channels: ChannelPerformanceItem[];
    totalAdSpend: number;
    totalAttributedSales: number;
    available: boolean;
    complete: boolean;
    pending: boolean;
    message: string | null;
    failedChannels: string[];
    currency: string;
}>();

const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: props.currency || 'USD',
    currencyDisplay: 'narrowSymbol',
    notation: Math.abs(value) >= 100000 ? 'compact' : 'standard',
    maximumFractionDigits: 2,
}).format(value);

const channelDot = (key: string) => ({
    facebook: 'bg-blue-500',
    google: 'bg-sky-500',
    tiktok: 'bg-slate-700',
    bing: 'bg-cyan-500',
    criteo: 'bg-orange-500',
    other: 'bg-slate-400',
}[key] || 'bg-violet-500');
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-end lg:justify-between sm:px-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">各广告渠道 花费 vs 销售占比</h2>
                <p class="mt-1 text-xs leading-5 text-slate-400">数据源 MySQL · 广告平台周期快照 · 渠道 ROI = 销售占比 ÷ 花费占比</p>
            </div>
            <div v-if="available && channels.length" class="flex flex-wrap gap-2 text-xs tabular-nums text-slate-500">
                <span class="rounded-full bg-blue-50 px-3 py-1.5">总花费 {{ money(totalAdSpend) }}</span>
                <span class="rounded-full bg-emerald-50 px-3 py-1.5">归因销售 {{ money(totalAttributedSales) }}</span>
            </div>
        </header>

        <div v-if="available && channels.length" class="px-5 py-6 sm:px-6">
            <div v-if="pending || !complete" class="mb-5 rounded-2xl border px-4 py-3 text-sm" :class="pending ? 'border-blue-100 bg-blue-50 text-blue-700' : 'border-amber-100 bg-amber-50 text-amber-700'">
                {{ message || (failedChannels.length ? `部分平台同步失败：${failedChannels.join('、')}` : '广告平台数据尚未完整。') }}
            </div>
            <div class="overflow-x-auto pb-2">
                <div class="min-w-[720px]">
                    <div class="mb-5 flex justify-end gap-5 text-xs font-medium text-slate-500">
                        <span class="inline-flex items-center gap-2"><i class="h-3 w-7 rounded bg-blue-500/80" />花费占比</span>
                        <span class="inline-flex items-center gap-2"><i class="h-3 w-7 rounded bg-emerald-500/80" />销售占比</span>
                    </div>

                    <div class="space-y-3">
                        <div v-for="channel in channels" :key="channel.key" class="grid grid-cols-[110px_minmax(0,1fr)] items-center gap-4">
                            <span class="truncate text-sm font-medium text-slate-600" :title="channel.name">{{ channel.name }}</span>
                            <div class="relative space-y-1.5 border-l border-slate-300 py-0.5">
                                <div class="h-3 rounded-r bg-blue-500/80" :style="{ width: `${Math.max(channel.spend_share_percent, channel.spend_share_percent > 0 ? 0.8 : 0)}%` }">
                                </div>
                                <div class="h-3 rounded-r bg-emerald-500/80" :style="{ width: `${Math.max(channel.sales_share_percent, channel.sales_share_percent > 0 ? 0.8 : 0)}%` }" />
                                <span class="absolute top-[-2px] text-xs font-semibold tabular-nums text-blue-600" :style="{ left: `min(calc(${channel.spend_share_percent}% + 8px), calc(100% - 42px))` }">{{ channel.spend_share_percent.toFixed(1) }}%</span>
                                <span class="absolute bottom-[-2px] text-xs font-semibold tabular-nums text-emerald-600" :style="{ left: `min(calc(${channel.sales_share_percent}% + 8px), calc(100% - 42px))` }">{{ channel.sales_share_percent.toFixed(1) }}%</span>
                            </div>
                        </div>
                    </div>

                    <div class="ml-[126px] mt-4 grid grid-cols-6 border-t border-slate-200 pt-2 text-[11px] tabular-nums text-slate-400">
                        <span v-for="tick in [0, 20, 40, 60, 80, 100]" :key="tick" :class="tick === 100 ? 'text-right' : ''">{{ tick }}%</span>
                    </div>
                </div>
            </div>

            <div class="mt-5 overflow-x-auto">
                <div class="min-w-[680px]">
                    <div class="grid grid-cols-[minmax(180px,1fr)_150px_150px_120px] gap-4 border-b border-slate-200 pb-3 text-xs font-medium text-slate-500">
                        <span>渠道</span>
                        <span class="text-right">花费占比</span>
                        <span class="text-right">销售占比</span>
                        <span class="text-right" title="销售占比除以花费占比，用于比较渠道相对效率">渠道 ROI ⓘ</span>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div v-for="channel in channels" :key="`table-${channel.key}`" class="grid grid-cols-[minmax(180px,1fr)_150px_150px_120px] items-center gap-4 py-3 text-sm">
                            <span class="inline-flex min-w-0 items-center gap-2 font-medium text-slate-700">
                                <i class="h-2.5 w-2.5 shrink-0 rounded-full" :class="channelDot(channel.key)" />
                                <span class="truncate" :title="channel.name">{{ channel.name }}</span>
                            </span>
                            <span class="text-right font-semibold tabular-nums text-blue-600" :title="money(channel.ad_spend)">{{ channel.spend_share_percent.toFixed(1) }}%</span>
                            <span class="text-right font-semibold tabular-nums text-emerald-600" :title="money(channel.attributed_sales)">{{ channel.sales_share_percent.toFixed(1) }}%</span>
                            <span class="text-right font-semibold tabular-nums" :class="channel.efficiency_roi === null ? 'text-slate-400' : channel.efficiency_roi >= 1 ? 'text-emerald-600' : 'text-rose-600'">
                                {{ channel.efficiency_roi === null ? '--' : `${channel.efficiency_roi.toFixed(2)}×` }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="grid min-h-64 place-items-center px-6 py-12 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-xl text-slate-400">%</span>
                <p class="mt-4 font-semibold text-slate-700">{{ pending ? '广告渠道数据正在准备' : '该活动周期内暂无广告渠道归因数据' }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ message || '数据来自 MySQL 中保存的广告平台周期快照。' }}</p>
            </div>
        </div>
    </section>
</template>
