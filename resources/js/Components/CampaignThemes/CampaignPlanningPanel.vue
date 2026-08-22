<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { CampaignReviewJudgment } from './reviewTypes';
import type { CampaignPlanningStatus, CampaignThemePlanning } from './planningTypes';

const props = defineProps<{ planning: CampaignThemePlanning; currency: string }>();

type StatusFilter = 'all' | CampaignPlanningStatus;

const statusFilter = ref<StatusFilter>('all');
const search = ref('');
const openingActivityId = ref<number | null>(null);

const statusMeta: Record<CampaignPlanningStatus, { label: string; classes: string }> = {
    upcoming: { label: '未开始', classes: 'bg-amber-50 text-amber-700 ring-amber-200' },
    in_progress: { label: '进行中', classes: 'bg-blue-50 text-blue-700 ring-blue-200' },
    completed: { label: '已完成', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
};

const judgmentMeta: Record<CampaignReviewJudgment, { label: string; classes: string }> = {
    reusable: { label: '可复用', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    scalable: { label: '放量适销', classes: 'bg-orange-50 text-orange-700 ring-orange-200' },
    underperforming: { label: '承接不足', classes: 'bg-rose-50 text-rose-700 ring-rose-200' },
    insufficient_data: { label: '数据不足', classes: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

const filteredActivities = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.planning.activities.filter((activity) => {
        if (statusFilter.value !== 'all' && activity.status !== statusFilter.value) return false;
        if (!needle) return true;

        return [activity.name, activity.campaign_id, activity.main_title, activity.core_offer]
            .filter((value): value is string => Boolean(value))
            .some((value) => value.toLocaleLowerCase().includes(needle));
    });
});

const compactMoney = (value: number | null) => value === null
    ? '--'
    : new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.currency || 'USD',
        currencyDisplay: 'narrowSymbol',
        notation: 'compact',
        maximumFractionDigits: 2,
    }).format(value);

const integer = (value: number | null) => value === null
    ? '--'
    : new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);

const shortDate = (value: string | null) => value ? `${value.slice(5, 7)}/${value.slice(8, 10)}` : '--';
const period = (startsOn: string | null, endsOn: string | null) => `${shortDate(startsOn)}–${shortDate(endsOn)}`;

const openReview = (activityId: number) => {
    openingActivityId.value = activityId;
    router.get('/campaign-themes', {
        tab: 'planning',
        activity: activityId,
        compare: 'auto',
        detail: 1,
        source: 'planning',
    }, {
        replace: true,
        preserveScroll: false,
        onFinish: () => { openingActivityId.value = null; },
    });
};
</script>

