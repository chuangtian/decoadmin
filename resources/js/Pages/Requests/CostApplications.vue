<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Row = {
    uuid: string; reference_no: string; title: string; description: string; category: string;
    amount: string; currency: string; desired_date: string; status: string; submitter: string;
    reviewer: string | null; review_note: string | null; created_at: string;
    software_url: string | null; software_account: string | null; software_password_set: boolean;
    renewal_mode: string | null; billing_cycle: string | null;
    payment_status: 'pending' | 'paid' | null; payer: string | null; paid_on: string | null; next_renewal_on: string | null; payment_reference: string | null;
    attachments: Array<{ uuid: string; name: string; url: string }>;
};
type Page = { data: Row[]; total: number; links: Array<{ url: string | null; label: string; active: boolean }> };
const props = defineProps<{ scope: 'mine' | 'all'; canCreate: boolean; filters: { status: string }; options: { categories: string[] }; requests: Page }>();
const categoryLabels: Record<string, string> = { travel: '差旅预算', advertising: '广告预算', software: '软件采购', office: '办公采购', entertainment: '业务招待', logistics: '物流费用', other: '其他费用' };
const statusLabels: Record<string, string> = { pending_approval: '待审批', approved: '已通过', rejected: '已驳回' };
const requestStatusLabel = (row: Row): string => row.status === 'approved' && row.payment_status ? (row.payment_status === 'paid' ? '已付款' : '待付款') : statusLabels[row.status];
const requestStatusClass = (row: Row): string => row.status === 'rejected' ? 'bg-rose-50 text-rose-700' : row.status === 'approved' && row.payment_status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700';
const createOpen = ref(false); const filters = ref({ ...props.filters }); const files = ref<File[]>([]);
const form = useForm({ title: '', category: 'software', amount: '', currency: 'CNY', desired_date: '', description: '', software_url: '', software_account: '', software_password: '', renewal_mode: 'manual', billing_cycle: 'annual', images: [] as File[] });
function apply(): void { router.get('/expense-requests', filters.value, { preserveState: true, replace: true }); }
function selectFiles(event: Event): void { const input = event.target as HTMLInputElement; files.value = Array.from(input.files ?? []).slice(0, 10); form.images = files.value; }
function submit(): void { form.post('/expense-requests', { forceFormData: true, onSuccess: () => { createOpen.value = false; files.value = []; form.reset(); form.category = 'software'; form.currency = 'CNY'; form.renewal_mode = 'manual'; form.billing_cycle = 'annual'; } }); }
</script>

