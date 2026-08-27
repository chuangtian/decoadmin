<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Campaign {
    enabled: boolean;
    code_prefix: string;
    discount_type: 'percentage' | 'fixed_amount';
    discount_value: string | number;
    applies_to: 'all' | 'products' | 'collections';
    target_ids: string[] | null;
    combines_with_order_discounts: boolean;
    combines_with_product_discounts: boolean;
    combines_with_shipping_discounts: boolean;
    usage_limit: number;
    validity_days: number;
    education_email_domains: string[] | null;
}

interface Claim {
    id: string;
    email: string;
    source: string;
    status: 'pending' | 'approved' | 'rejected';
    review_method: string | null;
    confidence: number | null;
    model_name: string | null;
    recognition_failure_code: string | null;
    recognition_result: Record<string, unknown> | null;
    has_evidence: boolean;
    submission_count: number;
    reviewer: string | null;
    rejection_reason: string | null;
    created_at: string;
    reviewed_at: string | null;
    discount: { id: string; code: string; status: string; usage_count: number; usage_limit: number; expires_at: string; last_synced_at: string | null } | null;
}

interface ClaimFilters {
    status: string;
    source: string;
    review_method: string;
    email: string;
    submitted_from: string;
    submitted_to: string;
    usage_status: string;
    per_page: number;
}

interface FilterOption { value: string; label: string }

interface BatchResult {
    requested: number;
    succeeded: number;
    unchanged: number;
    failed: number;
    items: Array<{ id: string; status: 'succeeded' | 'unchanged' | 'failed'; code: string | null; message: string }>;
}

