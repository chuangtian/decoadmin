<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    columns: string[];
    rows: Array<Record<string, unknown>>;
    labels?: Record<string, string>;
    pageSize?: number;
    emptyText?: string;
    compact?: boolean;
    readable?: boolean;
}>(), { labels: () => ({}), pageSize: 20, emptyText: '当前筛选暂无数据', compact: false, readable: true });
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
    <div class="min-w-0 max-w-full">
        <div class="max-w-full overflow-x-auto border border-slate-200" :class="compact ? 'rounded-xl' : 'rounded-2xl'">
            <table class="w-full min-w-max text-left" :class="readable ? 'text-[14px] leading-5' : compact ? 'text-xs' : 'text-sm'">
                <thead class="bg-slate-50 font-bold text-slate-500" :class="readable ? 'text-[14px] leading-5' : 'text-xs uppercase tracking-wider'"><tr><th v-for="column in columns" :key="column" class="whitespace-nowrap" :class="compact ? 'px-3 py-2.5' : 'px-4 py-3.5'">{{ labels[column] || column }}</th><th v-if="$slots.actions" class="px-3 py-2.5">操作</th></tr></thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-for="(row, index) in pageRows" :key="String(row.record_id ?? index)" class="hover:bg-slate-50/80">
                        <td v-for="column in columns" :key="column" class="max-w-[360px] whitespace-nowrap text-slate-700" :class="compact ? 'px-3 py-2' : 'px-4 py-3'">
                            <a v-if="isLink(row[column])" :href="valueText(row[column])" target="_blank" rel="noopener noreferrer" class="font-bold text-blue-600 hover:underline">查看</a>
                            <span v-else class="block max-w-[340px] truncate" :title="valueText(row[column])">{{ valueText(row[column]) }}</span>
                        </td>
                        <td v-if="$slots.actions" class="whitespace-nowrap px-3 py-2"><slot name="actions" :row="row" /></td>
                    </tr>
                    <tr v-if="!pageRows.length"><td :colspan="Math.max(1, columns.length + ($slots.actions ? 1 : 0))" class="px-5 text-center text-sm font-semibold text-slate-400" :class="compact ? 'py-8' : 'py-14'">{{ emptyText }}</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.length > pageSize" class="flex items-center justify-between font-semibold text-slate-500" :class="[compact ? 'mt-2' : 'mt-3', readable ? 'text-[14px] leading-5' : 'text-xs']">
            <span>共 {{ rows.length }} 条 · 第 {{ page }} / {{ totalPages }} 页</span>
            <div class="flex gap-2"><button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="page--">上一页</button><button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="page++">下一页</button></div>
        </div>
    </div>
</template>