<template>
    <div class="min-w-0 space-y-5">
        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex min-w-0 flex-col gap-4 xl:flex-row xl:items-center">
                <div class="grid min-w-0 gap-3 sm:grid-cols-[220px_minmax(240px,360px)] sm:items-center xl:flex-none">
                    <label class="flex min-w-0 items-center gap-3">
                        <span class="shrink-0 text-sm font-medium text-slate-500">活动状态</span>
                        <select v-model="statusFilter" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            <option value="all">全部</option>
                            <option value="upcoming">未开始</option>
                            <option value="in_progress">进行中</option>
                            <option value="completed">已完成</option>
                        </select>
                    </label>

                    <label class="flex min-w-0 items-center gap-3">
                        <span class="shrink-0 text-sm font-medium text-slate-500">搜索</span>
                        <span class="relative min-w-0 flex-1">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                            <input v-model="search" type="search" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-9 pr-3 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100" placeholder="活动名称 / 优惠信息">
                        </span>
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold xl:ml-auto xl:flex-nowrap xl:whitespace-nowrap">
                    <span class="mr-1 shrink-0 text-sm font-medium text-slate-500">共 {{ planning.counts.total }} 个活动</span>
                    <span v-if="planning.counts.upcoming" class="shrink-0 rounded-lg bg-amber-50 px-2.5 py-1.5 text-amber-700 ring-1 ring-inset ring-amber-200">{{ planning.counts.upcoming }} 未开始</span>
                    <span v-if="planning.counts.in_progress" class="shrink-0 rounded-lg bg-blue-50 px-2.5 py-1.5 text-blue-700 ring-1 ring-inset ring-blue-200">{{ planning.counts.in_progress }} 进行中</span>
                    <span v-if="planning.counts.completed" class="shrink-0 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-emerald-700 ring-1 ring-inset ring-emerald-200">{{ planning.counts.completed }} 已完成</span>
                </div>
            </div>
        </section>

        <div v-if="filteredActivities.length" class="grid min-w-0 gap-5 xl:grid-cols-2">
            <button
                v-for="activity in filteredActivities"
                :key="activity.id"
                type="button"
                class="group min-w-0 rounded-3xl border border-slate-200 border-l-4 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-300 sm:p-6"
                :class="[
                    activity.status === 'completed' ? 'border-l-emerald-500' : activity.status === 'in_progress' ? 'border-l-blue-500' : 'border-l-amber-500',
                    openingActivityId === activity.id ? 'pointer-events-none opacity-65' : '',
                ]"
                :aria-label="`打开${activity.name}复盘详情`"
                @click="openReview(activity.id)"
            >
                <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex min-w-0 flex-wrap items-center gap-2.5">
                        <h2 class="min-w-0 truncate text-lg font-semibold text-slate-950" :title="activity.name">{{ activity.name }}</h2>
                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusMeta[activity.status].classes">{{ statusMeta[activity.status].label }}</span>
                    </div>
                    <span class="w-fit shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="judgmentMeta[activity.judgment].classes">{{ judgmentMeta[activity.judgment].label }}</span>
                </div>

                <div class="mt-4 flex min-w-0 flex-col gap-2 text-sm text-slate-500 sm:flex-row sm:items-start sm:gap-5">
                    <span class="shrink-0 tabular-nums">▣ {{ period(activity.starts_on, activity.ends_on) }}</span>
                    <span class="min-w-0 break-words">🎁 {{ activity.core_offer || '暂无核心优惠' }}</span>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-4 border-b border-slate-100 pb-5 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium text-slate-400">GMV</dt>
                        <dd class="mt-1.5 text-lg font-semibold tabular-nums text-slate-900">{{ compactMoney(activity.gmv) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-400">广告花费</dt>
                        <dd class="mt-1.5 text-lg font-semibold tabular-nums text-slate-900">{{ compactMoney(activity.ad_spend) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-400">ROI</dt>
                        <dd class="mt-1.5 text-lg font-semibold tabular-nums" :class="activity.roi === null ? 'text-slate-400' : activity.roi >= 6 ? 'text-emerald-600' : activity.roi >= 5 ? 'text-orange-500' : 'text-rose-500'">{{ activity.roi === null ? '--' : activity.roi.toFixed(2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-400">订单数</dt>
                        <dd class="mt-1.5 text-lg font-semibold tabular-nums text-slate-900">{{ integer(activity.orders) }}</dd>
                    </div>
                </dl>

                <p class="mt-4 line-clamp-3 whitespace-pre-line text-sm leading-6 text-slate-500" :title="activity.summary || ''">
                    {{ activity.summary || '暂无活动总结，点击查看该活动的复盘详情与策划书。' }}
                </p>
                <span class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 opacity-0 transition group-hover:opacity-100">
                    查看复盘详情 <span aria-hidden="true">→</span>
                </span>
            </button>
        </div>

        <section v-else class="grid min-h-72 place-items-center rounded-3xl border border-dashed border-slate-200 bg-white p-8 text-center shadow-sm">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-xl text-slate-400">▤</span>
                <h2 class="mt-4 text-base font-semibold text-slate-800">没有符合条件的活动</h2>
                <p class="mt-2 text-sm text-slate-400">请调整活动状态或搜索关键词。</p>
            </div>
        </section>
    </div>
</template>
