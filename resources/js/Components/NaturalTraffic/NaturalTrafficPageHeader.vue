<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import type { NaturalTrafficDashboardBase } from '../../types/naturalTraffic';

const props = withDefaults(defineProps<{
    dashboard: NaturalTrafficDashboardBase;
    store: { id: number; name: string };
    configured: boolean;
    canSync: boolean;
    routePath: string;
    refreshPath: string;
    activeTab: string;
    accent?: 'blue' | 'violet' | 'emerald' | 'amber';
    selectLabel?: string;
    selectParam?: string;
    selectValue?: string;
    selectOptions?: string[];
    compact?: boolean;
}>(), {
    accent: 'blue',
    selectLabel: '',
    selectParam: '',
    selectValue: '',
    selectOptions: () => [],
    compact: false,
});

const emit = defineEmits<{ tab: [key: string] }>();
const syncing = ref(false);
const filters = reactive({
    date_from: props.dashboard.filters.date_from,
    date_to: props.dashboard.filters.date_to,
    comparison: props.dashboard.filters.comparison,
    select: props.selectValue,
});
const accentClasses = {
    blue: { dot: 'bg-blue-500', text: 'text-blue-700', tab: 'border-blue-500 text-blue-700 bg-blue-50/70' },
    violet: { dot: 'bg-violet-500', text: 'text-violet-700', tab: 'border-violet-500 text-violet-700 bg-violet-50/70' },
    emerald: { dot: 'bg-emerald-500', text: 'text-emerald-700', tab: 'border-emerald-500 text-emerald-700 bg-emerald-50/70' },
    amber: { dot: 'bg-amber-500', text: 'text-amber-700', tab: 'border-amber-500 text-amber-700 bg-amber-50/70' },
};

function applyFilters(): void {
    const query: Record<string, string> = {
        date_from: filters.date_from,
        date_to: filters.date_to,
        comparison: filters.comparison,
    };
    if (props.selectParam && filters.select) query[props.selectParam] = filters.select;
    router.get(props.routePath, query, { preserveScroll: true, preserveState: true });
}

function refresh(): void {
    if (!props.canSync || !props.configured || syncing.value) return;
    syncing.value = true;
    router.post(props.refreshPath, {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}

function formatDateTime(value: string | null): string {
    if (!value) return '尚未同步';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('zh-CN', {
        month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(date);
}
</script>

<template>
    <section class="overflow-hidden border border-slate-200 bg-white shadow-sm" :class="compact ? 'rounded-[22px]' : 'rounded-[28px]'">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between" :class="compact ? 'gap-3 px-5 py-4 lg:px-6' : 'gap-6 px-6 py-6 lg:px-8'">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-xs font-black uppercase tracking-[0.2em]" :class="[accentClasses[accent].text, compact ? 'mb-1' : 'mb-2']">
                    <span class="h-2 w-2 rounded-full" :class="accentClasses[accent].dot"></span>
                    Organic channel
                </div>
                <h1 class="font-black tracking-tight text-slate-950" :class="compact ? 'text-2xl' : 'text-3xl'">{{ dashboard.title }}</h1>
                <p class="max-w-3xl text-sm text-slate-500" :class="compact ? 'mt-1 leading-5' : 'mt-2 leading-6'">{{ store.name }} · {{ dashboard.description }}</p>
            </div>
            <div class="flex flex-wrap items-center" :class="compact ? 'gap-2' : 'gap-3'">
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50" :class="compact ? 'px-3 py-2' : 'px-4 py-2.5'">
                    <div class="flex items-center gap-2 text-xs font-bold text-emerald-800"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>当前项目 MySQL</div>
                    <p class="mt-1 text-[11px] tabular-nums text-emerald-700/70">{{ dashboard.source.table_count }} 张表 · {{ dashboard.source.record_count }} 条 · {{ formatDateTime(dashboard.source.last_synced_at) }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-2xl bg-slate-950 text-sm font-black text-white shadow-lg shadow-slate-950/10 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                    :class="compact ? 'px-4 py-2.5' : 'px-5 py-3'"
                    :disabled="!canSync || !configured || syncing"
                    @click="refresh"
                >
                    {{ syncing ? '同步中…' : configured ? '同步数据' : '数据源未配置' }}
                </button>
            </div>
        </div>

        <form class="grid gap-3 border-t border-slate-100 bg-slate-50/70 sm:grid-cols-2 lg:grid-cols-[170px_170px_190px_minmax(0,180px)_auto]" :class="compact ? 'px-5 py-3 lg:px-6' : 'px-6 py-4 lg:px-8'" @submit.prevent="applyFilters">
            <label class="text-xs font-bold text-slate-500">开始日期<input v-model="filters.date_from" type="date" class="w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800" :class="compact ? 'mt-1 h-9' : 'mt-1.5 py-2.5'" /></label>
            <label class="text-xs font-bold text-slate-500">结束日期<input v-model="filters.date_to" type="date" class="w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800" :class="compact ? 'mt-1 h-9' : 'mt-1.5 py-2.5'" /></label>
            <label class="text-xs font-bold text-slate-500">数据对比<select v-model="filters.comparison" class="w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800" :class="compact ? 'mt-1 h-9' : 'mt-1.5 py-2.5'"><option value="previous">对比上一等长周期</option><option value="none">无对比</option></select></label>
            <label v-if="selectParam" class="text-xs font-bold text-slate-500">{{ selectLabel }}<select v-model="filters.select" class="w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800" :class="compact ? 'mt-1 h-9' : 'mt-1.5 py-2.5'"><option value="">全部</option><option v-for="option in selectOptions" :key="option" :value="option">{{ option }}</option></select></label>
            <div class="flex items-end"><button type="submit" class="w-full rounded-xl border border-slate-300 bg-white px-5 text-sm font-black text-slate-800 shadow-sm hover:border-slate-400" :class="compact ? 'h-9' : 'py-2.5'">应用筛选</button></div>
        </form>

        <div class="flex gap-1 overflow-x-auto border-t border-slate-100" :class="compact ? 'px-5 pt-1 lg:px-6' : 'px-5 pt-3 lg:px-8'">
            <button
                v-for="tab in dashboard.tabs"
                :key="tab.key"
                type="button"
                class="whitespace-nowrap border-b-2 px-4 text-sm font-black transition"
                :class="[activeTab === tab.key ? accentClasses[accent].tab : 'border-transparent text-slate-500 hover:text-slate-800', compact ? 'py-2' : 'py-3']"
                @click="emit('tab', tab.key)"
            >{{ tab.label }}</button>
        </div>
    </section>
</template>
