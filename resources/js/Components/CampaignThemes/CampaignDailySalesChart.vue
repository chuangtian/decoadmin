<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { computed, ref } from 'vue';

interface DailyPerformancePoint {
    date: string;
    total_sales: number;
    ad_spend: number | null;
    roi: number | null;
    orders: number;
}

const props = defineProps<{
    currency: string;
    points: DailyPerformancePoint[];
    available: boolean;
    pending: boolean;
    message: string | null;
    adSpendAvailable: boolean;
    adSpendMessage: string | null;
    adSpendReconciled: boolean;
    adSpendCoveragePercent: number | null;
}>();

const hoveredIndex = ref<number | null>(null);
const plot = { left: 76, right: 64, top: 52, bottom: 308 };
const chartHeight = 400;
const slotWidth = 98;
const chartWidth = computed(() => Math.max(820, plot.left + plot.right + props.points.length * slotWidth));
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right);
const pointWidth = computed(() => props.points.length ? plotWidth.value / props.points.length : slotWidth);
const amountMax = computed(() => Math.max(1, ...props.points.flatMap((point) => [point.total_sales, point.ad_spend ?? 0])) * 1.14);
const roiMax = computed(() => Math.max(1, ...props.points.map((point) => point.roi ?? 0)) * 1.14);
const ticks = [0, 0.25, 0.5, 0.75, 1];
const xAt = (index: number) => plot.left + pointWidth.value * (index + 0.5);
const amountY = (value: number) => plot.bottom - (value / amountMax.value) * (plot.bottom - plot.top);
const roiY = (value: number) => plot.bottom - (value / roiMax.value) * (plot.bottom - plot.top);
const barGroupWidth = computed(() => Math.min(62, pointWidth.value * 0.72));
const barWidth = computed(() => Math.max(10, barGroupWidth.value / 2 - 3));
const roiPath = computed(() => {
    let drawing = false;

    return props.points.map((point, index) => {
        if (point.roi === null) {
            drawing = false;
            return '';
        }

        const command = drawing ? 'L' : 'M';
        drawing = true;
        return `${command} ${xAt(index)} ${roiY(point.roi)}`;
    }).filter(Boolean).join(' ');
});
const hoveredPoint = computed(() => hoveredIndex.value === null ? null : props.points[hoveredIndex.value] ?? null);
const hoveredX = computed(() => hoveredIndex.value === null ? 0 : xAt(hoveredIndex.value));

