<script setup lang="ts">
import { computed } from 'vue';

type Judgment = 'reusable' | 'scalable' | 'underperforming' | 'insufficient_data';

interface PerformanceItem {
    id: number;
    name: string;
    starts_on: string | null;
    ends_on: string | null;
    gmv: number;
    roi: number | null;
    conversion_rate_percent: number | null;
    store_visits: number | null;
    judgment: Judgment;
}

const props = defineProps<{ currency: string; activities: PerformanceItem[] }>();

const rankedActivities = computed(() => [...props.activities].sort((left, right) => {
    if (left.roi === null && right.roi === null) return right.id - left.id;
    if (left.roi === null) return 1;
    if (right.roi === null) return -1;
    return right.roi - left.roi || right.id - left.id;
}));

const judgmentMeta: Record<Judgment, { label: string; classes: string }> = {
    reusable: { label: '可复用', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    scalable: { label: '放量适销', classes: 'bg-orange-50 text-orange-700 ring-orange-200' },
    underperforming: { label: '承接不足', classes: 'bg-rose-50 text-rose-700 ring-rose-200' },
    insufficient_data: { label: '数据不足', classes: 'bg-slate-100 text-slate-500 ring-slate-200' },
};

const compactMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', notation: 'compact', maximumFractionDigits: 2,
}).format(value);
const integer = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
const shortDate = (date: string | null) => date ? date.slice(5).replace('-', '/') : '--';
const roiClass = (roi: number | null) => roi === null ? 'text-slate-400' : roi >= 6 ? 'text-emerald-600' : roi >= 5 ? 'text-orange-500' : 'text-rose-500';
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
            <h2 class="text-lg font-semibold text-slate-950">活动质量排行（已完成活动）</h2>
            <p class="mt-1 text-xs text-slate-400">GMV &gt; 0 的活动，按 ROI 从高到低排列</p>
        </header>

        <div v-if="rankedActivities.length" class="overflow-x-auto">
            <table class="min-w-[940px] w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <th class="w-20 px-6 py-4">#</th>
                        <th class="px-4 py-4">活动</th>
                        <th class="px-4 py-4">周期</th>
                        <th class="px-4 py-4 text-right">GMV</th>
                        <th class="px-4 py-4 text-right">ROI</th>
                        <th class="px-4 py-4 text-right">CVR</th>
                        <th class="px-4 py-4 text-right">店铺访问</th>
                        <th class="px-6 py-4 text-center">判断</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(activity, index) in rankedActivities" :key="activity.id" class="border-b border-slate-100 transition last:border-0 hover:bg-slate-50/80">
                        <td class="px-6 py-4">
                            <span class="grid h-8 w-8 place-items-center rounded-full text-sm font-semibold" :class="index < 3 ? 'bg-orange-500 text-white' : 'bg-slate-200 text-slate-600'">{{ index + 1 }}</span>
                        </td>
                        <td class="max-w-64 px-4 py-4 font-semibold text-slate-900"><span class="block truncate" :title="activity.name">{{ activity.name }}</span></td>
                        <td class="whitespace-nowrap px-4 py-4 text-sm tabular-nums text-slate-500">{{ shortDate(activity.starts_on) }}–{{ shortDate(activity.ends_on) }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-right font-medium tabular-nums text-slate-800">{{ compactMoney(activity.gmv) }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-base font-semibold tabular-nums" :class="roiClass(activity.roi)">{{ activity.roi === null ? '--' : activity.roi.toFixed(2) }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-slate-700">{{ activity.conversion_rate_percent === null ? '--' : `${activity.conversion_rate_percent.toFixed(3)}%` }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-slate-700">{{ activity.store_visits === null ? '--' : integer(activity.store_visits) }}</td>
                        <td class="px-6 py-4 text-center"><span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="judgmentMeta[activity.judgment].classes">{{ judgmentMeta[activity.judgment].label }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="px-6 py-16 text-center text-sm text-slate-400">暂无已完成活动数据。</p>
    </section>
</template>
