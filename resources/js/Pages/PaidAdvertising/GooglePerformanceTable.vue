<script setup lang="ts">
import { computed } from 'vue';

type PerformanceView = 'search-terms' | 'keywords';
type PerformanceRow = Record<string, string | number | null>;
type PerformanceTable = {
    schema: 'google-ads-performance-table-v1';
    view: PerformanceView;
    filters: { account: string | null; date_from: string; date_to: string; search: string; sort: string; direction: 'asc' | 'desc' };
    rows: PerformanceRow[];
    pagination: { page: number; per_page: number; total: number; last_page: number };
};
type Column = { key: string; label: string; format: 'text' | 'money' | 'ratio' | 'percent' | 'number'; sortable?: boolean };

const props = defineProps<{
    view: PerformanceView;
    table: PerformanceTable | null;
    loading: boolean;
    search: string;
    currency: string;
}>();
const emit = defineEmits<{
    (event: 'update:search', value: string): void;
    (event: 'sort', key: string): void;
    (event: 'page', page: number): void;
}>();

const columns = computed<Column[]>(() => props.view === 'keywords'
    ? [
        { key: 'keyword', label: '关键词', format: 'text', sortable: true },
        { key: 'match_type_label', label: '匹配类型', format: 'text' },
        { key: 'ad_group_name', label: '广告组', format: 'text' },
        { key: 'campaign_name', label: '广告系列', format: 'text', sortable: true },
        { key: 'status_label', label: '状态', format: 'text', sortable: true },
        { key: 'spend', label: '花费', format: 'money', sortable: true },
        { key: 'revenue', label: '收入', format: 'money', sortable: true },
        { key: 'roas', label: 'ROAS', format: 'ratio', sortable: true },
        { key: 'impressions', label: '曝光', format: 'number', sortable: true },
        { key: 'clicks', label: '点击', format: 'number', sortable: true },
        { key: 'ctr', label: 'CTR', format: 'percent', sortable: true },
        { key: 'cpc', label: 'CPC', format: 'money', sortable: true },
        { key: 'conversions', label: '转化', format: 'number', sortable: true },
    ]
    : [
        { key: 'search_term', label: '搜索词', format: 'text', sortable: true },
        { key: 'matched_keyword', label: '匹配关键词', format: 'text', sortable: true },
        { key: 'match_type_label', label: '匹配类型', format: 'text' },
        { key: 'status_label', label: '状态', format: 'text', sortable: true },
        { key: 'spend', label: '花费', format: 'money', sortable: true },
        { key: 'revenue', label: '收入', format: 'money', sortable: true },
        { key: 'roas', label: 'ROAS', format: 'ratio', sortable: true },
        { key: 'impressions', label: '曝光', format: 'number', sortable: true },
        { key: 'clicks', label: '点击', format: 'number', sortable: true },
        { key: 'ctr', label: 'CTR', format: 'percent', sortable: true },
        { key: 'cpc', label: 'CPC', format: 'money', sortable: true },
        { key: 'conversions', label: '转化', format: 'number', sortable: true },
    ]);
const title = computed(() => props.view === 'keywords' ? '关键词表现' : '搜索词报告');
const description = computed(() => props.view === 'keywords'
    ? '广告系列投放的关键词'
    : '仅展示有转化价值的搜索词');
const placeholder = computed(() => props.view === 'keywords'
    ? '输入关键词、广告组或广告系列，自动筛选…'
    : '输入搜索词或匹配关键词，自动筛选…');

function valueText(row: PerformanceRow, column: Column): string {
    const raw = row[column.key];
    if (raw === null || raw === undefined || raw === '') return '—';
    const value = Number(raw);
    if (column.format === 'text') return String(raw);
    if (column.format === 'money') {
        try {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: props.currency || 'USD', maximumFractionDigits: 2 }).format(value);
        } catch {
            return `$${value.toLocaleString('en-US', { maximumFractionDigits: 2 })}`;
        }
    }
    if (column.format === 'ratio') return `${value.toLocaleString('en-US', { maximumFractionDigits: 2 })}×`;
    if (column.format === 'percent') return `${value.toLocaleString('en-US', { maximumFractionDigits: 2 })}%`;
    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function sortKey(column: Column): string {
    if (column.key === 'status_label') return 'status';
    return column.key;
}
function sortMark(column: Column): string {
    if (!column.sortable) return '';
    if (props.table?.filters.sort !== sortKey(column)) return '↕';
    return props.table.filters.direction === 'asc' ? '↑' : '↓';
}
function statusClass(value: unknown): string {
    const label = String(value ?? '');
    if (['已添加', '启用', '效果最大化'].includes(label)) return 'bg-emerald-50 text-emerald-700';
    if (label === '已排除' || label === '已移除') return 'bg-rose-50 text-rose-700';
    if (label === '已暂停') return 'bg-amber-50 text-amber-700';
    return 'bg-slate-100 text-slate-600';
}
function onSearchInput(event: Event): void {
    emit('update:search', (event.target as HTMLInputElement).value);
}
</script>

