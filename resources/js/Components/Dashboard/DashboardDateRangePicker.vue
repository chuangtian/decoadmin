<script setup lang="ts">
import { computed, ref } from 'vue';

interface Period {
    days: number;
    from: string;
    to: string;
}

const props = defineProps<{ period: Period }>();
const emit = defineEmits<{ apply: [filters: { days: number; date_from: string; date_to: string }] }>();

const open = ref(false);
const rangeStart = ref(props.period.from);
const rangeEnd = ref(props.period.to);
const pastDays = ref(props.period.days || 30);
const includeToday = ref(true);
const viewMonth = ref(startOfMonth(parseDate(props.period.from)));

const today = startOfDay(parseDate(props.period.to));
const secondMonth = computed(() => addMonths(viewMonth.value, 1));
const monthPanels = computed(() => [monthGrid(viewMonth.value), monthGrid(secondMonth.value)]);
const rangeLabel = computed(() => {
    if (props.period.days === 1 && props.period.from === props.period.to) return props.period.to === toDate(today) ? '今天' : '昨天';
    if ([7, 30, 90].includes(props.period.days) && props.period.to === toDate(today)) return `过去 ${props.period.days} 天`;
    if (props.period.from && props.period.to) return `${shortDate(props.period.from)} – ${shortDate(props.period.to)}`;
    return `过去 ${props.period.days} 天`;
});

function startOfDay(value: Date) { return new Date(value.getFullYear(), value.getMonth(), value.getDate()); }
function startOfMonth(value: Date) { return new Date(value.getFullYear(), value.getMonth(), 1); }
function addDays(value: Date, days: number) { const next = new Date(value); next.setDate(next.getDate() + days); return next; }
function addMonths(value: Date, months: number) { return new Date(value.getFullYear(), value.getMonth() + months, 1); }
function parseDate(value: string) { const [year, month, day] = value.split('-').map(Number); return new Date(year, month - 1, day); }
function toDate(value: Date) { return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`; }
function shortDate(value: string) { const date = parseDate(value); return `${date.getMonth() + 1}月${date.getDate()}日`; }
function monthTitle(value: Date) { return `${value.getFullYear()}年 ${value.getMonth() + 1}月`; }

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

function isSelected(value: string) { return value === rangeStart.value || value === rangeEnd.value; }
function isInRange(value: string) { return Boolean(rangeStart.value && rangeEnd.value && value > rangeStart.value && value < rangeEnd.value); }

function chooseDay(value: string, future: boolean) {
    if (future) return;
    if (!rangeStart.value || rangeEnd.value) {
        rangeStart.value = value;
        rangeEnd.value = '';
        return;
    }
    if (value < rangeStart.value) {
        rangeEnd.value = rangeStart.value;
        rangeStart.value = value;
    } else {
        rangeEnd.value = value;
    }
}

function setRange(start: Date, end: Date) {
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

function apply() {
    if (!rangeStart.value || !rangeEnd.value) return;
    const days = Math.round((parseDate(rangeEnd.value).getTime() - parseDate(rangeStart.value).getTime()) / 86400000) + 1;
    emit('apply', { days, date_from: rangeStart.value, date_to: rangeEnd.value });
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <button type="button" class="flex min-w-44 items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left shadow-sm transition hover:border-slate-300" @click="open = !open">
            <span><span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">统计时间</span><strong class="mt-0.5 block text-sm text-slate-900">{{ rangeLabel }}</strong></span>
            <span class="text-slate-400">⌄</span>
        </button>

        <div v-if="open" class="absolute right-0 top-[calc(100%+10px)] z-40 w-[min(920px,calc(100vw-32px))] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/15">
            <div class="grid lg:grid-cols-[210px_1fr]">
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
                    <p class="mt-4 border-t border-slate-200 px-3 pt-3 text-sm font-semibold text-slate-700">自定义范围</p>
                </aside>

                <div class="p-4 sm:p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-xl text-slate-500 hover:bg-slate-100" @click="viewMonth = addMonths(viewMonth, -1)">‹</button>
                        <p class="text-sm font-semibold text-slate-900">{{ shortDate(rangeStart) }}{{ rangeEnd ? ` – ${shortDate(rangeEnd)}` : ' – 请选择结束日期' }}</p>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-xl text-slate-500 hover:bg-slate-100" @click="viewMonth = addMonths(viewMonth, 1)">›</button>
                    </div>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div v-for="panel in monthPanels" :key="panel.month.toISOString()">
                            <h3 class="mb-3 text-center text-sm font-semibold text-slate-900">{{ monthTitle(panel.month) }}</h3>
                            <div class="grid grid-cols-7 text-center text-xs font-semibold text-slate-400"><span v-for="day in ['一','二','三','四','五','六','日']" :key="day" class="py-2">{{ day }}</span></div>
                            <div class="grid grid-cols-7">
                                <button v-for="day in panel.days" :key="day.value" type="button" class="relative h-10 text-sm transition" :disabled="day.future" :class="[
                                    !day.current ? 'text-slate-300' : 'text-slate-700',
                                    day.future ? 'cursor-not-allowed opacity-30' : 'hover:bg-slate-100',
                                    isInRange(day.value) ? 'bg-emerald-50 text-emerald-800' : '',
                                    isSelected(day.value) ? 'rounded-xl bg-slate-950 font-semibold text-white hover:bg-slate-950' : '',
                                ]" @click="chooseDay(day.value, day.future)">{{ day.day }}</button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600" @click="open = false">取消</button>
                        <button type="button" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40" :disabled="!rangeStart || !rangeEnd" @click="apply">应用</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
