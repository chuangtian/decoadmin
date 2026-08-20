<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PaginatedResource, StoreSyncStatusSummary, SyncJobMode, SyncJobStatus, SyncJobStoreOption, SyncJobSummary, SyncJobType } from '../../types';
import { formatDateTimeInTimezone, useStoreDateTime } from '../../composables/useStoreDateTime';

const props = defineProps<{
    syncJobs: PaginatedResource<SyncJobSummary>;
    filters: { type: string; status: string; store_id: number | null; mode: string };
    stores: SyncJobStoreOption[];
    statuses: SyncJobStatus[];
    types: SyncJobType[];
    modes: SyncJobMode[];
    syncStatus: StoreSyncStatusSummary[];
}>();

const runnableStores = computed(() => props.stores.filter((store) => store.can_run));
const createForm = useForm({
    store_id: runnableStores.value[0]?.id ? String(runnableStores.value[0].id) : '',
    app_installation_id: runnableStores.value[0]?.installations[0]?.id
        ? String(runnableStores.value[0].installations[0].id)
        : '',
    type: 'products' as SyncJobType,
    mode: 'full' as SyncJobMode,
});
const selectedStore = computed(() => runnableStores.value.find((store) => String(store.id) === createForm.store_id) ?? null);
const syncTypeLabel = (type: string) => ({ products: '商品', orders: '订单', customers: '客户', inventory: '库存' }[type] ?? type);
const modeLabel = (mode: string) => ({ full: '全量', incremental: '增量', reconcile: '一致性校准' }[mode] ?? mode);
const overviewStatusLabel = (status: string) => ({ healthy: '正常', running: '同步中', warning: '需关注', critical: '严重异常' }[status] ?? status);
const overviewStatusClass = (status: string) => ({ healthy: 'bg-emerald-50 text-emerald-700', running: 'bg-blue-50 text-blue-700', warning: 'bg-amber-50 text-amber-700', critical: 'bg-rose-50 text-rose-700' }[status] ?? 'bg-slate-100 text-slate-600');
const syncStateLabel = (status: string) => ({ idle: '待命', queued: '排队中', running: '执行中', failed: '失败' }[status] ?? status);
const syncStateClass = (status: string) => ({ idle: 'text-emerald-600', queued: 'text-violet-600', running: 'text-blue-600', failed: 'text-rose-600' }[status] ?? 'text-slate-500');
const statusLabel = (status: string) => ({ pending: '待处理', queued: '已入队', running: '执行中', completed: '已完成', failed: '失败', cancelled: '已取消' }[status] ?? status);
const statusClass = (status: string) => ({
    pending: 'bg-slate-50 text-slate-700 ring-slate-200',
    queued: 'bg-violet-50 text-violet-700 ring-violet-100',
    running: 'bg-blue-50 text-blue-700 ring-blue-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    failed: 'bg-rose-50 text-rose-700 ring-rose-100',
    cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
}[status] ?? 'bg-slate-50 text-slate-600 ring-slate-200');
const { formatDateTime: currentStoreDateTime } = useStoreDateTime();
const jobTimezones = new Map(props.syncJobs.data.flatMap((job) => [
    [job.started_at, job.store.timezone],
    [job.finished_at, job.store.timezone],
    [job.created_at, job.store.timezone],
]).filter((entry): entry is [string, string] => Boolean(entry[0])));
const stateTimezones = new Map(props.syncStatus.flatMap((summary) => summary.sync_states.flatMap((state) => [
    [state.last_success_at, summary.store.timezone],
    [state.last_full_sync_at, summary.store.timezone],
    [state.last_incremental_sync_at, summary.store.timezone],
    [state.next_sync_at, summary.store.timezone],
])).filter((entry): entry is [string, string] => Boolean(entry[0])));
const dateLabel = (value: string | null) => value
    ? (jobTimezones.has(value) || stateTimezones.has(value)
        ? formatDateTimeInTimezone(value, jobTimezones.get(value) ?? stateTimezones.get(value)!)
        : currentStoreDateTime(value))
    : '—';
const selectStore = () => {
    createForm.app_installation_id = selectedStore.value?.installations[0]?.id
        ? String(selectedStore.value.installations[0].id)
        : '';
};
const createSyncJob = () => createForm.post('/sync', { preserveScroll: true });

const filterForm = useForm({
    type: props.filters.type,
    status: props.filters.status,
    store_id: props.filters.store_id ? String(props.filters.store_id) : '',
    mode: props.filters.mode,
});
const search = () => filterForm.get('/sync', { preserveState: true, replace: true });
const clearFilters = () => {
    filterForm.type = '';
    filterForm.status = '';
    filterForm.store_id = '';
    filterForm.mode = '';
    search();
};
const hasFilters = () => Boolean(props.filters.type || props.filters.status || props.filters.store_id || props.filters.mode);
</script>

