<script setup lang="ts">
import { computed } from 'vue';

interface DailyRow {
    date: string;
    spend: number;
    revenue: number;
    conversions: number;
    roas: number;
    impressions: number;
    clicks: number;
    ctr: number;
    cpc: number;
    cpa: number;
}

const props = defineProps<{ currency: string; rows: DailyRow[]; daysAvailable: number; daysExpected: number }>();
const coverageComplete = computed(() => props.daysAvailable >= props.daysExpected);
const maxRevenue = computed(() => Math.max(1, ...props.rows.map((row) => row.revenue)));
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2,
}).format(value || 0);
const number = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value || 0);
const formatDate = (date: string) => {
    const parsed = new Date(date + 'T12:00:00Z');
    return Number.isNaN(parsed.getTime()) ? date : new Intl.DateTimeFormat('zh-CN', {
        month: 'short', day: 'numeric', weekday: 'short', timeZone: 'UTC',
    }).format(parsed);
};
</script>

<template>
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Daily detail</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">每日数据明细</h2>
                <p class="mt-1 text-sm text-slate-500">固定展示 Criteo 系统时间（UTC）最近 7 天，不提供时间选择。</p>
            </div>
            <span class="w-fit rounded-full px-3 py-1.5 text-xs font-semibold" :class="coverageComplete ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">已覆盖 {{ daysAvailable }}/{{ daysExpected }} 天</span>
        </div>
        <div v-if="!coverageComplete" class="border-b border-amber-100 bg-amber-50 px-6 py-3 text-sm text-amber-800 sm:px-8">接口回填尚未覆盖完整 7 天；缺失日期按 0 展示，完成历史同步后会自动补齐。</div>
        <div class="overflow-x-auto">
            <table class="min-w-[1180px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400">
                    <tr>
                        <th class="px-6 py-3.5 font-semibold sm:px-8">日期</th><th class="px-5 py-3.5 text-right font-semibold">花费</th><th class="min-w-52 px-5 py-3.5 font-semibold">归因收入</th><th class="px-5 py-3.5 text-right font-semibold">ROAS</th><th class="px-5 py-3.5 text-right font-semibold">转化</th><th class="px-5 py-3.5 text-right font-semibold">CPA</th><th class="px-5 py-3.5 text-right font-semibold">曝光</th><th class="px-5 py-3.5 text-right font-semibold">点击</th><th class="px-5 py-3.5 text-right font-semibold">CTR</th><th class="px-6 py-3.5 text-right font-semibold sm:px-8">CPC</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <tr v-for="row in rows" :key="row.date" class="transition hover:bg-slate-50/70">
                        <td class="whitespace-nowrap px-6 py-4 font-medium text-slate-800 sm:px-8">{{ formatDate(row.date) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ money(row.spend) }}</td>
                        <td class="px-5 py-4"><div class="flex items-center gap-3"><div class="h-2 w-24 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: Math.max(0, Math.min(100, row.revenue / maxRevenue * 100)) + '%' }" /></div><span class="whitespace-nowrap font-medium tabular-nums">{{ money(row.revenue) }}</span></div></td>
                        <td class="whitespace-nowrap px-5 py-4 text-right font-semibold text-orange-600 tabular-nums">{{ row.roas.toFixed(2) }}×</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right font-semibold text-teal-700 tabular-nums">{{ number(row.conversions) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ money(row.cpa) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ number(row.impressions) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ number(row.clicks) }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ row.ctr.toFixed(2) }}%</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right tabular-nums sm:px-8">{{ money(row.cpc) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
