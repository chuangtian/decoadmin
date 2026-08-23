<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import GoogleAdsWeeklyAnalytics from './GoogleAdsWeeklyAnalytics.vue';

type MetricKey = 'spend' | 'revenue' | 'roi' | 'conversions' | 'add_to_cart' | 'checkout' | 'add_to_cart_cost' | 'checkout_cost' | 'cpa';
type WeekOption = { key: string; label: string; date_from: string; date_to: string };
type WeeklyReport = {
    schema: 'google-ads-weekly-report-v1';
    available: boolean;
    source_table: string | null;
    source_synced_at: string | null;
    weeks: WeekOption[];
    selected_week: WeekOption | null;
    previous_week: WeekOption | null;
    values: Record<MetricKey, number> | null;
    previous_values: Record<MetricKey, number> | null;
    changes: Record<MetricKey, number | null> | null;
    history: Array<{
        key: string; label: string; date_from: string; date_to: string;
        values: Record<MetricKey, number>;
    }>;
    message: string | null;
};

const props = defineProps<{
    report: WeeklyReport | null;
    loading: boolean;
    currency: string;
}>();
const emit = defineEmits<{ select: [week: string] }>();

const picker = ref<HTMLElement | null>(null);
const pickerOpen = ref(false);
const cards: Array<{ key: MetricKey; label: string; format: 'money' | 'number' | 'ratio'; color: string; tint: string }> = [
    { key: 'spend', label: '周花费', format: 'money', color: '#2563eb', tint: 'bg-blue-50' },
    { key: 'revenue', label: '周收入', format: 'money', color: '#16a34a', tint: 'bg-emerald-50' },
    { key: 'roi', label: 'ROI', format: 'ratio', color: '#0f9f79', tint: 'bg-teal-50' },
    { key: 'conversions', label: '成交数', format: 'number', color: '#16a34a', tint: 'bg-emerald-50' },
    { key: 'add_to_cart', label: '加购数', format: 'number', color: '#7c3aed', tint: 'bg-violet-50' },
    { key: 'checkout', label: '结账数', format: 'number', color: '#d97706', tint: 'bg-amber-50' },
    { key: 'add_to_cart_cost', label: '单次加购成本', format: 'money', color: '#7c3aed', tint: 'bg-violet-50' },
    { key: 'checkout_cost', label: '单次结账成本', format: 'money', color: '#d97706', tint: 'bg-amber-50' },
];
const selectedLabel = computed(() => props.report?.selected_week?.label ?? '暂无可选周期');

