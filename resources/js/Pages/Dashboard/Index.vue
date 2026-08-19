<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import TrendChart from '../../Components/Analytics/TrendChart.vue';
import AppIcon from '../../Components/Layout/AppIcon.vue';
import ShopifyConnectionStatus from '../../Components/Shopify/ShopifyConnectionStatus.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { ShopifyConnectionStatus as ConnectionStatus } from '../../types';

interface DashboardData {
    store: { id: number; name: string; shopify_domain: string; currency: string } | null;
    summary: {
        orders: number;
        orders_today: number;
        sales: number;
        sales_today: number;
        products: number;
        customers: number;
        inventory_available: number;
    };
    operations: {
        connection_status: ConnectionStatus | 'pending';
        last_sync_at: string | null;
        open_alerts: number;
        failed_sync_jobs_24h: number;
        failed_webhooks_24h: number;
    };
    sales_trend: Array<{ date: string; label: string; amount: number; orders: number }>;
    recent_orders: Array<{
        id: number;
        order_number: string;
        email: string | null;
        financial_status: string | null;
        total_price: string;
        currency: string;
        processed_at: string | null;
    }>;
    analytics_30d: {
        summary: { sales: string; orders: number; average_order_value: string };
        trend: Array<{ date: string; label: string; sales: number; orders: number }>;
        top_products: Array<{ product_id: number | null; title: string; units: number; revenue: number }>;
        low_stock: Array<{ id: number; sku: string; available: number }>;
    };
    store_comparison: { stores: Array<{ id: number; name: string; currency: string; orders: number; sales: number }> };
}

const props = defineProps<{ dashboard: DashboardData }>();

const maxSales = computed(() => Math.max(...props.dashboard.sales_trend.map((item) => item.amount), 1));

const money = (value: number | string, currency?: string) => new Intl.NumberFormat('zh-CN', {
    style: 'currency',
    currency: currency || props.dashboard.store?.currency || 'USD',
}).format(Number(value || 0));

const number = (value: number) => new Intl.NumberFormat('zh-CN').format(value);

