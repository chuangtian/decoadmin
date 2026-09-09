<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { CampaignPlanningActivity, CampaignThemePlanning } from './planningTypes';

const props = defineProps<{ planning: CampaignThemePlanning }>();

const dayMs = 86_400_000;
const monthNames = ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'];
const weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

const parseDate = (value: string) => new Date(`${value}T00:00:00Z`);
const isoDate = (value: Date) => value.toISOString().slice(0, 10);
const addDays = (value: Date, days: number) => new Date(value.getTime() + days * dayMs);
const diffDays = (left: Date, right: Date) => Math.round((left.getTime() - right.getTime()) / dayMs);
const monthStart = (value: string) => `${value.slice(0, 7)}-01`;
const activityEnd = (activity: CampaignPlanningActivity) => activity.ends_on || activity.starts_on;

const datedActivities = computed(() => props.planning.activities
    .filter((activity): activity is CampaignPlanningActivity & { starts_on: string } => Boolean(activity.starts_on))
    .map((activity) => ({
        ...activity,
        ends_on: activityEnd(activity) || activity.starts_on,
    }))
    .sort((left, right) => left.starts_on.localeCompare(right.starts_on) || left.id - right.id));

const colorClass = (activity: CampaignPlanningActivity) => {
    if (activity.status === 'in_progress') return 'bg-blue-500 text-white hover:bg-blue-600';
    if (activity.status === 'upcoming') return 'bg-slate-400 text-white hover:bg-slate-500';
    if (activity.roi !== null && activity.roi >= 6) return 'bg-emerald-500 text-white hover:bg-emerald-600';
    if (activity.roi !== null && activity.roi >= 5) return 'bg-amber-500 text-white hover:bg-amber-600';
    return 'bg-rose-500 text-white hover:bg-rose-600';
};

const timeline = computed(() => {
    if (!datedActivities.value.length) return null;

    const start = parseDate(datedActivities.value[0].starts_on);
    const end = datedActivities.value.reduce((latest, activity) => {
        const candidate = parseDate(activity.ends_on);
        return candidate > latest ? candidate : latest;
    }, parseDate(datedActivities.value[0].ends_on));
    const totalDays = Math.max(1, diffDays(end, start) + 1);
    const laneEnds: number[] = [];
    const items = datedActivities.value.map((activity) => {
        const activityStart = parseDate(activity.starts_on);
        const activityEndDate = parseDate(activity.ends_on);
        let lane = laneEnds.findIndex((laneEnd) => activityStart.getTime() > laneEnd);
        if (lane === -1) lane = laneEnds.length;
        laneEnds[lane] = activityEndDate.getTime();

        return {
            activity,
            lane,
            left: (diffDays(activityStart, start) / totalDays) * 100,
            width: Math.max(((diffDays(activityEndDate, activityStart) + 1) / totalDays) * 100, 0.8),
        };
    });

    const months: Array<{ key: string; label: string; left: number }> = [];
    let cursor = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), 1));
    while (cursor <= end) {
        months.push({
            key: isoDate(cursor),
            label: `${cursor.getUTCMonth() + 1}月`,
            left: Math.max(0, (diffDays(cursor, start) / totalDays) * 100),
        });
        cursor = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 1));
    }

    const merged: Array<{ start: Date; end: Date }> = [];
    datedActivities.value.forEach((activity) => {
        const interval = { start: parseDate(activity.starts_on), end: parseDate(activity.ends_on) };
        const last = merged[merged.length - 1];
        if (!last || interval.start.getTime() > last.end.getTime() + dayMs) merged.push(interval);
        else if (interval.end > last.end) last.end = interval.end;
    });
    const coveredDays = merged.reduce((total, interval) => total + diffDays(interval.end, interval.start) + 1, 0);
    const today = parseDate(props.planning.today);

    return {
        start,
        end,
        items,
        months,
        lanes: Math.max(1, laneEnds.length),
        coverage: Math.round((coveredDays / totalDays) * 100),
        todayLeft: today >= start && today <= end ? ((diffDays(today, start) + 0.5) / totalDays) * 100 : null,
    };
});

