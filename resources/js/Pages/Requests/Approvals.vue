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
    submitter: string;
    reviewer: string | null;
    review_note: string | null;
    created_at: string;
    software_url: string | null;
    software_account: string | null;
    software_password_set: boolean;
    renewal_mode: string | null;
    billing_cycle: string | null;
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
const filters = ref({ ...props.filters });
const selected = ref<Row | null>(null);
const reviewOpen = ref(false);
const form = useForm({ action: 'approve' as 'approve' | 'reject', note: '' });

function apply(): void {
    router.get('/request-approvals', filters.value, { preserveState: true, replace: true });
}

function open(row: Row): void {
    selected.value = row;
    form.reset();
    reviewOpen.value = true;
}

function review(action: 'approve' | 'reject'): void {
    if (!selected.value) return;
    form.action = action;
    form.put(`/request-approvals/${selected.value.uuid}`, {
        preserveScroll: true,
        onSuccess: () => {
            reviewOpen.value = false;
            selected.value = null;
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
                <button class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white">筛选</button>
            </form>

            <section class="grid gap-4 lg:grid-cols-2">
                <article v-for="row in requests.data" :key="row.uuid" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="row.kind === 'technical' ? 'bg-violet-50 text-violet-700' : row.kind === 'expense_request' ? 'bg-orange-50 text-orange-700' : 'bg-emerald-50 text-emerald-700'"
                                >{{ row.kind === 'technical' ? '技术需求' : row.kind === 'expense_request' ? '费用申请' : '发票报销' }}</span>
                                <span class="font-mono text-xs text-slate-400">{{ row.reference_no }}</span>
                            </div>
                            <h2 class="mt-3 font-semibold text-slate-900">{{ row.title }}</h2>
                        </div>
                        <span
                            class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="row.status === 'approved' ? 'bg-emerald-50 text-emerald-700' : row.status === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'"
                        >{{ statusLabels[row.status] }}</span>
                    </div>

                    <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">{{ row.description }}</p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 text-sm">
                        <div><dt class="text-xs text-slate-400">提交人</dt><dd class="mt-1 font-medium text-slate-700">{{ row.submitter }}</dd></div>
                        <div><dt class="text-xs text-slate-400">提交时间</dt><dd class="mt-1 text-slate-600">{{ row.created_at }}</dd></div>
                        <div v-if="row.kind === 'technical'"><dt class="text-xs text-slate-400">期望日期</dt><dd class="mt-1 text-slate-600">{{ row.desired_date }}</dd></div>
                        <div v-else><dt class="text-xs text-slate-400">{{ row.kind === 'expense_request' ? '申请金额' : '报销金额' }}</dt><dd class="mt-1 font-bold text-slate-900">{{ row.currency }} {{ row.amount }}</dd></div>
                    </dl>

                    <dl v-if="row.kind === 'expense_request' && row.category === 'software'" class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-xs">
                        <div class="col-span-2"><dt class="text-slate-400">软件网址</dt><dd class="mt-1 truncate"><a :href="row.software_url || '#'" target="_blank" rel="noreferrer" class="font-medium text-blue-600 hover:underline">{{ row.software_url }}</a></dd></div>
                        <div><dt class="text-slate-400">登录账号</dt><dd class="mt-1 truncate font-medium text-slate-700">{{ row.software_account }}</dd></div>
                        <div><dt class="text-slate-400">登录密码</dt><dd class="mt-1 font-medium text-slate-700">{{ row.software_password_set ? '已安全保存' : '未填写' }}</dd></div>
                        <div><dt class="text-slate-400">续费操作</dt><dd class="mt-1 font-medium text-slate-700">{{ row.renewal_mode === 'automatic' ? '自动续费' : '手动续费' }}</dd></div>
                        <div><dt class="text-slate-400">付费周期</dt><dd class="mt-1 font-medium text-slate-700">{{ row.billing_cycle === 'monthly' ? '月付' : '年付' }}</dd></div>
                    </dl>

                    <div v-if="row.attachments.length" class="mt-4 flex gap-2">
                        <a v-for="file in row.attachments.slice(0, 4)" :key="file.uuid" :href="file.url" target="_blank"><img :src="file.url" :alt="file.name" class="h-14 w-16 rounded-lg border object-cover" /></a>
                    </div>
                    <div v-if="row.review_note" class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600"><strong>审批意见：</strong>{{ row.review_note }}</div>
                    <button v-if="canManage && row.status === 'pending_approval'" class="mt-5 w-full rounded-xl bg-slate-900 py-2.5 text-sm font-semibold text-white" @click="open(row)">开始审批</button>
                </article>
                <div v-if="!requests.data.length" class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white py-16 text-center text-sm text-slate-400">当前没有需要审批的申请</div>
            </section>
        </main>

        <Teleport to="body">
            <dialog :open="reviewOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="reviewOpen = false">
                <form v-if="selected" class="mx-auto mt-[12vh] max-w-xl rounded-3xl bg-white p-7 shadow-2xl" @submit.prevent="review('approve')">
                    <div class="flex justify-between">
                        <div><p class="text-xs font-semibold text-amber-600">{{ selected.reference_no }}</p><h2 class="mt-1 text-xl font-bold">审批：{{ selected.title }}</h2></div>
                        <button type="button" class="text-2xl text-slate-400" @click="reviewOpen = false">×</button>
                    </div>
                    <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ selected.description }}</p>
                    <dl v-if="selected.kind === 'expense_request' && selected.category === 'software'" class="mt-4 grid grid-cols-2 gap-3 rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm">
                        <div class="col-span-2"><dt class="text-xs text-slate-400">软件网址</dt><dd class="mt-1 truncate"><a :href="selected.software_url || '#'" target="_blank" rel="noreferrer" class="font-medium text-blue-600 hover:underline">{{ selected.software_url }}</a></dd></div>
                        <div><dt class="text-xs text-slate-400">登录账号</dt><dd class="mt-1 truncate text-slate-700">{{ selected.software_account }}</dd></div>
                        <div><dt class="text-xs text-slate-400">登录密码</dt><dd class="mt-1 text-slate-700">{{ selected.software_password_set ? '已安全保存' : '未填写' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">续费操作</dt><dd class="mt-1 text-slate-700">{{ selected.renewal_mode === 'automatic' ? '自动续费' : '手动续费' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">付费周期</dt><dd class="mt-1 text-slate-700">{{ selected.billing_cycle === 'monthly' ? '月付' : '年付' }}</dd></div>
                    </dl>
                    <label class="mt-5 block text-sm font-semibold text-slate-700">审批意见<textarea v-model="form.note" maxlength="2000" rows="4" class="mt-2 w-full rounded-xl border-slate-200" placeholder="通过时可选；驳回时必须填写原因"></textarea></label>
                    <p v-if="form.hasErrors" class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ Object.values(form.errors).join('；') }}</p>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-xl border border-rose-200 bg-rose-50 px-5 text-sm font-semibold text-rose-700" @click="review('reject')">驳回</button>
                        <button type="submit" class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white">审批通过</button>
                    </div>
                </form>
            </dialog>
        </Teleport>
    </AppLayout>
</template>