const dateTime = (value: string | null) => value
    ? new Intl.DateTimeFormat('zh-CN', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '暂无记录';

const financialLabel = (status: string | null) => ({
    paid: '已付款', pending: '待付款', refunded: '已退款', partially_refunded: '部分退款', voided: '已作废', authorized: '已授权',
}[status || ''] || status || '未知');
</script>

<template>
    <Head title="工作台" />
    <AppLayout :breadcrumbs="[{ label: '工作台' }]">
        <section class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-emerald-700">运营概览</p>
                <h1 class="mt-1 text-3xl font-semibold tracking-[-0.03em] text-slate-950">{{ dashboard.store?.name ?? '工作台' }}</h1>
                <p class="mt-2 text-sm text-slate-500">订单、销售、商品、客户、库存及运行异常均来自当前授权店铺。</p>
            </div>
            <div v-if="dashboard.store" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs font-semibold text-slate-400">SHOPIFY 店铺</p>
                <p class="mt-1 font-mono text-sm font-semibold text-slate-800">{{ dashboard.store.shopify_domain }}</p>
            </div>
        </section>

        <section v-if="!dashboard.store" class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <AppIcon name="stores" :size="34" class="mx-auto text-slate-300" />
            <h2 class="mt-4 text-lg font-semibold text-slate-900">请先选择店铺</h2>
            <p class="mt-2 text-sm text-slate-500">选择有权限的店铺后，这里会显示该店铺的真实运营数据。</p>
        </section>

        <template v-else>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="card in [
                    { label: '累计销售额', value: money(dashboard.summary.sales), detail: `今日 ${money(dashboard.summary.sales_today)}`, icon: 'analytics' },
                    { label: '订单', value: number(dashboard.summary.orders), detail: `今日 ${dashboard.summary.orders_today} 单`, icon: 'orders' },
                    { label: '商品', value: number(dashboard.summary.products), detail: '已同步商品', icon: 'products' },
                    { label: '客户', value: number(dashboard.summary.customers), detail: '已同步客户', icon: 'customers' },
                ]" :key="card.label" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between">
                        <p class="text-sm font-semibold text-slate-500">{{ card.label }}</p>
                        <span class="grid h-10 w-10 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><AppIcon :name="card.icon" :size="20" /></span>
                    </div>
                    <p class="mt-5 text-2xl font-semibold tracking-tight text-slate-950">{{ card.value }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ card.detail }}</p>
                </article>
            </section>

            <section class="mt-5 grid gap-5 xl:grid-cols-[1.55fr_1fr]">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div><p class="text-xs font-semibold tracking-wider text-slate-400">最近 7 天</p><h2 class="mt-1 text-lg font-semibold text-slate-900">销售趋势</h2></div>
                        <Link href="/orders" class="text-sm font-semibold text-emerald-700">查看订单</Link>
                    </div>
                    <div class="mt-8 flex h-56 items-end gap-3">
                        <div v-for="item in dashboard.sales_trend" :key="item.date" class="flex min-w-0 flex-1 flex-col items-center justify-end gap-2">
                            <span class="text-[10px] font-semibold text-slate-400">{{ item.amount ? money(item.amount) : '' }}</span>
                            <div class="w-full rounded-t-xl bg-emerald-500/85 transition-all" :style="{ height: `${Math.max((item.amount / maxSales) * 150, item.orders ? 14 : 4)}px` }" />
                            <span class="text-xs text-slate-500">{{ item.label }}</span>
                        </div>
                    </div>
                </article>

                <article class="rounded-3xl bg-[#0d1828] p-6 text-white shadow-xl shadow-slate-900/8">
                    <p class="text-xs font-semibold tracking-wider text-slate-500">运营状态</p>
                    <div class="mt-5 flex items-center justify-between border-b border-white/10 pb-5">
                        <span class="text-sm text-slate-300">Shopify 连接</span>
                        <ShopifyConnectionStatus :status="dashboard.operations.connection_status" />
                    </div>
                    <div class="divide-y divide-white/10">
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">可用库存</span><strong>{{ number(dashboard.summary.inventory_available) }}</strong></div>
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">未处理告警</span><Link href="/alerts" class="font-semibold text-amber-300">{{ dashboard.operations.open_alerts }}</Link></div>
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">24 小时同步失败</span><strong :class="dashboard.operations.failed_sync_jobs_24h ? 'text-rose-300' : 'text-emerald-300'">{{ dashboard.operations.failed_sync_jobs_24h }}</strong></div>
                        <div class="flex items-center justify-between py-4"><span class="text-sm text-slate-300">24 小时 Webhook 失败</span><strong :class="dashboard.operations.failed_webhooks_24h ? 'text-rose-300' : 'text-emerald-300'">{{ dashboard.operations.failed_webhooks_24h }}</strong></div>
                    </div>
                    <p class="mt-4 text-xs text-slate-500">最近同步：{{ dateTime(dashboard.operations.last_sync_at) }}</p>
                </article>
            </section>

            <section class="mt-5 grid gap-5 xl:grid-cols-2">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-start justify-between"><div><p class="text-xs font-semibold tracking-wider text-slate-400">最近 30 天</p><h2 class="mt-1 text-lg font-semibold text-slate-900">订单趋势</h2></div><Link href="/analytics/sales" class="text-sm font-semibold text-emerald-700">完整分析</Link></div>
                    <TrendChart class="mt-6" :points="dashboard.analytics_30d.trend" metric="orders" color="bg-blue-500" />
                </article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">热销商品</h2><p class="mt-1 text-xs text-slate-400">最近 30 天</p></div><Link href="/analytics/sales" class="text-sm font-semibold text-emerald-700">查看全部</Link></div>
                    <div v-if="dashboard.analytics_30d.top_products.length" class="divide-y divide-slate-100"><div v-for="(product,index) in dashboard.analytics_30d.top_products.slice(0,5)" :key="`${product.product_id}-${product.title}`" class="flex items-center gap-4 px-6 py-4"><span class="grid h-8 w-8 place-items-center rounded-xl bg-emerald-50 text-xs font-semibold text-emerald-700">{{index+1}}</span><div class="min-w-0 flex-1"><Link v-if="product.product_id" :href="`/products/${product.product_id}`" class="block truncate font-semibold text-slate-900">{{product.title}}</Link><p v-else class="truncate font-semibold text-slate-900">{{product.title}}</p></div><span class="text-sm font-semibold text-slate-600">{{product.units}} 件</span></div></div>
                    <p v-else class="px-6 py-10 text-center text-sm text-slate-400">暂无商品销量数据。</p>
                </article>
            </section>

            <section class="mt-5 grid gap-5 xl:grid-cols-2">
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">库存预警</h2><p class="mt-1 text-xs text-slate-400">可用库存不高于 10</p></div><Link href="/inventory" class="text-sm font-semibold text-emerald-700">库存管理</Link></div><div v-if="dashboard.analytics_30d.low_stock.length" class="divide-y divide-slate-100"><div v-for="item in dashboard.analytics_30d.low_stock.slice(0,5)" :key="item.id" class="flex items-center justify-between px-6 py-4"><Link :href="`/inventory/${item.id}`" class="font-mono text-sm font-semibold text-slate-900">{{item.sku}}</Link><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="item.available<=0?'bg-rose-50 text-rose-700':'bg-amber-50 text-amber-700'">{{item.available}} 可用</span></div></div><p v-else class="px-6 py-10 text-center text-sm text-slate-400">当前库存充足。</p></article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="font-semibold text-slate-900">店铺对比</h2><p class="mt-1 text-xs text-slate-400">授权店铺 30 天销售额</p></div><Link href="/analytics/stores" class="text-sm font-semibold text-emerald-700">详细对比</Link></div><div class="divide-y divide-slate-100"><div v-for="store in dashboard.store_comparison.stores.slice(0,5)" :key="store.id" class="flex items-center justify-between px-6 py-4"><div><p class="font-semibold text-slate-900">{{store.name}}</p><p class="mt-1 text-xs text-slate-400">{{store.orders}} 单</p></div><strong>{{money(store.sales,store.currency)}}</strong></div></div></article>
            </section>

            <section class="mt-5 rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-900">最近订单</h2><Link href="/orders" class="text-sm font-semibold text-emerald-700">全部订单</Link></div>
                <div v-if="dashboard.recent_orders.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500"><tr><th class="px-6 py-3">订单</th><th class="px-6 py-3">客户</th><th class="px-6 py-3">付款状态</th><th class="px-6 py-3">金额</th><th class="px-6 py-3">时间</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="order in dashboard.recent_orders" :key="order.id"><td class="px-6 py-4"><Link :href="`/orders/${order.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ order.order_number }}</Link></td><td class="px-6 py-4 text-slate-500">{{ order.email || '未提供' }}</td><td class="px-6 py-4 text-slate-600">{{ financialLabel(order.financial_status) }}</td><td class="px-6 py-4 font-semibold text-slate-900">{{ money(order.total_price, order.currency) }}</td><td class="px-6 py-4 text-slate-500">{{ dateTime(order.processed_at) }}</td></tr></tbody>
                    </table>
                </div>
                <p v-else class="px-6 py-10 text-center text-sm text-slate-400">当前店铺暂时没有已同步订单。</p>
            </section>
        </template>
    </AppLayout>
</template>
