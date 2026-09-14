<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import Pagination from "../../Components/Navigation/Pagination.vue";
import AppLayout from "../../Layouts/AppLayout.vue";

type Row = {
    uuid: string;
    reference_no: string;
    title: string;
    description: string;
    category: string;
    amount: string;
    currency: string;
    desired_date: string;
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
    payment_status: "pending" | "paid" | null;
    payer: string | null;
    paid_on: string | null;
    next_renewal_on: string | null;
    renewal_status: "active" | "cancelled";
    cancelled_on: string | null;
    can_cancel_renewal: boolean;
    payment_reference: string | null;
    attachments: Array<{ uuid: string; name: string; url: string }>;
};
type Applicant = {
    id: number;
    name: string;
    job_title: string | null;
};
type Page = {
    data: Row[];
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};
const props = defineProps<{
    scope: "mine" | "all";
    canCreate: boolean;
    filters: { status: string };
    options: {
        categories: string[];
        canChooseApplicant?: boolean;
        currentApplicantId?: number;
        applicants?: Applicant[];
    };
    today: string;
    requests: Page;
}>();
const categoryLabels: Record<string, string> = {
    travel: "差旅预算",
    advertising: "广告预算",
    software: "软件采购",
    office: "办公采购",
    entertainment: "业务招待",
    logistics: "物流费用",
    other: "其他费用",
};
const statusLabels: Record<string, string> = {
    pending_approval: "待审批",
    approved: "已通过",
    rejected: "已驳回",
};
const requestStatusLabel = (row: Row): string => {
    if (row.renewal_status === "cancelled") {
        return "已取消续费";
    }
    const paymentLabel = row.payment_status === "paid" ? "已付款" : "待付款";
    if (!row.approval_required) return `免审批 · ${paymentLabel}`;
    return row.status === "approved" && row.payment_status
        ? row.payment_status === "paid"
            ? "已付款"
            : "待付款"
        : statusLabels[row.status];
};
const requestStatusClass = (row: Row): string =>
    row.renewal_status === "cancelled"
        ? "bg-amber-50 text-amber-700"
        : row.status === "rejected"
          ? "bg-rose-50 text-rose-700"
          : row.status === "approved" && row.payment_status === "paid"
            ? "bg-emerald-50 text-emerald-700"
            : !row.approval_required
              ? "bg-violet-50 text-violet-700"
              : "bg-amber-50 text-amber-700";
const createOpen = ref(false);
const cancelDialogOpen = ref(false);
const filters = ref({ ...props.filters });
const files = ref<File[]>([]);
const form = useForm({
    title: "",
    category: "software",
    amount: "",
    currency: "CNY",
    desired_date: "",
    description: "",
    approval_required: true,
    software_url: "",
    software_account: "",
    software_password: "",
    software_payment_method: "",
    renewal_mode: "manual",
    billing_cycle: "annual",
    applicant_id: "",
    images: [] as File[],
});
const applicantOptions = computed(() => props.options.applicants ?? []);
const currentApplicantId = computed(() =>
    String(props.options.currentApplicantId ?? "")
);
const canChooseApplicant = computed(() => props.options.canChooseApplicant === true);

function formatApplicantOption(applicant: Applicant): string {
    return `${applicant.name}${applicant.job_title ? `（${applicant.job_title}）` : ""}`;
}

function syncApplicantDefaults(): void {
    if (!canChooseApplicant.value) {
        form.applicant_id = "";
        return;
    }
    form.applicant_id = currentApplicantId.value;
}

const cancelRow = ref<Row | null>(null);
const cancelForm = useForm({
    cancelled_on: "",
});