interface Pagination<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string; currency: string };
    campaign: Campaign;
    claims: Pagination<Claim>;
    counts: { pending: number; approved: number; rejected: number };
    filters: ClaimFilters;
    filterOptions: { statuses: FilterOption[]; sources: FilterOption[]; review_methods: FilterOption[]; usage_statuses: FilterOption[] };
    batchResult: BatchResult | null;
    permissions: { viewEvidence: boolean; approve: boolean; reject: boolean; deleteClaim: boolean; manageCampaign: boolean; analytics: boolean; audit: boolean };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/student-discounts`;
type StudentDiscountTab = 'campaign' | 'claims';
const tabOptions: Array<{ value: StudentDiscountTab; name: string; description: string }> = [
    { value: 'campaign', name: '活动配置', description: '设置优惠规则与适用范围' },
    { value: 'claims', name: '申请记录', description: '审核申请并管理优惠码' },
];
const requestedTab = typeof window === 'undefined'
    ? null
    : new URLSearchParams(window.location.search).get('tab');
const activeTab = ref<StudentDiscountTab>(
    requestedTab === 'claims' || (requestedTab !== 'campaign' && Object.entries(props.filters).some(([key, value]) => key !== 'per_page' && value !== ''))
        ? 'claims'
        : 'campaign',
);
const selectTab = (tab: StudentDiscountTab) => {
    activeTab.value = tab;

    if (typeof window === 'undefined') return;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
};
const claimPageUrl = (url: string | null) => {
    if (!url) return '';

    const parsedUrl = new URL(url, 'http://localhost');
    parsedUrl.searchParams.set('tab', 'claims');

    return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
};
const campaignForm = useForm({
    enabled: props.campaign.enabled,
    code_prefix: props.campaign.code_prefix,
    discount_type: props.campaign.discount_type,
    discount_value: props.campaign.discount_value,
    applies_to: props.campaign.applies_to,
    target_ids_text: (props.campaign.target_ids ?? []).join('\n'),
    combines_with_order_discounts: props.campaign.combines_with_order_discounts,
    combines_with_product_discounts: props.campaign.combines_with_product_discounts,
    combines_with_shipping_discounts: props.campaign.combines_with_shipping_discounts,
    usage_limit: props.campaign.usage_limit,
    validity_days: props.campaign.validity_days,
    education_email_domains_text: (props.campaign.education_email_domains ?? []).join('\n'),
});
const lines = (value: string) => value.split(/[\n,]/).map(item => item.trim()).filter(Boolean);
const saveCampaign = () => campaignForm
    .transform(data => ({
        enabled: data.enabled,
        code_prefix: data.code_prefix,
        discount_type: data.discount_type,
        discount_value: data.discount_value,
        applies_to: data.applies_to,
        target_ids: lines(data.target_ids_text),
        combines_with_order_discounts: data.combines_with_order_discounts,
        combines_with_product_discounts: data.combines_with_product_discounts,
        combines_with_shipping_discounts: data.combines_with_shipping_discounts,
        usage_limit: data.usage_limit,
        validity_days: data.validity_days,
        education_email_domains: lines(data.education_email_domains_text),
    }))
    .put(`${baseUrl}/campaign`, { preserveScroll: true });

const filterForm = useForm({ tab: 'claims', ...props.filters });
const submitFilters = () => filterForm.get(baseUrl, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
    onSuccess: () => { activeTab.value = 'claims'; },
});
const applyStatus = (status: string) => {
    filterForm.status = status;
    submitFilters();
};
const resetFilters = () => router.get(baseUrl, { tab: 'claims', per_page: props.filters.per_page }, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
});
const hasActiveFilters = computed(() => [
    filterForm.status,
    filterForm.source,
    filterForm.review_method,
    filterForm.email,
    filterForm.submitted_from,
    filterForm.submitted_to,
    filterForm.usage_status,
].some(Boolean));

const selectedClaimIds = ref<string[]>([]);
const bulkSelectionLimit = 30;
const currentPendingIds = computed(() => props.claims.data.filter(claim => claim.status === 'pending').slice(0, bulkSelectionLimit).map(claim => claim.id));
const allPendingSelected = computed({
    get: () => currentPendingIds.value.length > 0 && currentPendingIds.value.every(id => selectedClaimIds.value.includes(id)),
    set: (selected: boolean) => { selectedClaimIds.value = selected ? [...currentPendingIds.value] : []; },
});
watch(() => props.claims.data.map(claim => claim.id).join(','), () => { selectedClaimIds.value = []; });
type ReviewAction = 'approve' | 'reject' | 'bulk_approve' | 'bulk_reject';
const reviewDialog = ref<{ action: ReviewAction; claim: Claim | null } | null>(null);
const reviewDialogElement = ref<HTMLElement | null>(null);
const reviewReason = ref('');
const reviewError = ref('');
const reviewProcessing = ref(false);
const reviewNeedsReason = computed(() => reviewDialog.value?.action === 'reject' || reviewDialog.value?.action === 'bulk_reject');
const reviewDialogTitle = computed(() => ({
    approve: '批准学生优惠申请',
    reject: '拒绝学生优惠申请',
    bulk_approve: `批量批准 ${selectedClaimIds.value.length} 条申请`,
    bulk_reject: `批量拒绝 ${selectedClaimIds.value.length} 条申请`,
}[reviewDialog.value?.action ?? 'approve']));
const reviewDialogDescription = computed(() => {
    if (reviewDialog.value?.action === 'approve') return `将为 ${reviewDialog.value.claim?.email ?? ''} 生成 Shopify 优惠码并发送通知邮件。`;
    if (reviewDialog.value?.action === 'reject') return `拒绝 ${reviewDialog.value.claim?.email ?? ''} 的申请，原因将发送给申请人。`;
    if (reviewDialog.value?.action === 'bulk_approve') return '系统会逐条重新校验当前店铺和待审核状态，再生成优惠码并发送通知邮件。';
    return '统一原因会写入每条申请，但不会写入审计日志正文。';
});
const openReviewDialog = (action: ReviewAction, claim: Claim | null = null) => {
    if (action.startsWith('bulk_') && selectedClaimIds.value.length === 0) return;
    reviewReason.value = '';
    reviewError.value = '';
    reviewDialog.value = { action, claim };
    nextTick(() => reviewDialogElement.value?.focus());
};
const closeReviewDialog = () => {
    if (!reviewProcessing.value) reviewDialog.value = null;
};
const submitReview = () => {
    if (!reviewDialog.value || reviewProcessing.value) return;
    const reason = reviewReason.value.trim();
    if (reviewNeedsReason.value && !reason) {
        reviewError.value = '请输入拒绝原因。';
        return;
    }

    const action = reviewDialog.value.action;
    const claim = reviewDialog.value.claim;
    const endpoint = action === 'approve' ? `${baseUrl}/claims/${claim?.id}/approve`
        : action === 'reject' ? `${baseUrl}/claims/${claim?.id}/reject`
            : action === 'bulk_approve' ? `${baseUrl}/claims/bulk-approve`
                : `${baseUrl}/claims/bulk-reject`;
    const payload = action === 'approve' ? {}
        : action === 'reject' ? { reason }
            : action === 'bulk_approve' ? { claim_ids: selectedClaimIds.value }
                : { claim_ids: selectedClaimIds.value, reason };
    reviewProcessing.value = true;
    reviewError.value = '';
    router.post(endpoint, payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (action.startsWith('bulk_')) selectedClaimIds.value = [];
            reviewDialog.value = null;
        },
        onError: errors => { reviewError.value = Object.values(errors)[0] ?? '操作失败，请检查输入后重试。'; },
        onFinish: () => { reviewProcessing.value = false; },
    });
};

const deleteTarget = ref<Claim | null>(null);
const deleteDialogElement = ref<HTMLElement | null>(null);
const deleteProcessing = ref(false);
const deleteError = ref('');
const openDelete = (claim: Claim) => {
    deleteError.value = '';
    deleteTarget.value = claim;
    nextTick(() => deleteDialogElement.value?.focus());
};
const closeDelete = () => {
    if (!deleteProcessing.value) deleteTarget.value = null;
};
const confirmDelete = () => {
    if (!deleteTarget.value) return;
    deleteProcessing.value = true;
    router.delete(`${baseUrl}/claims/${deleteTarget.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { deleteTarget.value = null; },
        onError: errors => { deleteError.value = Object.values(errors)[0] ?? '移除失败，请稍后重试。'; },
        onFinish: () => { deleteProcessing.value = false; },
    });
};

