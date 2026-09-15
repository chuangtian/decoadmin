<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Row = {
    uuid: string;
    reference_no: string;
    kind: 'technical' | 'expense_request' | 'expense';
    title: string;
    description: string;
    category: string;
    priority: string | null;
    desired_date: string | null;
    amount: string | null;
    currency: string | null;
    expense_date: string | null;
    status: string;
    approval_required: boolean;
    submitter: string;
    reviewer: string | null;
    review_note: string | null;
    created_at: string;
    software_url: string | null;
    software_account: string | null;
    software_password_set: boolean;
    software_payment_method: string | null;
    renewal_mode: string | null;
    billing_cycle: string | null;
    renewal_status: string | null;
    next_renewal_on: string | null;
    cancelled_on: string | null;
    attachments: Array<{ uuid: string; name: string; url: string }>;
};

type Page = { data: Row[]; current_page: number; last_page: number; total: number };

const props = defineProps<{
    canManage: boolean;
    filters: { status: string; kind: string };
    requests: Page;
}>();

const statusLabels: Record<string, string> = {
    pending_approval: '待审批',
    approved: '已通过',
    rejected: '已驳回',
};
const categoryLabels: Record<string, string> = {
    travel: '差旅预算',
    advertising: '广告预算',
    software: '软件采购',
    office: '办公采购',
    entertainment: '业务招待',
    logistics: '物流费用',
    other: '其他费用',
};
const billingCycleLabels: Record<string, string> = {
    monthly: '月付',
    bimonthly: '双月付',
    quarterly: '季付',
    annual: '年付',
};
const billingCycleLabel = (cycle: string | null): string => cycle ? (billingCycleLabels[cycle] ?? cycle) : '未填写';
const renewalModeLabel = (mode: string | null): string => mode === 'automatic' ? '自动续费' : mode === 'manual' ? '手动续费' : '未填写';
const categoryLabel = (category: string): string => categoryLabels[category] ?? category ?? '未填写';
const filters = ref({ ...props.filters });
const selected = ref<Row | null>(null);
const reviewOpen = ref(false);
const form = useForm({ action: 'approve' as 'approve' | 'reject', note: '' });

function apply(): void {
    router.get('/request-approvals', filters.value, { preserveState: true, replace: true });
}

function goToPage(page: number): void {
    if (page < 1 || page > props.requests.last_page) return;
    router.get('/request-approvals', { ...filters.value, page }, { preserveState: true, replace: true });
}

function requestTypeName(kind: Row['kind']): string {
    return kind === 'technical' ? '技术需求' : kind === 'expense_request' ? '费用申请' : '发票报销';
}

function requestTypeClass(kind: Row['kind']): string {
    return kind === 'technical'
        ? 'bg-blue-50 text-blue-700'
        : kind === 'expense_request'
          ? 'bg-orange-50 text-orange-700'
          : 'bg-emerald-50 text-emerald-700';
}

function requestStatusClass(status: string, approvalRequired: boolean): string {
    if (!approvalRequired) {
        return 'bg-slate-100 text-slate-700';
    }

    return status === 'approved'
        ? 'bg-emerald-50 text-emerald-700'
        : status === 'rejected'
          ? 'bg-rose-50 text-rose-700'
          : 'bg-amber-50 text-amber-700';
}

function requestRowClass(status: string, approvalRequired: boolean): string {
    if (!approvalRequired) {
        return 'bg-white';
    }

    return status === 'pending_approval'
        ? 'bg-amber-50/35'
        : status === 'approved'
          ? 'bg-emerald-50/35'
          : 'bg-rose-50/35';
}

function displayAmountOrDate(row: Row): string {
    if (row.kind === 'technical') {
        return row.desired_date ?? '未填写';
    }

    return `${row.currency ?? ''} ${row.amount ?? '0.00'}`.trim() || '未填写';
}

function attachmentLabel(total: number): string {
    return total > 1 ? `+${total - 1}` : '';
}

function open(row: Row): void {
    selected.value = row;
    form.reset();
    form.clearErrors();
    form.action = 'approve';
    form.note = '';
    reviewOpen.value = true;
}

function closeReview(): void {
    reviewOpen.value = false;
    selected.value = null;
    form.reset();
    form.clearErrors();
    form.action = 'approve';
    form.note = '';
}

