<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Attachment {
    uuid: string;
    name: string;
    url: string;
}

interface ReimbursementRow {
    uuid: string;
    reference_no: string;
    title: string;
    description: string;
    category: string;
    amount: string;
    currency: string;
    expense_date: string | null;
    submitter: string | null;
    reviewer: string | null;
    review_note: string | null;
    reviewed_at: string | null;
    payment_status: 'pending' | 'paid';
    payer: string | null;
    paid_on: string | null;
    payment_reference: string | null;
    attachments: Attachment[];
}

interface PageData<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    total: number;
}

type FilterStatus = 'pending' | 'paid' | 'all';

const props = defineProps<{
    organization: { id: number; name: string };
    canManage: boolean;
    today: string;
    filters: { payment_status: string };
    summary: { pending: number; paid: number };
    reimbursements: PageData<ReimbursementRow>;
}>();

const currencyFormatter = (value: string, currency: string): string => {
    const number = Number(value || 0);

    return new Intl.NumberFormat('zh-CN', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
        minimumFractionDigits: 2,
    }).format(number);
};

const formatDate = (value: string | null): string => {
    if (!value) return '—';

    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(`${value}T00:00:00`));
};

const formatDateTime = (value: string | null): string => {
    if (!value) return '—';

    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value.replace(' ', 'T')));
};

const categoryLabels: Record<string, string> = {
    travel: '差旅交通',
    advertising: '广告投放',
    software: '软件订阅',
    office: '办公用品',
    entertainment: '业务招待',
    logistics: '物流快递',
    other: '其他',
};

const statusMap: Record<ReimbursementRow['payment_status'], string> = {
    pending: '待报销',
    paid: '已报销',
};

const statusBadgeClass: Record<ReimbursementRow['payment_status'], string> = {
    pending: 'bg-amber-100 text-amber-800',
    paid: 'bg-emerald-100 text-emerald-800',
};

const allCount = computed(() => props.summary.pending + props.summary.paid);

const activeFilter = ref<FilterStatus>(
    props.filters.payment_status === 'paid' || props.filters.payment_status === 'pending' ? props.filters.payment_status : 'all',
);

const paymentForm = useForm({
    paid_on: props.today,
    payment_reference: '',
});

const activePayment = ref<ReimbursementRow | null>(null);

