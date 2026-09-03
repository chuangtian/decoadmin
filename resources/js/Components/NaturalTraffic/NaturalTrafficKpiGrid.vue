<script setup lang="ts">
import type { NaturalTrafficKpi } from '../../types/naturalTraffic';

const props = withDefaults(defineProps<{ kpis: NaturalTrafficKpi[]; currency?: string; columns?: number; compact?: boolean }>(), { currency: 'USD', columns: 5, compact: false });
const accents = ['border-t-blue-500', 'border-t-violet-500', 'border-t-emerald-500', 'border-t-amber-400', 'border-t-rose-500', 'border-t-cyan-500'];

function format(kpi: NaturalTrafficKpi): string {
    if (kpi.format === 'currency') return new Intl.NumberFormat('en-US', { style: 'currency', currency: props.currency, maximumFractionDigits: 2 }).format(kpi.value);
    if (kpi.format === 'percent') return `${kpi.value.toFixed(2)}%`;
    if (kpi.format === 'compact') return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(kpi.value);
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 }).format(kpi.value);
}

function change(kpi: NaturalTrafficKpi): string {
    if (kpi.change === null) return '无可比基数';
    if (kpi.change === 0) return '与上期持平';
    return `${kpi.change > 0 ? '↑' : '↓'} ${Math.abs(kpi.change).toFixed(1)}%`;
}
</script>

<template>
    <div class="grid sm:grid-cols-2" :class="[columns >= 6 ? 'lg:grid-cols-3 2xl:grid-cols-6' : columns === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-5', compact ? 'gap-3' : 'gap-4']">
        <article v-for="(kpi, index) in kpis" :key="kpi.key" class="border border-slate-200 border-t-[3px] bg-white shadow-sm" :class="[accents[index % accents.length], compact ? 'rounded-[18px] p-4' : 'rounded-[22px] p-5']">
            <p class="text-xs font-black uppercase tracking-wide text-slate-500">{{ kpi.label }}</p>
            <p class="whitespace-nowrap font-black tabular-nums tracking-tight text-slate-950" :class="compact ? 'mt-2 text-xl' : 'mt-3 text-2xl'" :title="format(kpi)">{{ format(kpi) }}</p>
            <p class="text-xs font-bold" :class="[kpi.change === null || kpi.change === 0 ? 'text-slate-400' : kpi.change > 0 ? 'text-emerald-600' : 'text-rose-600', compact ? 'mt-1' : 'mt-2']">{{ change(kpi) }}</p>
        </article>
    </div>
</template>
