<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
    recognition_result: Record<string, unknown> | null;
    has_evidence: boolean;
    submission_count: number;
    reviewer: string | null;
    rejection_reason: string | null;
    created_at: string;
    reviewed_at: string | null;
    discount: { code: string; status: string; usage_count: number; usage_limit: number; expires_at: string } | null;
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
    filters: { status: string };
    permissions: { viewEvidence: boolean; approve: boolean; reject: boolean; manageCampaign: boolean; analytics: boolean; audit: boolean };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/student-discounts`;
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

const approve = (claim: Claim) => {
    if (!window.confirm(`确认通过 ${claim.email} 的申请并生成 Shopify 优惠码？`)) return;
    router.post(`${baseUrl}/claims/${claim.id}/approve`, {}, { preserveScroll: true });
};
const reject = (claim: Claim) => {
    const reason = window.prompt(`请输入拒绝 ${claim.email} 的原因（必填）：`);
    if (!reason?.trim()) return;
    router.post(`${baseUrl}/claims/${claim.id}/reject`, { reason: reason.trim() }, { preserveScroll: true });
};
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

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
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

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">审核与发码</p><h2 class="mt-1 text-xl font-semibold text-slate-950">申请记录</h2></header>
                <div class="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-4 sm:px-7">
                    <Link v-for="option in [{ value: '', name: '全部' }, { value: 'pending', name: '待审核' }, { value: 'approved', name: '已通过' }, { value: 'rejected', name: '已拒绝' }]" :key="option.value" :href="option.value ? `${baseUrl}?status=${option.value}` : baseUrl" class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="filters.status === option.value ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200'">{{ option.name }}</Link>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">申请人</th><th class="px-5 py-3">识别</th><th class="px-5 py-3">状态 / 优惠码</th><th class="px-5 py-3">提交时间</th><th class="px-5 py-3 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="claim in claims.data" :key="claim.id" class="align-top">
                                <td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ claim.email }}</p><p class="mt-1 text-xs text-slate-500">{{ claim.source === 'education_email' ? '教育邮箱快速通过' : '学生证' }} · 第 {{ claim.submission_count }} 次提交</p></td>
                                <td class="px-5 py-4"><p class="font-semibold text-slate-800">{{ claim.confidence === null ? '—' : `${claim.confidence} / 100` }}</p><p class="mt-1 text-xs text-slate-500">{{ claim.model_name ?? claim.review_method ?? '未识别' }}</p><details v-if="permissions.viewEvidence && claim.recognition_result" class="mt-2"><summary class="cursor-pointer text-xs font-semibold text-emerald-700">查看结构化识别</summary><pre class="mt-2 max-w-md overflow-auto rounded-lg bg-slate-950 p-3 text-[11px] text-slate-200">{{ JSON.stringify(claim.recognition_result, null, 2) }}</pre></details></td>
                                <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="badge(claim.status)">{{ label(claim.status) }}</span><div v-if="claim.discount" class="mt-2"><code class="font-semibold text-slate-900">{{ claim.discount.code }}</code><p class="mt-1 text-xs text-slate-500">{{ label(claim.discount.status) }} · {{ claim.discount.usage_count }}/{{ claim.discount.usage_limit }} · {{ new Date(claim.discount.expires_at).toLocaleString() }}</p></div><p v-if="claim.rejection_reason" class="mt-2 max-w-sm text-xs text-rose-600">{{ claim.rejection_reason }}</p></td>
                                <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{{ new Date(claim.created_at).toLocaleString() }}<p v-if="claim.reviewer" class="mt-1">审核：{{ claim.reviewer }}</p></td>
                                <td class="px-5 py-4"><div class="flex justify-end gap-2"><a v-if="permissions.viewEvidence && claim.has_evidence" :href="`${baseUrl}/claims/${claim.id}/evidence`" target="_blank" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700">证件</a><button v-if="claim.status === 'pending' && permissions.approve" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white" @click="approve(claim)">通过</button><button v-if="claim.status === 'pending' && permissions.reject" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white" @click="reject(claim)">拒绝</button></div></td>
                            </tr>
                            <tr v-if="claims.data.length === 0"><td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">暂无申请记录。</td></tr>
                        </tbody>
                    </table>
                </div>
                <footer v-if="claims.links.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500"><span>第 {{ claims.from ?? 0 }}–{{ claims.to ?? 0 }} 条，共 {{ claims.total }} 条</span><div class="flex gap-1"><Link v-for="item in claims.links" :key="item.label" :href="item.url ?? ''" preserve-scroll class="rounded-lg px-2.5 py-1.5 ring-1" :class="[item.active ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200', !item.url ? 'pointer-events-none opacity-40' : '']"><span v-html="item.label" /></Link></div></footer>
            </section>
        </div>
    </AppLayout>
</template>
