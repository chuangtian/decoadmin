<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type StatusState = 'pending_approval' | 'approved' | 'rejected' | 'paid';
type PaymentStatus = 'pending' | 'paid' | null;
type DisplayStatus = 'pending_approval' | 'rejected' | 'pending_reimbursement' | 'reimbursed';
type Row = {
  uuid: string;
  reference_no: string;
  title: string;
  description: string;
  category: string;
  amount: string;
  currency: string;
  expense_date: string;
  status: StatusState;
  payment_status: PaymentStatus;
  payer: string | null;
  paid_on: string | null;
  payment_reference: string | null;
  submitter: string;
  reviewer: string | null;
  review_note: string | null;
  created_at: string;
  attachments: Array<{ uuid: string; name: string; url: string }>;
};
type Page = { data: Row[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };

const props = defineProps<{ scope: 'mine' | 'all'; canCreate: boolean; filters: { status: string }; options: { categories: string[] }; requests: Page }>();

const categoryLabels: Record<string, string> = {
  travel: '差旅交通',
  advertising: '广告投放',
  software: '软件订阅',
  office: '办公用品',
  entertainment: '业务招待',
  logistics: '物流快递',
  other: '其他',
};
const statusLabels: Record<DisplayStatus, string> = {
  pending_approval: '待审批',
  rejected: '已驳回',
  pending_reimbursement: '待报销',
  reimbursed: '已报销',
};
const statusOptions: Array<{ value: '' | DisplayStatus; label: string }> = [
  { value: '', label: '全部状态' },
  { value: 'pending_approval', label: statusLabels.pending_approval },
  { value: 'rejected', label: statusLabels.rejected },
  { value: 'pending_reimbursement', label: statusLabels.pending_reimbursement },
  { value: 'reimbursed', label: statusLabels.reimbursed },
];
const statusClass: Record<DisplayStatus, string> = {
  pending_approval: 'bg-amber-50 text-amber-700',
  rejected: 'bg-rose-50 text-rose-700',
  pending_reimbursement: 'bg-amber-50 text-amber-700',
  reimbursed: 'bg-emerald-50 text-emerald-700',
};

const createOpen = ref(false);
const filters = ref({ ...props.filters });
const images = ref<File[]>([]);
const form = useForm({ title: '', category: 'software', amount: '', currency: 'CNY', expense_date: '', description: '', images: [] as File[] });

function filter(): void {
  router.get('/expense-claims', filters.value, { preserveState: true, replace: true });
}

function files(event: Event): void {
  const input = event.target as HTMLInputElement;
  images.value = Array.from(input.files ?? []).slice(0, 10);
  form.images = images.value;
}

function submit(): void {
  form.post('/expense-claims', {
    forceFormData: true,
    onSuccess: () => {
      createOpen.value = false;
      images.value = [];
      form.reset();
      form.category = 'software';
      form.currency = 'CNY';
    },
  });
}

function displayStatus(row: Row): DisplayStatus {
  if (row.status === 'rejected') return 'rejected';
  if (row.status === 'pending_approval') return 'pending_approval';
  if (row.payment_status === 'paid' || row.status === 'paid') return 'reimbursed';
  return 'pending_reimbursement';
}

function paymentMeta(row: Row): string {
  if (row.payment_status !== 'paid' && row.status !== 'paid') return '';
  const paidOn = row.paid_on ?? '—';
  const payer = row.payer ?? '—';
  const reference = row.payment_reference ?? '—';
  const parts = [`打款日期：${paidOn}`, `经办人：${payer}`, `参考号：${reference}`];
  return parts.join(' / ');
}
</script>

<template>
  <Head title="发票报销" />
  <AppLayout :breadcrumbs="[{ label: '个人' }, { label: '需求和报销' }, { label: '发票报销' }]">
    <main class="mx-auto w-full max-w-[1400px] space-y-5">
      <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
          <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ scope === 'all' ? '全部报销申请' : '我的报销' }}</span>
          <h1 class="mt-3 text-2xl font-bold text-slate-950">发票报销</h1>
          <p class="mt-1.5 text-sm text-slate-500">提交费用与发票，实时查看审批状态和审批意见。</p>
        </div>
        <button v-if="canCreate" class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700" @click="createOpen = true">+ 发起报销</button>
      </header>

      <form class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="filter">
        <select v-model="filters.status" class="h-11 min-w-48 rounded-xl border-slate-200 text-sm">
          <option v-for="option in statusOptions" :key="option.value || 'all'" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <button class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white">筛选</button>
      </form>

      <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[1050px] text-left text-sm">
            <thead class="bg-slate-50 text-slate-500">
              <tr>
                <th class="px-5 py-4">编号 / 事项</th>
                <th class="px-4 py-4">费用类型</th>
                <th class="px-4 py-4">报销金额</th>
                <th class="px-4 py-4">费用日期</th>
                <th class="px-4 py-4">提交人</th>
                <th class="px-4 py-4">发票</th>
                <th class="px-4 py-4">审批状态</th>
                <th class="px-4 py-4">审批意见</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in requests.data" :key="row.uuid" class="align-top hover:bg-slate-50">
                <td class="px-5 py-5">
                  <p class="font-mono text-xs text-slate-400">{{ row.reference_no }}</p>
                  <p class="mt-1 font-semibold text-slate-900">{{ row.title }}</p>
                  <p class="mt-1 line-clamp-2 max-w-[320px] text-slate-500">{{ row.description }}</p>
                </td>
                <td class="px-4 py-5">
                  <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ categoryLabels[row.category] }}</span>
                </td>
                <td class="whitespace-nowrap px-4 py-5 text-lg font-bold text-slate-900">{{ row.currency }} {{ row.amount }}</td>
                <td class="whitespace-nowrap px-4 py-5 text-slate-600">{{ row.expense_date }}</td>
                <td class="whitespace-nowrap px-4 py-5">{{ row.submitter }}</td>
                <td class="px-4 py-5">
                  <div class="flex gap-1">
                    <a v-for="file in row.attachments.slice(0, 3)" :key="file.uuid" :href="file.url" target="_blank">
                      <img :src="file.url" :alt="file.name" class="h-12 w-12 rounded-lg border object-cover" />
                    </a>
                  </div>
                </td>
                <td class="px-4 py-5">
                  <span
                    class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold"
                    :class="statusClass[displayStatus(row)]"
                  >
                    {{ statusLabels[displayStatus(row)] }}
                  </span>
                  <p v-if="row.reviewer" class="mt-2 text-xs text-slate-400">审批人：{{ row.reviewer }}</p>
                </td>
                <td class="max-w-[240px] px-4 py-5 text-slate-600">
                  <p>{{ row.review_note || '—' }}</p>
                  <p v-if="displayStatus(row) === 'reimbursed' && paymentMeta(row)" class="mt-2 text-[12px] text-slate-500">
                    {{ paymentMeta(row) }}
                  </p>
                </td>
              </tr>
              <tr v-if="!requests.data.length">
                <td colspan="8" class="py-16 text-center text-slate-400">暂无报销记录</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <Teleport to="body">
        <dialog :open="createOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="createOpen = false">
          <form class="mx-auto mt-[4vh] max-h-[92vh] max-w-2xl overflow-y-auto rounded-3xl bg-white p-7 shadow-2xl" @submit.prevent="submit">
            <div class="flex justify-between">
              <div>
                <h2 class="text-xl font-bold">发起报销</h2>
                <p class="mt-1 text-sm text-slate-500">请填写真实费用信息并上传至少一张发票。</p>
              </div>
              <button type="button" class="text-2xl text-slate-400" @click="createOpen = false">×</button>
            </div>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
              <label class="text-sm font-semibold text-slate-700 sm:col-span-2">
                报销事项
                <input v-model="form.title" required maxlength="180" class="mt-2 h-11 w-full rounded-xl border-slate-200" placeholder="例如：8 月设计软件订阅费" />
              </label>
              <label class="text-sm font-semibold text-slate-700">
                费用类型
                <select v-model="form.category" class="mt-2 h-11 w-full rounded-xl border-slate-200">
                  <option v-for="item in options.categories" :key="item" :value="item">{{ categoryLabels[item] }}</option>
                </select>
              </label>
              <label class="text-sm font-semibold text-slate-700">
                费用日期
                <input v-model="form.expense_date" required type="date" class="mt-2 h-11 w-full rounded-xl border-slate-200" />
              </label>
              <label class="text-sm font-semibold text-slate-700">
                金额
                <input v-model="form.amount" required type="number" min="0.01" step="0.01" class="mt-2 h-11 w-full rounded-xl border-slate-200" />
              </label>
              <label class="text-sm font-semibold text-slate-700">
                币种
                <select v-model="form.currency" class="mt-2 h-11 w-full rounded-xl border-slate-200">
                  <option>CNY</option>
                  <option>USD</option>
                  <option>EUR</option>
                  <option>GBP</option>
                </select>
              </label>
              <label class="text-sm font-semibold text-slate-700 sm:col-span-2">
                费用说明
                <textarea v-model="form.description" required maxlength="5000" rows="5" class="mt-2 w-full rounded-xl border-slate-200" placeholder="说明费用用途、项目归属等信息"></textarea>
              </label>
              <label class="rounded-2xl border-2 border-dashed border-emerald-200 bg-emerald-50/50 p-5 text-sm font-semibold text-slate-700 sm:col-span-2">
                上传发票或付款凭证
                <span class="text-rose-500">*</span>
                <input required type="file" multiple accept="image/*" class="mt-3 block w-full text-sm" @change="files" />
                <span class="mt-2 block text-xs font-normal text-slate-400">最多 10 张，单张不超过 8MB。</span>
              </label>
            </div>
            <p v-if="form.hasErrors" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ Object.values(form.errors).join('；') }}</p>
            <div class="mt-6 flex justify-end gap-3">
              <button type="button" class="h-11 rounded-xl border px-5 text-sm font-semibold" @click="createOpen = false">取消</button>
              <button class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white">提交报销</button>
            </div>
          </form>
        </dialog>
      </Teleport>
    </main>
  </AppLayout>
</template>
