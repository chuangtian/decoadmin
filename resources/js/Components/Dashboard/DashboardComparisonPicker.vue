<script setup lang="ts">
import { computed, ref, watch } from 'vue';

type ComparisonMode = 'none' | 'previous' | 'year' | 'year_weekday' | 'custom';

interface Period {
    from: string;
    to: string;
}

const props = defineProps<{
    comparison: { mode: ComparisonMode; label: string; period: Period | null };
    currentPeriod: Period;
}>();
const emit = defineEmits<{
    apply: [filters: { comparison: ComparisonMode; comparison_date_from?: string; comparison_date_to?: string }];
}>();

const open = ref(false);
const mode = ref<ComparisonMode>(props.comparison.mode || 'previous');
const customFrom = ref(props.comparison.mode === 'custom' ? (props.comparison.period?.from || '') : '');
const customTo = ref(props.comparison.mode === 'custom' ? (props.comparison.period?.to || '') : '');

const options = computed<{ value: ComparisonMode; label: string; description: string }[]>(() => [
    { value: 'none', label: '无对比', description: '仅显示当前周期数据' },
    { value: 'previous', label: props.currentPeriod.from === props.currentPeriod.to ? '昨天' : '上一周期', description: '与紧邻的同长度周期对比' },
    { value: 'year', label: '前一年', description: '与前一年相同日期对比' },
    { value: 'year_weekday', label: '前一年（匹配星期几）', description: '与 364 天前的同星期周期对比' },
    { value: 'custom', label: '自定义', description: '选择任意对比日期范围' },
]);

const buttonLabel = computed(() => props.comparison.label || '上一周期');

watch(() => props.comparison, (value) => {
    mode.value = value.mode;
    if (value.mode === 'custom') {
        customFrom.value = value.period?.from || '';
        customTo.value = value.period?.to || '';
    }
}, { deep: true });

function choose(next: ComparisonMode) {
    mode.value = next;
    if (next === 'custom') {
        if (!customFrom.value || !customTo.value) {
            customFrom.value = props.currentPeriod.from;
            customTo.value = props.currentPeriod.to;
        }
        return;
    }
    emit('apply', { comparison: next });
    open.value = false;
}

function applyCustom() {
    if (!customFrom.value || !customTo.value || customFrom.value > customTo.value) return;
    emit('apply', {
        comparison: 'custom',
        comparison_date_from: customFrom.value,
        comparison_date_to: customTo.value,
    });
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <button type="button" class="flex min-w-48 items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left shadow-sm transition hover:border-slate-300" @click="open = !open">
            <span>
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">对比周期</span>
                <strong class="mt-0.5 block text-sm text-slate-900">{{ buttonLabel }}</strong>
            </span>
            <span class="text-slate-400">⌄</span>
        </button>

        <div v-if="open" class="absolute right-0 top-[calc(100%+10px)] z-50 w-[min(420px,calc(100vw-32px))] rounded-3xl border border-slate-200 bg-white p-3 shadow-2xl shadow-slate-900/15">
            <button v-for="option in options" :key="option.value" type="button" class="flex w-full items-start justify-between gap-4 rounded-2xl px-4 py-3 text-left transition hover:bg-slate-50" :class="mode === option.value ? 'bg-emerald-50/70' : ''" @click="choose(option.value)">
                <span>
                    <strong class="block text-sm text-slate-900">{{ option.label }}</strong>
                    <span class="mt-0.5 block text-xs text-slate-500">{{ option.description }}</span>
                </span>
                <span v-if="mode === option.value" class="text-sm font-bold text-emerald-600">✓</span>
            </button>

            <div v-if="mode === 'custom'" class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-500">开始日期<input v-model="customFrom" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"></label>
                    <label class="text-xs font-semibold text-slate-500">结束日期<input v-model="customTo" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"></label>
                </div>
                <button type="button" class="mt-3 w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40" :disabled="!customFrom || !customTo || customFrom > customTo" @click="applyCustom">应用自定义对比</button>
            </div>

            <button type="button" class="mt-2 w-full rounded-xl px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-50" @click="open = false">取消</button>
        </div>
    </div>
</template>