function review(action: 'approve' | 'reject'): void {
    if (!selected.value) return;
    form.action = action;
    form.put(`/request-approvals/${selected.value.uuid}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeReview();
        },
    });
}
</script>

<template>
    <Head title="需求审批" />
    <AppLayout :breadcrumbs="[{ label: '个人' }, { label: '需求和报销' }, { label: '需求审批' }]">
        <main class="mx-auto w-full max-w-[1400px] space-y-5">
            <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">超级管理员审批工作台</span>
                <h1 class="mt-3 text-2xl font-bold text-slate-950">需求审批</h1>
                <p class="mt-1.5 text-sm text-slate-500">集中审核技术需求、费用申请和发票报销，所有审批意见都会保留。</p>
            </header>

            <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[180px_180px_auto]" @submit.prevent="apply">
                <select v-model="filters.kind" class="h-11 rounded-xl border-slate-200 text-sm">
                    <option value="">全部类型</option>
                    <option value="technical">技术需求</option>
                    <option value="expense_request">费用申请</option>
                    <option value="expense">发票报销</option>
                </select>
                <select v-model="filters.status" class="h-11 rounded-xl border-slate-200 text-sm">
                    <option value="pending_approval">待审批</option>
                    <option value="approved">已通过</option>
                    <option value="rejected">已驳回</option>
                </select>
                <button class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white sm:justify-self-start">筛选</button>
            </form>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1380px] text-sm text-slate-700">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">编号/申请信息</th>
                                <th class="px-4 py-3 text-left font-semibold">申请类型 / 费用类型</th>
                                <th class="px-4 py-3 text-left font-semibold">提交人/时间</th>
                                <th class="px-4 py-3 text-left font-semibold">金额或期望日期</th>
                                <th class="w-64 px-4 py-3 text-left font-semibold">采购信息</th>
                                <th class="px-4 py-3 text-left font-semibold">附件</th>
                                <th class="px-4 py-3 text-left font-semibold">状态/审批意见</th>
                                <th class="px-4 py-3 text-left font-semibold">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="requests.data.length">
                                <tr v-for="row in requests.data" :key="row.uuid" :class="[requestRowClass(row.status, row.approval_required), 'border-b border-slate-200']">
                                    <td class="max-w-[260px] px-4 py-3 align-top">
                                        <p class="font-mono text-xs text-slate-500">{{ row.reference_no }}</p>
                                        <p class="mt-1 font-semibold text-slate-900">{{ row.title }}</p>
                                        <p class="mt-1 line-clamp-2 max-w-[240px] text-slate-500">{{ row.description }}</p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="requestTypeClass(row.kind)">
                                            {{ requestTypeName(row.kind) }}
                                        </span>
                                        <p v-if="row.kind === 'expense_request'" class="mt-2 font-semibold text-slate-700">
                                            {{ categoryLabel(row.category) }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <p class="font-semibold text-slate-700">{{ row.submitter }}</p>
                                        <p class="mt-1 text-slate-500">{{ row.created_at }}</p>
                                    </td>
                                    <td class="px-4 py-3 align-top whitespace-nowrap">
                                        <p class="font-semibold text-slate-900">{{ displayAmountOrDate(row) }}</p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div v-if="row.kind === 'expense_request'" class="max-w-64 space-y-1 text-xs text-slate-600">
                                            <a v-if="row.software_url" :href="row.software_url" target="_blank" rel="noreferrer" class="block truncate font-semibold text-blue-600 hover:underline">{{ row.software_url }}</a>
                                            <p>付费方式：{{ row.software_payment_method || '未填写' }}</p>
                                            <p class="truncate">账号：{{ row.software_account || '未填写' }}</p>
                                            <p>密码：{{ row.software_password_set ? '已安全保存' : '未填写' }}</p>
                                            <p class="font-semibold text-orange-700">{{ renewalModeLabel(row.renewal_mode) }} · {{ billingCycleLabel(row.billing_cycle) }}</p>
                                            <p v-if="row.next_renewal_on" class="font-semibold text-orange-700">下次续费：{{ row.next_renewal_on }}</p>
                                            <p v-if="row.renewal_status === 'cancelled'" class="font-semibold text-amber-700">已取消续费 · {{ row.cancelled_on || '日期未填写' }}</p>
                                        </div>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div v-if="row.attachments.length" class="flex items-center gap-2">
                                            <a :href="row.attachments[0].url" target="_blank" rel="noreferrer">
                                                <img :src="row.attachments[0].url" :alt="row.attachments[0].name" class="h-10 w-14 rounded-lg border border-slate-200 object-cover" />
                                            </a>
                                            <span v-if="row.attachments.length > 1" class="inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-slate-900 px-2 text-xs font-semibold text-white">
                                                {{ attachmentLabel(row.attachments.length) }}
                                            </span>
                                        </div>
                                        <span v-else class="text-slate-400">无</span>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="requestStatusClass(row.status, row.approval_required)"
                                        >
                                            {{ row.approval_required ? (statusLabels[row.status] || row.status) : '免审批' }}
                                        </span>
                                        <p v-if="row.review_note" class="mt-2 line-clamp-2 max-w-[220px] text-slate-500">{{ row.review_note }}</p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <button
                                            v-if="canManage && row.status === 'pending_approval' && row.approval_required"
                                            class="rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white"
                                            @click="open(row)"
                                        >审批</button>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                </tr>
                            </template>
                            <tr v-else>
                                <td colspan="8" class="px-4 py-12 text-center text-slate-400">当前没有需要审批的申请</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="requests.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3">
                    <p class="text-slate-500">共 {{ requests.total }} 条</p>
                    <div class="flex items-center gap-2">
                        <button
                            class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-600 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="requests.current_page <= 1"
                            @click="goToPage(requests.current_page - 1)"
                        >上一页</button>
                        <span class="px-2 text-sm text-slate-500">第 {{ requests.current_page }} / {{ requests.last_page }} 页</span>
                        <button
                            class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-600 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="requests.current_page >= requests.last_page"
                            @click="goToPage(requests.current_page + 1)"
                        >下一页</button>
                    </div>
                </div>
            </section>
        </main>

        <Teleport to="body">
            <dialog
                :open="reviewOpen"
                class="fixed inset-0 z-50 m-0 h-full w-full max-w-none items-center justify-center overflow-hidden bg-slate-950/45 p-3 open:flex sm:p-6"
                @click.self="closeReview"
            >
                <form
                    v-if="selected"
                    class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl sm:max-h-[calc(100dvh-3rem)]"
                    @submit.prevent="review('approve')"
                >
                    <div class="flex shrink-0 justify-between border-b border-slate-100 px-5 py-4 sm:px-7 sm:py-5">
                        <div><p class="text-xs font-semibold text-amber-600">{{ selected.reference_no }}</p><h2 class="mt-1 text-xl font-bold">审批：{{ selected.title }}</h2></div>
                        <button type="button" class="text-2xl text-slate-400" aria-label="关闭审批" @click="closeReview">×</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-7 sm:py-5">
                        <p class="rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ selected.description }}</p>
                        <dl v-if="selected.kind === 'expense_request'" class="mt-4 grid grid-cols-2 gap-3 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm">
                            <div class="col-span-2"><dt class="font-semibold text-orange-800">采购信息</dt></div>
                            <div class="col-span-2"><dt class="text-xs text-slate-400">费用类型</dt><dd class="mt-1 font-semibold text-slate-700">{{ categoryLabel(selected.category) }}</dd></div>
                            <div class="col-span-2"><dt class="text-xs text-slate-400">付费方式</dt><dd class="mt-1 text-slate-700">{{ selected.software_payment_method || '未填写' }}</dd></div>
                            <div class="col-span-2"><dt class="text-xs text-slate-400">软件网址</dt><dd class="mt-1 truncate"><a v-if="selected.software_url" :href="selected.software_url" target="_blank" rel="noreferrer" class="font-medium text-blue-600 hover:underline">{{ selected.software_url }}</a><span v-else class="text-slate-400">未填写</span></dd></div>
                            <div><dt class="text-xs text-slate-400">登录账号</dt><dd class="mt-1 truncate text-slate-700">{{ selected.software_account || '未填写' }}</dd></div>
                            <div><dt class="text-xs text-slate-400">登录密码</dt><dd class="mt-1 text-slate-700">{{ selected.software_password_set ? '已安全保存' : '未填写' }}</dd></div>
                            <div><dt class="text-xs text-slate-400">续费操作</dt><dd class="mt-1 text-slate-700">{{ renewalModeLabel(selected.renewal_mode) }}</dd></div>
                            <div><dt class="text-xs text-slate-400">付费周期</dt><dd class="mt-1 text-slate-700">{{ billingCycleLabel(selected.billing_cycle) }}</dd></div>
                            <div class="col-span-2"><dt class="text-xs text-slate-400">下次续费</dt><dd class="mt-1 text-slate-700">{{ selected.next_renewal_on || '尚未产生' }}</dd></div>
                        </dl>
                        <label class="mt-5 block text-sm font-semibold text-slate-700">审批意见<textarea v-model="form.note" maxlength="2000" rows="4" class="mt-2 w-full rounded-xl border-slate-200" placeholder="通过时可选；驳回时必须填写原因"></textarea></label>
                        <p v-if="form.hasErrors" class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ Object.values(form.errors).join('；') }}</p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-100 bg-white px-5 py-4 sm:px-7">
                        <button type="button" class="h-11 rounded-xl border border-rose-200 bg-rose-50 px-5 text-sm font-semibold text-rose-700 disabled:opacity-50" :disabled="form.processing" @click="review('reject')">驳回</button>
                        <button type="submit" class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white disabled:opacity-50" :disabled="form.processing">{{ form.processing ? '提交中…' : '审批通过' }}</button>
                    </div>
                </form>
            </dialog>
        </Teleport>
    </AppLayout>
</template>