const compactMoney = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', notation: 'compact', maximumFractionDigits: 1,
}).format(value);
const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', maximumFractionDigits: 2,
}).format(value);
const shortDate = (value: string) => value.slice(5).replace('-', '/');
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">活动周期销售额、广告花费与 ROI 走势</h2>
                <p class="mt-1 text-xs text-slate-400">Shopify 销售与营销渠道上报花费 · 活动日期完整补齐 · 日期倒序</p>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-xs font-medium text-slate-500">
                <span class="inline-flex items-center gap-2"><i class="h-3 w-7 rounded bg-emerald-500/30" />销售额</span>
                <span class="inline-flex items-center gap-2"><i class="h-3 w-7 rounded bg-blue-500/40" />广告花费</span>
                <span class="inline-flex items-center gap-2"><i class="h-0.5 w-7 bg-orange-500" />ROI</span>
            </div>
        </header>

        <div v-if="points.length" class="review-scrollbar min-w-0 overflow-x-auto px-3 pb-3 pt-2 sm:px-4">
            <div class="relative" :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }">
                <svg v-readable-chart :viewBox="`0 0 ${chartWidth} ${chartHeight}`" :width="chartWidth" :height="chartHeight" role="img" aria-label="活动每日销售额、广告花费与 ROI">
                    <text :x="plot.left" y="27" class="fill-slate-400 text-xs font-medium">金额</text>
                    <text :x="chartWidth - plot.right" y="27" text-anchor="end" class="fill-slate-400 text-xs font-medium">ROI</text>
                    <g v-for="ratio in ticks" :key="ratio">
                        <line :x1="plot.left" :x2="chartWidth - plot.right" :y1="plot.bottom - ratio * (plot.bottom - plot.top)" :y2="plot.bottom - ratio * (plot.bottom - plot.top)" class="stroke-slate-200" />
                        <text :x="plot.left - 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" text-anchor="end" class="fill-slate-400 text-xs tabular-nums">{{ compactMoney(amountMax * ratio) }}</text>
                        <text :x="chartWidth - plot.right + 10" :y="plot.bottom - ratio * (plot.bottom - plot.top) + 4" class="fill-slate-400 text-xs tabular-nums">{{ (roiMax * ratio).toFixed(1) }}x</text>
                    </g>

                    <g v-for="(point, index) in points" :key="point.date">
                        <rect
                            :x="xAt(index) - barWidth - 2"
                            :y="amountY(point.total_sales)"
                            :width="barWidth"
                            :height="plot.bottom - amountY(point.total_sales)"
                            rx="6"
                            fill="#10b981"
                            :fill-opacity="hoveredIndex === index ? 0.48 : 0.28"
                        />
                        <rect
                            v-if="point.ad_spend !== null"
                            :x="xAt(index) + 2"
                            :y="amountY(point.ad_spend)"
                            :width="barWidth"
                            :height="plot.bottom - amountY(point.ad_spend)"
                            rx="6"
                            fill="#3b82f6"
                            :fill-opacity="hoveredIndex === index ? 0.58 : 0.38"
                        />
                    </g>

                    <path v-if="roiPath" :d="roiPath" fill="none" stroke="#f97316" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" />
                    <g v-for="(point, index) in points" :key="`point-${point.date}`">
                        <template v-if="point.roi !== null">
                            <circle :cx="xAt(index)" :cy="roiY(point.roi)" r="4.5" fill="#f97316" stroke="white" stroke-width="2" />
                            <text :x="xAt(index)" :y="roiY(point.roi) - 11" text-anchor="middle" fill="#f97316" class="text-xs font-semibold tabular-nums">{{ point.roi.toFixed(2) }}</text>
                        </template>
                        <text :x="xAt(index)" :y="plot.bottom + 29" text-anchor="middle" class="fill-slate-500 text-xs tabular-nums">{{ shortDate(point.date) }}</text>
                        <rect :x="xAt(index) - pointWidth / 2" :y="plot.top" :width="pointWidth" :height="plot.bottom - plot.top + 45" fill="transparent" tabindex="0" @mouseenter="hoveredIndex = index" @mouseleave="hoveredIndex = null" @focus="hoveredIndex = index" @blur="hoveredIndex = null" />
                    </g>
                    <line v-if="hoveredPoint" :x1="hoveredX" :x2="hoveredX" :y1="plot.top" :y2="plot.bottom" class="stroke-slate-400" stroke-dasharray="5 5" />
                </svg>

                <div v-if="hoveredPoint" class="pointer-events-none absolute top-16 z-10 w-64 rounded-xl border border-slate-200 bg-white/95 p-4 text-sm shadow-xl" :style="{ left: `${Math.min(Math.max(hoveredX + 14, plot.left), chartWidth - 270)}px` }">
                    <p class="font-semibold text-slate-900">{{ hoveredPoint.date }}</p>
                    <dl class="mt-3 space-y-2 text-slate-600">
                        <div class="flex justify-between gap-4"><dt>销售额</dt><dd class="font-semibold tabular-nums text-slate-900">{{ money(hoveredPoint.total_sales) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>广告花费</dt><dd class="font-semibold tabular-nums text-slate-900">{{ hoveredPoint.ad_spend === null ? '--' : money(hoveredPoint.ad_spend) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>ROI</dt><dd class="font-semibold tabular-nums text-slate-900">{{ hoveredPoint.roi === null ? '--' : hoveredPoint.roi.toFixed(2) }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>

        <p v-if="points.length && adSpendReconciled" class="border-t border-blue-100 bg-blue-50 px-5 py-3 text-xs leading-5 text-blue-700 sm:px-6">
            日广告花费按 Shopify 已上报渠道的日期分布，校准至飞书活动总广告花费；Shopify 原始覆盖约 {{ adSpendCoveragePercent?.toFixed(2) }}%，该数据用于活动节奏分析，不等同于各广告平台原始日账单。
        </p>
        <p v-else-if="points.length && !adSpendAvailable" class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-700 sm:px-6">
            {{ adSpendMessage || (pending ? '日广告花费正在准备，完成后会自动补齐 ROI。' : 'Shopify 暂未返回营销渠道日广告花费，因此 ROI 暂不显示。') }}
        </p>

        <div v-if="!points.length" class="grid min-h-64 place-items-center px-6 py-12 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-xl text-slate-400">↻</span>
                <p class="mt-4 font-semibold text-slate-700">{{ pending ? 'Shopify 日数据正在准备' : '暂无每日趋势数据' }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ message || (available ? '当前活动期间没有销售记录。' : '稍后将自动刷新。') }}</p>
            </div>
        </div>
    </section>
</template>

<style scoped>
.review-scrollbar { scrollbar-color: rgb(148 163 184 / 0.55) transparent; scrollbar-width: thin; }
.review-scrollbar::-webkit-scrollbar { height: 7px; }
.review-scrollbar::-webkit-scrollbar-track { background: transparent; }
.review-scrollbar::-webkit-scrollbar-thumb { border: 2px solid white; border-radius: 999px; background: rgb(148 163 184 / 0.55); }
</style>