const selectedMonth = ref(monthStart(props.planning.today));
const selectedDate = ref(props.planning.today);

const monthDate = computed(() => parseDate(selectedMonth.value));
const monthTitle = computed(() => `${monthNames[monthDate.value.getUTCMonth()]} ${monthDate.value.getUTCFullYear()}年`);

const calendarWeeks = computed(() => {
    const first = monthDate.value;
    const gridStart = addDays(first, -first.getUTCDay());
    return Array.from({ length: 6 }, (_, weekIndex) => {
        const weekStart = addDays(gridStart, weekIndex * 7);
        const weekEnd = addDays(weekStart, 6);
        const laneEnds: number[] = [];
        const events = datedActivities.value
            .filter((activity) => parseDate(activity.starts_on) <= weekEnd && parseDate(activity.ends_on) >= weekStart)
            .map((activity) => {
                const start = parseDate(activity.starts_on) < weekStart ? weekStart : parseDate(activity.starts_on);
                const end = parseDate(activity.ends_on) > weekEnd ? weekEnd : parseDate(activity.ends_on);
                const startColumn = diffDays(start, weekStart) + 1;
                const endColumn = diffDays(end, weekStart) + 1;
                let lane = laneEnds.findIndex((laneEnd) => startColumn > laneEnd);
                if (lane === -1) lane = laneEnds.length;
                laneEnds[lane] = endColumn;
                return { activity, startColumn, span: endColumn - startColumn + 1, lane };
            });

        return {
            key: isoDate(weekStart),
            days: Array.from({ length: 7 }, (_, dayIndex) => {
                const date = addDays(weekStart, dayIndex);
                return {
                    iso: isoDate(date),
                    number: date.getUTCDate(),
                    inMonth: date.getUTCMonth() === first.getUTCMonth(),
                    isToday: isoDate(date) === props.planning.today,
                };
            }),
            events,
            lanes: laneEnds.length,
        };
    });
});

const shiftMonth = (delta: number) => {
    const current = monthDate.value;
    selectedMonth.value = isoDate(new Date(Date.UTC(current.getUTCFullYear(), current.getUTCMonth() + delta, 1)));
};

const goToday = () => {
    selectedDate.value = props.planning.today;
    selectedMonth.value = monthStart(props.planning.today);
};

const changeDate = (event: Event) => {
    const value = (event.target as HTMLInputElement).value;
    if (!value) return;
    selectedDate.value = value;
    selectedMonth.value = monthStart(value);
};

const openDetail = (activityId: number) => router.get('/campaign-themes', {
    tab: 'calendar',
    activity: activityId,
    compare: 'auto',
    detail: 1,
    source: 'calendar',
}, {
    replace: true,
    preserveScroll: true,
});
</script>

