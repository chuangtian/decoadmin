<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import TrendChart from '../../Components/Analytics/TrendChart.vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Trend { date: string; label: string; sales: number; orders: number }
interface Product { product_id: number | null; title: string; units: number; revenue: number }
interface LowStock { id: number; sku: string; available: number }
const props = defineProps<{
    store: { id: number; name: string; currency: string };
    analytics: { period: { days: number }; summary: { sales: string; orders: number; average_order_value: string }; trend: Trend[]; top_products: Product[]; low_stock: LowStock[] };
}>();
const money = (value: number | string) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.store.currency }).format(Number(value || 0));
const changeDays = (event: Event) => router.get('/analytics/sales', { days: (event.target as HTMLSelectElement).value }, { preserveState: true });
</script>

<template>
    <Head title="销售分析" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '销售分析' }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-sm font-semibold text-emerald-700">数据分析 V2</p><h1 class="mt-1 text-3xl font-semibold text-slate-950">销售与订单趋势</h1><p class="mt-2 text-sm text-slate-500">基于 {{ store.name }} 已同步的 Shopify 真实数据。</p></div>
                <select :value="analytics.period.days" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold shadow-sm" @change="changeDays"><option :value="7">最近 7 天</option><option :value="30">最近 30 天</option><option :value="90">最近 90 天</option></select>
            </header>
            <section class="grid gap-4 md:grid-cols-3">
                <article v-for="item in [{label:'销售额',value:money(analytics.summary.sales)},{label:'订单数',value:analytics.summary.orders.toLocaleString('zh-CN')},{label:'平均客单价',value:money(analytics.summary.average_order_value)}]" :key="item.label" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-semibold text-slate-500">{{ item.label }}</p><p class="mt-4 text-3xl font-semibold tracking-tight text-slate-950">{{ item.value }}</p><p class="mt-2 text-xs text-slate-400">统计周期：{{ analytics.period.days }} 天</p></article>
            </section>
            <section class="grid gap-5 xl:grid-cols-2">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-900">销售趋势</h2><p class="mt-1 text-sm text-slate-500">每日订单销售总额</p><TrendChart class="mt-6" :points="analytics.trend" metric="sales" /></article>
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-900">订单趋势</h2><p class="mt-1 text-sm text-slate-500">每日新增订单数量</p><TrendChart class="mt-6" :points="analytics.trend" metric="orders" color="bg-blue-500" /></article>
            </section>
            <section class="grid gap-5 xl:grid-cols-2">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-900">热销商品</h2><p class="mt-1 text-sm text-slate-500">最近 30 天按销量排序</p></div><div v-if="analytics.top_products.length" class="divide-y divide-slate-100"><div v-for="(product,index) in analytics.top_products" :key="`${product.product_id}-${product.title}`" class="flex items-center gap-4 px-6 py-4"><span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-sm font-semibold text-emerald-700">{{ index+1 }}</span><div class="min-w-0 flex-1"><Link v-if="product.product_id" :href="`/products/${product.product_id}`" class="truncate font-semibold text-slate-900 hover:text-emerald-700">{{ product.title }}</Link><p v-else class="truncate font-semibold text-slate-900">{{ product.title }}</p><p class="mt-1 text-xs text-slate-400">{{ product.units }} 件</p></div><strong class="text-sm text-slate-800">{{ money(product.revenue) }}</strong></div></div><EmptyState v-else title="暂无热销商品" description="同步订单后将按真实销量生成排行。" icon="products" /></article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">库存预警</h2><p class="mt-1 text-sm text-slate-500">可用库存不高于 10</p></div><Link href="/inventory" class="text-sm font-semibold text-emerald-700">查看库存</Link></div><div v-if="analytics.low_stock.length" class="divide-y divide-slate-100"><div v-for="item in analytics.low_stock" :key="item.id" class="flex items-center justify-between px-6 py-4"><div><Link :href="`/inventory/${item.id}`" class="font-mono text-sm font-semibold text-slate-900 hover:text-emerald-700">{{ item.sku }}</Link><p class="mt-1 text-xs text-slate-400">库存项目 #{{ item.id }}</p></div><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="item.available <= 0 ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'">{{ item.available }} 可用</span></div></div><EmptyState v-else title="库存充足" description="当前没有低库存项目。" icon="inventory" /></article>
            </section>
        </div>
    </AppLayout>
</template>
