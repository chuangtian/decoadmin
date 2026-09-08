<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Program { public_id: string; name: string; type: string; status: string; attribution_model: string; attribution_window_days: number; hold_days: number; currency: string; memberships_count: number; coupon_enabled: boolean; customer_discount_type: string | null; customer_discount_rate_basis_points: number | null; customer_discount_amount_minor: number | null }
interface Membership { public_id: string; status: string; program: { public_id: string; name: string } | null; promoter: { public_id: string; display_name: string; email: string; type: string; status: string }; link: { url: string; code: string; status: string } | null; coupon: { code: string; status: string; last_error: string | null } | null }
const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string; currency: string };
    section: 'overview' | 'programs' | 'promoters';
    settings: { affiliate_enabled: boolean; customer_referral_enabled: boolean };
    stats: { programs: number; active_programs: number; promoters: number; pending_memberships: number };
    programs: Program[];
    memberships: Membership[];
    permissions: { managePrograms: boolean; managePromoters: boolean; manageSettings: boolean };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/affiliate`;
const showProgramForm = ref(false);
const showPromoterForm = ref(false);
const settingsForm = useForm({ ...props.settings });
const programForm = useForm({ name: '', type: 'affiliate', attribution_model: 'coupon_wins', attribution_window_days: 30, hold_days: 30, commission_type: 'percentage', rate_basis_points: 1000, amount_minor: null as number | null, coupon_enabled: true, customer_discount_type: 'percentage', customer_discount_rate_basis_points: 1000, customer_discount_amount_minor: null as number | null });
const promoterForm = useForm({ display_name: '', email: '', type: 'affiliate', program_public_id: props.programs[0]?.public_id ?? '' });
const sectionTitle = computed(() => ({ overview: '推荐与联盟', programs: '推广计划', promoters: '推广者' })[props.section]);
const submitProgram = () => programForm.post(`${baseUrl}/programs`, { preserveScroll: true, onSuccess: () => { programForm.reset(); showProgramForm.value = false; } });
const submitPromoter = () => promoterForm.post(`${baseUrl}/promoters`, { preserveScroll: true, onSuccess: () => { promoterForm.reset('display_name', 'email'); showPromoterForm.value = false; } });
const saveSettings = () => settingsForm.put(`${baseUrl}/settings`, { preserveScroll: true });
const syncCoupon = (membership: Membership) => router.post(`${baseUrl}/memberships/${membership.public_id}/sync-coupon`, {}, { preserveScroll: true });
const couponStatus = (status: string) => ({ active: '已生效', scheduled: '未到生效时间', disabled: '已停用', sync_pending: '等待同步', provisioning: '等待创建', enable_pending: '等待启用', disable_pending: '等待停用' }[status] ?? status);
const typeLabel = (value: string) => ({ affiliate: '联盟客', influencer: '达人', ambassador: '品牌大使', advocate: '顾客推荐', partner: '合作伙伴' }[value] ?? value);
const transitionProgram = (program: Program, action: 'activate' | 'pause') => router.post(`${baseUrl}/programs/${program.public_id}/transition`, { action }, { preserveScroll: true });
const transitionMembership = (membership: Membership, action: 'approve' | 'suspend') => router.post(`${baseUrl}/memberships/${membership.public_id}/transition`, { action }, { preserveScroll: true });
</script>

<template>
    <Head :title="sectionTitle" />
    <AppLayout :breadcrumbs="[{ label: '推荐与联盟' }, ...(section === 'overview' ? [] : [{ label: sectionTitle }])]">
        <div class="space-y-6">
            <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">{{ store.name }}</p><h1 class="mt-1 text-2xl font-bold text-slate-950">{{ sectionTitle }}</h1><p class="mt-2 text-sm text-slate-500">管理推广计划、合作伙伴和推荐奖励。</p></div>
                <button v-if="section === 'programs' && permissions.managePrograms" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white" @click="showProgramForm = !showProgramForm">新建计划</button>
                <button v-if="section === 'promoters' && permissions.managePromoters && programs.length" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white" @click="showPromoterForm = !showPromoterForm">添加推广者</button>
            </header>

            <nav class="flex gap-2 rounded-2xl border border-slate-200 bg-white p-2">
                <Link v-for="tab in [{ key: 'overview', label: '概览', path: '' }, { key: 'programs', label: '推广计划', path: '/programs' }, { key: 'promoters', label: '推广者', path: '/promoters' }]" :key="tab.key" :href="`${baseUrl}${tab.path}`" class="rounded-xl px-4 py-2 text-sm font-semibold" :class="section === tab.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100'">{{ tab.label }}</Link>
            </nav>

            <template v-if="section === 'overview'">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="item in [{ label: '推广计划', value: stats.programs }, { label: '已启用计划', value: stats.active_programs }, { label: '推广者', value: stats.promoters }, { label: '待审核', value: stats.pending_memberships }]" :key="item.label" class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">{{ item.label }}</p><p class="mt-2 text-3xl font-bold text-slate-950">{{ item.value }}</p></article>
                </div>
                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="text-lg font-semibold text-slate-950">店铺功能</h2><p class="mt-1 text-sm text-slate-500">先启用联盟营销；顾客推荐可在联盟账本稳定后开启。</p>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="flex items-center justify-between rounded-xl border border-slate-200 p-4"><span><b class="block text-sm">联盟营销</b><small class="text-slate-500">推广计划、佣金与结算</small></span><input v-model="settingsForm.affiliate_enabled" type="checkbox" class="rounded border-slate-300 text-emerald-600" :disabled="!permissions.manageSettings"></label>
                        <label class="flex items-center justify-between rounded-xl border border-slate-200 p-4"><span><b class="block text-sm">顾客推荐</b><small class="text-slate-500">好友优惠与推荐奖励</small></span><input v-model="settingsForm.customer_referral_enabled" type="checkbox" class="rounded border-slate-300 text-emerald-600" :disabled="!permissions.manageSettings"></label>
                    </div>
                    <button v-if="permissions.manageSettings" class="mt-5 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="settingsForm.processing" @click="saveSettings">保存设置</button>
                </section>
            </template>

            <template v-else-if="section === 'programs'">
                <form v-if="showProgramForm" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2" @submit.prevent="submitProgram">
                    <label class="sm:col-span-2"><span class="text-sm font-medium">计划名称</span><input v-model="programForm.name" class="mt-1 w-full rounded-xl border-slate-300" required maxlength="120"></label>
                    <label><span class="text-sm font-medium">类型</span><select v-model="programForm.type" class="mt-1 w-full rounded-xl border-slate-300"><option value="affiliate">联盟客</option><option value="influencer">达人</option><option value="ambassador">品牌大使</option><option value="advocate">顾客推荐</option><option value="partner">合作伙伴</option></select></label>
                    <label><span class="text-sm font-medium">归因方式</span><select v-model="programForm.attribution_model" class="mt-1 w-full rounded-xl border-slate-300"><option value="coupon_wins">优惠码优先</option><option value="last_click">最后点击</option><option value="first_click">首次点击</option></select></label>
                    <label><span class="text-sm font-medium">归因窗口（天）</span><input v-model.number="programForm.attribution_window_days" type="number" min="1" max="90" class="mt-1 w-full rounded-xl border-slate-300"></label>
                    <label><span class="text-sm font-medium">等待期（天）</span><input v-model.number="programForm.hold_days" type="number" min="0" max="90" class="mt-1 w-full rounded-xl border-slate-300"></label>
                    <label><span class="text-sm font-medium">佣金类型</span><select v-model="programForm.commission_type" class="mt-1 w-full rounded-xl border-slate-300"><option value="percentage">百分比</option><option value="fixed">固定金额</option></select></label>
                    <label v-if="programForm.commission_type === 'percentage'"><span class="text-sm font-medium">佣金比例（%）</span><input :value="programForm.rate_basis_points / 100" type="number" step="0.01" min="0.01" max="100" class="mt-1 w-full rounded-xl border-slate-300" @input="programForm.rate_basis_points = Math.round(Number(($event.target as HTMLInputElement).value) * 100)"></label>
                    <label v-else><span class="text-sm font-medium">固定金额（{{ store.currency }}）</span><input :value="(programForm.amount_minor ?? 0) / 100" type="number" step="0.01" min="0.01" class="mt-1 w-full rounded-xl border-slate-300" @input="programForm.amount_minor = Math.round(Number(($event.target as HTMLInputElement).value) * 100)"></label>
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 sm:col-span-2"><input v-model="programForm.coupon_enabled" type="checkbox" class="rounded border-slate-300 text-emerald-600"><span><b class="block text-sm">生成顾客优惠码</b><small class="text-slate-500">佣金比例与顾客优惠分别设置</small></span></label>
                    <template v-if="programForm.coupon_enabled">
                        <label><span class="text-sm font-medium">顾客优惠类型</span><select v-model="programForm.customer_discount_type" class="mt-1 w-full rounded-xl border-slate-300"><option value="percentage">百分比</option><option value="fixed">固定金额</option></select></label>
                        <label v-if="programForm.customer_discount_type === 'percentage'"><span class="text-sm font-medium">顾客优惠（%）</span><input :value="programForm.customer_discount_rate_basis_points / 100" type="number" step="0.01" min="0.01" max="100" class="mt-1 w-full rounded-xl border-slate-300" @input="programForm.customer_discount_rate_basis_points = Math.round(Number(($event.target as HTMLInputElement).value) * 100)"></label>
                        <label v-else><span class="text-sm font-medium">顾客优惠金额（{{ store.currency }}）</span><input :value="(programForm.customer_discount_amount_minor ?? 0) / 100" type="number" step="0.01" min="0.01" class="mt-1 w-full rounded-xl border-slate-300" @input="programForm.customer_discount_amount_minor = Math.round(Number(($event.target as HTMLInputElement).value) * 100)"></label>
                    </template>
                    <div class="sm:col-span-2"><button class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white" :disabled="programForm.processing">创建计划</button><p v-if="Object.keys(programForm.errors).length" class="mt-2 text-sm text-rose-600">{{ Object.values(programForm.errors)[0] }}</p></div>
                </form>
                <EmptyState v-if="!programs.length" title="还没有推广计划" description="创建第一个计划，设置归因窗口、等待期和默认佣金。" icon="campaign" />
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-4">计划</th><th class="p-4">归因</th><th class="p-4">等待期</th><th class="p-4">推广者</th><th class="p-4">状态</th><th v-if="permissions.managePrograms" class="p-4">操作</th></tr></thead><tbody><tr v-for="program in programs" :key="program.public_id" class="border-t border-slate-100"><td class="p-4"><b>{{ program.name }}</b><span class="ml-2 text-xs text-slate-500">{{ typeLabel(program.type) }}</span></td><td class="p-4">{{ program.attribution_model }} · {{ program.attribution_window_days }} 天</td><td class="p-4">{{ program.hold_days }} 天</td><td class="p-4">{{ program.memberships_count }}</td><td class="p-4">{{ program.status }}</td><td v-if="permissions.managePrograms" class="p-4"><button v-if="program.status !== 'active'" class="font-semibold text-emerald-700" @click="transitionProgram(program, 'activate')">启用</button><button v-else class="font-semibold text-amber-700" @click="transitionProgram(program, 'pause')">暂停</button></td></tr></tbody></table></div>
            </template>

            <template v-else>
                <form v-if="showPromoterForm" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2" @submit.prevent="submitPromoter">
                    <label><span class="text-sm font-medium">名称</span><input v-model="promoterForm.display_name" class="mt-1 w-full rounded-xl border-slate-300" required></label>
                    <label><span class="text-sm font-medium">邮箱</span><input v-model="promoterForm.email" type="email" class="mt-1 w-full rounded-xl border-slate-300" required></label>
                    <label><span class="text-sm font-medium">身份</span><select v-model="promoterForm.type" class="mt-1 w-full rounded-xl border-slate-300"><option value="affiliate">联盟客</option><option value="influencer">达人</option><option value="ambassador">品牌大使</option><option value="advocate">顾客推荐</option><option value="partner">合作伙伴</option></select></label>
                    <label><span class="text-sm font-medium">加入计划</span><select v-model="promoterForm.program_public_id" class="mt-1 w-full rounded-xl border-slate-300"><option v-for="program in programs" :key="program.public_id" :value="program.public_id">{{ program.name }}</option></select></label>
                    <div class="sm:col-span-2"><button class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white" :disabled="promoterForm.processing">添加推广者</button><p v-if="Object.keys(promoterForm.errors).length" class="mt-2 text-sm text-rose-600">{{ Object.values(promoterForm.errors)[0] }}</p></div>
                </form>
                <EmptyState v-if="!memberships.length" title="还没有推广者" :description="programs.length ? '添加推广者并分配到当前店铺的推广计划。' : '请先创建推广计划，再添加推广者。'" icon="users" />
                <div v-else class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-4">推广者</th><th class="p-4">计划</th><th class="p-4">身份</th><th class="p-4">推广资产</th><th class="p-4">状态</th><th v-if="permissions.managePromoters" class="p-4">操作</th></tr></thead><tbody><tr v-for="membership in memberships" :key="membership.public_id" class="border-t border-slate-100"><td class="p-4"><b>{{ membership.promoter.display_name }}</b><p class="text-xs text-slate-500">{{ membership.promoter.email }}</p></td><td class="p-4">{{ membership.program?.name }}</td><td class="p-4">{{ typeLabel(membership.promoter.type) }}</td><td class="p-4"><template v-if="membership.link"><a :href="membership.link.url" target="_blank" rel="noreferrer" class="block max-w-64 truncate font-medium text-emerald-700">{{ membership.link.url }}</a><p class="mt-1 text-xs text-slate-500">优惠码 {{ membership.coupon?.code }} · {{ membership.coupon ? couponStatus(membership.coupon.status) : '未配置' }}</p><p v-if="membership.coupon?.last_error" class="mt-1 max-w-64 text-xs text-red-600">{{ membership.coupon.last_error }}</p><button v-if="membership.coupon && permissions.managePromoters" class="mt-1 text-xs font-medium text-emerald-700" @click="syncCoupon(membership)">同步 Shopify 优惠码</button></template><span v-else class="text-slate-400">审核后生成</span></td><td class="p-4">{{ membership.status }}</td><td v-if="permissions.managePromoters" class="p-4"><button v-if="membership.status !== 'approved'" class="font-semibold text-emerald-700" @click="transitionMembership(membership, 'approve')">通过</button><button v-else class="font-semibold text-amber-700" @click="transitionMembership(membership, 'suspend')">暂停</button></td></tr></tbody></table></div>
            </template>
        </div>
    </AppLayout>
</template>