const syncableCodeIds = computed(() => [...new Set(props.claims.data.flatMap(claim => claim.discount ? [claim.discount.id] : []))]);
const usageRefreshing = ref(false);
let usageReloadTimer: ReturnType<typeof setTimeout> | null = null;
const syncUsage = () => {
    if (syncableCodeIds.value.length === 0 || usageRefreshing.value) return;
    usageRefreshing.value = true;
    router.post(`${baseUrl}/claims/usage-sync`, { code_ids: syncableCodeIds.value }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            if (usageReloadTimer) clearTimeout(usageReloadTimer);
            usageReloadTimer = setTimeout(() => router.reload({
                only: ['claims'],
                onFinish: () => { usageRefreshing.value = false; },
            }), 2000);
        },
    });
};
onBeforeUnmount(() => {
    if (usageReloadTimer) clearTimeout(usageReloadTimer);
});
const badge = (status: string) => ({
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    approved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    rejected: 'bg-rose-50 text-rose-700 ring-rose-200',
    unused: 'bg-blue-50 text-blue-700 ring-blue-200',
    partially_used: 'bg-violet-50 text-violet-700 ring-violet-200',
    used_up: 'bg-slate-100 text-slate-700 ring-slate-200',
    expired: 'bg-slate-100 text-slate-500 ring-slate-200',
}[status] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
const label = (status: string) => ({
    pending: '待人工审核', approved: '已通过', rejected: '已拒绝', unused: '未使用',
    partially_used: '部分使用', used_up: '已用完', expired: '已失效',
}[status] ?? status);
const sourceLabel = (source: string) => source === 'education_email' ? '教育邮箱' : '学生证';
const verificationLabel = (claim: Claim) => ({
    education_email: '教育邮箱快速通过',
    ai: 'AI 自动验证',
    manual: '人工审核',
}[claim.review_method ?? (claim.source === 'education_email' ? 'education_email' : '')] ?? '尚未验证');
const recognitionFailureLabel = (code: string | null) => ({
    invalid_api_key: 'AI 服务凭证无效',
    permission_denied: 'AI 服务权限不足',
    model_not_found: 'AI 模型不可用',
    rate_limited: 'AI 服务请求过于频繁',
    api_error: 'AI 服务暂时不可用',
    timeout: 'AI 服务响应超时',
    not_configured: 'AI 服务尚未配置',
}[code ?? ''] ?? 'AI 验证未完成');
</script>

<template>
    <Head title="学生优惠" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '学生优惠' }]">
        <div class="mx-auto max-w-7xl space-y-7">
            <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ organization.name }} · {{ store.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">学生优惠</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">管理教育邮箱快速通过、AI 学生证识别、人工审核和 Shopify 优惠码发放。</p>
                </div>
                <span class="inline-flex self-start rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="campaign.enabled ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">{{ campaign.enabled ? '活动已启用' : '活动已停用' }}</span>
            </section>

            <section v-if="permissions.analytics" class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5"><p class="text-sm font-semibold text-amber-700">待审核</p><p class="mt-2 text-3xl font-semibold text-amber-950">{{ counts.pending }}</p></div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><p class="text-sm font-semibold text-emerald-700">已通过</p><p class="mt-2 text-3xl font-semibold text-emerald-950">{{ counts.approved }}</p></div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5"><p class="text-sm font-semibold text-rose-700">已拒绝</p><p class="mt-2 text-3xl font-semibold text-rose-950">{{ counts.rejected }}</p></div>
            </section>

            <nav class="overflow-x-auto pb-1" aria-label="学生优惠管理" role="tablist">
                <div class="flex min-w-max gap-3">
                    <button
                        v-for="tab in tabOptions"
                        :id="`${tab.value}-tab`"
                        :key="tab.value"
                        type="button"
                        role="tab"
                        :aria-controls="`${tab.value}-panel`"
                        :aria-selected="activeTab === tab.value"
                        class="flex min-w-[240px] items-center gap-3 rounded-2xl border px-4 py-3.5 text-left shadow-sm transition focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:min-w-[280px]"
                        :class="activeTab === tab.value
                            ? 'border-blue-200 bg-blue-50 text-blue-700 ring-1 ring-blue-100'
                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50'"
                        @click="selectTab(tab.value)"
                    >
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition"
                            :class="activeTab === tab.value ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-100 text-slate-500'"
                        >
                            <svg v-if="tab.value === 'campaign'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h10m4 0h2M4 17h2m4 0h10M14 5v4M6 15v4" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h7.5L19 8.25v12H7v-16.5Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3.75v4.5h5M10 12h6m-6 4h6" />
                            </svg>
                        </span>
                        <span>
                            <span class="block text-sm font-semibold">{{ tab.name }}</span>
                            <span class="mt-1 block text-xs" :class="activeTab === tab.value ? 'text-blue-600' : 'text-slate-500'">{{ tab.description }}</span>
                        </span>
                    </button>
                </div>
            </nav>

            <section
                v-if="activeTab === 'campaign'"
                id="campaign-panel"
                role="tabpanel"
                aria-labelledby="campaign-tab"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">每店铺独立</p><h2 class="mt-1 text-xl font-semibold text-slate-950">活动配置</h2></div>
                    <span class="text-xs font-semibold text-slate-500">{{ permissions.manageCampaign ? '可编辑' : '只读' }}</span>
                </header>
                <form class="p-5 sm:p-7" @submit.prevent="saveCampaign">
                    <div class="mb-6 flex items-start justify-between gap-4 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-100">
                        <div><h3 class="text-sm font-semibold text-slate-900">启用学生优惠活动</h3><p class="mt-1 text-xs leading-5 text-slate-500">停用后公开申请接口会返回活动未开放，已发出的 Shopify 优惠码不受影响。</p></div>
                        <label class="relative inline-flex shrink-0 cursor-pointer items-center"><input v-model="campaignForm.enabled" :disabled="!permissions.manageCampaign" type="checkbox" class="peer sr-only" /><span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500 peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5" /></label>
                    </div>
                    <div class="grid gap-5 lg:grid-cols-3">
                        <label><span class="text-sm font-semibold text-slate-800">优惠码前缀</span><input v-model="campaignForm.code_prefix" :disabled="!permissions.manageCampaign" maxlength="24" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50" /><span v-if="campaignForm.errors.code_prefix" class="mt-1 block text-xs text-rose-600">{{ campaignForm.errors.code_prefix }}</span></label>
                        <label><span class="text-sm font-semibold text-slate-800">优惠类型</span><select v-model="campaignForm.discount_type" :disabled="!permissions.manageCampaign" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm disabled:bg-slate-50"><option value="percentage">百分比</option><option value="fixed_amount">固定金额（{{ store.currency }}）</option></select></label>
                        <label><span class="text-sm font-semibold text-slate-800">优惠值</span><input v-model="campaignForm.discount_value" :disabled="!permissions.manageCampaign" type="number" min="0.01" step="0.01" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm disabled:bg-slate-50" /></label>
                        <label><span class="text-sm font-semibold text-slate-800">适用范围</span><select v-model="campaignForm.applies_to" :disabled="!permissions.manageCampaign" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm disabled:bg-slate-50"><option value="all">全部商品</option><option value="products">指定商品</option><option value="collections">指定集合</option></select></label>
                        <label><span class="text-sm font-semibold text-slate-800">单码使用上限</span><input v-model="campaignForm.usage_limit" :disabled="!permissions.manageCampaign" type="number" min="1" max="100000" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm disabled:bg-slate-50" /></label>
                        <label><span class="text-sm font-semibold text-slate-800">有效期（天）</span><input v-model="campaignForm.validity_days" :disabled="!permissions.manageCampaign" type="number" min="1" max="365" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm disabled:bg-slate-50" /></label>
                        <label v-if="campaignForm.applies_to !== 'all'" class="lg:col-span-3"><span class="text-sm font-semibold text-slate-800">{{ campaignForm.applies_to === 'products' ? 'Shopify Product GID' : 'Shopify Collection GID' }}</span><textarea v-model="campaignForm.target_ids_text" :disabled="!permissions.manageCampaign" rows="3" placeholder="每行一个 gid://shopify/..." class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm disabled:bg-slate-50" /></label>
                        <label class="lg:col-span-3"><span class="text-sm font-semibold text-slate-800">自定义教育邮箱白名单</span><textarea v-model="campaignForm.education_email_domains_text" :disabled="!permissions.manageCampaign" rows="3" placeholder="每行一个域名，例如 university.example" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm disabled:bg-slate-50" /><span class="mt-1 block text-xs text-slate-400">系统始终自动接受 .edu、.edu.xx、.ac.xx；这里只补充店铺自己的域名。</span></label>
                    </div>
                    <div class="mt-6 grid gap-3 sm:grid-cols-3">
                        <label class="flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="campaignForm.combines_with_order_discounts" :disabled="!permissions.manageCampaign" type="checkbox" /> 可与订单优惠组合</label>
                        <label class="flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="campaignForm.combines_with_product_discounts" :disabled="!permissions.manageCampaign" type="checkbox" /> 可与商品优惠组合</label>
                        <label class="flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="campaignForm.combines_with_shipping_discounts" :disabled="!permissions.manageCampaign" type="checkbox" /> 可与运费优惠组合</label>
                    </div>
                    <div v-if="permissions.manageCampaign" class="mt-7 flex justify-end"><button :disabled="campaignForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ campaignForm.processing ? '保存中…' : '保存活动配置' }}</button></div>
                </form>
            </section>

            <section
                v-else
                id="claims-panel"
                role="tabpanel"
                aria-labelledby="claims-tab"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <header class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">审核与发码</p><h2 class="mt-1 text-xl font-semibold text-slate-950">申请记录</h2></div>
                    <button type="button" :disabled="syncableCodeIds.length === 0 || usageRefreshing" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50" @click="syncUsage">
                        {{ usageRefreshing ? '刷新中…' : '刷新本页使用情况' }}
                    </button>
                </header>
                <form class="space-y-4 border-b border-slate-100 bg-slate-50/60 px-5 py-5 sm:px-7" @submit.prevent="submitFilters">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="option in [{ value: '', label: '全部' }, ...filterOptions.statuses]" :key="option.value" type="button" class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="filterForm.status === option.value ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200'" @click="applyStatus(option.value)">{{ option.label }}</button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <label class="text-xs font-semibold text-slate-600"><span>证明</span><select v-model="filterForm.source" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option value="">全部证明</option><option v-for="option in filterOptions.sources" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="text-xs font-semibold text-slate-600"><span>验证方式</span><select v-model="filterForm.review_method" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option value="">全部方式</option><option v-for="option in filterOptions.review_methods" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="text-xs font-semibold text-slate-600"><span>使用情况</span><select v-model="filterForm.usage_status" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option value="">全部使用情况</option><option v-for="option in filterOptions.usage_statuses" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="text-xs font-semibold text-slate-600"><span>申请人邮箱</span><input v-model="filterForm.email" type="search" maxlength="120" placeholder="模糊搜索邮箱" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800" /></label>
                        <label class="text-xs font-semibold text-slate-600"><span>提交开始日期</span><input v-model="filterForm.submitted_from" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800" /></label>
                        <label class="text-xs font-semibold text-slate-600"><span>提交结束日期</span><input v-model="filterForm.submitted_to" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800" /></label>
                        <label class="text-xs font-semibold text-slate-600"><span>每页条数</span><select v-model="filterForm.per_page" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option :value="20">20 条</option><option :value="30">30 条</option><option :value="50">50 条</option></select></label>
                        <div class="flex items-end gap-2"><button :disabled="filterForm.processing" class="flex-1 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">筛选</button><button v-if="hasActiveFilters" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600" @click="resetFilters">重置</button></div>
                    </div>
                </form>
                <div v-if="batchResult" class="border-b border-slate-100 bg-blue-50 px-5 py-3 text-sm text-blue-800 sm:px-7">上次批量处理：成功 {{ batchResult.succeeded }} 条，已处理 {{ batchResult.unchanged }} 条，失败 {{ batchResult.failed }} 条。</div>
                <div v-if="selectedClaimIds.length > 0" class="flex flex-col gap-3 border-b border-slate-100 bg-amber-50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <p class="text-sm font-semibold text-amber-900">已选择当前页 {{ selectedClaimIds.length }} 条待审核申请（每次最多 {{ bulkSelectionLimit }} 条）</p>
                    <div class="flex flex-wrap gap-2"><button v-if="permissions.approve" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white" @click="openReviewDialog('bulk_approve')">批量批准</button><button v-if="permissions.reject" type="button" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white" @click="openReviewDialog('bulk_reject')">批量拒绝</button><button type="button" class="rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs font-semibold text-amber-800" @click="selectedClaimIds = []">取消选择</button></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-[1180px] divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th class="w-12 px-4 py-3"><input v-if="permissions.approve || permissions.reject" v-model="allPendingSelected" type="checkbox" :disabled="currentPendingIds.length === 0" aria-label="选择当前页全部待审核申请" /></th><th class="px-4 py-3">申请人</th><th class="px-4 py-3">证明 / 验证</th><th class="px-4 py-3">申请状态</th><th class="px-4 py-3">使用情况</th><th class="px-4 py-3">提交时间</th><th class="px-4 py-3 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="claim in claims.data" :key="claim.id" class="align-top">
                                <td class="px-4 py-4"><input v-if="claim.status === 'pending' && (permissions.approve || permissions.reject)" v-model="selectedClaimIds" :value="claim.id" type="checkbox" :disabled="!selectedClaimIds.includes(claim.id) && selectedClaimIds.length >= bulkSelectionLimit" :aria-label="`选择 ${claim.email} 的申请`" /></td>
                                <td class="px-4 py-4"><p class="font-semibold text-slate-900">{{ claim.email }}</p><p class="mt-1 text-xs text-slate-500">第 {{ claim.submission_count }} 次提交</p></td>
                                <td class="px-4 py-4"><p class="font-semibold text-slate-800">{{ sourceLabel(claim.source) }}</p><p class="mt-1 text-xs text-slate-500">{{ verificationLabel(claim) }}<span v-if="claim.confidence !== null"> · {{ claim.confidence }}/100</span></p><p v-if="claim.recognition_failure_code" class="mt-1 text-xs font-semibold text-amber-700">{{ recognitionFailureLabel(claim.recognition_failure_code) }}</p><details v-if="permissions.viewEvidence && claim.recognition_result" class="mt-2"><summary class="cursor-pointer text-xs font-semibold text-emerald-700">查看结构化识别</summary><pre class="mt-2 max-w-md overflow-auto rounded-lg bg-slate-950 p-3 text-[11px] text-slate-200">{{ JSON.stringify(claim.recognition_result, null, 2) }}</pre></details></td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="badge(claim.status)">{{ label(claim.status) }}</span><div v-if="claim.discount" class="mt-2"><code class="font-semibold text-slate-900">{{ claim.discount.code }}</code><p class="mt-1 text-xs text-slate-500">有效期至 {{ new Date(claim.discount.expires_at).toLocaleDateString() }}</p></div><p v-if="claim.rejection_reason" class="mt-2 max-w-sm text-xs text-rose-600">{{ claim.rejection_reason }}</p></td>
                                <td class="px-4 py-4"><template v-if="claim.discount"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="badge(claim.discount.status)">{{ label(claim.discount.status) }}</span><p class="mt-2 text-xs font-semibold text-slate-700">{{ claim.discount.usage_count }}/{{ claim.discount.usage_limit }} 次</p><p class="mt-1 text-[11px] text-slate-400">{{ claim.discount.last_synced_at ? `同步于 ${new Date(claim.discount.last_synced_at).toLocaleString()}` : '尚未向 Shopify 同步' }}</p></template><span v-else class="text-xs text-slate-400">尚未发码</span></td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-500">{{ new Date(claim.created_at).toLocaleString() }}<p v-if="claim.reviewer" class="mt-1">审核：{{ claim.reviewer }}</p></td>
                                <td class="px-4 py-4"><div class="flex flex-wrap justify-end gap-2"><a v-if="permissions.viewEvidence && claim.has_evidence" :href="`${baseUrl}/claims/${claim.id}/evidence`" target="_blank" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700">证件</a><button v-if="claim.status === 'pending' && permissions.approve" type="button" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white" @click="openReviewDialog('approve', claim)">通过</button><button v-if="claim.status === 'pending' && permissions.reject" type="button" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white" @click="openReviewDialog('reject', claim)">拒绝</button><button v-if="permissions.deleteClaim" type="button" class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50" @click="openDelete(claim)">删除</button></div></td>
                            </tr>
                            <tr v-if="claims.data.length === 0"><td colspan="7" class="px-5 py-14 text-center"><p class="text-sm font-semibold text-slate-700">没有符合条件的申请记录</p><p class="mt-1 text-xs text-slate-400">调整筛选条件或等待新的学生优惠申请。</p><button v-if="hasActiveFilters" type="button" class="mt-4 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600" @click="resetFilters">清除筛选</button></td></tr>
                        </tbody>
                    </table>
                </div>
                <footer v-if="claims.links.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500"><span>第 {{ claims.from ?? 0 }}–{{ claims.to ?? 0 }} 条，共 {{ claims.total }} 条</span><div class="flex gap-1"><Link v-for="item in claims.links" :key="item.label" :href="claimPageUrl(item.url)" preserve-scroll class="rounded-lg px-2.5 py-1.5 ring-1" :class="[item.active ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200', !item.url ? 'pointer-events-none opacity-40' : '']"><span v-html="item.label" /></Link></div></footer>
            </section>

            <Teleport to="body">
                <div v-if="reviewDialog" ref="reviewDialogElement" tabindex="-1" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 outline-none" role="dialog" aria-modal="true" aria-labelledby="review-dialog-title" aria-describedby="review-dialog-description" @keydown.esc="closeReviewDialog" @click.self="closeReviewDialog">
                    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div class="flex h-11 w-11 items-center justify-center rounded-full" :class="reviewNeedsReason ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path v-if="reviewNeedsReason" stroke-linecap="round" stroke-linejoin="round" d="m7 7 10 10M17 7 7 17" /><path v-else stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg></div><h3 id="review-dialog-title" class="mt-4 text-lg font-semibold text-slate-950">{{ reviewDialogTitle }}</h3><p id="review-dialog-description" class="mt-2 text-sm leading-6 text-slate-600">{{ reviewDialogDescription }}</p><label v-if="reviewNeedsReason" class="mt-4 block text-sm font-semibold text-slate-700"><span>拒绝原因</span><textarea v-model="reviewReason" autofocus rows="4" maxlength="1000" :disabled="reviewProcessing" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-normal" placeholder="请输入拒绝原因" @input="reviewError = ''" /></label><p v-if="reviewError" class="mt-2 text-xs text-rose-600" role="alert">{{ reviewError }}</p><div class="mt-5 flex justify-end gap-2"><button type="button" :disabled="reviewProcessing" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50" @click="closeReviewDialog">取消</button><button type="button" :disabled="reviewProcessing" class="rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :class="reviewNeedsReason ? 'bg-rose-600' : 'bg-emerald-600'" @click="submitReview">{{ reviewProcessing ? '处理中…' : (reviewNeedsReason ? '确认拒绝' : '确认批准') }}</button></div></div>
                </div>
                <div v-if="deleteTarget" ref="deleteDialogElement" tabindex="-1" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 outline-none" role="dialog" aria-modal="true" aria-labelledby="delete-claim-title" aria-describedby="delete-claim-description" @keydown.esc="closeDelete" @click.self="closeDelete">
                    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div class="flex h-11 w-11 items-center justify-center rounded-full bg-rose-50 text-rose-600"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5" /></svg></div><h3 id="delete-claim-title" class="mt-4 text-lg font-semibold text-slate-950">从申请列表移除</h3><p id="delete-claim-description" class="mt-2 text-sm leading-6 text-slate-600">将移除 <span class="font-semibold text-slate-900">{{ deleteTarget.email }}</span> 的申请记录，学生证文件会立即删除；审计记录和已生成优惠码关联将保留。</p><p v-if="deleteError" class="mt-2 text-xs text-rose-600" role="alert">{{ deleteError }}</p><div class="mt-5 flex justify-end gap-2"><button type="button" :disabled="deleteProcessing" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50" @click="closeDelete">取消</button><button type="button" :disabled="deleteProcessing" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="confirmDelete">{{ deleteProcessing ? '处理中…' : '确认移除' }}</button></div></div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
