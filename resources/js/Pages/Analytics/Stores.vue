<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface StoreComparison {
    id: number;
    name: string;
    currency: string;
    orders: number;
    sales: number;
    average_order_value: number;
}

const props = defineProps<{
    organization: { id: number; name: string };
    comparison: { period: { days: number }; stores: StoreComparison[] };
}>();

const maxSales = Math.max(...props.comparison.stores.map((store) => store.sales), 1);
const money = (value: number, currency: string) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(value);
const changeDays = (event: Event) => router.get(
    '/analytics/stores',
    { days: (event.target as HTMLSelectElement).value },
    { preserveState: true },
);
</script>

<template>
    <Head title="店铺对比" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '店铺对比' }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ organization.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold text-slate-950">授权店铺对比</h1>
                    <p class="mt-2 text-sm text-slate-500">仅比较当前用户有权访问的店铺。</p>
                </div>
                <select :value="comparison.period.days" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold shadow-sm" @change="changeDays">
                    <option :value="7">最近 7 天</option>
                    <option :value="30">最近 30 天</option>
                    <option :value="90">最近 90 天</option>
                </select>
            </header>

            <section v-if="comparison.stores.length" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="hidden grid-cols-[minmax(180px,1fr)_2fr_140px_160px] gap-5 border-b border-slate-100 bg-slate-50 px-6 py-4 text-xs font-semibold text-slate-500 md:grid">
                    <span>店铺</span>
                    <span>销售表现</span>
                    <span>订单</span>
                    <span>客单价</span>
                </div>
                <div class="divide-y divide-slate-100">
                    <article v-for="store in comparison.stores" :key="store.id" class="grid gap-5 px-5 py-5 md:grid-cols-[minmax(180px,1fr)_2fr_140px_160px] md:items-center md:px-6">
                        <div>
                            <p class="font-semibold text-slate-900">{{ store.name }}</p>
                            <p class="mt-1 text-xs text-slate-400">店铺 #{{ store.id }}</p>
                        </div>
                        <div>
                            <p class="mb-2 text-xs font-semibold text-slate-500 md:hidden">销售表现</p>
                            <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.max((store.sales / maxSales) * 100, store.sales ? 3 : 0)}%` }" />
                            </div>
                            <p class="mt-2 text-sm font-semibold text-slate-800">{{ money(store.sales, store.currency) }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-5 md:contents">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 md:hidden">订单</p>
                                <strong class="mt-1 block text-slate-900 md:mt-0">{{ store.orders.toLocaleString('zh-CN') }} 单</strong>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-slate-500 md:hidden">客单价</p>
                                <span class="mt-1 block font-semibold text-slate-700 md:mt-0">{{ money(store.average_order_value, store.currency) }}</span>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <EmptyState v-else title="暂无可对比店铺" description="当前组织没有已授权且启用的店铺。" icon="stores" />
        </div>
    </AppLayout>
</template>
