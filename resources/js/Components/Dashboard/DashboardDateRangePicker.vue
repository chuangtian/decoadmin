<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { todayDateKeyInTimezone } from '../../composables/useStoreDateTime';

type ComparisonMode = 'none' | 'previous' | 'year' | 'custom';
type SelectionTarget = 'current' | 'comparison';

interface Period {
    days: number;
    from: string;
    to: string;
    timezone: string;
}

interface Comparison {
    mode: ComparisonMode;
    label: string;
    period: Period | null;
}

interface DateFilters {
    days: number;
    date_from: string;
    date_to: string;
    comparison: ComparisonMode;
    comparison_date_from?: string;
    comparison_date_to?: string;
}

const props = defineProps<{ period: Period; comparison?: Comparison }>();
const emit = defineEmits<{ apply: [filters: DateFilters] }>();

const open = ref(false);
const rangeStart = ref(props.period.from);
const rangeEnd = ref(props.period.to);
const comparisonMode = ref<ComparisonMode>(props.comparison?.mode || 'none');
const comparisonStart = ref(props.comparison?.period?.from || '');
const comparisonEnd = ref(props.comparison?.period?.to || '');
const selectionTarget = ref<SelectionTarget>('current');
const pastDays = ref(props.period.days || 30);
const includeToday = ref(true);
const viewMonth = ref(startOfMonth(parseDate(props.period.from)));

const today = startOfDay(parseDate(todayDateKeyInTimezone(props.period.timezone)));
const secondMonth = computed(() => addMonths(viewMonth.value, 1));
const monthPanels = computed(() => [monthGrid(viewMonth.value), monthGrid(secondMonth.value)]);
const rangeLabel = computed(() => {
    if (props.period.days === 1 && props.period.from === props.period.to) return props.period.to === toDate(today) ? '今天' : '昨天';
    if ([7, 30, 90].includes(props.period.days) && props.period.to === toDate(today)) return `过去 ${props.period.days} 天`;
    if (props.period.from && props.period.to) return `${shortDate(props.period.from)} – ${shortDate(props.period.to)}`;
    return `过去 ${props.period.days} 天`;
});
const comparisonButtonLabel = computed(() => {
    if (!props.comparison || props.comparison.mode === 'none') return '未开启对比';
    if (!props.comparison.period) return props.comparison.label;
    return `${props.comparison.label} · ${shortDate(props.comparison.period.from)} – ${shortDate(props.comparison.period.to)}`;
});
const comparisonOptions: Array<{ value: ComparisonMode; label: string }> = [
    { value: 'none', label: '关闭' },
    { value: 'previous', label: '上一周期' },
    { value: 'year', label: '去年同期' },
    { value: 'custom', label: '自定义' },
];

watch([() => props.period, () => props.comparison], () => {
    rangeStart.value = props.period.from;
    rangeEnd.value = props.period.to;
    pastDays.value = props.period.days || 30;
    comparisonMode.value = props.comparison?.mode || 'none';
    comparisonStart.value = props.comparison?.period?.from || '';
    comparisonEnd.value = props.comparison?.period?.to || '';
    selectionTarget.value = 'current';
    viewMonth.value = startOfMonth(parseDate(props.period.from));
}, { deep: true });

