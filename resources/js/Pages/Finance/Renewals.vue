<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface PaymentRequest {
    uuid: string;
    reference_no: string;
    title: string;
    description: string;
    category: string;
    amount: string;
    currency: string;
    desired_date: string | null;
    submitter: string | null;
    software_url: string | null;
    software_account: string | null;
    software_password_set: boolean;
    software_payment_method: string | null;
    renewal_mode: string | null;
    billing_cycle: string | null;
    approval_required: boolean;
    payment_status: 'pending' | 'paid';
    is_new_application: boolean;
    payer: string | null;
    paid_on: string | null;
    next_renewal_on: string | null;
    payment_reference: string | null;
}
const props = defineProps<{
    organization: { id: number; name: string };
    canManage: boolean;
    paymentRequests: PaymentRequest[];
}>();

const selected = ref<PaymentRequest | null>(null);
const revealedPasswords = ref<Record<string, string>>({});
const loadingPasswords = ref<Record<string, boolean>>({});
const copied = ref('');
const revealError = ref('');
const activeFilter = ref<'pending' | 'manual' | 'automatic'>('pending');
const payment = useForm({ paid_on: new Date().toISOString().slice(0, 10), payment_reference: '' });
const passwordTimers = new Map<string, ReturnType<typeof setTimeout>>();
const today = new Date().toISOString().slice(0, 10);
const pendingCount = computed(() => props.paymentRequests.filter((item) => item.payment_status === 'pending').length);
const manualCount = computed(() => props.paymentRequests.filter((item) => item.payment_status === 'paid' && item.renewal_mode === 'manual').length);
const automaticCount = computed(() => props.paymentRequests.filter((item) => item.payment_status === 'paid' && item.renewal_mode === 'automatic').length);
const overdueCount = computed(() => props.paymentRequests.filter((item) => item.next_renewal_on && item.next_renewal_on < today).length);
const filteredRequests = computed(() => {
    return props.paymentRequests.filter((item) => {
        if (activeFilter.value === 'pending') return item.payment_status === 'pending';
        if (activeFilter.value === 'manual') return item.payment_status === 'paid' && item.renewal_mode === 'manual';
        if (activeFilter.value === 'automatic') return item.payment_status === 'paid' && item.renewal_mode === 'automatic';
        return false;
    });
});

const filterLabels = computed(() => ({
    pending: `首次待付款 (${pendingCount.value})`,
    manual: `手动续费 (${manualCount.value})`,
    automatic: `自动续费 (${automaticCount.value})`,
}));

function setFilter(filter: typeof activeFilter.value): void {
    activeFilter.value = filter;
}

function isManualRenewal(item: PaymentRequest): boolean {
    return item.renewal_mode === 'manual';
}

function isAutomaticRenewal(item: PaymentRequest): boolean {
    return item.renewal_mode === 'automatic';
}

function clearPasswords(): void {
    passwordTimers.forEach(clearTimeout);
    passwordTimers.clear();
    revealedPasswords.value = {};
    loadingPasswords.value = {};
}

function onVisibilityChange(): void {
    if (document.hidden) clearPasswords();
}

onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange));
onBeforeUnmount(() => {
    clearPasswords();
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

async function togglePassword(item: PaymentRequest): Promise<void> {
    if (item.uuid in revealedPasswords.value) {
        delete revealedPasswords.value[item.uuid];
        clearTimeout(passwordTimers.get(item.uuid));
        passwordTimers.delete(item.uuid);
        return;
    }
    if (!props.canManage || loadingPasswords.value[item.uuid]) return;
    loadingPasswords.value[item.uuid] = true;
    revealError.value = '';
    try {
        const response = await fetch(`/finance/expense-requests/${item.uuid}/password`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('密码读取失败');
        const payload = await response.json();
        if (typeof payload.data?.value !== 'string') throw new Error('密码读取失败');
        revealedPasswords.value[item.uuid] = payload.data.value;
        passwordTimers.set(item.uuid, setTimeout(() => {
            delete revealedPasswords.value[item.uuid];
            passwordTimers.delete(item.uuid);
        }, 30000));
    } catch {
        revealError.value = '密码读取失败，请确认权限后重试。';
    } finally {
        delete loadingPasswords.value[item.uuid];
    }
}

async function copyValue(value: string, key: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1500);
    } catch {
        revealError.value = '复制失败，请手动选择内容复制。';
    }
}

function openPayment(item: PaymentRequest): void {
    selected.value = item;
    payment.reset();
    payment.paid_on = today;
}

function savePayment(): void {
    if (!selected.value) return;
    payment.post(`/finance/expense-requests/${selected.value.uuid}/payment`, {
        preserveScroll: true,
        onSuccess: () => { selected.value = null; },
    });
}

</script>

<template>
    <Head title="续费管理" />
    <AppLayout :breadcrumbs="[{ label: '公司财务' }, { label: '续费' }]">
        <main class="mx-auto w-full max-w-7xl space-y-6">
            <header class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-semibold text-emerald-700">公司范围 · {{ organization.name }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-slate-950">付款与续费管理</h1>
                <p class="mt-2 text-sm text-slate-500">费用申请审批通过后由财务付款；软件采购只申请一次，后续在原记录上持续续费。</p>
            </header>

            <section class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-2xl border border-orange-100 bg-orange-50 p-5"><p class="text-sm font-semibold text-orange-700">手动续费</p><p class="mt-3 text-3xl font-bold text-orange-950">{{ manualCount }}</p></article>
                <article class="rounded-2xl border border-blue-100 bg-blue-50 p-5"><p class="text-sm font-semibold text-blue-700">自动续费</p><p class="mt-3 text-3xl font-bold text-blue-950">{{ automaticCount }}</p></article>
                <article class="rounded-2xl border border-rose-100 bg-rose-50 p-5"><p class="text-sm font-semibold text-rose-700">已到续费日期</p><p class="mt-3 text-3xl font-bold text-rose-950">{{ overdueCount }}</p></article>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="font-semibold text-slate-900">付款与续费项目</h2>
                    <p class="mt-1 text-sm text-slate-500">首次付款优先显示但不计入续费统计；续费项目按方式和下一续费日期管理。</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="rounded-full px-3 py-2 text-sm font-semibold transition" :class="activeFilter === 'pending' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="setFilter('pending')">{{ filterLabels.pending }}</button>
                        <button type="button" class="rounded-full px-3 py-2 text-sm font-semibold transition" :class="activeFilter === 'manual' ? 'bg-orange-500 text-white' : 'bg-orange-50 text-orange-700 hover:bg-orange-100'" @click="setFilter('manual')">{{ filterLabels.manual }}</button>
                        <button type="button" class="rounded-full px-3 py-2 text-sm font-semibold transition" :class="activeFilter === 'automatic' ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-700 hover:bg-blue-100'" @click="setFilter('automatic')">{{ filterLabels.automatic }}</button>
                    </div>
                </div>
                <p v-if="revealError" class="mx-6 mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ revealError }}</p>
                <div v-if="filteredRequests.length" class="divide-y divide-slate-100">
                    <article v-for="item in filteredRequests" :key="item.uuid" class="grid gap-5 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_220px_auto] lg:items-center" :class="item.is_new_application ? 'bg-rose-50' : item.next_renewal_on && item.next_renewal_on < today ? 'bg-rose-50/50' : ''">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <strong class="text-sm text-slate-900">{{ item.title }}</strong>
                                <span class="font-mono text-xs text-slate-400">{{ item.reference_no }}</span>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="item.payment_status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">{{ item.payment_status === 'pending' ? '首次待付款' : '续费中' }}</span>
                                <span v-if="item.category === 'software' && isManualRenewal(item)" class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700">手动续费</span>
                                <span v-else-if="item.category === 'software' && isAutomaticRenewal(item)" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">自动续费</span>
                                <span v-if="!item.approval_required" class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700">免审批</span>
                            </div>
                            <p class="mt-2 line-clamp-2 text-sm text-slate-500">{{ item.description }}</p>
                            <p class="mt-2 text-xs text-slate-400">申请人：{{ item.submitter }}</p>
                            <div v-if="item.category === 'software'" class="mt-3 flex flex-wrap gap-x-4 gap-y-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                                <a v-if="item.software_url" :href="item.software_url" target="_blank" rel="noreferrer" class="font-semibold text-blue-600 hover:underline">打开软件网站</a>
                                <span v-else class="text-slate-400">软件网址未填写</span>
                                <span>付费方式：{{ item.software_payment_method || '未填写' }}</span>
                                <span class="inline-flex items-center gap-1">账号：<strong class="font-mono text-slate-800">{{ item.software_account || '未填写' }}</strong><button v-if="canManage && item.software_account" type="button" class="font-semibold text-blue-600 hover:underline" @click="copyValue(item.software_account || '', `${item.uuid}-account`)">{{ copied === `${item.uuid}-account` ? '已复制' : '复制' }}</button></span>
                                <span class="inline-flex items-center gap-1">密码：<strong class="font-mono text-slate-800">{{ revealedPasswords[item.uuid] ?? (item.software_password_set ? '••••••••' : '未填写') }}</strong><button v-if="canManage && item.software_password_set" type="button" :disabled="loadingPasswords[item.uuid]" class="font-semibold text-blue-600 hover:underline disabled:opacity-50" @click="togglePassword(item)">{{ loadingPasswords[item.uuid] ? '读取中' : item.uuid in revealedPasswords ? '隐藏' : '显示' }}</button><button v-if="item.uuid in revealedPasswords" type="button" class="font-semibold text-blue-600 hover:underline" @click="copyValue(revealedPasswords[item.uuid] || '', `${item.uuid}-password`)">{{ copied === `${item.uuid}-password` ? '已复制' : '复制' }}</button></span>
                                <span>付费周期：{{ item.billing_cycle === 'monthly' ? '月付' : '年付' }}</span>
                            </div>
                        </div>
                        <dl class="grid grid-cols-2 gap-3 text-sm lg:grid-cols-1">
                            <div><dt class="text-xs text-slate-400">本期金额</dt><dd class="mt-1 font-bold text-slate-900">{{ item.currency }} {{ item.amount }}</dd></div>
                            <div v-if="item.next_renewal_on"><dt class="text-xs text-slate-400">下次续费</dt><dd class="mt-1 font-semibold" :class="item.next_renewal_on < today ? 'text-rose-700' : 'text-orange-700'">{{ item.next_renewal_on }}</dd></div>
                            <div v-else><dt class="text-xs text-slate-400">计划使用</dt><dd class="mt-1 text-slate-600">{{ item.desired_date }}</dd></div>
                            <div v-if="item.paid_on"><dt class="text-xs text-slate-400">最近付款</dt><dd class="mt-1 text-slate-600">{{ item.paid_on }} · {{ item.payer }}</dd></div>
                        </dl>
                        <button v-if="canManage && item.payment_status === 'pending'" class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white" @click="openPayment(item)">确认首次付款</button>
                        <button v-else-if="canManage && isManualRenewal(item)" class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white" @click="openPayment(item)">记录续费</button>
                        <span v-else-if="isAutomaticRenewal(item)" class="rounded-xl bg-blue-50 px-4 py-3 text-center text-xs font-semibold text-blue-700">到期自动确认（无需财务手动操作）</span>
                    </article>
                </div>
                <EmptyState v-else title="当前分类暂无项目" :description="activeFilter === 'pending' ? '当前没有首次待付款项目。' : activeFilter === 'manual' ? '当前没有手动续费项目。' : '当前没有自动续费项目。'" icon="finance" />
            </section>

        </main>

        <Teleport to="body">
            <dialog :open="selected !== null" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="selected = null">
                <form v-if="selected" class="mx-auto mt-[14vh] max-w-lg rounded-3xl bg-white p-7 shadow-2xl" @submit.prevent="savePayment">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="text-xs font-semibold text-emerald-700">{{ selected.reference_no }}</p><h2 class="mt-1 text-xl font-bold text-slate-950">{{ selected.payment_status === 'pending' ? '确认首次付款' : '记录本次续费' }}</h2><p class="mt-1 text-sm text-slate-500">{{ selected.title }} · {{ selected.currency }} {{ selected.amount }}</p></div>
                        <button type="button" class="text-2xl text-slate-400" @click="selected = null">×</button>
                    </div>
                    <div v-if="selected.category === 'software'" class="mt-5 rounded-xl bg-orange-50 p-4 text-sm text-orange-900"><p>付费方式：{{ selected.software_payment_method || '未填写' }}</p><p class="mt-1">{{ selected.renewal_mode === 'automatic' ? '自动续费' : '手动续费' }} · {{ selected.billing_cycle === 'monthly' ? '月付' : '年付' }}</p><p class="mt-1 text-xs text-orange-700">确认后系统会从本次付款日期起计算下一次续费日期。</p></div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700">实际付款日期<input v-model="payment.paid_on" required type="date" class="mt-2 h-11 w-full rounded-xl border-slate-200" /></label>
                    <label class="mt-4 block text-sm font-semibold text-slate-700">付款参考号（可选）<input v-model="payment.payment_reference" maxlength="180" class="mt-2 h-11 w-full rounded-xl border-slate-200" placeholder="银行流水、支付平台单号等" /></label>
                    <p v-if="payment.hasErrors" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ Object.values(payment.errors).join('；') }}</p>
                    <div class="mt-6 flex justify-end gap-3"><button type="button" class="h-11 rounded-xl border px-5 text-sm font-semibold" @click="selected = null">取消</button><button :disabled="payment.processing" class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white">确认并保存</button></div>
                </form>
            </dialog>
        </Teleport>
    </AppLayout>
</template>
