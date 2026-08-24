<script setup lang="ts">
import { computed, ref } from 'vue';

interface CampaignShareItem {
    id: string;
    name: string;
    spend: number;
    share: number;
}

const props = defineProps<{
    currency: string;
    breakdown: { available: boolean; total_spend: number; items: CampaignShareItem[] };
}>();
const colors = ['#f97316', '#e11d48', '#2563eb', '#10b981', '#8b5cf6', '#f59e0b'];
const hoveredIndex = ref<number | null>(null);
const segments = computed(() => {
    let offset = 0;
    return props.breakdown.items.map((item, index) => {
        const segment = { ...item, color: colors[index % colors.length], offset };
        offset += item.share;
        return segment;
    });
});
const activeIndex = computed(() => hoveredIndex.value ?? 0);
const active = computed(() => segments.value[activeIndex.value] ?? null);
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2,
}).format(value || 0);
</script>

<template>
    <section class="h-full min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-600">Campaign mix</p>
            <h2 class="mt-2 text-lg font-semibold text-slate-950">广告系列花费占比</h2>
            <p class="mt-1 text-xs text-slate-400">最近 7 天 · 最多展示前 5 个广告系列</p>
        </header>
        <div v-if="breakdown.available" class="grid min-h-[320px] gap-4 p-6 sm:grid-cols-[minmax(180px,240px)_minmax(0,1fr)] sm:items-center">
            <div class="relative mx-auto aspect-square w-full max-w-56">
                <svg viewBox="0 0 220 220" class="h-full w-full -rotate-90" role="img" aria-label="Criteo 广告系列花费占比环形图">
                    <title>Criteo 广告系列花费占比</title>
                    <circle cx="110" cy="110" r="78" fill="none" stroke="#f1f5f9" stroke-width="34" />
                    <circle
                        v-for="(item, index) in segments"
                        :key="item.id"
                        cx="110" cy="110" r="78" fill="none" :stroke="item.color" stroke-width="34" pathLength="100"
                        :stroke-dasharray="item.share + ' ' + (100 - item.share)" :stroke-dashoffset="-item.offset"
                        class="cursor-pointer transition-opacity" :class="activeIndex === index ? 'opacity-100' : 'opacity-80'"
                        tabindex="0" :aria-label="item.name + '，花费占比 ' + item.share.toFixed(2) + '%'"
                        @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null"
                    />
                </svg>
                <div class="pointer-events-none absolute inset-0 grid place-content-center text-center">
                    <template v-if="active">
                        <p class="max-w-28 truncate text-xs font-medium text-slate-500">{{ active.name }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-950">{{ active.share.toFixed(1) }}%</p>
                        <p class="mt-1 text-xs tabular-nums text-slate-400">{{ money(active.spend) }}</p>
                    </template>
                </div>
            </div>
            <div class="min-w-0 space-y-2.5">
                <button v-for="(item, index) in segments" :key="'legend-' + item.id" type="button" class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-slate-50" @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null">
                    <i class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: item.color }" />
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700" :title="item.name">{{ item.name }}</span>
                    <span class="shrink-0 text-xs font-semibold tabular-nums text-slate-500">{{ item.share.toFixed(1) }}%</span>
                </button>
                <div class="mt-3 flex items-center justify-between border-t border-slate-100 px-3 pt-4 text-xs text-slate-400">
                    <span>总花费</span><strong class="text-sm font-semibold tabular-nums text-slate-700">{{ money(breakdown.total_spend) }}</strong>
                </div>
            </div>
        </div>
        <div v-else class="grid min-h-[320px] place-items-center p-8 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 3v9h9" /></svg>
                </span>
                <p class="mt-4 text-sm font-semibold text-slate-700">等待广告系列数据</p>
                <p class="mt-1 text-xs text-slate-400">下次同步会自动获取 Campaign 维度。</p>
            </div>
        </div>
    </section>
</template>
