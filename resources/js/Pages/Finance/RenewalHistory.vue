<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface PaymentHistory {
    uuid: string;
    type: 'initial_payment' | 'manual_renewal' | 'automatic_renewal';
    amount: string;
    currency: string;
    paid_on: string;
    reference: string | null;
    payer: string;
    request: { uuid: string; reference_no: string; title: string; category: string; submitter: string | null };
}
interface PaymentPage {
    data: PaymentHistory[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    total: number;
}

const props = defineProps<{
    organization: { id: number; name: string };
    history: { month: string; totals: Array<{ currency: string; total: string }>; payments: PaymentPage };
}>();

const month = ref(props.history.month);
const paymentTypeLabels: Record<PaymentHistory['type'], string> = {
    initial_payment: '首次付款',
    manual_renewal: '手动续费',
    automatic_renewal: '自动续费',
};

function applyMonth(): void {
    router.get('/finance/renewal-history', { month: month.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="续费历史记录" />
    <AppLayout :breadcrumbs="[{ label: '公司财务' }, { label: '续费历史记录' }]">
        <main class="mx-auto w-full max-w-7xl space-y-6">
            <header class="flex flex-col gap-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-sm font-semibold text-emerald-700">公司范围 · {{ organization.name }}</p><h1 class="mt-2 text-3xl font-semibold text-slate-950">续费历史记录</h1><p class="mt-2 text-sm text-slate-500">按月查看首次付款、手动续费和系统自动续费记录。</p></div>
                <form class="flex gap-2" @submit.prevent="applyMonth"><label class="text-xs font-semibold text-slate-500">月份<input v-model="month" type="month" class="mt-1 block h-10 rounded-xl border-slate-200 text-sm" /></label><button class="mt-5 h-10 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white">查看</button></form>
            </header>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap gap-2 border-b border-slate-100 bg-slate-50 px-6 py-4"><span v-for="total in history.totals" :key="total.currency" class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-slate-700 shadow-sm">{{ total.currency }} {{ total.total }}</span><span class="px-2 py-1 text-sm text-slate-400">共 {{ history.payments.total }} 笔</span></div>
                <div v-if="history.payments.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-6 py-4">付款日期</th><th class="px-6 py-4">项目</th><th class="px-6 py-4">付款类型</th><th class="px-6 py-4">金额</th><th class="px-6 py-4">申请人</th><th class="px-6 py-4">经办人</th><th class="px-6 py-4">参考号</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="record in history.payments.data" :key="record.uuid"><td class="px-6 py-4 font-medium text-slate-700">{{ record.paid_on }}</td><td class="px-6 py-4"><p class="font-semibold text-slate-900">{{ record.request.title }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ record.request.reference_no }}</p></td><td class="px-6 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="record.type === 'automatic_renewal' ? 'bg-blue-50 text-blue-700' : record.type === 'manual_renewal' ? 'bg-violet-50 text-violet-700' : 'bg-emerald-50 text-emerald-700'">{{ paymentTypeLabels[record.type] }}</span></td><td class="px-6 py-4 font-bold text-slate-900">{{ record.currency }} {{ record.amount }}</td><td class="px-6 py-4 text-slate-600">{{ record.request.submitter }}</td><td class="px-6 py-4 text-slate-600">{{ record.payer }}</td><td class="px-6 py-4 text-slate-500">{{ record.reference || '—' }}</td></tr></tbody>
                    </table>
                </div>
                <EmptyState v-else title="该月暂无续费记录" description="选择其他月份查看历史付款与续费记录。" icon="finance" />
                <div v-if="history.payments.links.length > 3" class="border-t border-slate-100 px-6 py-4"><Pagination :links="history.payments.links" /></div>
            </section>
        </main>
    </AppLayout>
</template>