function apply(): void {
    router.get("/expense-requests", filters.value, {
        preserveState: true,
        replace: true,
    });
}
function selectFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    files.value = Array.from(input.files ?? []).slice(0, 10);
    form.images = files.value;
}
function submit(): void {
    if (canChooseApplicant.value && !form.applicant_id) {
        syncApplicantDefaults();
    }
    form.post("/expense-requests", {
        forceFormData: true,
        onSuccess: () => {
            createOpen.value = false;
            files.value = [];
            form.reset();
            form.category = "software";
            form.currency = "CNY";
            form.approval_required = true;
            form.renewal_mode = "manual";
            form.billing_cycle = "annual";
            form.applicant_id = canChooseApplicant.value ? currentApplicantId.value : "";
        },
    });
}
function openCreateDialog(): void {
    syncApplicantDefaults();
    createOpen.value = true;
}

function isAfterOrEqual(dateA: string, dateB: string): boolean {
    if (!dateA || !dateB) return false;
    return dateA >= dateB;
}

const selectedRow = computed<Row | null>(() => cancelRow.value);

const cancelNotice = computed(() => {
    const row = selectedRow.value;
    if (!row || !row.renewal_mode) return "";
    if (row.renewal_mode !== "automatic") {
        return "手动续费：取消后仅保留财务已确认的付款记录，不再触发后续续费。";
    }
    if (!row.next_renewal_on || !cancelForm.cancelled_on) {
        return "自动续费：请选择取消日期后系统将自动按规则停止续费。";
    }
    return isAfterOrEqual(cancelForm.cancelled_on, row.next_renewal_on)
        ? "自动续费：取消日大于或等于下次续费日时，将先补记到取消日为止的自动续费，再停止续费。"
        : "自动续费：取消日早于下次续费日时，不会再产生下一期续费。";
});

function openCancelRenewal(row: Row): void {
    cancelRow.value = row;
    cancelForm.cancelled_on = props.today;
    cancelDialogOpen.value = true;
}

function closeCancelRenewal(): void {
    cancelDialogOpen.value = false;
    cancelRow.value = null;
    cancelForm.cancelled_on = "";
    cancelForm.reset();
}

function submitCancelRenewal(): void {
    if (!cancelRow.value) return;
    cancelForm.put(`/expense-requests/${cancelRow.value.uuid}/cancel-renewal`, {
        onSuccess: () => {
            closeCancelRenewal();
            cancelForm.reset();
        },
    });
}
</script>