<template>
    <Head title="费用申请" />
    <AppLayout :breadcrumbs="[{ label: '个人' }, { label: '需求和报销' }, { label: '费用申请' }]">
        <main class="mx-auto w-full max-w-[1400px] space-y-5">
            <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between"><div><span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700">{{ scope === 'all' ? '全部费用申请' : '我的费用申请' }}</span><h1 class="mt-3 text-2xl font-bold text-slate-950">费用申请</h1><p class="mt-1.5 text-sm text-slate-500">在采购或产生费用前提交预算申请，审批通过后再执行支出。</p></div><button v-if="canCreate" class="h-11 rounded-xl bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700" @click="createOpen = true">+ 发起费用申请</button></header>
            <form class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="apply"><select v-model="filters.status" class="h-11 min-w-48 rounded-xl border-slate-200 text-sm"><option value="">全部状态</option><option v-for="(label, value) in statusLabels" :key="value" :value="value">{{ label }}</option></select><button class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white">筛选</button></form>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="requests.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1280px] table-fixed text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500">
                            <tr><th class="w-40 px-5 py-4">编号</th><th class="w-72 px-5 py-4">申请事项</th><th class="w-32 px-5 py-4">费用类型</th><th class="w-40 px-5 py-4">金额 / 日期</th><th class="w-72 px-5 py-4">采购信息</th><th class="w-40 px-5 py-4">提交信息</th><th class="w-32 px-5 py-4">状态</th><th class="w-44 px-5 py-4">附件 / 审批</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in requests.data" :key="row.uuid" class="align-top transition hover:bg-slate-50/70">
                                <td class="px-5 py-5 font-mono text-xs font-semibold text-slate-500">{{ row.reference_no }}</td>
                                <td class="px-5 py-5"><p class="font-semibold text-slate-900">{{ row.title }}</p><p class="mt-2 line-clamp-3 leading-6 text-slate-500">{{ row.description }}</p></td>
                                <td class="px-5 py-5"><span class="inline-flex rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700">{{ categoryLabels[row.category] }}</span></td>
                                <td class="px-5 py-5"><p class="font-bold text-slate-900">{{ row.currency }} {{ row.amount }}</p><p class="mt-2 text-xs text-slate-400">计划使用</p><p class="mt-1 text-slate-600">{{ row.desired_date }}</p></td>
                                <td class="px-5 py-5">
                                    <div v-if="row.category === 'software'" class="space-y-1.5 text-xs text-slate-600"><a :href="row.software_url || '#'" target="_blank" rel="noreferrer" class="block truncate font-semibold text-blue-600 hover:underline">{{ row.software_url }}</a><p class="truncate">账号：{{ row.software_account }}</p><p>密码：{{ row.software_password_set ? '已安全保存' : '未填写' }}</p><p>{{ row.renewal_mode === 'automatic' ? '自动续费' : '手动续费' }} · {{ row.billing_cycle === 'monthly' ? '月付' : '年付' }}</p><p v-if="row.next_renewal_on" class="font-semibold text-orange-700">下次续费：{{ row.next_renewal_on }}</p></div>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-5 py-5"><p class="font-medium text-slate-700">{{ row.submitter }}</p><p class="mt-2 text-xs text-slate-400">{{ row.created_at }}</p></td>
                                <td class="px-5 py-5"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="requestStatusClass(row)">{{ requestStatusLabel(row) }}</span></td>
                                <td class="px-5 py-5"><div v-if="row.attachments.length" class="flex -space-x-2"><a v-for="file in row.attachments.slice(0, 3)" :key="file.uuid" :href="file.url" target="_blank"><img :src="file.url" :alt="file.name" class="h-10 w-12 rounded-lg border-2 border-white object-cover shadow-sm" /></a><span v-if="row.attachments.length > 3" class="grid h-10 w-10 place-items-center rounded-lg bg-slate-800 text-xs font-bold text-white">+{{ row.attachments.length - 3 }}</span></div><p v-else class="text-xs text-slate-400">无附件</p><p v-if="row.review_note" class="mt-3 line-clamp-3 text-xs leading-5 text-slate-500">审批：{{ row.review_note }}</p></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="py-16 text-center text-sm text-slate-400">暂无费用申请</div>
                <div v-if="requests.links.length > 3" class="border-t border-slate-100 px-5 py-4"><Pagination :links="requests.links" /></div>
            </section>
        </main>
        <Teleport to="body"><dialog :open="createOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="createOpen = false"><form class="mx-auto mt-[3vh] max-h-[94vh] max-w-2xl overflow-y-auto rounded-3xl bg-white p-7 shadow-2xl" @submit.prevent="submit"><div class="flex justify-between"><div><h2 class="text-xl font-bold">发起费用申请</h2><p class="mt-1 text-sm text-slate-500">费用发生前填写预算、用途和计划使用日期。</p></div><button type="button" class="text-2xl text-slate-400" @click="createOpen = false">×</button></div><div class="mt-6 grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold text-slate-700 sm:col-span-2">申请事项<input v-model="form.title" required maxlength="180" class="mt-2 h-11 w-full rounded-xl border-slate-200" placeholder="例如：采购年度项目管理软件" /></label><label class="text-sm font-semibold text-slate-700">费用类型<select v-model="form.category" class="mt-2 h-11 w-full rounded-xl border-slate-200"><option v-for="item in options.categories" :key="item" :value="item">{{ categoryLabels[item] }}</option></select></label><label class="text-sm font-semibold text-slate-700">计划使用日期<input v-model="form.desired_date" required type="date" class="mt-2 h-11 w-full rounded-xl border-slate-200" /></label><label class="text-sm font-semibold text-slate-700">预计金额<input v-model="form.amount" required type="number" min="0.01" step="0.01" class="mt-2 h-11 w-full rounded-xl border-slate-200" /></label><label class="text-sm font-semibold text-slate-700">币种<select v-model="form.currency" class="mt-2 h-11 w-full rounded-xl border-slate-200"><option>CNY</option><option>USD</option><option>EUR</option><option>GBP</option></select></label>
                    <section v-if="form.category === 'software'" class="grid gap-4 rounded-2xl border border-orange-200 bg-orange-50/60 p-5 sm:col-span-2 sm:grid-cols-2"><div class="sm:col-span-2"><h3 class="text-sm font-bold text-orange-900">软件采购信息</h3><p class="mt-1 text-xs text-orange-700">账号和密码将加密保存；审批通过后由财务付款，后续续费无需重复申请。</p></div><label class="text-sm font-semibold text-slate-700 sm:col-span-2">软件网址<input v-model="form.software_url" required type="url" maxlength="500" class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white" placeholder="https://example.com" /></label><label class="text-sm font-semibold text-slate-700">登录账号<input v-model="form.software_account" required maxlength="255" autocomplete="off" class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white" placeholder="邮箱或用户名" /></label><label class="text-sm font-semibold text-slate-700">登录密码<input v-model="form.software_password" required type="password" maxlength="500" autocomplete="new-password" class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white" placeholder="输入登录密码" /></label><label class="text-sm font-semibold text-slate-700">续费操作<select v-model="form.renewal_mode" required class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"><option value="automatic">自动续费</option><option value="manual">手动续费</option></select></label><label class="text-sm font-semibold text-slate-700">付费周期<select v-model="form.billing_cycle" required class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"><option value="monthly">月付</option><option value="annual">年付</option></select></label></section>
                    <label class="text-sm font-semibold text-slate-700 sm:col-span-2">申请说明<textarea v-model="form.description" required maxlength="5000" rows="6" class="mt-2 w-full rounded-xl border-slate-200" placeholder="说明费用用途、必要性、供应商或预期收益"></textarea></label><label class="text-sm font-semibold text-slate-700 sm:col-span-2">参考附件（可选）<input type="file" multiple accept="image/*" class="mt-2 block w-full text-sm" @change="selectFiles" /></label></div><p v-if="form.hasErrors" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ Object.values(form.errors).join('；') }}</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="h-11 rounded-xl border px-5 text-sm font-semibold" @click="createOpen = false">取消</button><button class="h-11 rounded-xl bg-orange-600 px-5 text-sm font-semibold text-white">提交审批</button></div></form></dialog></Teleport>
    </AppLayout>
</template>
