<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateRangeFilters from '../../Components/Analytics/DateRangeFilters.vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    report: {
        type: string;
        period: { days: number; from: string; to: string; include_test: boolean; include_cancelled: boolean };
        summary: { net_sales: number; orders: number; refunds: number; average_order_value: number };
        headers: Array<{ key: string; label: string }>;
        rows: Array<Record<string, string | number | null>>;
    };
    canExport: boolean;
}>();

const type = ref(props.report.type);
const moneyKeys = new Set(['net_sales', 'refunds', 'lifetime_value']);
const riskLabels: Record<string, string> = { out_of_stock: '已缺货', low_stock: '低库存', slow_moving: '滞销风险', healthy: '正常' };
const money = (value: unknown) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.store.currency }).format(Number(value || 0));
const display = (key: string, value: unknown) => key === 'risk' ? (riskLabels[String(value)] ?? value) : moneyKeys.has(key) ? money(value) : (value ?? '—');
const filterExtra = computed(() => ({ report_type: type.value }));
const changeType = () => router.get('/reports', { report_type: type.value, date_from: props.report.period.from, date_to: props.report.period.to, include_test: props.report.period.include_test ? 1 : 0, include_cancelled: props.report.period.include_cancelled ? 1 : 0 }, { preserveState: true, replace: true });
const exportUrl = (format: string) => {
    const params = new URLSearchParams({ report_type: type.value, date_from: props.report.period.from, date_to: props.report.period.to, include_test: props.report.period.include_test ? '1' : '0', include_cancelled: props.report.period.include_cancelled ? '1' : '0' });
    return `/reports/export/${format}?${params.toString()}`;
};
</script>

<template>
    <Head title="报表中心" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '报表中心' }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-emerald-700">经营报表</p><h1 class="mt-1 text-3xl font-semibold text-slate-950">报表中心</h1><p class="mt-2 text-sm text-slate-500">{{ store.name }} · 支持筛选、CSV 和 Excel 导出。</p></div><div v-if="canExport" class="flex gap-2"><a :href="exportUrl('csv')" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm">导出 CSV</a><a :href="exportUrl('excel')" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm">导出 Excel</a></div></header>
            <DateRangeFilters action="/reports" :period="report.period" :extra="filterExtra" />
            <section class="flex flex-col gap-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-semibold text-slate-900">报表类型</h2><p class="mt-1 text-sm text-slate-500">按当前店铺和统计周期生成。</p></div><select v-model="type" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold" @change="changeType"><option value="sales">销售日报</option><option value="products">商品排行</option><option value="customers">客户价值</option><option value="inventory">库存风险</option></select></section>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><article v-for="item in [['净销售额',money(report.summary.net_sales)],['订单',report.summary.orders],['退款',money(report.summary.refunds)],['客单价',money(report.summary.average_order_value)]]" :key="String(item[0])" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold text-slate-500">{{ item[0] }}</p><p class="mt-2 text-2xl font-semibold text-slate-950">{{ item[1] }}</p></article></section>
            <section v-if="report.rows.length" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th v-for="header in report.headers" :key="header.key" class="whitespace-nowrap px-5 py-4">{{ header.label }}</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="(row,index) in report.rows" :key="index" class="hover:bg-slate-50"><td v-for="header in report.headers" :key="header.key" class="whitespace-nowrap px-5 py-4 text-slate-700">{{ display(header.key, row[header.key]) }}</td></tr></tbody></table></div></section>
            <EmptyState v-else title="当前报表暂无数据" description="调整统计周期，或先同步当前店铺的 Shopify 数据。" icon="reports" />
        </div>
    </AppLayout>
</template>
