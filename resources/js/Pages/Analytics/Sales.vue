<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DateRangeFilters from '../../Components/Analytics/DateRangeFilters.vue';
import TrendChart from '../../Components/Analytics/TrendChart.vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Trend { date: string; label: string; sales: number; net_sales: number; refunds: number; orders: number }
interface Ranking { product_id: number | null; name: string; vendor: string; product_type: string; units: number; net_sales: number }
interface Comparison { current: number; baseline: number; change: number; change_percent: number | null }
interface RiskItem { id: number; sku: string; available: number; units_sold: number; estimated_days_cover: number | null; turnover: number | null; risk: string }
const props = defineProps<{
    store: { id: number; name: string; currency: string };
    analytics: {
        period: { days: number; from: string; to: string; timezone: string; include_test: boolean; include_cancelled: boolean };
        summary: { gross_sales: number; net_sales: number; discounts: number; refunds: number; taxes: number; shipping: number; total_sales: number; orders: number; average_order_value: number };
        comparisons: { previous: Record<string, Comparison>; year_over_year: Record<string, Comparison> };
        trend: Trend[];
        customers: { active: number; new: number; returning: number; repeat_customers: number; repeat_rate: number; average_lifetime_value: number | null };
        rankings: { products: Ranking[]; vendors: Array<{vendor: string; units: number; net_sales: number}>; product_types: Array<{product_type: string; units: number; net_sales: number}> };
        inventory: { summary: { out_of_stock: number; low_stock: number; slow_moving: number }; items: RiskItem[] };
    };
}>();

const money = (value: number | string) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.store.currency }).format(Number(value || 0));
const comparisonPercent = (group: Record<string, Comparison>, key: string) => group[key]?.change_percent ?? null;
const percent = (value: number | null) => value === null ? '暂无基期' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
const riskLabel: Record<string, string> = { out_of_stock: '已缺货', low_stock: '低库存', slow_moving: '滞销风险', healthy: '正常' };
const cards = [
    ['净销售额', 'net_sales', true], ['订单数', 'orders', false], ['平均客单价', 'average_order_value', true],
    ['退款', 'refunds', true], ['折扣', 'discounts', true], ['税费', 'taxes', true],
] as const;
</script>

<template>
    <Head title="销售分析" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '销售分析' }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-sm font-semibold text-emerald-700">数据分析 V2</p><h1 class="mt-1 text-3xl font-semibold text-slate-950">销售经营分析</h1><p class="mt-2 text-sm text-slate-500">{{ store.name }} · {{ analytics.period.timezone }} · {{ store.currency }}</p></div>
                <Link href="/reports" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-sm">进入报表中心</Link>
            </header>

            <DateRangeFilters action="/analytics/sales" :period="analytics.period" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="([label,key,isMoney]) in cards" :key="key" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold text-slate-500">{{ label }}</p>
                    <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ isMoney ? money(analytics.summary[key]) : Number(analytics.summary[key]).toLocaleString('zh-CN') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700">环比 {{ percent(comparisonPercent(analytics.comparisons.previous, key)) }}</span>
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-blue-700">同比 {{ percent(comparisonPercent(analytics.comparisons.year_over_year, key)) }}</span>
                    </div>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-900">净销售趋势</h2><p class="mt-1 text-sm text-slate-500">按店铺时区归档，不含测试单和取消单（除非筛选开启）</p><TrendChart class="mt-6" :points="analytics.trend" metric="sales" /></article>
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-900">订单趋势</h2><p class="mt-1 text-sm text-slate-500">统计周期内每日有效订单</p><TrendChart class="mt-6" :points="analytics.trend" metric="orders" color="bg-blue-500" /></article>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <article v-for="item in [
                    ['活跃客户', analytics.customers.active], ['新客户', analytics.customers.new], ['老客户', analytics.customers.returning],
                    ['复购客户', analytics.customers.repeat_customers], ['复购率', `${analytics.customers.repeat_rate}%`], ['平均客户价值', analytics.customers.average_lifetime_value === null ? '请查看客户价值报告' : money(analytics.customers.average_lifetime_value)],
                ]" :key="String(item[0])" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold text-slate-500">{{ item[0] }}</p><p class="mt-2 text-xl font-semibold text-slate-950">{{ item[1] }}</p></article>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.35fr_1fr]">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-900">商品销售排行</h2><p class="mt-1 text-sm text-slate-500">按商品净销售额排序</p></div>
                    <div v-if="analytics.rankings.products.length" class="divide-y divide-slate-100">
                        <div v-for="(product,index) in analytics.rankings.products.slice(0, 10)" :key="`${product.product_id}-${product.name}`" class="grid grid-cols-[36px_minmax(0,1fr)_90px_120px] items-center gap-3 px-6 py-4">
                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-50 text-xs font-semibold text-emerald-700">{{ index+1 }}</span>
                            <div class="min-w-0"><Link v-if="product.product_id" :href="`/products/${product.product_id}`" class="block truncate font-semibold text-slate-900 hover:text-emerald-700">{{ product.name }}</Link><p v-else class="truncate font-semibold text-slate-900">{{ product.name }}</p><p class="mt-1 truncate text-xs text-slate-400">{{ product.vendor }} · {{ product.product_type }}</p></div>
                            <span class="text-right text-sm text-slate-500">{{ product.units }} 件</span><strong class="text-right text-sm text-slate-900">{{ money(product.net_sales) }}</strong>
                        </div>
                    </div>
                    <EmptyState v-else title="暂无商品销售数据" description="同步订单后将生成真实排行。" icon="products" />
                </article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">库存周转与缺货风险</h2><p class="mt-1 text-sm text-slate-500">结合周期销量和当前库存估算</p></div><Link href="/inventory" class="text-sm font-semibold text-emerald-700">库存详情</Link></div>
                    <div class="grid grid-cols-3 gap-px bg-slate-100"><div v-for="item in [['已缺货',analytics.inventory.summary.out_of_stock],['低库存',analytics.inventory.summary.low_stock],['滞销风险',analytics.inventory.summary.slow_moving]]" :key="String(item[0])" class="bg-slate-50 p-4 text-center"><p class="text-xs text-slate-500">{{ item[0] }}</p><p class="mt-1 text-xl font-semibold text-slate-900">{{ item[1] }}</p></div></div>
                    <div v-if="analytics.inventory.items.length" class="divide-y divide-slate-100"><div v-for="item in analytics.inventory.items.slice(0, 8)" :key="item.id" class="flex items-center justify-between gap-4 px-6 py-4"><div><Link :href="`/inventory/${item.id}`" class="font-mono text-sm font-semibold text-slate-900">{{ item.sku }}</Link><p class="mt-1 text-xs text-slate-400">售出 {{ item.units_sold }} · 可售 {{ item.estimated_days_cover ?? '—' }} 天</p></div><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="item.risk === 'out_of_stock' ? 'bg-rose-50 text-rose-700' : item.risk === 'low_stock' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'">{{ riskLabel[item.risk] }}</span></div></div>
                </article>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm"><h2 class="font-semibold text-slate-900">统计口径</h2><p class="mt-2">净销售额为当前商品小计，已扣除退款影响；总销售额、税费、运费和折扣独立展示。默认排除 Shopify 测试订单与已取消订单。</p></section>
        </div>
    </AppLayout>
</template>
