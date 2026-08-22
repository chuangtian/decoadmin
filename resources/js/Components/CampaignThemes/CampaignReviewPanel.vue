<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CampaignConversionFunnel from './CampaignConversionFunnel.vue';
import CampaignDailySalesChart from './CampaignDailySalesChart.vue';
import CampaignModelSales from './CampaignModelSales.vue';
import CampaignTrafficCostChart from './CampaignTrafficCostChart.vue';
import type { CampaignReviewActivityOption, CampaignReviewJudgment, CampaignThemeReview } from './reviewTypes';

const props = defineProps<{ review: CampaignThemeReview; currency: string }>();
const selectedImage = ref<string | null>(null);
const changing = ref(false);

const judgmentMeta: Record<CampaignReviewJudgment, { label: string; classes: string }> = {
    reusable: { label: '可复用', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    scalable: { label: '放量适销', classes: 'bg-orange-50 text-orange-700 ring-orange-200' },
    underperforming: { label: '承接不足', classes: 'bg-rose-50 text-rose-700 ring-rose-200' },
    insufficient_data: { label: '数据不足', classes: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

const metricDefinitions = [
    { key: 'gmv', label: 'GMV', format: 'money' },
    { key: 'ad_spend', label: '广告花费', format: 'money' },
    { key: 'roi', label: 'ROI', format: 'decimal' },
    { key: 'orders', label: '订单数', format: 'integer' },
    { key: 'daily_average_sales', label: '日均 GMV', format: 'money' },
    { key: 'daily_average_ad_spend', label: '日均广告花费', format: 'money' },
    { key: 'conversion_rate_percent', label: 'CVR 转化率', format: 'percent' },
    { key: 'average_order_value', label: 'AOV 平均客单价', format: 'money' },
    { key: 'daily_average_sessions', label: '日均浏览数', format: 'integer' },
    { key: 'daily_average_site_views', label: '日均站浏览数', format: 'integer' },
    { key: 'daily_average_cart_addition_cost', label: '日均加购成本', format: 'money' },
    { key: 'daily_average_checkout_cost', label: '日均结账成本', format: 'money' },
] as const;

const cards = computed(() => metricDefinitions.map((definition) => ({
    ...definition,
    metric: props.review.activity?.metrics[definition.key] ?? { value: null, comparison_value: null, change_percent: null },
})));

const money = (value: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: props.currency || 'USD', currencyDisplay: 'narrowSymbol', notation: Math.abs(value) >= 100000 ? 'compact' : 'standard', maximumFractionDigits: 2,
}).format(value);
const integer = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
const formatMetric = (value: number | null, format: string) => {
    if (value === null) return '--';
    if (format === 'money') return money(value);
    if (format === 'integer') return integer(value);
    if (format === 'percent') return `${value.toFixed(3)}%`;
    return value.toFixed(2);
};
const shortDate = (value: string | null) => value ? value.slice(5).replace('-', '/') : '--';
const period = (activity: CampaignReviewActivityOption | null) => activity ? `${shortDate(activity.starts_on)}–${shortDate(activity.ends_on)}` : '--';

const navigate = (activity: number | null, comparison: string | number) => {
    changing.value = true;
    router.get('/campaign-themes', {
        tab: 'review',
        ...(activity ? { activity } : {}),
        compare: comparison,
    }, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onFinish: () => { changing.value = false; },
    });
};

const selectActivity = (event: Event) => navigate(Number((event.target as HTMLSelectElement).value), 'auto');
const selectComparison = (event: Event) => navigate(props.review.selected_activity_id, (event.target as HTMLSelectElement).value);
</script>

<template>
    <div v-if="review.activity" class="min-w-0 space-y-6" :class="changing ? 'pointer-events-none opacity-70' : ''">
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="grid min-w-0 gap-6 p-5 xl:grid-cols-[minmax(0,1fr)_360px] 2xl:grid-cols-[minmax(0,1fr)_420px] sm:p-6">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-xl font-semibold text-slate-950">活动效果分析</h2>
                        <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="judgmentMeta[review.activity.judgment].classes">{{ judgmentMeta[review.activity.judgment].label }}</span>
                    </div>
                    <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-[120px_minmax(0,1fr)]">
                        <dt class="text-slate-400">活动主题</dt>
                        <dd class="font-semibold text-slate-900">{{ review.activity.name }} <span v-if="review.activity.campaign_id" class="ml-2 font-normal text-slate-400">#{{ review.activity.campaign_id }}</span></dd>
                        <dt class="text-slate-400">主标题</dt>
                        <dd class="break-words text-slate-700">{{ review.activity.main_title || '--' }}</dd>
                        <dt class="text-slate-400">活动时间</dt>
                        <dd class="tabular-nums text-slate-700">{{ period(review.activity) }}</dd>
                        <dt class="text-slate-400">核心优惠</dt>
                        <dd class="break-words text-slate-700">{{ review.activity.core_offer || '--' }}</dd>
                    </dl>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <button v-if="review.activity.campaign_images.length" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700" @click="selectedImage = review.activity.campaign_images[0]">活动图片（{{ review.activity.campaign_images.length }}）</button>
                        <button v-if="review.activity.email_images.length" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700" @click="selectedImage = review.activity.email_images[0]">EDM 图片（{{ review.activity.email_images.length }}）</button>
                        <a v-if="review.activity.planning_document_url" :href="review.activity.planning_document_url" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700">打开策划书</a>
                    </div>

                </div>

                <div class="min-w-0 self-start space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">当前活动</span>
                        <select class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-800 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100" :value="review.selected_activity_id ?? ''" @change="selectActivity">
                            <option v-for="activity in review.activities" :key="activity.id" :value="activity.id">{{ activity.name }}（{{ period(activity) }}）</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">对比活动</span>
                        <select class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100" :value="review.comparison_activity_id ?? 'none'" @change="selectComparison">
                            <option value="auto">上一个活动（自动）</option>
                            <option value="none">不对比</option>
                            <option v-for="activity in review.activities.filter((item) => item.id !== review.selected_activity_id)" :key="activity.id" :value="activity.id">{{ activity.name }}（{{ period(activity) }}）</option>
                        </select>
                    </label>
                    <p class="rounded-xl bg-white px-3 py-3 text-xs leading-5 text-slate-500">
                        活动按开始日期倒序。默认选择最新已完成活动，并自动对比紧邻的上一个活动。
                    </p>
                </div>

                <div v-if="review.activity.campaign_images.length + review.activity.email_images.length" class="flex min-w-0 gap-3 overflow-x-auto pb-1 xl:col-span-2">
                    <button v-for="image in [...review.activity.campaign_images, ...review.activity.email_images]" :key="image" type="button" class="h-20 w-32 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-50" @click="selectedImage = image">
                        <img :src="image" alt="活动素材缩略图" class="h-full w-full object-cover" loading="lazy">
                    </button>
                </div>
            </div>
        </section>

        <section class="min-w-0 rounded-3xl border border-blue-100 bg-blue-50/35 p-4 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">核心指标</h2>
                    <p class="mt-1 text-xs text-slate-400">飞书活动字段；浏览、加购与结账指标来自 Shopify 数据库快照<span v-if="review.comparison_activity"> · 对比 {{ review.comparison_activity.name }}</span></p>
                </div>
                <span class="w-fit rounded-full bg-white px-3 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-100">本地数据库</span>
            </div>
            <div class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-6">
                <article v-for="card in cards" :key="card.key" class="min-h-20 min-w-0 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm">
                    <p class="text-xs font-medium text-slate-500">{{ card.label }}</p>
                    <strong class="mt-1.5 block truncate text-xl font-semibold leading-none tabular-nums text-slate-950" :title="formatMetric(card.metric.value, card.format)">{{ formatMetric(card.metric.value, card.format) }}</strong>
                    <p v-if="review.comparison_activity && card.metric.change_percent !== null" class="mt-1.5 text-xs font-semibold tabular-nums" :class="card.metric.change_percent >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                        {{ card.metric.change_percent >= 0 ? '+' : '' }}{{ card.metric.change_percent.toFixed(1) }}%
                    </p>
                    <p v-else-if="review.comparison_activity" class="mt-1.5 text-xs text-slate-400">对比 --</p>
                </article>
            </div>
        </section>

        <CampaignDailySalesChart
            :currency="currency"
            :points="review.daily_sales.points || []"
            :available="review.daily_sales.available"
            :pending="review.daily_sales.pending"
            :message="review.daily_sales.message"
            :ad-spend-available="review.daily_sales.ad_spend_available"
            :ad-spend-message="review.daily_sales.ad_spend_message"
            :ad-spend-reconciled="review.daily_sales.ad_spend_reconciled"
            :ad-spend-coverage-percent="review.daily_sales.ad_spend_coverage_percent"
        />

        <CampaignTrafficCostChart
            :currency="currency"
            :points="review.traffic_cost_trend.points || []"
            :available="review.traffic_cost_trend.available"
            :pending="review.traffic_cost_trend.pending"
            :stale="review.traffic_cost_trend.stale"
            :message="review.traffic_cost_trend.message"
            :ad-spend-available="review.traffic_cost_trend.ad_spend_available"
            :ad-spend-message="review.traffic_cost_trend.ad_spend_message"
            :ad-spend-reconciled="review.traffic_cost_trend.ad_spend_reconciled"
            :ad-spend-coverage-percent="review.traffic_cost_trend.ad_spend_coverage_percent"
        />

        <CampaignConversionFunnel
            :stages="review.funnel.stages || []"
            :available="review.funnel.available"
            :pending="review.funnel.pending"
            :message="review.funnel.message"
            :comparison-name="review.comparison_activity?.name || null"
        />

        <CampaignModelSales
            :models="review.model_sales.models || []"
            :total-units="review.model_sales.total_units || 0"
            :available="review.model_sales.available"
            :pending="review.model_sales.pending"
            :message="review.model_sales.message"
            :comparison-available="review.model_sales.comparison_available"
        />

        <section class="rounded-3xl border border-dashed border-slate-300 bg-slate-50/70 px-6 py-5">
            <h2 class="text-sm font-semibold text-slate-700">等待口径或数据接入的板块</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">广告渠道拆分花费与归因销售仍等待稳定口径，暂不展示数值，避免填充模拟数据。</p>
        </section>

        <Teleport to="body">
            <div v-if="selectedImage" class="fixed inset-0 z-[100] grid place-items-center bg-slate-950/75 p-5 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="活动素材预览" @click.self="selectedImage = null">
                <button type="button" class="absolute right-6 top-6 grid h-11 w-11 place-items-center rounded-full bg-white text-xl text-slate-700 shadow-lg" aria-label="关闭预览" @click="selectedImage = null">×</button>
                <img :src="selectedImage" alt="活动素材大图" class="max-h-[88vh] max-w-[92vw] rounded-2xl bg-white object-contain shadow-2xl">
            </div>
        </Teleport>
    </div>

    <section v-else class="grid min-h-96 place-items-center rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div>
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-2xl text-slate-400">✎</span>
            <h2 class="mt-4 text-lg font-semibold text-slate-800">暂无可复盘活动</h2>
            <p class="mt-2 text-sm text-slate-400">飞书活动销售额大于 0 后，会出现在复盘活动列表中。</p>
        </div>
    </section>
</template>
