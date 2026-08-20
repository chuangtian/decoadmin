<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { AuditLogResult, AuditLogSummary, PaginatedResource } from '../../types';
import { formatDateTimeInTimezone, useStoreDateTime } from '../../composables/useStoreDateTime';

const props = defineProps<{
    auditLogs: PaginatedResource<AuditLogSummary>;
    summary: { total: number; last_24_hours: number; system_events: number; active_actors: number };
    filters: { search: string; action: string; user_id: number | null; store_id: number | null; date_from: string; date_to: string };
    options: {
        actions: Array<{ value: string; label: string }>;
        users: Array<{ id: number; name: string; email: string }>;
        stores: Array<{ id: number; name: string }>;
    };
}>();

const form = useForm({
    search: props.filters.search,
    action: props.filters.action,
    user_id: props.filters.user_id ? String(props.filters.user_id) : '',
    store_id: props.filters.store_id ? String(props.filters.store_id) : '',
    date_from: props.filters.date_from,
    date_to: props.filters.date_to,
});

const search = () => form.get('/audit-logs', { preserveState: true, replace: true });
const clearFilters = () => {
    form.search = '';
    form.action = '';
    form.user_id = '';
    form.store_id = '';
    form.date_from = '';
    form.date_to = '';
    search();
};
const hasFilters = () => Boolean(props.filters.search || props.filters.action || props.filters.user_id || props.filters.store_id || props.filters.date_from || props.filters.date_to);
const { formatDateTime: currentStoreDateTime } = useStoreDateTime();
const auditTimezones = new Map(props.auditLogs.data
    .filter((audit) => audit.created_at && audit.store?.timezone)
    .map((audit) => [audit.created_at!, audit.store!.timezone] as [string, string]));
const dateLabel = (value: string | null) => value
    ? (auditTimezones.has(value) ? formatDateTimeInTimezone(value, auditTimezones.get(value)!) : currentStoreDateTime(value))
    : '—';
const resultLabel = (result: AuditLogResult) => ({ success: '成功', warning: '需要关注', error: '异常', info: '已记录' }[result]);
const resultClass = (result: AuditLogResult) => ({
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    warning: 'bg-amber-50 text-amber-700 ring-amber-100',
    error: 'bg-rose-50 text-rose-700 ring-rose-100',
    info: 'bg-slate-100 text-slate-600 ring-slate-200',
}[result]);

const summaryCards = [
    { key: 'total', label: '全部记录', description: '当前可访问范围' },
    { key: 'last_24_hours', label: '最近 24 小时', description: '最新操作记录' },
    { key: 'active_actors', label: '操作人员', description: '有记录的成员' },
    { key: 'system_events', label: '系统事件', description: '后台自动产生' },
] as const;
</script>