<template>
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-6 sm:px-8 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="flex flex-wrap items-baseline gap-3">
                    <h2 class="text-xl font-semibold text-slate-950">{{ title }}</h2>
                    <span class="text-sm text-slate-500">{{ description }}，共 {{ table?.pagination.total ?? 0 }} 条</span>
                </div>
                <p class="mt-1 text-xs text-slate-400">数据来自本地 MySQL，随当前广告账户和统计日期切换</p>
            </div>
            <label class="relative block w-full lg:w-[420px]">
                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-400">⌕</span>
                <input
                    :value="search"
                    type="search"
                    :placeholder="placeholder"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-10 text-sm text-slate-800 outline-none ring-blue-500 placeholder:text-slate-400 focus:bg-white focus:ring-2"
                    @input="onSearchInput"
                />
                <button v-if="search" type="button" class="absolute inset-y-0 right-3 text-lg text-slate-400 hover:text-slate-700" aria-label="清空搜索" @click="emit('update:search', '')">×</button>
            </label>
        </div>

        <div class="relative overflow-x-auto">
            <div v-if="loading" class="absolute inset-0 z-20 flex min-h-64 items-center justify-center bg-white/75 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取本地数据…</div>
            <table class="w-full min-w-[1480px] border-collapse text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th v-for="column in columns" :key="column.key" class="whitespace-nowrap border-b border-slate-200 px-4 py-3 first:pl-7">
                            <button v-if="column.sortable" type="button" class="inline-flex items-center gap-1.5 hover:text-slate-900" @click="emit('sort', sortKey(column))">
                                {{ column.label }} <span class="text-blue-500">{{ sortMark(column) }}</span>
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <tr v-for="row in table?.rows ?? []" :key="String(row.row_key)" class="transition hover:bg-slate-50/80">
                        <td v-for="column in columns" :key="column.key" class="max-w-[300px] px-4 py-4 first:pl-7" :class="column.key === 'roas' || column.key === 'revenue' ? 'font-semibold text-emerald-600' : ''">
                            <span v-if="column.key === 'status_label'" class="inline-flex rounded-md px-2 py-1 text-xs font-semibold" :class="statusClass(row[column.key])">{{ valueText(row, column) }}</span>
                            <span v-else-if="column.format === 'text'" class="block truncate" :title="valueText(row, column)">{{ valueText(row, column) }}</span>
                            <span v-else class="whitespace-nowrap tabular-nums">{{ valueText(row, column) }}</span>
                        </td>
                    </tr>
                    <tr v-if="!loading && !(table?.rows.length)">
                        <td :colspan="columns.length" class="h-64 px-6 text-center text-sm text-slate-400">当前条件下暂无数据</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer class="flex flex-col gap-3 border-t border-slate-100 px-6 py-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <span>第 {{ table?.pagination.page ?? 1 }} / {{ table?.pagination.last_page ?? 1 }} 页 · 每页 {{ table?.pagination.per_page ?? 25 }} 条</span>
            <div class="flex items-center gap-2">
                <button type="button" class="h-9 rounded-lg border border-slate-200 px-4 font-medium text-slate-600 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || (table?.pagination.page ?? 1) <= 1" @click="emit('page', (table?.pagination.page ?? 1) - 1)">上一页</button>
                <button type="button" class="h-9 rounded-lg border border-slate-200 px-4 font-medium text-slate-600 transition hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || (table?.pagination.page ?? 1) >= (table?.pagination.last_page ?? 1)" @click="emit('page', (table?.pagination.page ?? 1) + 1)">下一页</button>
            </div>
        </footer>
    </section>
</template>
