<script setup lang="ts">
import { computed, ref } from 'vue';

interface TrafficCostPoint {
    date: string;
    sessions: number;
    cart_additions: number;
    reached_checkout: number;
    ad_spend: number | null;
    cart_addition_cost: number | null;
    checkout_cost: number | null;
}

const props = defineProps<{
    currency: string;
    points: TrafficCostPoint[];
    available: boolean;
    pending: boolean;
    stale: boolean;
    message: string | null;
    adSpendAvailable: boolean;
    adSpendMessage: string | null;
    adSpendReconciled: boolean;
    adSpendCoveragePercent: number | null;
}>();

const hoveredIndex = ref<number | null>(null);
const scroller = ref<HTMLElement | null>(null);
const plot = { left: 72, right: 78, top: 52, bottom: 308 };
const chartHeight = 400;
const slotWidth = 98;
const chartWidth = computed(() => Math.max(820, plot.left + plot.right + props.points.length * slotWidth));
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right);
const pointWidth = computed(() => props.points.length ? plotWidth.value / props.points.length : slotWidth);
const barWidth = computed(() => Math.min(48, Math.max(18, pointWidth.value * 0.5)));

const niceMaximum = (values: number[]) => {
    const maximum = Math.max(0, ...values);
    if (maximum === 0) return 1;

    const padded = maximum * 1.12;
    const magnitude = 10 ** Math.floor(Math.log10(padded));
    const normalized = padded / magnitude;
    const step = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find((candidate) => normalized <= candidate) ?? 10;

    return step * magnitude;
};

const trafficMaximum = computed(() => niceMaximum(props.points.map((point) => point.sessions)));
const costMaximum = computed(() => niceMaximum(props.points.flatMap((point) => [
    point.cart_addition_cost ?? 0,
    point.checkout_cost ?? 0,
])));
const ticks = [0, 0.25, 0.5, 0.75, 1];
const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const trafficY = (value: number) => plot.bottom - (value / trafficMaximum.value) * (plot.bottom - plot.top);
const costY = (value: number) => plot.bottom - (value / costMaximum.value) * (plot.bottom - plot.top);

const buildCostPath = (valueFor: (point: TrafficCostPoint) => number | null) => {
    let drawing = false;

    return props.points.map((point, index) => {
        const value = valueFor(point);
        if (value === null) {
            drawing = false;
            return '';
        }

        const command = drawing ? 'L' : 'M';
        drawing = true;
        return command + ' ' + xAt(index) + ' ' + costY(value);
    }).filter(Boolean).join(' ');
};

const cartAdditionCostPath = computed(() => buildCostPath((point) => point.cart_addition_cost));
const checkoutCostPath = computed(() => buildCostPath((point) => point.checkout_cost));
const hoveredPoint = computed(() => hoveredIndex.value === null ? null : props.points[hoveredIndex.value] ?? null);
const hoveredX = computed(() => hoveredIndex.value === null ? 0 : xAt(hoveredIndex.value));
const tooltipStyle = computed(() => {
    const tooltipWidth = 288;
    const viewportBounds = scroller.value?.getBoundingClientRect();
    const chartBounds = scroller.value?.firstElementChild?.getBoundingClientRect();
    const visibleLeft = viewportBounds && chartBounds ? viewportBounds.left - chartBounds.left : 0;
    const visibleRight = viewportBounds && chartBounds ? viewportBounds.right - chartBounds.left : chartWidth.value;
    const minimumLeft = Math.max(0, visibleLeft + 8);
    const maximumLeft = Math.max(minimumLeft, Math.min(chartWidth.value - tooltipWidth, visibleRight - tooltipWidth - 8));

    return {
        left: Math.max(minimumLeft, Math.min(hoveredX.value + 14, maximumLeft)) + 'px',
    };
});