<template>
    <Head title="审计日志" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '审计日志' }]">
        <div class="mx-auto max-w-7xl">
            <section class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">系统管理</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">审计日志</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">查询重要操作的执行人、店铺、时间与脱敏变更内容。审计记录仅供查看，不能在后台修改或删除。</p>
                </div>
                <span class="inline-flex self-start rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 lg:self-auto">只读记录</span>
            </section>

            <section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <article v-for="card in summaryCards" :key="card.key" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <p class="text-xs font-semibold text-slate-500">{{ card.label }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-950">{{ summary[card.key] }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ card.description }}</p>
                </article>
            </section>

            <form class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="search">
                <div class="grid gap-3 lg:grid-cols-[minmax(240px,1.3fr)_minmax(180px,1fr)_minmax(180px,1fr)_minmax(180px,1fr)]">
                    <label><span class="sr-only">搜索审计日志</span><input v-model="form.search" type="search" placeholder="搜索事件、人员、店铺或记录编号" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /></label>
                    <label><span class="sr-only">事件类型</span><select v-model="form.action" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option value="">全部事件类型</option><option v-for="action in options.actions" :key="action.value" :value="action.value">{{ action.label }}</option></select></label>
                    <label><span class="sr-only">操作人</span><select v-model="form.user_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option value="">全部操作人</option><option v-for="user in options.users" :key="user.id" :value="String(user.id)">{{ user.name }}</option></select></label>
                    <label><span class="sr-only">店铺</span><select v-model="form.store_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option value="">全部授权店铺</option><option v-for="store in options.stores" :key="store.id" :value="String(store.id)">{{ store.name }}</option></select></label>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-[200px_200px_auto]">
                    <label class="block"><span class="mb-1.5 block text-xs font-semibold text-slate-500">开始日期</span><input v-model="form.date_from" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /></label>
                    <label class="block"><span class="mb-1.5 block text-xs font-semibold text-slate-500">结束日期</span><input v-model="form.date_to" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /><p v-if="form.errors.date_to" class="mt-1.5 text-xs text-rose-600">{{ form.errors.date_to }}</p></label>
                    <div class="flex items-end gap-2"><button type="submit" :disabled="form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '查询中…' : '查询' }}</button><button v-if="hasFilters()" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="clearFilters">清除</button></div>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="auditLogs.data.length">
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[980px] text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3.5">事件</th><th class="px-5 py-3.5">操作人</th><th class="px-5 py-3.5">店铺</th><th class="px-5 py-3.5">结果</th><th class="px-5 py-3.5">时间</th><th class="px-5 py-3.5 text-right">操作</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="audit in auditLogs.data" :key="audit.id" class="transition hover:bg-slate-50/70"><td class="px-5 py-4"><Link :href="`/audit-logs/${audit.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ audit.action_label }}</Link><p class="mt-1 text-xs text-slate-400">{{ audit.category }} · {{ audit.action }}</p></td><td class="px-5 py-4"><p class="font-medium text-slate-800">{{ audit.actor?.name ?? '系统' }}</p><p class="mt-1 text-xs text-slate-400">{{ audit.actor?.email ?? '后台自动执行' }}</p></td><td class="px-5 py-4"><p class="font-medium text-slate-800">{{ audit.store?.name ?? '组织范围' }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ audit.store?.shopify_domain ?? '—' }}</p></td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="resultClass(audit.result)">{{ resultLabel(audit.result) }}</span></td><td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(audit.created_at) }}</td><td class="px-5 py-4 text-right"><Link :href="`/audit-logs/${audit.id}`" class="rounded-lg px-3 py-2 font-semibold text-emerald-700 transition hover:bg-emerald-50">查看详情</Link></td></tr></tbody>
                        </table>
                    </div>
                    <div class="divide-y divide-slate-100 md:hidden"><article v-for="audit in auditLogs.data" :key="audit.id" class="p-5"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><Link :href="`/audit-logs/${audit.id}`" class="font-semibold text-slate-900">{{ audit.action_label }}</Link><p class="mt-1 text-xs text-slate-400">{{ audit.category }}</p></div><span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="resultClass(audit.result)">{{ resultLabel(audit.result) }}</span></div><div class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-xs"><div><p class="text-slate-400">操作人</p><p class="mt-1 font-semibold text-slate-700">{{ audit.actor?.name ?? '系统' }}</p></div><div><p class="text-slate-400">店铺</p><p class="mt-1 font-semibold text-slate-700">{{ audit.store?.name ?? '组织范围' }}</p></div></div><p class="mt-3 text-xs text-slate-500">{{ dateLabel(audit.created_at) }}</p><Link :href="`/audit-logs/${audit.id}`" class="mt-4 inline-flex text-sm font-semibold text-emerald-700">查看详情 →</Link></article></div>
                </div>
                <EmptyState v-else class="border-0 shadow-none" :title="hasFilters() ? '没有匹配的审计记录' : '尚无审计记录'" :description="hasFilters() ? '请调整事件、人员、店铺或日期过滤条件。' : '重要系统操作发生后，会以只读记录的形式出现在这里。'" icon="audit" />
            </div>

            <div v-if="auditLogs.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in auditLogs.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
        </div>
    </AppLayout>
</template>