<template>
    <Head title="费用申请" />
    <AppLayout
        :breadcrumbs="[
            { label: '个人' },
            { label: '需求和报销' },
            { label: '费用申请' },
        ]"
    >
        <main class="mx-auto w-full max-w-[1400px] space-y-5">
            <header
                class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <span
                        class="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700"
                        >{{
                            scope === "all" ? "全部费用申请" : "我的费用申请"
                        }}</span
                    >
                    <h1 class="mt-3 text-2xl font-bold text-slate-950">
                        费用申请
                    </h1>
                    <p class="mt-1.5 text-sm text-slate-500">
                        在采购或产生费用前提交预算申请，审批通过后再执行支出。
                    </p>
                </div>
                <button
                    v-if="canCreate"
                    class="h-11 rounded-xl bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700"
                    @click="openCreateDialog"
                >
                    + 发起费用申请
                </button>
            </header>
            <form
                class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
                @submit.prevent="apply"
            >
                <select
                    v-model="filters.status"
                    class="h-11 min-w-48 rounded-xl border-slate-200 text-sm"
                >
                    <option value="">全部状态</option>
                    <option
                        v-for="(label, value) in statusLabels"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option></select
                ><button
                    class="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white"
                >
                    筛选
                </button>
            </form>
            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div v-if="requests.data.length" class="overflow-x-auto">
                    <table
                        class="w-full min-w-[1420px] table-fixed text-left text-sm"
                    >
                        <thead
                            class="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500"
                        >
                            <tr>
                                <th class="w-40 px-5 py-4">编号</th>
                                <th class="w-72 px-5 py-4">申请事项</th>
                                <th class="w-32 px-5 py-4">费用类型</th>
                                <th class="w-40 px-5 py-4">金额 / 日期</th>
                                <th class="w-72 px-5 py-4">采购信息</th>
                                <th class="w-40 px-5 py-4">提交信息</th>
                                <th class="w-32 px-5 py-4">状态</th>
                                <th class="w-36 px-5 py-4">操作</th>
                                <th class="w-44 px-5 py-4">附件 / 审批</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in requests.data"
                                :key="row.uuid"
                                class="align-top transition hover:bg-slate-50/70"
                            >
                                <td
                                    class="px-5 py-5 font-mono text-xs font-semibold text-slate-500"
                                >
                                    {{ row.reference_no }}
                                </td>
                                <td class="px-5 py-5">
                                    <p class="font-semibold text-slate-900">
                                        {{ row.title }}
                                    </p>
                                    <p
                                        class="mt-2 line-clamp-3 leading-6 text-slate-500"
                                    >
                                        {{ row.description }}
                                    </p>
                                </td>
                                <td class="px-5 py-5">
                                    <span
                                        class="inline-flex rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700"
                                        >{{
                                            categoryLabels[row.category]
                                        }}</span
                                    >
                                </td>
                                <td class="px-5 py-5">
                                    <p class="font-bold text-slate-900">
                                        {{ row.currency }} {{ row.amount }}
                                    </p>
                                    <p class="mt-2 text-xs text-slate-400">
                                        计划使用
                                    </p>
                                    <p class="mt-1 text-slate-600">
                                        {{ row.desired_date }}
                                    </p>
                                </td>
                                <td class="px-5 py-5">
                                    <div
                                        v-if="row.category === 'software'"
                                        class="space-y-1.5 text-xs text-slate-600"
                                    >
                                        <a
                                            v-if="row.software_url"
                                            :href="row.software_url"
                                            target="_blank"
                                            rel="noreferrer"
                                            class="block truncate font-semibold text-blue-600 hover:underline"
                                            >{{ row.software_url }}</a
                                        >
                                        <p v-else class="text-slate-400">
                                            网址：未填写
                                        </p>
                                        <p>
                                            付费方式：{{
                                                row.software_payment_method ||
                                                "未填写"
                                            }}
                                        </p>
                                        <p class="truncate">
                                            账号：{{ row.software_account || "未填写" }}
                                        </p>
                                        <p>
                                            密码：{{
                                                row.software_password_set
                                                    ? "已安全保存"
                                                    : "未填写"
                                            }}
                                        </p>
                                        <p
                                            v-if="
                                                row.renewal_status !==
                                                'cancelled'
                                            "
                                            class="font-semibold text-orange-700"
                                        >
                                            {{
                                                row.renewal_mode === "automatic"
                                                    ? "自动续费"
                                                    : "手动续费"
                                            }}
                                            ·
                                            {{
                                                row.billing_cycle === "monthly"
                                                    ? "月付"
                                                    : "年付"
                                            }}
                                        </p>
                                        <p
                                            v-if="
                                                row.renewal_status ===
                                                'cancelled'
                                            "
                                            class="font-semibold text-amber-700"
                                        >
                                            已取消 · {{
                                                row.renewal_mode === "automatic"
                                                    ? "自动续费"
                                                    : "手动续费"
                                            }}
                                            ·
                                            {{
                                                row.billing_cycle === "monthly"
                                                    ? "月付"
                                                    : "年付"
                                            }}
                                        </p>
                                        <p
                                            v-if="
                                                row.renewal_status ===
                                                'cancelled'
                                            "
                                            class="text-amber-600"
                                        >
                                            取消日期：{{ row.cancelled_on || "未填写" }}
                                        </p>
                                        <p
                                            v-if="row.next_renewal_on"
                                            class="font-semibold text-orange-700"
                                        >
                                            下次续费：{{ row.next_renewal_on }}
                                        </p>
                                    </div>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-5 py-5">
                                    <p class="font-medium text-slate-700">
                                        {{ row.submitter }}
                                    </p>
                                    <p class="mt-2 text-xs text-slate-400">
                                        {{ row.created_at }}
                                    </p>
                                </td>
                                <td class="px-5 py-5">
                                    <span
                                        class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="requestStatusClass(row)"
                                        >{{ requestStatusLabel(row) }}</span
                                    >
                                </td>
                                <td class="px-5 py-5">
                                    <button
                                        v-if="row.can_cancel_renewal"
                                        type="button"
                                        class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700"
                                        @click="openCancelRenewal(row)"
                                    >
                                        取消续费
                                    </button>
                                    <span v-else class="text-sm text-slate-300"
                                        >—</span
                                    >
                                </td>
                                <td class="px-5 py-5">
                                    <div
                                        v-if="row.attachments.length"
                                        class="flex -space-x-2"
                                    >
                                        <a
                                            v-for="file in row.attachments.slice(
                                                0,
                                                3,
                                            )"
                                            :key="file.uuid"
                                            :href="file.url"
                                            target="_blank"
                                            ><img
                                                :src="file.url"
                                                :alt="file.name"
                                                class="h-10 w-12 rounded-lg border-2 border-white object-cover shadow-sm" /></a
                                        ><span
                                            v-if="row.attachments.length > 3"
                                            class="grid h-10 w-10 place-items-center rounded-lg bg-slate-800 text-xs font-bold text-white"
                                            >+{{
                                                row.attachments.length - 3
                                            }}</span
                                        >
                                    </div>
                                    <p v-else class="text-xs text-slate-400">
                                        无附件
                                    </p>
                                    <p
                                        v-if="row.review_note"
                                        class="mt-3 line-clamp-3 text-xs leading-5 text-slate-500"
                                    >
                                        审批：{{ row.review_note }}
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="py-16 text-center text-sm text-slate-400">
                    暂无费用申请
                </div>
                <div
                    v-if="requests.links.length > 3"
                    class="border-t border-slate-100 px-5 py-4"
                >
                    <Pagination :links="requests.links" />
                </div>
            </section>
        </main>
        <Teleport to="body"
            ><dialog
                :open="createOpen"
                class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4"
                @click.self="createOpen = false"
            >
                <form
                    class="mx-auto mt-[3vh] flex h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
                    @submit.prevent="submit"
                >
                    <div class="flex shrink-0 justify-between p-7 pb-0">
                        <div>
                            <h2 class="text-xl font-bold">发起费用申请</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                费用发生前填写预算、用途和计划使用日期。
                            </p>
                        </div>
                        <button
                            type="button"
                            class="text-2xl text-slate-400"
                            @click="createOpen = false"
                        >
                            ×
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-7 pb-6">
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <label
                            v-if="canChooseApplicant"
                            class="text-sm font-semibold text-slate-700 sm:col-span-2"
                            >申请人<select
                                v-model="form.applicant_id"
                                required
                                class="mt-2 h-11 w-full rounded-xl border-slate-200"
                            >
                            <option
                                v-for="applicant in applicantOptions"
                                :key="applicant.id"
                                :value="String(applicant.id)"
                            >
                                {{ formatApplicantOption(applicant) }}
                            </option>
                            </select></label
                        >
                        <label
                            class="text-sm font-semibold text-slate-700 sm:col-span-2"
                            >申请事项<input
                                v-model="form.title"
                                required
                                maxlength="180"
                                class="mt-2 h-11 w-full rounded-xl border-slate-200"
                                placeholder="例如：采购年度项目管理软件" /></label
                        ><label class="text-sm font-semibold text-slate-700"
                            >费用类型<select
                                v-model="form.category"
                                class="mt-2 h-11 w-full rounded-xl border-slate-200"
                            >
                                <option
                                    v-for="item in options.categories"
                                    :key="item"
                                    :value="item"
                                >
                                    {{ categoryLabels[item] }}
                                </option>
                            </select></label
                        ><label class="text-sm font-semibold text-slate-700"
                            >计划使用日期<input
                                v-model="form.desired_date"
                                required
                                type="date"
                                class="mt-2 h-11 w-full rounded-xl border-slate-200" /></label
                        ><label class="text-sm font-semibold text-slate-700"
                            >预计金额<input
                                v-model="form.amount"
                                required
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="mt-2 h-11 w-full rounded-xl border-slate-200" /></label
                        ><label class="text-sm font-semibold text-slate-700"
                            >币种<select
                                v-model="form.currency"
                                class="mt-2 h-11 w-full rounded-xl border-slate-200"
                            >
                                <option>CNY</option>
                                <option>USD</option>
                                <option>EUR</option>
                                <option>GBP</option>
                            </select></label
                        >
                        <fieldset class="sm:col-span-2">
                            <legend class="text-sm font-semibold text-slate-700">审批方式</legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <label class="flex cursor-pointer gap-3 rounded-xl border p-4 transition" :class="form.approval_required ? 'border-orange-300 bg-orange-50' : 'border-slate-200 bg-white hover:border-slate-300'">
                                    <input v-model="form.approval_required" type="radio" :value="true" class="mt-1 border-slate-300 text-orange-600 focus:ring-orange-500" />
                                    <span><strong class="block text-sm text-slate-800">需要审批</strong><span class="mt-1 block text-xs font-normal leading-5 text-slate-500">提交给超级管理员审批，通过后进入财务待付款。</span></span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-xl border p-4 transition" :class="!form.approval_required ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white hover:border-slate-300'">
                                    <input v-model="form.approval_required" type="radio" :value="false" class="mt-1 border-slate-300 text-violet-600 focus:ring-violet-500" />
                                    <span><strong class="block text-sm text-slate-800">免审批</strong><span class="mt-1 block text-xs font-normal leading-5 text-slate-500">提交后跳过审批，直接进入财务待付款。</span></span>
                                </label>
                            </div>
                        </fieldset>
                        <section
                            v-if="form.category === 'software'"
                            class="grid gap-4 rounded-2xl border border-orange-200 bg-orange-50/60 p-5 sm:col-span-2 sm:grid-cols-2"
                        >
                            <div class="sm:col-span-2">
                                <h3 class="text-sm font-bold text-orange-900">
                                    软件采购信息
                                </h3>
                                <p class="mt-1 text-xs text-orange-700">
                                    网址与账号密码可选；付费方式为必填。账号和密码会加密保存，请勿填写完整银行卡号或安全码。
                                </p>
                            </div>
                            <label
                                class="text-sm font-semibold text-slate-700 sm:col-span-2"
                                >软件网址（可选）<input
                                    v-model="form.software_url"
                                    type="url"
                                    maxlength="500"
                                    class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"
                                    placeholder="https://example.com" /></label
                            ><label class="text-sm font-semibold text-slate-700"
                                >登录账号（可选）<input
                                    v-model="form.software_account"
                                    maxlength="255"
                                    autocomplete="off"
                                    class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"
                                    placeholder="邮箱或用户名" /></label
                            ><label class="text-sm font-semibold text-slate-700"
                                >登录密码（可选）<input
                                    v-model="form.software_password"
                                    type="password"
                                    maxlength="500"
                                    autocomplete="new-password"
                                    class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"
                                    placeholder="输入登录密码" /></label
                            ><label
                                class="text-sm font-semibold text-slate-700 sm:col-span-2"
                                >付费方式（必填）<input
                                    v-model="form.software_payment_method"
                                    required
                                    maxlength="255"
                                    class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"
                                    placeholder="例如：公司信用卡、PayPal、对公转账" /></label
                            ><label class="text-sm font-semibold text-slate-700"
                                >续费操作<select
                                    v-model="form.renewal_mode"
                                    required
                                    class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"
                                >
                                    <option value="automatic">自动续费</option>
                                    <option value="manual">手动续费</option>
                                </select></label
                            ><label class="text-sm font-semibold text-slate-700"
                                >付费周期<select
                                    v-model="form.billing_cycle"
                                    required
                                    class="mt-2 h-11 w-full rounded-xl border-orange-200 bg-white"
                                >
                                    <option value="monthly">月付</option>
                                    <option value="annual">年付</option>
                                </select></label
                            >
                        </section>
                        <label
                            class="text-sm font-semibold text-slate-700 sm:col-span-2"
                            >申请说明<textarea
                                v-model="form.description"
                                required
                                maxlength="5000"
                                rows="6"
                                class="mt-2 w-full rounded-xl border-slate-200"
                                placeholder="说明费用用途、必要性、供应商或预期收益"
                            ></textarea></label
                        ><label
                            class="text-sm font-semibold text-slate-700 sm:col-span-2"
                            >参考附件（可选）<input
                                type="file"
                                multiple
                                accept="image/*"
                                class="mt-2 block w-full text-sm"
                                @change="selectFiles"
                        /></label>
                    </div>
                    <p
                        v-if="form.hasErrors"
                        class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"
                    >
                        {{ Object.values(form.errors).join("；") }}
                    </p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-100 bg-white px-7 py-4">
                        <button
                            type="button"
                            class="h-11 rounded-xl border px-5 text-sm font-semibold"
                            @click="createOpen = false"
                        >
                            取消</button
                        ><button
                            class="h-11 rounded-xl bg-orange-600 px-5 text-sm font-semibold text-white"
                        >
                            {{ form.approval_required ? "提交" : "提交并进入待付款" }}
                        </button>
                    </div>
                </form>
            </dialog></Teleport
        ><Teleport to="body"
            ><dialog
                :open="cancelDialogOpen"
                class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4"
                @click.self="closeCancelRenewal"
            >
                <form
                    class="mx-auto mt-[10vh] flex w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
                    @submit.prevent="submitCancelRenewal"
                >
                    <div class="flex shrink-0 justify-between p-7 pb-0">
                        <div>
                            <h2 class="text-xl font-bold">取消续费</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ selectedRow?.title }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="text-2xl text-slate-400"
                            @click="closeCancelRenewal"
                        >
                            ×
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 px-7 pb-6 pt-6">
                        <div class="space-y-2 text-sm text-slate-700">
                            <p>
                                续费方式：{{
                                    selectedRow?.renewal_mode === "automatic"
                                        ? "自动续费"
                                        : "手动续费"
                                }}
                            </p>
                            <p>
                                下次续费日期：{{
                                    selectedRow?.next_renewal_on || "暂无"
                                }}
                            </p>
                            <p class="leading-6 text-amber-700">
                                {{ cancelNotice }}
                            </p>
                        </div>
                        <label class="mt-6 block text-sm font-semibold text-slate-700">
                            取消日期（必填）
                            <input
                                v-model="cancelForm.cancelled_on"
                                required
                                type="date"
                                :max="today"
                                class="mt-2 h-11 w-full rounded-xl border-slate-200"
                            />
                        </label>
                        <p
                            v-if="cancelForm.errors.cancelled_on"
                            class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"
                        >
                            {{ cancelForm.errors.cancelled_on }}
                        </p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-100 bg-white px-7 py-4">
                        <button
                            type="button"
                            class="h-11 rounded-xl border px-5 text-sm font-semibold"
                            @click="closeCancelRenewal"
                        >
                            取消
                        </button>
                        <button
                            type="submit"
                            class="h-11 rounded-xl bg-rose-600 px-5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-rose-300"
                            :disabled="cancelForm.processing"
                        >
                            确认取消续费
                        </button>
                    </div>
                </form>
            </dialog></Teleport
        >
    </AppLayout>
</template>