const integer = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
const compactInteger = (value: number) => new Intl.NumberFormat('en-US', {
    notation: 'compact', maximumFractionDigits: 1,
}).format(value);
const compactMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', notation: 'compact', maximumFractionDigits: 1,
}).format(value);
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', maximumFractionDigits: 2,
}).format(value);
const shortDate = (value: string) => value.slice(5).replace('-', '/');
const coverage = computed(() => props.adSpendCoveragePercent === null ? '--' : props.adSpendCoveragePercent.toFixed(2));
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-950">活动期间流量、加购成本与结账成本走势</h2>
                    <span v-if="pending || stale" class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-100">
                        {{ pending ? '数据更新中' : '最近快照' }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-400">Shopify Session 与转化漏斗 · 活动日广告花费 · 日期倒序</p>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-xs font-medium text-slate-500 sm:justify-end">
                <span class="inline-flex items-center gap-2"><i class="h-3 w-7 rounded bg-blue-500/35" />访问 Session（左轴）</span>
                <span class="inline-flex items-center gap-2"><i class="h-0.5 w-7 bg-emerald-500" />加购成本（右轴）</span>
                <span class="inline-flex items-center gap-2"><i class="h-0.5 w-7 bg-orange-500" />结账成本（右轴）</span>
            </div>
        </header>

        <div ref="scroller" v-if="points.length" class="traffic-cost-scrollbar min-w-0 overflow-x-auto px-3 pb-3 pt-2 sm:px-4" @scroll="hoveredIndex = null">
            <div class="relative" :style="{ width: chartWidth + 'px', height: chartHeight + 'px' }">
                <svg :viewBox="'0 0 ' + chartWidth + ' ' + chartHeight" :width="chartWidth" :height="chartHeight" role="img" aria-label="活动每日访问 Session、加购成本与结账成本">
                    <text :x="plot.left" y="27" class="fill-slate-400 text-[11px] font-medium">访问 Session</text>
                    <text :x="chartWidth - plot.right" y="27" text-anchor="end" class="fill-slate-400 text-[11px] font-medium">成本</text>
                    <g v-for="ratio in ticks" :key="ratio">
                        <line :x1="plot.left" :x2="chartWidth - plot.right" :y1="plot.bottom - ratio * (plot.bottom - plot.top)" :y2="plot.bottom - ratio * (plot.bottom - plot.top)" class="stroke-slate-200" />
                        <text :x="plot.left - 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-[11px] tabular-nums">{{ compactInteger(trafficMaximum * ratio) }}</text>
                        <text :x="chartWidth - plot.right + 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" class="fill-slate-400 text-[11px] tabular-nums">{{ compactMoney(costMaximum * ratio) }}</text>
                    </g>

                    <g v-for="(point, index) in points" :key="point.date">
                        <rect
                            :x="xAt(index) - barWidth / 2"
                            :y="trafficY(point.sessions)"
                            :width="barWidth"
                            :height="plot.bottom - trafficY(point.sessions)"
                            rx="6"
                            fill="#3b82f6"
                            :fill-opacity="hoveredIndex === index ? 0.55 : 0.32"
                        />
                    </g>

                    <path v-if="cartAdditionCostPath" :d="cartAdditionCostPath" fill="none" stroke="#10b981" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" />
                    <path v-if="checkoutCostPath" :d="checkoutCostPath" fill="none" stroke="#f97316" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" />
                    <g v-for="(point, index) in points" :key="'cost-' + point.date">
                        <circle v-if="point.cart_addition_cost !== null" :cx="xAt(index)" :cy="costY(point.cart_addition_cost)" r="4.5" fill="#10b981" stroke="white" stroke-width="2" />
                        <circle v-if="point.checkout_cost !== null" :cx="xAt(index)" :cy="costY(point.checkout_cost)" r="4.5" fill="#f97316" stroke="white" stroke-width="2" />
                        <text :x="xAt(index)" :y="plot.bottom + 29" text-anchor="middle" class="fill-slate-500 text-[11px] tabular-nums">{{ shortDate(point.date) }}</text>
                        <rect
                            :x="xAt(index) - pointWidth / 2"
                            :y="plot.top"
                            :width="pointWidth"
                            :height="plot.bottom - plot.top + 45"
                            fill="transparent"
                            tabindex="0"
                            @mouseenter="hoveredIndex = index"
                            @mouseleave="hoveredIndex = null"
                            @focus="hoveredIndex = index"
                            @blur="hoveredIndex = null"
                        />
                    </g>
                    <line v-if="hoveredPoint" :x1="hoveredX" :x2="hoveredX" :y1="plot.top" :y2="plot.bottom" class="stroke-slate-400" stroke-dasharray="5 5" />
                </svg>

                <div v-if="hoveredPoint" class="pointer-events-none absolute top-16 z-10 w-72 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl" :style="tooltipStyle">
                    <p class="font-semibold text-slate-900">{{ hoveredPoint.date }}</p>
                    <dl class="mt-3 space-y-2 text-slate-600">
                        <div class="flex justify-between gap-4"><dt>访问 Session</dt><dd class="font-semibold tabular-nums text-slate-900">{{ integer(hoveredPoint.sessions) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>加购 Session</dt><dd class="font-semibold tabular-nums text-slate-900">{{ integer(hoveredPoint.cart_additions) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>到达结账 Session</dt><dd class="font-semibold tabular-nums text-slate-900">{{ integer(hoveredPoint.reached_checkout) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>广告花费</dt><dd class="font-semibold tabular-nums text-slate-900">{{ hoveredPoint.ad_spend === null ? '--' : money(hoveredPoint.ad_spend) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>加购成本</dt><dd class="font-semibold tabular-nums text-emerald-700">{{ hoveredPoint.cart_addition_cost === null ? '--' : money(hoveredPoint.cart_addition_cost) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>结账成本</dt><dd class="font-semibold tabular-nums text-orange-700">{{ hoveredPoint.checkout_cost === null ? '--' : money(hoveredPoint.checkout_cost) }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>

        <p v-if="points.length && stale" class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-700 sm:px-6">
            {{ message || '当前展示最近一次可用快照，新数据准备完成后会自动刷新。' }}
        </p>
        <p v-if="points.length && adSpendReconciled" class="border-t border-blue-100 bg-blue-50 px-5 py-3 text-xs leading-5 text-blue-700 sm:px-6">
            日广告花费按 Shopify 已上报渠道的日期分布，校准至飞书活动总广告花费；Shopify 原始覆盖约 {{ coverage }}%，加购与结账成本由校准后的日花费计算，不等同于各广告平台原始日账单。
        </p>
        <p v-else-if="points.length && !adSpendAvailable" class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-700 sm:px-6">
            {{ adSpendMessage || (pending ? '日广告花费正在准备，完成后会自动补齐加购与结账成本。' : 'Shopify 暂未返回营销渠道日广告花费，因此加购与结账成本暂不显示。') }}
        </p>

        <div v-if="!points.length" class="grid min-h-64 place-items-center px-6 py-12 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-xl text-slate-400">↻</span>
                <p class="mt-4 font-semibold text-slate-700">{{ pending ? 'Shopify 日流量与成本数据正在准备' : '暂无流量与成本趋势数据' }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ message || (available ? '当前活动期间没有访问或成本记录。' : '稍后将自动刷新。') }}</p>
            </div>
        </div>
    </section>
</template>

<style scoped>
.traffic-cost-scrollbar { scrollbar-color: rgb(148 163 184 / 0.55) transparent; scrollbar-width: thin; }
.traffic-cost-scrollbar::-webkit-scrollbar { height: 7px; }
.traffic-cost-scrollbar::-webkit-scrollbar-track { background: transparent; }
.traffic-cost-scrollbar::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.55); }
</style>
