<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
    name: string | null;
    email: string;
    source: string;
    status: 'pending' | 'approved' | 'rejected' | 'voided';
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

type EmailTemplateType = 'approval' | 'rejection';
interface EmailBranding {
    logo_url: string;
    primary_color: string;
    shop_url: string;
    support_email: string;
    support_url: string;
    instagram_url: string;
    facebook_url: string;
    tiktok_url: string;
    youtube_url: string;
}
interface EmailTemplate {
    subject: string;
    preheader: string;
    heading: string;
    body: string;
    cta_label: string;
    footer_note: string;
}
interface EmailTemplateVariable { key: string; label: string; sample: string; types: EmailTemplateType[] }
interface EmailTemplateConfiguration {
    branding: EmailBranding;
    templates: Record<EmailTemplateType, EmailTemplate>;
    defaults: { branding: EmailBranding; templates: Record<EmailTemplateType, EmailTemplate> };
    variables: EmailTemplateVariable[];
}

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
    emailTemplates: EmailTemplateConfiguration;
    claims: Pagination<Claim>;
    counts: { pending: number; approved: number; rejected: number };
    filters: ClaimFilters;
    filterOptions: { statuses: FilterOption[]; sources: FilterOption[]; review_methods: FilterOption[]; usage_statuses: FilterOption[] };
    batchResult: BatchResult | null;
    permissions: { viewEvidence: boolean; approve: boolean; reject: boolean; deleteClaim: boolean; manageCampaign: boolean; manageEmailTemplates: boolean; analytics: boolean; audit: boolean };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/student-discounts`;
type StudentDiscountTab = 'campaign' | 'claims' | 'emails';
const tabOptions: Array<{ value: StudentDiscountTab; name: string; description: string }> = [
    { value: 'claims', name: '申请记录', description: '审核申请并管理优惠码' },
    { value: 'campaign', name: '活动配置', description: '设置优惠规则与适用范围' },
    { value: 'emails', name: '邮件内容', description: '编辑批准与拒绝通知' },
];
const requestedTab = new URL(usePage().url, 'http://localhost').searchParams.get('tab');
const activeTab = ref<StudentDiscountTab>(requestedTab === 'campaign' || requestedTab === 'emails' ? requestedTab : 'claims');
const selectTab = (tab: StudentDiscountTab) => {
    activeTab.value = tab;

    if (typeof window === 'undefined') return;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
};
const handleTabKeydown = (event: KeyboardEvent, index: number) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

    event.preventDefault();
    const lastIndex = tabOptions.length - 1;
    const nextIndex = event.key === 'Home'
        ? 0
        : event.key === 'End'
            ? lastIndex
            : event.key === 'ArrowRight'
                ? (index + 1) % tabOptions.length
                : (index - 1 + tabOptions.length) % tabOptions.length;
    const nextTab = tabOptions[nextIndex];

    selectTab(nextTab.value);
    nextTick(() => document.getElementById(`${nextTab.value}-tab`)?.focus());
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

const selectedEmailTemplate = ref<EmailTemplateType>('approval');
const emailPreviewMode = ref<'desktop' | 'mobile'>('desktop');
const emailTemplateForm = useForm({
    branding: { ...props.emailTemplates.branding },
    approval: { ...props.emailTemplates.templates.approval },
    rejection: { ...props.emailTemplates.templates.rejection },
});
const currentEmailTemplate = computed(() => emailTemplateForm[selectedEmailTemplate.value]);
const currentEmailSubject = computed({
    get: () => currentEmailTemplate.value.subject,
    set: (value: string) => { currentEmailTemplate.value.subject = value; },
});
const currentEmailBody = computed({
    get: () => currentEmailTemplate.value.body,
    set: (value: string) => { currentEmailTemplate.value.body = value; },
});
const availableEmailVariables = computed(() => props.emailTemplates.variables.filter(variable => variable.types.includes(selectedEmailTemplate.value)));
const emailVariableToken = (key: string) => `{{ ${key} }}`;
const renderEmailPreview = (content: string) => props.emailTemplates.variables.reduce(
    (rendered, variable) => rendered.split(emailVariableToken(variable.key)).join(variable.sample),
    content,
);
const emailPreviewSubject = computed(() => renderEmailPreview(currentEmailSubject.value));
const emailPreviewBody = computed(() => renderEmailPreview(currentEmailBody.value));
const emailPreviewHeading = computed(() => renderEmailPreview(currentEmailTemplate.value.heading));
const emailPreviewPreheader = computed(() => renderEmailPreview(currentEmailTemplate.value.preheader));
const emailPreviewCode = computed(() => props.emailTemplates.variables.find(variable => variable.key === 'discount_code')?.sample ?? 'STUDENT-AB12CD34');
const emailTemplateError = computed(() => Object.values(emailTemplateForm.errors)[0] ?? '');
const configuredSocials = computed(() => [
    { label: 'Instagram', url: emailTemplateForm.branding.instagram_url, icon: '/images/email/social/instagram.png' },
    { label: 'Facebook', url: emailTemplateForm.branding.facebook_url, icon: '/images/email/social/facebook.png' },
    { label: 'TikTok', url: emailTemplateForm.branding.tiktok_url, icon: '/images/email/social/tiktok.png' },
    { label: 'YouTube', url: emailTemplateForm.branding.youtube_url, icon: '/images/email/social/youtube.png' },
].filter(social => Boolean(social.url)));
const insertEmailVariable = (key: string) => {
    const token = emailVariableToken(key);
    currentEmailBody.value = `${currentEmailBody.value}${currentEmailBody.value.endsWith(' ') || currentEmailBody.value.endsWith('\n') ? '' : ' '}${token}`;
};
const restoreEmailDefault = () => {
    emailTemplateForm[selectedEmailTemplate.value] = { ...props.emailTemplates.defaults.templates[selectedEmailTemplate.value] };
    emailTemplateForm.clearErrors();
};
const restoreEmailBrandingDefault = () => {
    emailTemplateForm.branding = { ...props.emailTemplates.defaults.branding };
    emailTemplateForm.clearErrors();
};
const saveEmailTemplates = () => emailTemplateForm.put(`${baseUrl}/email-templates`, {
    preserveScroll: true,
    onSuccess: () => emailTemplateForm.defaults(),
});
const testEmailForm = useForm({ email: '' });
const sendTestEmail = () => testEmailForm
    .transform(data => ({
        type: selectedEmailTemplate.value,
        email: data.email,
        branding: emailTemplateForm.branding,
        approval: emailTemplateForm.approval,
        rejection: emailTemplateForm.rejection,
    }))
    .post(`${baseUrl}/email-templates/test`, { preserveScroll: true });

const filterForm = useForm({
    tab: 'claims',
    status: props.filters.status,
    source: '',
    review_method: props.filters.review_method,
    usage_status: props.filters.usage_status,
    per_page: props.filters.per_page,
    email: '',
    submitted_from: '',
    submitted_to: '',
});
const submitFilters = () => filterForm
    .transform(data => ({
        tab: data.tab,
        status: data.status,
        review_method: data.review_method,
        usage_status: data.usage_status,
        per_page: data.per_page,
    }))
    .get(baseUrl, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onSuccess: () => { activeTab.value = 'claims'; },
    });
const resetFilters = () => router.get(baseUrl, { tab: 'claims', per_page: props.filters.per_page }, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
});
const hasActiveFilters = computed(() => [
    filterForm.status,
    filterForm.review_method,
    filterForm.usage_status,
].some(Boolean));

const selectedClaimIds = ref<string[]>([]);
const bulkSelectionLimit = 30;
const bulkActionsOpen = ref(false);
const bulkActionsElement = ref<HTMLElement | null>(null);
const currentPendingIds = computed(() => props.claims.data.filter(claim => claim.status === 'pending').slice(0, bulkSelectionLimit).map(claim => claim.id));
const allPendingSelected = computed({
    get: () => currentPendingIds.value.length > 0 && currentPendingIds.value.every(id => selectedClaimIds.value.includes(id)),
    set: (selected: boolean) => { selectedClaimIds.value = selected ? [...currentPendingIds.value] : []; },
});
watch(() => props.claims.data.map(claim => claim.id).join(','), () => {
    selectedClaimIds.value = [];
    bulkActionsOpen.value = false;
});
watch(() => selectedClaimIds.value.length, count => {
    if (count === 0) bulkActionsOpen.value = false;
});
const closeBulkActions = () => { bulkActionsOpen.value = false; };
const handleBulkActionsPointerDown = (event: PointerEvent) => {
    if (!(event.target instanceof Node) || bulkActionsElement.value?.contains(event.target)) return;
    closeBulkActions();
};
const handleBulkActionsKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') closeBulkActions();
};
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
    closeBulkActions();
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

const evidencePreview = ref<Claim | null>(null);
const evidencePreviewElement = ref<HTMLElement | null>(null);
const evidenceUrl = (claim: Claim) => `${baseUrl}/claims/${claim.id}/evidence`;
const canPreviewEvidence = (claim: Claim) => props.permissions.viewEvidence
    && claim.has_evidence
    && claim.source === 'student_id';
const openEvidencePreview = (claim: Claim) => {
    if (!canPreviewEvidence(claim)) return;
    evidencePreview.value = claim;
    nextTick(() => evidencePreviewElement.value?.focus());
};
const closeEvidencePreview = () => { evidencePreview.value = null; };

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
onMounted(() => {
    document.addEventListener('pointerdown', handleBulkActionsPointerDown);
    document.addEventListener('keydown', handleBulkActionsKeydown);
});
onBeforeUnmount(() => {
    if (usageReloadTimer) clearTimeout(usageReloadTimer);
    document.removeEventListener('pointerdown', handleBulkActionsPointerDown);
    document.removeEventListener('keydown', handleBulkActionsKeydown);
});
const badge = (status: string) => ({
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    approved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    rejected: 'bg-rose-50 text-rose-700 ring-rose-200',
    voided: 'bg-slate-100 text-slate-500 ring-slate-200',
    unused: 'bg-blue-50 text-blue-700 ring-blue-200',
    partially_used: 'bg-violet-50 text-violet-700 ring-violet-200',
    used_up: 'bg-slate-100 text-slate-700 ring-slate-200',
    expired: 'bg-slate-100 text-slate-500 ring-slate-200',
}[status] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
const label = (status: string) => ({
    pending: '待人工审核', approved: '已通过', rejected: '已拒绝', voided: '已作废', unused: '未使用',
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
type RecognitionFieldKey = 'institution_name' | 'student_name' | 'student_identifier_masked' | 'expiry_date';
const recognitionValue = (result: Record<string, unknown>, key: RecognitionFieldKey) => {
    const value = result[key];

    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
};
const recognitionIsStudentId = (result: Record<string, unknown>) => result.is_student_id;
const recognitionConclusion = (result: Record<string, unknown>) => {
    if (recognitionIsStudentId(result) === true) return '识别为学生证';
    if (recognitionIsStudentId(result) === false) return '未识别为学生证';

    return '识别结论不明确';
};
const recognitionConclusionClass = (result: Record<string, unknown>) => {
    if (recognitionIsStudentId(result) === true) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (recognitionIsStudentId(result) === false) return 'bg-rose-50 text-rose-700 ring-rose-200';

    return 'bg-amber-50 text-amber-700 ring-amber-200';
};
const recognitionSuggestion = (result: Record<string, unknown>) => {
    const confidence = typeof result.confidence === 'number' ? result.confidence : null;

    if (recognitionIsStudentId(result) === false) {
        return '系统未识别到清晰的学生证版式，需要人工结合证件图片复核。';
    }

    if (recognitionIsStudentId(result) === true && confidence !== null && confidence < 80) {
        return '系统识别到学生证版式，但置信度较低，需要人工复核。';
    }

    if (recognitionIsStudentId(result) === true) {
        return '系统识别到学生证版式，请结合申请信息确认后完成审核。';
    }

    return '系统没有返回明确的版式识别结论，需要人工复核。';
};
const recognitionExpiryDate = (result: Record<string, unknown>) => {
    const value = recognitionValue(result, 'expiry_date');
    const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return match ? `${match[1]}年${match[2]}月${match[3]}日` : value;
};
const recognitionFields = (result: Record<string, unknown>) => [
    { key: 'institution_name', label: '学校 / 机构', value: recognitionValue(result, 'institution_name') },
    { key: 'student_name', label: '证件姓名', value: recognitionValue(result, 'student_name') },
    { key: 'student_identifier_masked', label: '学号（已脱敏）', value: recognitionValue(result, 'student_identifier_masked') },
    { key: 'expiry_date', label: '证件有效期', value: recognitionExpiryDate(result) },
].filter((field): field is { key: string; label: string; value: string } => field.value !== null);
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

            <nav class="overflow-x-auto" aria-label="学生优惠管理" role="tablist">
                <div class="grid min-w-[760px] grid-cols-3 gap-1.5 rounded-2xl border border-slate-200 bg-slate-100/80 p-1.5">
                    <button
                        v-for="(tab, index) in tabOptions"
                        :id="`${tab.value}-tab`"
                        :key="tab.value"
                        type="button"
                        role="tab"
                        :aria-controls="`${tab.value}-panel`"
                        :aria-selected="activeTab === tab.value"
                        :tabindex="activeTab === tab.value ? 0 : -1"
                        class="flex items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left transition focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1"
                        :class="activeTab === tab.value
                            ? 'border-blue-200 bg-white text-slate-950 shadow-sm'
                            : 'border-transparent text-slate-600 hover:border-slate-200 hover:bg-white/70'"
                        @click="selectTab(tab.value)"
                        @keydown="handleTabKeydown($event, index)"
                    >
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition"
                            :class="activeTab === tab.value ? 'bg-blue-50 text-blue-700' : 'bg-white text-slate-500 ring-1 ring-slate-200'"
                        >
                            <svg v-if="tab.value === 'campaign'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h10m4 0h2M4 17h2m4 0h10M14 5v4M6 15v4" />
                            </svg>
                            <svg v-else-if="tab.value === 'emails'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5v10.5H3.75V6.75Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 7.5 7.5 5.25 7.5-5.25" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h7.5L19 8.25v12H7v-16.5Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3.75v4.5h5M10 12h6m-6 4h6" />
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="block text-sm font-semibold">{{ tab.name }}</span>
                                <span v-if="tab.value === 'claims'" class="inline-flex min-w-5 items-center justify-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-bold leading-none text-amber-700" :aria-label="`待审核 ${counts.pending} 条`">{{ counts.pending }}</span>
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-slate-500">{{ tab.description }}</span>
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
                v-else-if="activeTab === 'claims'"
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
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="text-xs font-semibold text-slate-600"><span>申请状态</span><select v-model="filterForm.status" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option value="">全部状态</option><option v-for="option in filterOptions.statuses" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="text-xs font-semibold text-slate-600"><span>验证方式</span><select v-model="filterForm.review_method" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option value="">全部方式</option><option v-for="option in filterOptions.review_methods" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="text-xs font-semibold text-slate-600"><span>使用情况</span><select v-model="filterForm.usage_status" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option value="">全部使用情况</option><option v-for="option in filterOptions.usage_statuses" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                    </div>
                    <div class="flex flex-col gap-3 border-t border-slate-200/70 pt-4 sm:flex-row sm:items-end sm:justify-between">
                        <label class="w-full text-xs font-semibold text-slate-600 sm:w-40"><span>每页条数</span><select v-model="filterForm.per_page" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal text-slate-800"><option :value="20">20 条</option><option :value="30">30 条</option><option :value="50">50 条</option></select></label>
                        <div class="flex justify-end gap-2"><button :disabled="filterForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">筛选</button><button v-if="hasActiveFilters" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600" @click="resetFilters">重置</button></div>
                    </div>
                </form>
                <div v-if="batchResult" class="border-b border-slate-100 bg-blue-50 px-5 py-3 text-sm text-blue-800 sm:px-7">上次批量处理：成功 {{ batchResult.succeeded }} 条，已处理 {{ batchResult.unchanged }} 条，失败 {{ batchResult.failed }} 条。</div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-white px-5 py-3 sm:px-7">
                    <div class="flex items-center gap-3"><p class="text-sm font-semibold text-slate-800">已选择 <span class="text-blue-700">{{ selectedClaimIds.length }}</span> 条</p><button v-if="selectedClaimIds.length > 0" type="button" class="text-xs font-semibold text-slate-500 hover:text-slate-800" @click="selectedClaimIds = []">清除选择</button><span class="hidden text-xs text-slate-400 sm:inline">仅可选择当前页待审核申请，每次最多 {{ bulkSelectionLimit }} 条</span></div>
                    <div ref="bulkActionsElement" class="relative">
                        <button
                            type="button"
                            :disabled="selectedClaimIds.length === 0"
                            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45"
                            aria-haspopup="menu"
                            :aria-expanded="bulkActionsOpen"
                            aria-controls="student-discount-bulk-actions"
                            @click="bulkActionsOpen = !bulkActionsOpen"
                        >
                            更多操作
                            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.72a.75.75 0 0 1 1.06 0L10 11.44l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.78a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                        </button>
                        <div v-if="bulkActionsOpen" id="student-discount-bulk-actions" role="menu" aria-label="批量审核操作" class="absolute right-0 z-20 mt-2 w-44 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl" @keydown.esc.stop="closeBulkActions">
                            <button v-if="permissions.approve" type="button" role="menuitem" class="flex w-full items-center rounded-lg px-3 py-2 text-left text-sm font-semibold text-emerald-700 hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none" @click="openReviewDialog('bulk_approve')">一键批准</button>
                            <button v-if="permissions.reject" type="button" role="menuitem" class="flex w-full items-center rounded-lg px-3 py-2 text-left text-sm font-semibold text-rose-700 hover:bg-rose-50 focus:bg-rose-50 focus:outline-none" @click="openReviewDialog('bulk_reject')">一键拒绝</button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-[1260px] divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th class="w-12 px-4 py-3"><input v-if="permissions.approve || permissions.reject" v-model="allPendingSelected" type="checkbox" :disabled="currentPendingIds.length === 0" aria-label="选择当前页全部待审核申请" /></th><th class="px-4 py-3">申请人</th><th class="w-28 px-4 py-3">学生证</th><th class="px-4 py-3">证明 / 验证</th><th class="px-4 py-3">申请状态</th><th class="px-4 py-3">使用情况</th><th class="px-4 py-3">提交时间</th><th class="px-4 py-3 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="claim in claims.data" :key="claim.id" class="align-top">
                                <td class="px-4 py-4"><input v-if="claim.status === 'pending' && (permissions.approve || permissions.reject)" v-model="selectedClaimIds" :value="claim.id" type="checkbox" :disabled="!selectedClaimIds.includes(claim.id) && selectedClaimIds.length >= bulkSelectionLimit" :aria-label="`选择 ${claim.email} 的申请`" /></td>
                                <td class="px-4 py-4"><p class="font-semibold text-slate-900">{{ claim.name || '未填写姓名' }}</p><p class="mt-1 break-all text-xs text-slate-500">{{ claim.email }}</p><p class="mt-1 text-xs text-slate-400">第 {{ claim.submission_count }} 次提交</p></td>
                                <td class="px-4 py-4">
                                    <button v-if="canPreviewEvidence(claim)" type="button" class="group relative block h-12 w-[72px] overflow-hidden rounded-lg border border-slate-200 bg-slate-100 shadow-sm transition hover:border-slate-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" :aria-label="`放大查看 ${claim.name || claim.email} 的学生证`" @click="openEvidencePreview(claim)">
                                        <img :src="evidenceUrl(claim)" alt="学生证缩略图" class="h-full w-full object-cover transition duration-200 group-hover:scale-105" loading="lazy" />
                                        <span class="absolute inset-0 flex items-center justify-center bg-slate-950/0 text-white opacity-0 transition group-hover:bg-slate-950/35 group-hover:opacity-100" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m16 16 4 4M8 11h6m-3-3v6" /></svg></span>
                                    </button>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="font-semibold text-slate-800">{{ sourceLabel(claim.source) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ verificationLabel(claim) }}<span v-if="claim.confidence !== null"> · {{ claim.confidence }}/100</span></p>
                                    <p v-if="claim.recognition_failure_code" class="mt-1 text-xs font-semibold text-amber-700">{{ recognitionFailureLabel(claim.recognition_failure_code) }}</p>
                                    <details v-if="permissions.viewEvidence && claim.recognition_result" class="mt-2">
                                        <summary class="cursor-pointer text-xs font-semibold text-emerald-700">查看识别结论</summary>
                                        <div class="mt-2 max-w-md rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 shadow-sm">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="font-semibold text-slate-500">AI 识别结论</span>
                                                <span class="inline-flex rounded-full px-2.5 py-1 font-semibold ring-1" :class="recognitionConclusionClass(claim.recognition_result)">{{ recognitionConclusion(claim.recognition_result) }}</span>
                                            </div>
                                            <p class="mt-3 leading-5 text-slate-700">{{ recognitionSuggestion(claim.recognition_result) }}</p>
                                            <dl v-if="recognitionFields(claim.recognition_result).length > 0" class="mt-3 grid gap-2 border-t border-slate-200 pt-3 sm:grid-cols-2">
                                                <div v-for="field in recognitionFields(claim.recognition_result)" :key="field.key" class="min-w-0">
                                                    <dt class="text-[11px] font-semibold text-slate-400">{{ field.label }}</dt>
                                                    <dd class="mt-0.5 break-words font-medium text-slate-700">{{ field.value }}</dd>
                                                </div>
                                            </dl>
                                            <p class="mt-3 border-t border-slate-200 pt-2 text-[11px] leading-4 text-slate-400">该结论仅表示证件版式识别结果，不代表证件真实性或当前在校状态。</p>
                                        </div>
                                    </details>
                                </td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="badge(claim.status)">{{ label(claim.status) }}</span><div v-if="claim.discount" class="mt-2"><code class="font-semibold text-slate-900">{{ claim.discount.code }}</code><p class="mt-1 text-xs text-slate-500">有效期至 {{ new Date(claim.discount.expires_at).toLocaleDateString() }}</p></div><p v-if="claim.rejection_reason" class="mt-2 max-w-sm text-xs text-rose-600">{{ claim.rejection_reason }}</p></td>
                                <td class="px-4 py-4"><template v-if="claim.discount"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="badge(claim.discount.status)">{{ label(claim.discount.status) }}</span><p class="mt-2 text-xs font-semibold text-slate-700">{{ claim.discount.usage_count }}/{{ claim.discount.usage_limit }} 次</p><p class="mt-1 text-[11px] text-slate-400">{{ claim.discount.last_synced_at ? `同步于 ${new Date(claim.discount.last_synced_at).toLocaleString()}` : '尚未向 Shopify 同步' }}</p></template><span v-else class="text-xs text-slate-400">尚未发码</span></td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-500">{{ new Date(claim.created_at).toLocaleString() }}<p v-if="claim.reviewer" class="mt-1">审核：{{ claim.reviewer }}</p></td>
                                <td class="px-4 py-4"><div class="flex flex-wrap justify-end gap-2"><button v-if="claim.status === 'pending' && permissions.approve" type="button" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white" @click="openReviewDialog('approve', claim)">通过</button><button v-if="claim.status === 'pending' && permissions.reject" type="button" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white" @click="openReviewDialog('reject', claim)">拒绝</button><button v-if="permissions.deleteClaim" type="button" class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50" @click="openDelete(claim)">删除</button></div></td>
                            </tr>
                            <tr v-if="claims.data.length === 0"><td colspan="8" class="px-5 py-14 text-center"><p class="text-sm font-semibold text-slate-700">没有符合条件的申请记录</p><p class="mt-1 text-xs text-slate-400">调整筛选条件或等待新的学生优惠申请。</p><button v-if="hasActiveFilters" type="button" class="mt-4 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600" @click="resetFilters">清除筛选</button></td></tr>
                        </tbody>
                    </table>
                </div>
                <footer v-if="claims.links.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500"><span>第 {{ claims.from ?? 0 }}–{{ claims.to ?? 0 }} 条，共 {{ claims.total }} 条</span><div class="flex gap-1"><Link v-for="item in claims.links" :key="item.label" :href="claimPageUrl(item.url)" preserve-scroll class="rounded-lg px-2.5 py-1.5 ring-1" :class="[item.active ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200', !item.url ? 'pointer-events-none opacity-40' : '']"><span v-html="item.label" /></Link></div></footer>
            </section>

            <section
                v-else
                id="emails-panel"
                role="tabpanel"
                aria-labelledby="emails-tab"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">每店铺独立</p><h2 class="mt-1 text-xl font-semibold text-slate-950">邮件内容</h2><p class="mt-1 text-xs leading-5 text-slate-500">批准、自动发码和人工拒绝通知共用这里的内容；发送身份继续使用现有系统邮件配置。</p></div>
                    <span class="text-xs font-semibold text-slate-500">{{ permissions.manageEmailTemplates ? '可编辑' : '只读' }}</span>
                </header>

                <div class="border-b border-slate-100 bg-slate-50/60 p-5 sm:p-7">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button type="button" class="rounded-2xl border p-4 text-left transition" :class="selectedEmailTemplate === 'approval' ? 'border-emerald-300 bg-emerald-50 ring-1 ring-emerald-200' : 'border-slate-200 bg-white hover:border-slate-300'" @click="selectedEmailTemplate = 'approval'">
                            <span class="flex items-center justify-between gap-3"><span class="text-sm font-semibold text-slate-950">批准与发码邮件</span><span class="rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-700">批准</span></span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">教育邮箱快速通过、AI 通过和人工批准后发送。</span>
                        </button>
                        <button type="button" class="rounded-2xl border p-4 text-left transition" :class="selectedEmailTemplate === 'rejection' ? 'border-rose-300 bg-rose-50 ring-1 ring-rose-200' : 'border-slate-200 bg-white hover:border-slate-300'" @click="selectedEmailTemplate = 'rejection'">
                            <span class="flex items-center justify-between gap-3"><span class="text-sm font-semibold text-slate-950">拒绝通知邮件</span><span class="rounded-full bg-rose-100 px-2 py-1 text-[11px] font-semibold text-rose-700">拒绝</span></span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">人工拒绝申请后发送，并自动带入拒绝原因。</span>
                        </button>
                    </div>
                </div>

                <div class="space-y-7 p-5 sm:p-7">
                    <section class="rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-base font-semibold text-slate-950">店铺品牌与链接</h3><p class="mt-1 text-xs leading-5 text-slate-500">当前店铺独立保存；留空的 Logo、客服或社交链接不会显示在邮件中。</p></div><button v-if="permissions.manageEmailTemplates" type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50" @click="restoreEmailBrandingDefault">恢复品牌默认值</button></div>
                        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <label class="block"><span class="text-xs font-semibold text-slate-700">Logo 图片 URL</span><input v-model="emailTemplateForm.branding.logo_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="https://…/logo.png" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-100" /></label>
                            <label class="block"><span class="text-xs font-semibold text-slate-700">品牌主色</span><span class="mt-1.5 flex rounded-xl border border-slate-200 bg-white p-1"><input v-model="emailTemplateForm.branding.primary_color" :disabled="!permissions.manageEmailTemplates" type="color" class="h-9 w-12 cursor-pointer rounded-lg border-0 bg-transparent p-0" /><input v-model="emailTemplateForm.branding.primary_color" :disabled="!permissions.manageEmailTemplates" maxlength="7" class="min-w-0 flex-1 border-0 px-2 font-mono text-sm uppercase outline-none" /></span></label>
                            <label class="block"><span class="text-xs font-semibold text-slate-700">SHOP NOW 跳转链接</span><input v-model="emailTemplateForm.branding.shop_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="https://your-store.com" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-100" /></label>
                            <label class="block"><span class="text-xs font-semibold text-slate-700">客服邮箱</span><input v-model="emailTemplateForm.branding.support_email" :disabled="!permissions.manageEmailTemplates" type="email" maxlength="254" placeholder="support@example.com" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-100" /></label>
                            <label class="block"><span class="text-xs font-semibold text-slate-700">客服页面 URL</span><input v-model="emailTemplateForm.branding.support_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="https://…/support" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-100" /></label>
                        </div>
                        <details class="mt-4 rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer text-sm font-semibold text-slate-700">社交媒体链接（可选）</summary><div class="mt-4 grid gap-3 md:grid-cols-2"><input v-model="emailTemplateForm.branding.instagram_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="Instagram URL" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" /><input v-model="emailTemplateForm.branding.facebook_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="Facebook URL" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" /><input v-model="emailTemplateForm.branding.tiktok_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="TikTok URL" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" /><input v-model="emailTemplateForm.branding.youtube_url" :disabled="!permissions.manageEmailTemplates" type="url" maxlength="2048" placeholder="YouTube URL" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" /></div></details>
                    </section>

                    <div class="grid gap-7 xl:grid-cols-[minmax(0,1.05fr)_minmax(420px,0.95fr)]">
                        <div class="space-y-4">
                            <label class="block"><span class="text-sm font-semibold text-slate-800">邮件主题</span><input v-model="currentEmailSubject" :disabled="!permissions.manageEmailTemplates" maxlength="180" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50" /></label>
                            <label class="block"><span class="text-sm font-semibold text-slate-800">收件箱预览摘要</span><input v-model="currentEmailTemplate.preheader" :disabled="!permissions.manageEmailTemplates" maxlength="240" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50" /></label>
                            <label class="block"><span class="text-sm font-semibold text-slate-800">邮件标题</span><input v-model="currentEmailTemplate.heading" :disabled="!permissions.manageEmailTemplates" maxlength="240" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50" /></label>
                            <label class="block"><span class="text-sm font-semibold text-slate-800">邮件正文</span><textarea v-model="currentEmailBody" :disabled="!permissions.manageEmailTemplates" rows="7" maxlength="5000" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm leading-6 outline-none focus:border-emerald-500 disabled:bg-slate-50" /></label>
                            <div class="grid gap-4 md:grid-cols-2"><label class="block"><span class="text-sm font-semibold text-slate-800">按钮文字</span><input v-model="currentEmailTemplate.cta_label" :disabled="!permissions.manageEmailTemplates" maxlength="80" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></label><label class="block"><span class="text-sm font-semibold text-slate-800">优惠说明</span><input v-model="currentEmailTemplate.footer_note" :disabled="!permissions.manageEmailTemplates" maxlength="500" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></label></div>
                            <div>
                                <p class="text-xs font-semibold text-slate-600">插入变量</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button v-for="variable in availableEmailVariables" :key="variable.key" type="button" :disabled="!permissions.manageEmailTemplates" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-mono text-xs text-slate-700 hover:border-emerald-300 hover:bg-emerald-50 disabled:opacity-50" :title="variable.label" @click="insertEmailVariable(variable.key)">{{ emailVariableToken(variable.key) }}</button>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-400">仅支持上方安全变量，不执行 HTML、脚本或其他模板代码。优惠码由系统固定区域显示；拒绝正文必须保留拒绝原因变量。</p>
                            </div>
                            <div v-if="emailTemplateError" class="rounded-xl bg-rose-50 px-4 py-3 text-xs text-rose-700">{{ emailTemplateError }}</div>
                            <div v-if="permissions.manageEmailTemplates" class="flex flex-wrap gap-2">
                                <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50" @click="restoreEmailDefault">恢复当前默认内容</button>
                                <button type="button" :disabled="emailTemplateForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="saveEmailTemplates">{{ emailTemplateForm.processing ? '保存中…' : '保存邮件设置' }}</button>
                            </div>
                        </div>

                        <aside class="space-y-4">
                            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 shadow-sm">
                                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">实时预览</p><p class="mt-1 max-w-xs truncate text-sm font-semibold text-slate-900" :title="emailPreviewSubject">{{ emailPreviewSubject }}</p><p class="mt-0.5 max-w-xs truncate text-[11px] text-slate-400">{{ emailPreviewPreheader }}</p></div><div class="flex rounded-lg bg-slate-100 p-1 text-[11px] font-semibold"><button type="button" class="rounded-md px-2 py-1" :class="emailPreviewMode === 'desktop' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="emailPreviewMode = 'desktop'">桌面</button><button type="button" class="rounded-md px-2 py-1" :class="emailPreviewMode === 'mobile' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="emailPreviewMode = 'mobile'">手机</button></div></div>
                                <div class="overflow-auto bg-slate-200 p-4"><div class="mx-auto bg-white shadow-sm transition-all" :class="emailPreviewMode === 'mobile' ? 'max-w-[320px]' : 'max-w-[560px]'">
                                    <div class="border-b border-slate-100 px-6 py-6 text-center"><img v-if="emailTemplateForm.branding.logo_url" :src="emailTemplateForm.branding.logo_url" :alt="store.name" class="mx-auto max-h-14 max-w-[180px] object-contain" /><p v-else class="text-xl font-extrabold" :style="{ color: emailTemplateForm.branding.primary_color }">{{ store.name }}</p></div>
                                    <div class="px-6 py-7"><h3 class="text-2xl font-extrabold leading-tight text-slate-950">{{ emailPreviewHeading }}</h3><p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-600">{{ emailPreviewBody }}</p><div v-if="selectedEmailTemplate === 'approval'" class="mt-5 break-all border-[3px] px-4 py-5 text-center font-mono text-xl font-extrabold tracking-wider" :style="{ borderColor: emailTemplateForm.branding.primary_color }">{{ emailPreviewCode }}</div><div v-if="emailTemplateForm.branding.shop_url && currentEmailTemplate.cta_label" class="mt-3 px-4 py-4 text-center text-sm font-extrabold text-white" :style="{ backgroundColor: emailTemplateForm.branding.primary_color }">{{ currentEmailTemplate.cta_label }}</div><p v-if="currentEmailTemplate.footer_note" class="mt-4 text-xs italic leading-5 text-slate-500">{{ currentEmailTemplate.footer_note }}</p></div>
                                    <div v-if="emailTemplateForm.branding.support_url || emailTemplateForm.branding.support_email || configuredSocials.length" class="border-t border-slate-100 px-6 py-5 text-xs leading-5 text-slate-500"><template v-if="emailTemplateForm.branding.support_url || emailTemplateForm.branding.support_email"><strong class="text-slate-800">HELP</strong><br>Having trouble? <span class="font-semibold underline" :style="{ color: emailTemplateForm.branding.primary_color }">Contact our support team</span>.</template><div v-if="configuredSocials.length" class="mt-4 flex flex-wrap items-center justify-center gap-4"><img v-for="social in configuredSocials" :key="social.label" :src="social.icon" :alt="social.label" :title="social.label" class="h-7 w-7 object-contain" /></div></div>
                                </div></div>
                            </div>
                            <form v-if="permissions.manageEmailTemplates" class="rounded-2xl border border-blue-100 bg-blue-50 p-4" @submit.prevent="sendTestEmail">
                                <p class="text-sm font-semibold text-blue-950">发送测试邮件</p>
                                <p class="mt-1 text-xs leading-5 text-blue-700">使用当前编辑内容和示例变量发送，不会创建申请或优惠码。</p>
                                <div class="mt-3 flex flex-col gap-2 sm:flex-row xl:flex-col 2xl:flex-row"><input v-model="testEmailForm.email" type="email" maxlength="254" required placeholder="测试收件邮箱" class="min-w-0 flex-1 rounded-xl border border-blue-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-500" /><button :disabled="testEmailForm.processing" class="shrink-0 rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ testEmailForm.processing ? '发送中…' : '发送测试' }}</button></div>
                                <p v-if="Object.values(testEmailForm.errors)[0]" class="mt-2 text-xs text-rose-600">{{ Object.values(testEmailForm.errors)[0] }}</p>
                            </form>
                        </aside>
                    </div>
                </div>
            </section>

            <Teleport to="body">
                <div v-if="evidencePreview" ref="evidencePreviewElement" tabindex="-1" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 outline-none" role="dialog" aria-modal="true" aria-labelledby="evidence-preview-title" @keydown.esc="closeEvidencePreview" @click.self="closeEvidencePreview">
                    <div class="relative flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"><header class="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h3 id="evidence-preview-title" class="text-base font-semibold text-slate-950">学生证预览</h3><p class="mt-0.5 text-xs text-slate-500">仅用于当前店铺人工审核</p></div><button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-xl text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500" aria-label="关闭学生证预览" @click="closeEvidencePreview">×</button></header><div class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-slate-100 p-4"><img :src="evidenceUrl(evidencePreview)" alt="学生证预览" class="max-h-[76vh] max-w-full rounded-xl object-contain shadow-sm" /></div></div>
                </div>
                <div v-if="reviewDialog" ref="reviewDialogElement" tabindex="-1" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 outline-none" role="dialog" aria-modal="true" aria-labelledby="review-dialog-title" aria-describedby="review-dialog-description" @keydown.esc="closeReviewDialog" @click.self="closeReviewDialog">
                    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div class="flex h-11 w-11 items-center justify-center rounded-full" :class="reviewNeedsReason ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path v-if="reviewNeedsReason" stroke-linecap="round" stroke-linejoin="round" d="m7 7 10 10M17 7 7 17" /><path v-else stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg></div><h3 id="review-dialog-title" class="mt-4 text-lg font-semibold text-slate-950">{{ reviewDialogTitle }}</h3><p id="review-dialog-description" class="mt-2 text-sm leading-6 text-slate-600">{{ reviewDialogDescription }}</p><label v-if="reviewNeedsReason" class="mt-4 block text-sm font-semibold text-slate-700"><span>拒绝原因</span><textarea v-model="reviewReason" autofocus rows="4" maxlength="1000" :disabled="reviewProcessing" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-normal" placeholder="请输入拒绝原因" @input="reviewError = ''" /></label><p v-if="reviewError" class="mt-2 text-xs text-rose-600" role="alert">{{ reviewError }}</p><div class="mt-5 flex justify-end gap-2"><button type="button" :disabled="reviewProcessing" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50" @click="closeReviewDialog">取消</button><button type="button" :disabled="reviewProcessing" class="rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :class="reviewNeedsReason ? 'bg-rose-600' : 'bg-emerald-600'" @click="submitReview">{{ reviewProcessing ? '处理中…' : (reviewNeedsReason ? '确认拒绝' : '确认批准') }}</button></div></div>
                </div>
                <div v-if="deleteTarget" ref="deleteDialogElement" tabindex="-1" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 outline-none" role="dialog" aria-modal="true" aria-labelledby="delete-claim-title" aria-describedby="delete-claim-description" @keydown.esc="closeDelete" @click.self="closeDelete">
                    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div class="flex h-11 w-11 items-center justify-center rounded-full bg-rose-50 text-rose-600"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5" /></svg></div><h3 id="delete-claim-title" class="mt-4 text-lg font-semibold text-slate-950">删除申请记录</h3><p id="delete-claim-description" class="mt-2 text-sm leading-6 text-slate-600">将删除 <span class="font-semibold text-slate-900">{{ deleteTarget.email }}</span> 的申请记录、学生证文件及关联优惠券。审计记录仍会保留。</p><p v-if="deleteError" class="mt-2 text-xs text-rose-600" role="alert">{{ deleteError }}</p><div class="mt-5 flex justify-end gap-2"><button type="button" :disabled="deleteProcessing" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50" @click="closeDelete">取消</button><button type="button" :disabled="deleteProcessing" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="confirmDelete">{{ deleteProcessing ? '处理中…' : '确认删除' }}</button></div></div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