function applyFilter(payment_status: FilterStatus): void {
    activeFilter.value = payment_status;

    router.get('/finance/reimbursements', { payment_status }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function openPayment(row: ReimbursementRow): void {
    activePayment.value = row;
    paymentForm.reset('paid_on', 'payment_reference');
    paymentForm.paid_on = props.today;
    paymentForm.payment_reference = '';
}

function closePaymentDialog(): void {
    activePayment.value = null;
}

function submitPayment(): void {
    if (!activePayment.value) return;

    if (paymentForm.paid_on > props.today) {
        paymentForm.setError('paid_on', '打款日期不能晚于今天。');
        return;
    }

    paymentForm.post(`/finance/reimbursements/${activePayment.value.uuid}/payment`, {
        preserveScroll: true,
        onSuccess: () => {
            activePayment.value = null;
            paymentForm.reset('paid_on', 'payment_reference');
        },
    });
}
</script>

<template>
    <Head title="报销管理" />
    <AppLayout :breadcrumbs="[{ label: '公司财务' }, { label: '报销管理' }]">
        <main class="mx-auto w-full max-w-7xl space-y-6">
            <section class="rounded-3xl border border-emerald-100 bg-emerald-50 p-6">
                <p class="text-sm font-semibold text-emerald-700">财务流程说明</p>
                <h1 class="mt-1 text-3xl font-semibold text-emerald-950">报销管理</h1>
                <p class="mt-2 text-sm text-emerald-900/80">审批通过后由财务确认打款，完成财务报销闭环并留存付款凭证。</p>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <article class="rounded-3xl border border-amber-100 bg-amber-50 p-6 shadow-sm">
                    <p class="text-sm font-semibold text-amber-700">待报销</p>
                    <p class="mt-3 text-4xl font-bold text-amber-900">{{ summary.pending }}</p>
                    <p class="mt-1 text-sm text-amber-700">审批通过但未完成付款</p>
                </article>
                <article class="rounded-3xl border border-emerald-100 bg-emerald-50 p-6 shadow-sm">
                    <p class="text-sm font-semibold text-emerald-700">已报销</p>
                    <p class="mt-3 text-4xl font-bold text-emerald-900">{{ summary.paid }}</p>
                    <p class="mt-1 text-sm text-emerald-700">完成财务打款</p>
                </article>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">报销列表</h2>
                        <p class="mt-1 text-sm text-slate-500">共 {{ allCount }} 条记录。</p>
                    </div>
                    <div class="inline-flex rounded-full bg-slate-100 p-1">
                        <button
                            type="button"
                            class="rounded-full px-3 py-2 text-sm font-semibold transition"
                            :class="activeFilter === 'all' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-white'"
                            @click="applyFilter('all')"
                        >
                            全部 ({{ allCount }})
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-2 text-sm font-semibold transition"
                            :class="activeFilter === 'pending' ? 'bg-amber-400 text-white' : 'text-slate-700 hover:bg-white'"
                            @click="applyFilter('pending')"
                        >
                            待报销 ({{ summary.pending }})
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-2 text-sm font-semibold transition"
                            :class="activeFilter === 'paid' ? 'bg-emerald-600 text-white' : 'text-slate-700 hover:bg-white'"
                            @click="applyFilter('paid')"
                        >
                            已报销 ({{ summary.paid }})
                        </button>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div v-if="reimbursements.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                            <tr>
                                <th class="px-6 py-4">申请信息</th>
                                <th class="px-6 py-4">费用类型 / 金额</th>
                                <th class="px-6 py-4">提交人</th>
                                <th class="px-6 py-4">费用 / 审批时间</th>
                                <th class="px-6 py-4">发票附件</th>
                                <th class="px-6 py-4">状态 / 审批意见</th>
                                <th class="px-6 py-4">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in reimbursements.data" :key="row.uuid" class="hover:bg-slate-50/70">
                                <td class="px-6 py-5">
                                    <p class="text-xs font-mono text-slate-400">{{ row.reference_no }}</p>
                                    <p class="mt-1 font-semibold text-slate-900">{{ row.title }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ row.description }}</p>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="font-medium text-slate-900">{{ categoryLabels[row.category] || row.category }}</p>
                                    <p class="mt-1 font-semibold text-emerald-700">{{ currencyFormatter(row.amount, row.currency) }}</p>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="font-medium text-slate-900">{{ row.submitter }}</p>
                                    <p class="mt-1 text-xs text-slate-500">审批人：{{ row.reviewer }}</p>
                                </td>
                                <td class="px-6 py-5 text-xs text-slate-600">
                                    <p>费用日期：{{ formatDate(row.expense_date) }}</p>
                                    <p class="mt-1">审批时间：{{ formatDateTime(row.reviewed_at) }}</p>
                                </td>
                                <td class="px-6 py-5">
                                    <div v-if="row.attachments.length" class="flex flex-wrap gap-2">
                                        <a
                                            v-for="attachment in row.attachments"
                                            :key="attachment.uuid"
                                            :href="attachment.url"
                                            target="_blank"
                                            rel="noreferrer"
                                            class="group block w-16 overflow-hidden rounded-xl border border-slate-200 bg-white"
                                        >
                                            <img :src="attachment.url" :alt="attachment.name" class="h-16 w-16 object-cover" />
                                            <p class="h-8 overflow-hidden px-1 py-1 text-center text-xs leading-4 text-slate-500 group-hover:text-slate-700">{{ attachment.name }}</p>
                                        </a>
                                    </div>
                                    <span v-else class="text-slate-400">暂无附件</span>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass[row.payment_status]">{{ statusMap[row.payment_status] }}</span>
                                    <p class="mt-2 text-xs text-slate-500">{{ row.review_note || '暂无审批意见' }}</p>
                                    <p v-if="row.payment_status === 'paid'" class="mt-2 text-xs text-emerald-700">付款日期：{{ row.paid_on ? formatDate(row.paid_on) : '—' }}，经办人：{{ row.payer || '—' }}，参考号：{{ row.payment_reference || '—' }}</p>
                                </td>
                                <td class="px-6 py-5">
                                    <button
                                        v-if="canManage && row.payment_status === 'pending'"
                                        class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                        type="button"
                                        @click="openPayment(row)"
                                    >
                                        确认报销
                                    </button>
                                    <span v-else-if="row.payment_status === 'paid'" class="text-sm text-emerald-700">已完成</span>
                                    <span v-else class="text-sm text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <EmptyState v-else title="暂无报销记录" description="当前筛选条件下没有可展示的报销申请。" icon="finance" />
            </section>

            <Pagination v-if="reimbursements.links.length" :links="reimbursements.links" />

            <Teleport v-if="activePayment" to="body">
                <div class="fixed inset-0 z-50 grid place-items-center bg-slate-950/45 p-4" @click.self="closePaymentDialog">
                    <form class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl" @submit.prevent="submitPayment">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-500">{{ activePayment.reference_no }}</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-900">确认报销</h2>
                                <p class="mt-2 text-sm text-slate-500">{{ activePayment.title }} · {{ currencyFormatter(activePayment.amount, activePayment.currency) }}</p>
                            </div>
                            <button class="text-2xl text-slate-400" type="button" @click="closePaymentDialog">×</button>
                        </div>

                        <label class="mt-5 block text-sm font-semibold text-slate-700">
                            实际打款日期
                            <input
                                v-model="paymentForm.paid_on"
                                type="date"
                                :max="today"
                                required
                                class="mt-2 block h-11 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"
                            />
                        </label>

                        <label class="mt-4 block text-sm font-semibold text-slate-700">
                            付款参考号（可选）
                            <input
                                v-model="paymentForm.payment_reference"
                                type="text"
                                maxlength="180"
                                class="mt-2 block h-11 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"
                                placeholder="银行流水号、转账订单号"
                            />
                        </label>

                        <p v-if="paymentForm.hasErrors" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">
                            {{ Object.values(paymentForm.errors).join('；') }}
                        </p>

                        <div class="mt-6 flex justify-end gap-3">
                            <button class="rounded-xl border px-4 py-2 text-sm font-semibold" type="button" @click="closePaymentDialog">取消</button>
                            <button class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" :disabled="paymentForm.processing" type="submit">确认并提交</button>
                        </div>
                    </form>
                </div>
            </Teleport>
        </main>
    </AppLayout>
</template>