<template>
    <Head title="数据同步" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '数据同步' }]">
        <div class="mx-auto max-w-7xl">
            <div class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">数据同步中心</p>
                    <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">同步任务</h2>
                    <p class="mt-2 text-sm text-slate-500">统一管理 Shopify 数据同步任务、执行状态和运行日志。</p>
                </div>
                <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">商品、订单、客户和库存同步已接入 Shopify</span>
            </div>

            <section class="mb-6 space-y-4">
                <div>
                    <h3 class="font-semibold text-slate-950">店铺同步状态总览</h3>
                    <p class="mt-1 text-sm text-slate-500">查看连接、Webhook、四类同步水位和异常情况。</p>
                </div>
                <article v-for="overview in syncStatus" :key="overview.store.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="font-semibold text-slate-950">{{ overview.store.name }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ overview.store.shopify_domain }}</p></div>
                        <div class="flex flex-wrap items-center gap-2 text-xs"><span class="rounded-full px-2.5 py-1 font-semibold" :class="overviewStatusClass(overview.status)">{{ overviewStatusLabel(overview.status) }}</span><span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">运行任务 {{ overview.running_jobs }}</span><span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">未处理告警 {{ overview.open_alerts }}</span></div>
                    </header>
                    <div class="grid gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-4">
                        <div v-for="state in overview.sync_states" :key="state.type" class="bg-white p-4">
                            <div class="flex items-center justify-between gap-2"><p class="font-semibold text-slate-800">{{ syncTypeLabel(state.type) }}</p><span class="text-xs" :class="syncStateClass(state.status)">{{ syncStateLabel(state.status) }}</span></div>
                            <dl class="mt-3 space-y-2 text-xs"><div class="flex justify-between gap-3"><dt class="text-slate-400">最近成功</dt><dd class="text-right text-slate-600">{{ dateLabel(state.last_success_at) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-400">最近全量</dt><dd class="text-right text-slate-600">{{ dateLabel(state.last_full_sync_at) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-400">最近增量</dt><dd class="text-right text-slate-600">{{ dateLabel(state.last_incremental_sync_at) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-400">下次检查</dt><dd class="text-right text-slate-600">{{ dateLabel(state.next_sync_at) }}</dd></div><div v-if="state.consecutive_failures" class="flex justify-between gap-3"><dt class="text-slate-400">连续失败</dt><dd class="text-right text-rose-600">{{ state.consecutive_failures }} 次</dd></div><div v-if="state.last_error_code" class="flex justify-between gap-3"><dt class="text-slate-400">错误码</dt><dd class="break-all text-right font-mono text-rose-600">{{ state.last_error_code }}</dd></div></dl>
                        </div>
                    </div>
                </article>
            </section>

            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-5">
                    <h3 class="font-semibold text-slate-950">创建同步任务</h3>
                    <p class="mt-1 text-sm text-slate-500">选择店铺和同步类型后，任务会进入 Shopify 数据同步队列。</p>
                </div>
                <form v-if="runnableStores.length" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_150px_150px_auto] lg:items-end" @submit.prevent="createSyncJob">
                    <label class="block"><span class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">店铺</span><select v-model="createForm.store_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" @change="selectStore"><option v-for="store in runnableStores" :key="store.id" :value="String(store.id)">{{ store.name }} · {{ store.shopify_domain }}</option></select><p v-if="createForm.errors.store_id" class="mt-1.5 text-xs text-rose-600">{{ createForm.errors.store_id }}</p></label>
                    <label class="block"><span class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">应用安装记录</span><select v-model="createForm.app_installation_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option value="">不指定应用安装记录</option><option v-for="installation in selectedStore?.installations ?? []" :key="installation.id" :value="String(installation.id)">{{ installation.app?.name ?? `安装记录 #${installation.id}` }}</option></select><p v-if="createForm.errors.app_installation_id" class="mt-1.5 text-xs text-rose-600">{{ createForm.errors.app_installation_id }}</p></label>
                    <label class="block"><span class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">同步类型</span><select v-model="createForm.type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option v-for="type in types" :key="type" :value="type">{{ syncTypeLabel(type) }}</option></select><p v-if="createForm.errors.type" class="mt-1.5 text-xs text-rose-600">{{ createForm.errors.type }}</p></label>
                    <label class="block"><span class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">同步模式</span><select v-model="createForm.mode" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option v-for="mode in modes" :key="mode" :value="mode">{{ modeLabel(mode) }}</option></select><p v-if="createForm.errors.mode" class="mt-1.5 text-xs text-rose-600">{{ createForm.errors.mode }}</p></label>
                    <button type="submit" :disabled="createForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">{{ createForm.processing ? '创建中…' : '创建任务' }}</button>
                </form>
                <EmptyState v-else class="border-0 p-4 shadow-none" title="没有可执行同步的店铺" description="请确认当前账号具有店铺同步执行权限。" icon="sync" />
            </section>

            <form class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[160px_160px_160px_minmax(0,1fr)_auto]" @submit.prevent="search">
                <label><span class="sr-only">同步类型过滤</span><select v-model="filterForm.type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none"><option value="">全部同步类型</option><option v-for="type in types" :key="type" :value="type">{{ syncTypeLabel(type) }}</option></select></label>
                <label><span class="sr-only">状态过滤</span><select v-model="filterForm.status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none"><option value="">全部状态</option><option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
                <label><span class="sr-only">同步模式过滤</span><select v-model="filterForm.mode" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none"><option value="">全部同步模式</option><option v-for="mode in modes" :key="mode" :value="mode">{{ modeLabel(mode) }}</option></select></label>
                <label><span class="sr-only">店铺过滤</span><select v-model="filterForm.store_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none"><option value="">全部授权店铺</option><option v-for="store in stores" :key="store.id" :value="String(store.id)">{{ store.name }}</option></select></label>
                <div class="flex gap-2"><button type="submit" :disabled="filterForm.processing" class="flex-1 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white lg:flex-none">筛选</button><button v-if="hasFilters()" type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600" @click="clearFilters">清除</button></div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="syncJobs.data.length">
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[980px] text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3.5">店铺</th><th class="px-5 py-3.5">同步类型</th><th class="px-5 py-3.5">模式</th><th class="px-5 py-3.5">状态</th><th class="px-5 py-3.5">开始时间</th><th class="px-5 py-3.5">完成时间</th><th class="px-5 py-3.5 text-right">操作</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="job in syncJobs.data" :key="job.id" class="transition hover:bg-slate-50/70"><td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ job.store.name }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ job.store.shopify_domain }}</p></td><td class="px-5 py-4"><Link :href="`/sync/${job.id}`" class="font-semibold text-slate-800 hover:text-emerald-700">{{ syncTypeLabel(job.type) }}</Link><p v-if="job.app_installation?.app" class="mt-1 text-xs text-slate-400">{{ job.app_installation.app.name }}</p></td><td class="px-5 py-4 text-xs font-semibold text-slate-600">{{ modeLabel(job.mode) }}</td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(job.status)">{{ statusLabel(job.status) }}</span></td><td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(job.started_at) }}</td><td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(job.finished_at) }}</td><td class="px-5 py-4 text-right"><Link :href="`/sync/${job.id}`" class="rounded-lg px-3 py-2 font-semibold text-emerald-700 transition hover:bg-emerald-50">查看详情</Link></td></tr></tbody>
                        </table>
                    </div>
                    <div class="divide-y divide-slate-100 md:hidden"><article v-for="job in syncJobs.data" :key="job.id" class="p-5"><div class="flex items-start justify-between gap-3"><div><Link :href="`/sync/${job.id}`" class="font-semibold text-slate-900">{{ syncTypeLabel(job.type) }}同步</Link><p class="mt-1 text-xs text-slate-400">{{ job.store.name }} · {{ modeLabel(job.mode) }}</p></div><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(job.status)">{{ statusLabel(job.status) }}</span></div><dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-xs"><div><dt class="text-slate-400">开始时间</dt><dd class="mt-1 text-slate-700">{{ dateLabel(job.started_at) }}</dd></div><div><dt class="text-slate-400">完成时间</dt><dd class="mt-1 text-slate-700">{{ dateLabel(job.finished_at) }}</dd></div></dl><Link :href="`/sync/${job.id}`" class="mt-4 inline-flex text-sm font-semibold text-emerald-700">查看详情 →</Link></article></div>
                </div>
                <EmptyState v-else class="border-0 shadow-none" :title="hasFilters() ? '没有匹配的同步任务' : '尚未创建同步任务'" :description="hasFilters() ? '请调整同步类型、状态或店铺过滤条件。' : '使用上方表单创建第一个同步任务；商品类型会读取并保存 Shopify 真实数据。'" icon="sync" />
            </div>

            <div v-if="syncJobs.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in syncJobs.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
        </div>
    </AppLayout>
</template>