function startOfDay(value: Date) { return new Date(value.getFullYear(), value.getMonth(), value.getDate()); }
function startOfMonth(value: Date) { return new Date(value.getFullYear(), value.getMonth(), 1); }
function addDays(value: Date, days: number) { const next = new Date(value); next.setDate(next.getDate() + days); return next; }
function addMonths(value: Date, months: number) { return new Date(value.getFullYear(), value.getMonth() + months, 1); }
function addYears(value: Date, years: number) { const next = new Date(value); next.setFullYear(next.getFullYear() + years); return next; }
function parseDate(value: string) { const [year, month, day] = value.split('-').map(Number); return new Date(year, month - 1, day); }
function toDate(value: Date) { return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`; }
function shortDate(value: string) { const date = parseDate(value); return `${date.getMonth() + 1}月${date.getDate()}日`; }
function monthTitle(value: Date) { return `${value.getFullYear()}年 ${value.getMonth() + 1}月`; }
function rangeDays(start: string, end: string) { return Math.round((parseDate(end).getTime() - parseDate(start).getTime()) / 86400000) + 1; }

function monthGrid(month: Date) {
    const leading = (month.getDay() + 6) % 7;
    const first = addDays(month, -leading);
    return {
        month,
        days: Array.from({ length: 42 }, (_, index) => {
            const date = addDays(first, index);
            return { value: toDate(date), day: date.getDate(), current: date.getMonth() === month.getMonth(), future: date > today };
        }),
    };
}

function isCurrentSelected(value: string) { return value === rangeStart.value || value === rangeEnd.value; }
function isCurrentInRange(value: string) { return Boolean(rangeStart.value && rangeEnd.value && value > rangeStart.value && value < rangeEnd.value); }
function isComparisonSelected(value: string) { return comparisonMode.value === 'custom' && (value === comparisonStart.value || value === comparisonEnd.value); }
function isComparisonInRange(value: string) { return comparisonMode.value === 'custom' && Boolean(comparisonStart.value && comparisonEnd.value && value > comparisonStart.value && value < comparisonEnd.value); }

function chooseDay(value: string, future: boolean) {
    if (future) return;
    if (selectionTarget.value === 'comparison') {
        if (!comparisonStart.value || comparisonEnd.value) {
            comparisonStart.value = value;
            comparisonEnd.value = '';
        } else if (value < comparisonStart.value) {
            comparisonEnd.value = comparisonStart.value;
            comparisonStart.value = value;
        } else {
            comparisonEnd.value = value;
        }
        return;
    }

    if (!rangeStart.value || rangeEnd.value) {
        rangeStart.value = value;
        rangeEnd.value = '';
    } else if (value < rangeStart.value) {
        rangeEnd.value = rangeStart.value;
        rangeStart.value = value;
    } else {
        rangeEnd.value = value;
    }
}

function setRange(start: Date, end: Date) {
    selectionTarget.value = 'current';
    rangeStart.value = toDate(start);
    rangeEnd.value = toDate(end);
    viewMonth.value = startOfMonth(start);
}

function preset(kind: 'today' | 'yesterday' | 'past' | 'month' | 'quarter' | 'bfcm') {
    if (kind === 'today') return setRange(today, today);
    if (kind === 'yesterday') return setRange(addDays(today, -1), addDays(today, -1));
    if (kind === 'month') return setRange(new Date(today.getFullYear(), today.getMonth(), 1), today);
    if (kind === 'quarter') return setRange(new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1), today);
    if (kind === 'bfcm') {
        const year = today.getMonth() < 10 ? today.getFullYear() - 1 : today.getFullYear();
        const thanksgiving = new Date(year, 10, 1);
        while (thanksgiving.getDay() !== 4) thanksgiving.setDate(thanksgiving.getDate() + 1);
        thanksgiving.setDate(thanksgiving.getDate() + 21);
        return setRange(addDays(thanksgiving, 1), addDays(thanksgiving, 4));
    }
    const end = includeToday.value ? today : addDays(today, -1);
    setRange(addDays(end, -(Math.max(1, pastDays.value) - 1)), end);
}

function chooseComparison(mode: ComparisonMode) {
    comparisonMode.value = mode;
    if (mode !== 'custom') {
        selectionTarget.value = 'current';
        return;
    }

    if (!comparisonStart.value || !comparisonEnd.value) {
        const end = addDays(parseDate(rangeStart.value), -1);
        const start = addDays(end, -(Math.max(1, rangeDays(rangeStart.value, rangeEnd.value || rangeStart.value)) - 1));
        comparisonStart.value = toDate(start);
        comparisonEnd.value = toDate(end);
    }
    selectionTarget.value = 'comparison';
    viewMonth.value = startOfMonth(parseDate(comparisonStart.value));
}

function selectCurrentRange() {
    selectionTarget.value = 'current';
    viewMonth.value = startOfMonth(parseDate(rangeStart.value));
}

function selectComparisonRange() {
    chooseComparison('custom');
}

function comparisonPreview() {
    if (comparisonMode.value === 'none' || !rangeStart.value || !rangeEnd.value) return null;
    if (comparisonMode.value === 'custom') {
        return comparisonStart.value && comparisonEnd.value
            ? { from: comparisonStart.value, to: comparisonEnd.value }
            : null;
    }
    if (comparisonMode.value === 'year') {
        return { from: toDate(addYears(parseDate(rangeStart.value), -1)), to: toDate(addYears(parseDate(rangeEnd.value), -1)) };
    }
    const end = addDays(parseDate(rangeStart.value), -1);
    const start = addDays(end, -(Math.max(1, rangeDays(rangeStart.value, rangeEnd.value)) - 1));
    return { from: toDate(start), to: toDate(end) };
}

function apply() {
    if (!rangeStart.value || !rangeEnd.value) return;
    if (comparisonMode.value === 'custom' && (!comparisonStart.value || !comparisonEnd.value)) return;
    const filters: DateFilters = {
        days: rangeDays(rangeStart.value, rangeEnd.value),
        date_from: rangeStart.value,
        date_to: rangeEnd.value,
        comparison: comparisonMode.value,
    };
    if (comparisonMode.value === 'custom') {
        filters.comparison_date_from = comparisonStart.value;
        filters.comparison_date_to = comparisonEnd.value;
    }
    emit('apply', filters);
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <button type="button" class="flex min-w-56 items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left shadow-sm transition hover:border-slate-300" @click="open = !open">
            <span>
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">统计时间</span>
                <strong class="mt-0.5 block text-sm text-slate-900">{{ rangeLabel }}</strong>
                <small v-if="comparison" class="mt-1 block max-w-64 truncate text-[11px] font-medium text-sky-600">对比：{{ comparisonButtonLabel }}</small>
            </span>
            <span class="text-slate-400">⌄</span>
        </button>

        <div v-if="open" class="absolute right-0 top-[calc(100%+10px)] z-40 w-[min(1040px,calc(100vw-32px))] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/15">
            <div class="grid lg:grid-cols-[250px_1fr]">
                <aside class="border-b border-slate-100 bg-slate-50 p-3 lg:border-b-0 lg:border-r">
                    <button v-for="item in [
                        ['today','今天'],['yesterday','昨天'],['month','本月至今'],['quarter','本季度至今'],['bfcm','黑色星期五和网络星期一'],
                    ]" :key="item[0]" type="button" class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-700 hover:bg-white" @click="preset(item[0] as 'today' | 'yesterday' | 'month' | 'quarter' | 'bfcm')">{{ item[1] }}</button>
                    <div class="mt-2 border-t border-slate-200 pt-3">
                        <p class="px-3 text-xs font-semibold text-slate-400">过去</p>
                        <div class="mt-2 flex gap-2 px-3">
                            <input v-model.number="pastDays" type="number" min="1" max="366" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            <button type="button" class="rounded-xl bg-slate-950 px-3 text-sm font-semibold text-white" @click="preset('past')">天</button>
                        </div>
                        <label class="mt-3 flex items-center gap-2 px-3 text-sm font-medium text-slate-600"><input v-model="includeToday" type="checkbox" class="rounded border-slate-300 text-emerald-600">包含今天</label>
                    </div>
                    <button type="button" class="mt-3 w-full rounded-xl border-t border-slate-200 px-3 pt-4 pb-2 text-left text-sm font-semibold" :class="selectionTarget === 'current' ? 'text-emerald-700' : 'text-slate-700'" @click="selectCurrentRange">自定义统计范围</button>

                    <div v-if="comparison" class="mt-2 border-t border-slate-200 px-3 pt-4">
                        <div class="flex items-center justify-between"><p class="text-sm font-semibold text-slate-800">环比</p><span class="text-[11px] text-slate-400">选择对比周期</span></div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button v-for="option in comparisonOptions" :key="option.value" type="button" class="rounded-xl border px-2 py-2 text-xs font-semibold transition" :class="comparisonMode === option.value ? 'border-sky-400 bg-sky-50 text-sky-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'" @click="chooseComparison(option.value)">{{ option.label }}</button>
                        </div>
                        <button v-if="comparisonMode === 'custom'" type="button" class="mt-3 w-full rounded-xl px-3 py-2 text-left text-xs font-semibold" :class="selectionTarget === 'comparison' ? 'bg-sky-100 text-sky-700' : 'bg-white text-slate-600'" @click="selectComparisonRange">
                            选择对比日期
                            <span v-if="comparisonStart && comparisonEnd" class="mt-1 block font-normal">{{ shortDate(comparisonStart) }} – {{ shortDate(comparisonEnd) }}</span>
                        </button>
                    </div>
                </aside>

                <div class="p-4 sm:p-6">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-500 hover:bg-slate-100" @click="viewMonth = addMonths(viewMonth, -1)">‹</button>
                        <div class="min-w-0 text-center">
                            <p class="text-xs font-semibold" :class="selectionTarget === 'comparison' ? 'text-sky-600' : 'text-emerald-600'">{{ selectionTarget === 'comparison' ? '正在选择对比周期' : '正在选择统计周期' }}</p>
                            <p class="mt-1 truncate text-sm font-semibold text-slate-900">{{ shortDate(rangeStart) }}{{ rangeEnd ? ` – ${shortDate(rangeEnd)}` : ' – 请选择结束日期' }}</p>
                            <p v-if="comparisonPreview()" class="mt-1 truncate text-xs font-medium text-sky-600">对比 {{ shortDate(comparisonPreview()!.from) }} – {{ shortDate(comparisonPreview()!.to) }}</p>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-500 hover:bg-slate-100" @click="viewMonth = addMonths(viewMonth, 1)">›</button>
                    </div>
                    <div class="mb-3 flex flex-wrap justify-center gap-4 text-xs font-medium text-slate-500">
                        <span class="flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-slate-950" />统计周期</span>
                        <span v-if="comparisonMode !== 'none'" class="flex items-center gap-2"><i class="h-2.5 w-2.5 rounded-full bg-sky-500" />对比周期</span>
                    </div>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div v-for="panel in monthPanels" :key="panel.month.toISOString()">
                            <h3 class="mb-3 text-center text-sm font-semibold text-slate-900">{{ monthTitle(panel.month) }}</h3>
                            <div class="grid grid-cols-7 text-center text-xs font-semibold text-slate-400"><span v-for="day in ['一','二','三','四','五','六','日']" :key="day" class="py-2">{{ day }}</span></div>
                            <div class="grid grid-cols-7">
                                <button v-for="day in panel.days" :key="day.value" type="button" class="relative h-10 text-sm transition" :disabled="day.future" :class="[
                                    !day.current ? 'text-slate-300' : 'text-slate-700',
                                    day.future ? 'cursor-not-allowed opacity-30' : 'hover:bg-slate-100',
                                    isComparisonInRange(day.value) ? 'bg-sky-50 text-sky-800' : '',
                                    isComparisonSelected(day.value) ? 'rounded-xl bg-sky-500 font-semibold text-white hover:bg-sky-500' : '',
                                    isCurrentInRange(day.value) ? 'bg-emerald-50 text-emerald-800' : '',
                                    isCurrentSelected(day.value) ? 'rounded-xl bg-slate-950 font-semibold text-white hover:bg-slate-950' : '',
                                ]" @click="chooseDay(day.value, day.future)">{{ day.day }}</button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600" @click="open = false">取消</button>
                        <button type="button" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40" :disabled="!rangeStart || !rangeEnd || (comparisonMode === 'custom' && (!comparisonStart || !comparisonEnd))" @click="apply">应用</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