function money(value: number): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol',
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(value);
    } catch {
        return `$${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
}
function valueText(key: MetricKey, format: 'money' | 'number' | 'ratio'): string {
    const value = props.report?.values?.[key];
    if (value === null || value === undefined) return '—';
    if (format === 'money') return money(value);
    if (format === 'ratio') return `${value.toFixed(2)}×`;
    return value.toLocaleString('en-US', { maximumFractionDigits: 0 });
}
function changeText(key: MetricKey): string {
    const value = props.report?.changes?.[key];
    if (value === null || value === undefined) return '暂无上周对比';
    return `${value > 0 ? '+' : ''}${value.toFixed(1)}% 环比`;
}
function changeClass(key: MetricKey): string {
    const value = props.report?.changes?.[key];
    if (value === null || value === undefined || value === 0) return 'text-slate-400';
    return value > 0 ? 'text-emerald-600' : 'text-rose-600';
}
function chooseWeek(week: string): void {
    pickerOpen.value = false;
    if (week !== props.report?.selected_week?.key) emit('select', week);
}
function closeOnOutsideClick(event: MouseEvent): void {
    if (picker.value && !picker.value.contains(event.target as Node)) pickerOpen.value = false;
}
function syncedAt(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('zh-CN', { hour12: false });
}

onMounted(() => document.addEventListener('click', closeOnOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutsideClick));
</script>

<template>
    <section class="relative space-y-5" aria-labelledby="google-weekly-title">
        <header class="rounded-3xl border border-blue-100 bg-gradient-to-r from-blue-50 via-white to-emerald-50 p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-sm font-bold text-white shadow-sm">17</span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600">Google Ads · 独立周口径</p>
                            <h2 id="google-weekly-title" class="mt-1 text-xl font-semibold text-slate-950">Google 周报</h2>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-slate-500">来自已同步的「{{ report?.source_table || 'Google周数据' }}」，不受顶部统计日期影响。</p>
                </div>

                <div v-if="report?.available" class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <span class="shrink-0 text-sm font-semibold text-slate-600">选择周</span>
                    <div ref="picker" class="relative w-full sm:w-[360px]" @keydown.esc="pickerOpen = false">
                        <button
                            type="button"
                            class="flex h-12 w-full items-center justify-between gap-4 rounded-xl border bg-white px-4 text-left text-sm font-semibold text-slate-800 shadow-sm outline-none transition focus:ring-2 focus:ring-blue-500"
                            :class="pickerOpen ? 'border-blue-500 ring-2 ring-blue-100' : 'border-slate-200 hover:border-blue-300'"
                            :aria-expanded="pickerOpen"
                            aria-haspopup="listbox"
                            @click.stop="pickerOpen = !pickerOpen"
                        >
                            <span class="truncate">{{ selectedLabel }}</span>
                            <svg class="h-4 w-4 shrink-0 text-blue-600 transition-transform" :class="pickerOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <div v-if="pickerOpen" class="absolute right-0 z-40 mt-2 max-h-80 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl" role="listbox" aria-label="Google Ads 周报周期">
                            <button
                                v-for="week in report.weeks"
                                :key="week.key"
                                type="button"
                                role="option"
                                class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm font-medium transition"
                                :class="week.key === report.selected_week?.key ? 'bg-blue-50 text-blue-700' : 'text-slate-700 hover:bg-slate-50'"
                                :aria-selected="week.key === report.selected_week?.key"
                                @click="chooseWeek(week.key)"
                            >
                                <span>{{ week.label }}</span>
                                <span v-if="week.key === report.selected_week?.key" class="text-xs font-bold text-blue-600">✓</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="report?.available" class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-blue-100/80 pt-4 text-xs">
                <p class="text-slate-500">当前：<span class="font-semibold text-slate-700">{{ report.selected_week?.label }}</span>
                    <template v-if="report.previous_week"> · 对比上周：<span class="font-semibold text-slate-700">{{ report.previous_week.label }}</span></template>
                </p>
                <p class="text-slate-400">数据同步于 {{ syncedAt(report.source_synced_at) }}</p>
            </div>
        </header>

        <div v-if="report?.available && report.values" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article v-for="card in cards" :key="card.key" class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                <div class="h-1" :style="{ backgroundColor: card.color }" />
                <div class="p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                        <i class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: card.color }" />
                    </div>
                    <p class="mt-4 text-3xl font-semibold tracking-tight tabular-nums" :style="{ color: card.color }">{{ valueText(card.key, card.format) }}</p>
                    <p class="mt-4 text-sm font-semibold tabular-nums" :class="changeClass(card.key)">{{ changeText(card.key) }}</p>
                </div>
            </article>
        </div>

        <GoogleAdsWeeklyAnalytics
            v-if="report?.available && report.values"
            :report="report"
            :currency="currency"
        />

        <div v-else-if="!loading" class="grid min-h-[360px] place-items-center rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-sm">
            <div>
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-lg text-slate-500">17</span>
                <p class="mt-4 text-base font-semibold text-slate-800">暂无可用周报数据</p>
                <p class="mt-2 text-sm text-slate-500">{{ report?.message || '正在等待 Google 周数据同步到本地数据库。' }}</p>
            </div>
        </div>

        <div v-if="loading" class="absolute inset-0 z-30 grid min-h-[360px] place-items-center rounded-3xl bg-white/75 text-sm font-semibold text-slate-500 backdrop-blur-[2px]">
            <span class="inline-flex items-center gap-3"><i class="h-5 w-5 animate-spin rounded-full border-2 border-blue-200 border-t-blue-600" />正在读取 Google 周数据…</span>
        </div>
    </section>
</template>