<template>
    <div class="min-w-0 space-y-6">
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-baseline gap-x-5 gap-y-2">
                <h2 class="text-xl font-semibold text-slate-950">全周期活动预览</h2>
                <p class="text-sm text-slate-500">{{ planning.counts.total }} 场活动 · 促销覆盖 ≈{{ timeline?.coverage ?? 0 }}%</p>
            </div>

            <div v-if="timeline" class="relative mt-10 min-w-0" :style="{ height: `${88 + timeline.lanes * 38}px` }">
                <div class="absolute inset-x-0 top-7 h-px bg-slate-200" />
                <span
                    v-for="month in timeline.months"
                    :key="month.key"
                    class="absolute top-0 -translate-x-1/2 text-xs font-medium text-slate-500"
                    :style="{ left: `${month.left}%` }"
                >{{ month.label }}</span>

                <button
                    v-for="item in timeline.items"
                    :key="item.activity.id"
                    type="button"
                    class="absolute z-10 h-8 min-w-2 overflow-hidden rounded-lg px-2 text-left text-xs font-semibold shadow-sm transition hover:z-30 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-offset-2"
                    :class="colorClass(item.activity)"
                    :style="{ left: `${item.left}%`, width: `${item.width}%`, top: `${44 + item.lane * 38}px` }"
                    :title="`${item.activity.name} · ${item.activity.starts_on}–${item.activity.ends_on} · ROI ${item.activity.roi ?? '--'}`"
                    :aria-label="`打开${item.activity.name}活动详情`"
                    @click="openDetail(item.activity.id)"
                >
                    <span class="block truncate">{{ item.activity.name }}</span>
                </button>

                <div v-if="timeline.todayLeft !== null" class="pointer-events-none absolute bottom-0 top-5 z-20 w-0.5 bg-amber-400" :style="{ left: `${timeline.todayLeft}%` }">
                    <span class="absolute -top-8 left-1/2 -translate-x-1/2 rounded-lg bg-amber-300 px-2 py-1 text-xs font-bold text-amber-950 shadow-sm">今天</span>
                </div>
            </div>

            <div v-else class="mt-8 grid min-h-36 place-items-center rounded-2xl border border-dashed border-slate-200 text-sm text-slate-400">暂无带日期的活动</div>

            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
                <span class="inline-flex items-center gap-2"><i class="h-3 w-5 rounded bg-emerald-500" />ROI≥6 优秀</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-5 rounded bg-amber-500" />5–6 达标</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-5 rounded bg-rose-500" />&lt;5 低于保本</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-5 rounded bg-blue-500" />进行中</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-5 rounded bg-slate-400" />未开始</span>
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="goToday">今天</button>
                    <button type="button" class="grid h-10 w-10 place-items-center rounded-xl text-xl text-slate-600 transition hover:bg-slate-100" aria-label="上一个月" @click="shiftMonth(-1)">‹</button>
                    <button type="button" class="grid h-10 w-10 place-items-center rounded-xl text-xl text-slate-600 transition hover:bg-slate-100" aria-label="下一个月" @click="shiftMonth(1)">›</button>
                    <h2 class="ml-2 text-xl font-semibold text-slate-950">{{ monthTitle }}</h2>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <select class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700" aria-label="日历视图">
                        <option>月</option>
                    </select>
                    <input :value="selectedDate" type="date" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700" aria-label="选择日期" @change="changeDate">
                </div>
            </header>

            <div class="overflow-x-auto">
                <div class="min-w-[760px]">
                <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 px-1">
                    <div v-for="weekday in weekdays" :key="weekday" class="px-3 py-3 text-center text-xs font-semibold text-slate-500">{{ weekday }}</div>
                </div>

                <div v-for="week in calendarWeeks" :key="week.key" class="relative border-b border-slate-100 last:border-b-0">
                    <div class="grid grid-cols-7">
                        <div v-for="day in week.days" :key="day.iso" class="min-h-28 border-r border-slate-100 p-2 last:border-r-0" :class="day.inMonth ? 'bg-white' : 'bg-slate-50/70'">
                            <span class="grid h-7 w-7 place-items-center rounded-full text-xs font-semibold" :class="day.isToday ? 'bg-amber-300 text-amber-950' : day.inMonth ? 'text-slate-700' : 'text-slate-300'">{{ day.number }}</span>
                        </div>
                    </div>
                    <div class="pointer-events-none absolute inset-x-0 top-10 grid grid-cols-7 gap-x-px gap-y-1 px-1">
                        <button
                            v-for="event in week.events"
                            :key="`${week.key}-${event.activity.id}`"
                            type="button"
                            class="pointer-events-auto z-10 h-6 min-w-0 truncate rounded-md px-2 text-left text-xs font-semibold shadow-sm transition hover:z-20 hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-offset-1"
                            :class="colorClass(event.activity)"
                            :style="{ gridColumn: `${event.startColumn} / span ${event.span}`, gridRow: `${event.lane + 1}` }"
                            :title="`${event.activity.name} · ${event.activity.starts_on}–${event.activity.ends_on}`"
                            :aria-label="`打开${event.activity.name}活动详情`"
                            @click="openDetail(event.activity.id)"
                        >{{ event.activity.name }}</button>
                    </div>
                </div>
                </div>
            </div>
        </section>
    </div>
</template>
