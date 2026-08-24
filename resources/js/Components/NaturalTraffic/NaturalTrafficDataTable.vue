<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    columns: string[];
    rows: Array<Record<string, unknown>>;
    labels?: Record<string, string>;
    pageSize?: number;
    emptyText?: string;
}>(), { labels: () => ({}), pageSize: 20, emptyText: '当前筛选暂无数据' });
const page = ref(1);
const totalPages = computed(() => Math.max(1, Math.ceil(props.rows.length / props.pageSize)));
const pageRows = computed(() => props.rows.slice((page.value - 1) * props.pageSize, page.value * props.pageSize));
watch(() => props.rows, () => { page.value = 1; });

function valueText(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (Array.isArray(value)) return value.map(valueText).filter((item) => item !== '—').join(', ') || '—';
    if (typeof value === 'object') {
        const object = value as Record<string, unknown>;
        if (object.link) return String(object.link);
        if (object.text) return String(object.text);
        if (object.name) return String(object.name);
        return JSON.stringify(value);
    }
    if (typeof value === 'number') return new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 }).format(value);
    return String(value);
}
function isLink(value: unknown): boolean { return /^https?:\/\//i.test(valueText(value)); }
</script>

<template>
    <div>
        <div class="overflow-x-auto rounded-2xl border border-slate-200">
            <table class="w-full min-w-max text-left text-sm">
                <thead class="bg-slate-50 text-[11px] font-black uppercase tracking-wider text-slate-500"><tr><th v-for="column in columns" :key="column" class="whitespace-nowrap px-4 py-3.5">{{ labels[column] || column }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-for="(row, index) in pageRows" :key="String(row.record_id ?? index)" class="hover:bg-slate-50/80">
                        <td v-for="column in columns" :key="column" class="max-w-[360px] whitespace-nowrap px-4 py-3 text-slate-700">
                            <a v-if="isLink(row[column])" :href="valueText(row[column])" target="_blank" rel="noopener noreferrer" class="font-bold text-blue-600 hover:underline">查看</a>
                            <span v-else class="block max-w-[340px] truncate" :title="valueText(row[column])">{{ valueText(row[column]) }}</span>
                        </td>
                    </tr>
                    <tr v-if="!pageRows.length"><td :colspan="Math.max(1, columns.length)" class="px-5 py-14 text-center text-sm font-semibold text-slate-400">{{ emptyText }}</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.length > pageSize" class="mt-3 flex items-center justify-between text-xs font-semibold text-slate-500">
            <span>共 {{ rows.length }} 条 · 第 {{ page }} / {{ totalPages }} 页</span>
            <div class="flex gap-2"><button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="page--">上一页</button><button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="page++">下一页</button></div>
        </div>
    </div>
</template>
