<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import ShopifyConnectionStatus from '../../Components/Shopify/ShopifyConnectionStatus.vue';
import StoreStatusOverview from '../../Components/Stores/StoreStatusOverview.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { AuditLogResult, SharedProps, ShopifyConnectionHistory, ShopifyStore, StoreAppSummary, StoreOperations, SyncJobMode, SyncJobStatus, SyncJobType, WebhookEventStatus } from '../../types';
import { formatDateTimeInTimezone } from '../../composables/useStoreDateTime';

const props = defineProps<{
    store: { data: ShopifyStore };
    connectionHistory: ShopifyConnectionHistory[];
    storeApps: StoreAppSummary[];
    operations: StoreOperations;
}>();
const page = usePage<SharedProps>();
const canConnect = computed(() => page.props.auth.permissions.includes('apps.install'));
const canDisconnect = computed(() => page.props.auth.permissions.includes('apps.uninstall') && props.store.data.connection_status === 'connected');
const canReconnect = computed(() => ['pending', 'invalid', 'disconnected'].includes(props.store.data.connection_status));
const tabs = [
    { id: 'overview', name: '概览', icon: 'stores', description: '', features: [] },
    { id: 'shopify', name: 'Shopify', icon: 'shopify', description: 'Shopify 连接详情与 API 状态将在后续阶段接入。', features: ['连接信息', '授权范围', 'API 状态'] },
    { id: 'apps', name: '应用', icon: 'apps', description: '应用安装与配置能力将在应用中心阶段接入。', features: ['已安装应用', '安装状态', '应用配置'] },
    { id: 'sync', name: '同步', icon: 'sync', description: '', features: [] },
    { id: 'webhooks', name: 'Webhook', icon: 'webhooks', description: '', features: [] },
    { id: 'logs', name: '日志', icon: 'audit', description: '', features: [] },
];
const requestedTab = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
const activeTab = ref(tabs.some((tab) => tab.id === requestedTab) ? requestedTab as string : 'overview');
const currentTab = computed(() => tabs.find((tab) => tab.id === activeTab.value) ?? tabs[0]);
const selectTab = (tab: string) => {
    activeTab.value = tab;
    const url = new URL(window.location.href);
    if (tab === 'overview') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
};
const form = useForm({});
const healthForm = useForm({});
const disconnectForm = useForm({});
const syncForm = useForm({
    store_id: String(props.store.data.id),
    app_installation_id: props.operations.sync.installations[0]?.id ? String(props.operations.sync.installations[0].id) : '',
    type: (props.operations.sync.runnable_types[0] ?? 'products') as SyncJobType,
    mode: 'full' as SyncJobMode,
    return_to_store: true,
});
const syncRetryForm = useForm({});
const webhookRetryForm = useForm({});
const reconnect = () => form.post(`/stores/${props.store.data.id}/connect`);
const verifyConnection = () => healthForm.post(`/stores/${props.store.data.id}/shopify/verify`, { preserveScroll: true });
const disconnect = () => {
    if (window.confirm('确定要真正卸载当前 Shopify 应用吗？Shopify 将立即撤销访问权限并停止所有任务。')) {
        disconnectForm.post(`/stores/${props.store.data.id}/shopify/uninstall`, { preserveScroll: true });
    }
};
const createSyncJob = () => syncForm.post('/sync', { preserveScroll: true });
const retrySyncJob = (id: number) => syncRetryForm.post(`/sync/${id}/retry`, { preserveScroll: true });
const retryWebhook = (id: number) => webhookRetryForm.post(`/webhooks/${id}/retry`, { preserveScroll: true });
const dateLabel = (value: string | null) => value ? formatDateTimeInTimezone(value, props.store.data.timezone) : '暂无记录';
const eventLabels: Record<string, string> = {
    shopify_connection_connected: '连接成功',
    shopify_connection_warning: '连接警告',
    shopify_connection_invalid: '访问令牌失效',
    shopify_connection_disconnected: '连接已断开',
    shopify_connection_reconnected: '重新连接成功',
    shopify_app_uninstalled: '应用已卸载',
};
const syncStatusLabel = (status: string) => ({ pending: '待处理', queued: '已入队', running: '执行中', completed: '已完成', failed: '失败', cancelled: '已取消' }[status] ?? status);
const syncTypeLabel = (type: string) => ({ products: '商品', orders: '订单', customers: '客户', inventory: '库存' }[type] ?? type);
const syncModeLabel = (mode: string) => ({ full: '全量', incremental: '增量', reconcile: '一致性校准' }[mode] ?? mode);
const webhookStatusLabel = (status: string) => ({ received: '已接收', queued: '已入队', processing: '处理中', processed: '已处理', failed: '处理失败', retrying: '重试排队中' }[status] ?? status);
const statusClass = (status: SyncJobStatus | WebhookEventStatus) => ({
    pending: 'bg-slate-50 text-slate-700 ring-slate-200',
    received: 'bg-slate-50 text-slate-700 ring-slate-200',
    queued: 'bg-violet-50 text-violet-700 ring-violet-100',
    running: 'bg-blue-50 text-blue-700 ring-blue-100',
    processing: 'bg-blue-50 text-blue-700 ring-blue-100',
    retrying: 'bg-amber-50 text-amber-700 ring-amber-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    processed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    failed: 'bg-rose-50 text-rose-700 ring-rose-100',
    cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
}[status] ?? 'bg-slate-50 text-slate-600 ring-slate-200');
const logStatusLabel = (status: AuditLogResult) => ({ success: '成功', warning: '需关注', error: '异常', info: '已记录' }[status]);
const logStatusClass = (status: AuditLogResult) => ({
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    warning: 'bg-amber-50 text-amber-700 ring-amber-100',
    error: 'bg-rose-50 text-rose-700 ring-rose-100',
    info: 'bg-slate-100 text-slate-600 ring-slate-200',
}[status]);
const logSourceLabel = (source: string) => ({ audit: '操作记录', sync: '数据同步', webhook: 'Webhook' }[source] ?? source);
</script>

<template>
    <Head :title="store.data.name" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '店铺管理', href: '/stores' }, { label: store.data.name }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/stores" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回店铺列表</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex items-center gap-4"><span class="grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-xl font-bold text-emerald-700">{{ store.data.name.charAt(0).toUpperCase() }}</span><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ store.data.name }}</h2><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ store.data.environment === 'development' ? '开发测试环境' : '正式环境' }}</span></div><p class="mt-1 font-mono text-xs text-slate-500">{{ store.data.shopify_domain }}</p></div></div>
                <div class="flex flex-wrap items-center gap-2"><form v-if="canConnect && !store.data.connection" @submit.prevent="reconnect"><button :disabled="form.processing" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 disabled:opacity-50">{{ form.processing ? '正在跳转…' : '继续连接' }}</button></form></div>
            </div>

            <div class="mt-7 overflow-x-auto border-b border-slate-200"><nav class="flex min-w-max gap-1" aria-label="店铺详情导航"><button v-for="tab in tabs" :key="tab.id" type="button" class="border-b-2 px-4 py-3 text-sm font-semibold transition" :class="activeTab === tab.id ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'" @click="selectTab(tab.id)">{{ tab.name }}</button></nav></div>

            <StoreStatusOverview v-if="activeTab === 'overview'" class="mt-6" :store="store.data" />

            <section v-else-if="activeTab === 'shopify'" class="mt-6">
                <div v-if="store.data.connection" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Shopify 管理 API</p>
                            <h3 class="mt-1 text-xl font-semibold text-slate-950">连接生命周期</h3>
                            <p class="mt-1 text-sm text-slate-500">查看授权生命周期、API 连通状态与最近一次异常。</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <ShopifyConnectionStatus :status="store.data.connection_status" size="md" />
                            <form v-if="canConnect && canReconnect" @submit.prevent="reconnect"><button :disabled="form.processing" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-50">{{ form.processing ? '正在跳转…' : '重新连接 Shopify' }}</button></form>
                            <button v-else-if="canConnect" type="button" :disabled="healthForm.processing" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-50" @click="verifyConnection">{{ healthForm.processing ? '正在检测…' : '检测连接' }}</button>
                            <button v-if="canDisconnect" type="button" :disabled="disconnectForm.processing" class="rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 disabled:cursor-wait disabled:opacity-50" @click="disconnect">{{ disconnectForm.processing ? '正在断开…' : '断开 Shopify 连接' }}</button>
                        </div>
                    </div>
                    <dl class="grid gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="bg-white px-5 py-5 sm:px-6"><dt class="text-xs font-semibold tracking-wider text-slate-400">连接状态</dt><dd class="mt-2"><ShopifyConnectionStatus :status="store.data.connection_status" /></dd></div>
                        <div class="bg-white px-5 py-5 sm:px-6"><dt class="text-xs font-semibold tracking-wider text-slate-400">最近验证时间</dt><dd class="mt-2 text-sm font-semibold text-slate-900">{{ dateLabel(store.data.connection.last_verified_at) }}</dd></div>
                        <div class="bg-white px-5 py-5 sm:px-6"><dt class="text-xs font-semibold tracking-wider text-slate-400">最近 API 检测</dt><dd class="mt-2 text-sm font-semibold text-slate-900">{{ dateLabel(store.data.connection.last_api_check) }}</dd></div>
                        <div class="bg-white px-5 py-5 sm:px-6"><dt class="text-xs font-semibold tracking-wider text-slate-400">API 版本</dt><dd class="mt-2 font-mono text-sm font-semibold text-slate-900">{{ store.data.connection.api_version }}</dd></div>
                    </dl>
                    <div v-if="store.data.connection.last_error" class="border-t border-rose-100 bg-rose-50 px-5 py-4 text-sm text-rose-800 sm:px-6">
                        <p class="font-semibold">最近一次检测异常</p>
                        <p class="mt-1">{{ store.data.connection.last_error }}</p>
                        <p class="mt-1 text-xs text-rose-600">{{ dateLabel(store.data.connection.last_error_at) }}</p>
                    </div>
                </div>
                <EmptyState v-else title="尚未建立 Shopify 连接" description="请先完成 Shopify OAuth 授权，再验证连接健康状态。" icon="shopify" />

                <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-5 sm:px-6">
                        <h3 class="font-semibold text-slate-950">连接历史</h3>
                        <p class="mt-1 text-sm text-slate-500">最近 20 条 Shopify 连接状态变化，不包含访问令牌或应用密钥。</p>
                    </div>
                    <div v-if="connectionHistory.length" class="divide-y divide-slate-100">
                        <article v-for="event in connectionHistory" :key="event.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:px-6">
                            <div><p class="font-semibold text-slate-900">{{ eventLabels[event.action] ?? event.action }}</p><p v-if="event.reason" class="mt-1 text-xs text-slate-500">{{ event.reason }}</p></div>
                            <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">操作人</p><p class="mt-1 text-sm font-medium text-slate-700">{{ event.actor }}</p></div>
                            <div class="sm:text-right"><p class="text-xs text-slate-400">{{ dateLabel(event.created_at) }}</p><div v-if="event.new_status" class="mt-2 sm:flex sm:justify-end"><ShopifyConnectionStatus :status="event.new_status" /></div></div>
                        </article>
                    </div>
                    <p v-else class="px-6 py-10 text-center text-sm text-slate-500">暂无连接历史。</p>
                </section>
            </section>

            <section v-else-if="activeTab === 'apps'" class="mt-6 space-y-4">
                <article v-for="app in storeApps" :key="app.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-50 text-lg font-bold text-emerald-700">D</span>
                                <div>
                                    <h3 class="text-lg font-semibold text-slate-950">{{ app.name }}</h3>
                                    <p class="font-mono text-xs text-slate-400">{{ app.handle }}</p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="app.status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-slate-100 text-slate-600 ring-slate-200'">{{ app.status === 'active' ? '已安装' : '未安装' }}</span>
                            </div>
                            <p class="mt-4 text-sm leading-6 text-slate-500">{{ app.description || '一个 Shopify App 集成个性化、邮件、短信、弹窗与表单、评论和页面构建器。' }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <span v-for="feature in ['个性化', '邮件', '短信', '弹窗与表单', '评论', '页面构建器']" :key="feature" class="rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-100">{{ feature }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-col items-stretch gap-2 sm:min-w-36">
                            <button v-if="app.installable && app.status !== 'active' && app.app_status === 'active' && canConnect" type="button" :disabled="form.processing" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 disabled:opacity-50" @click="reconnect">{{ form.processing ? '正在跳转…' : '安装应用' }}</button>
                            <button v-else-if="app.installable && app.status === 'active' && canDisconnect" type="button" :disabled="disconnectForm.processing" class="rounded-xl border border-rose-200 bg-white px-5 py-2.5 text-sm font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 disabled:opacity-50" @click="disconnect">{{ disconnectForm.processing ? '正在卸载…' : '卸载应用' }}</button>
                            <p class="text-center text-xs text-slate-400">{{ !app.installable ? '暂未接入安装流程' : app.app_status !== 'active' ? '应用当前不可安装' : app.status === 'active' ? `安装于 ${dateLabel(app.installed_at)}` : '由组织管理员操作' }}</p>
                        </div>
                    </div>
                </article>
                <EmptyState v-if="!storeApps.length" title="暂无可安装应用" description="请先在应用中心创建并启用 Shopify 应用。" icon="apps" />
            </section>

            <section v-else-if="activeTab === 'sync'" class="mt-6 space-y-5">
                <template v-if="operations.capabilities.sync_view">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <article v-for="item in [
                            { label: '全部任务', value: operations.sync.summary.total },
                            { label: '执行中', value: operations.sync.summary.running },
                            { label: '已完成', value: operations.sync.summary.completed },
                            { label: '失败', value: operations.sync.summary.failed },
                        ]" :key="item.label" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-semibold text-slate-500">{{ item.label }}</p>
                            <p class="mt-2 text-2xl font-semibold text-slate-950">{{ item.value }}</p>
                        </article>
                    </div>

                    <section v-if="operations.sync.runnable_types.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5">
                            <h3 class="font-semibold text-slate-950">创建同步任务</h3>
                            <p class="mt-1 text-sm text-slate-500">为当前店铺执行商品、订单、客户或库存同步。</p>
                        </div>
                        <form class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px_auto] lg:items-end" @submit.prevent="createSyncJob">
                            <label><span class="mb-1.5 block text-xs font-semibold text-slate-500">同步类型</span><select v-model="syncForm.type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm"><option v-for="type in operations.sync.runnable_types" :key="type" :value="type">{{ syncTypeLabel(type) }}</option></select></label>
                            <label><span class="mb-1.5 block text-xs font-semibold text-slate-500">应用安装记录</span><select v-model="syncForm.app_installation_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm"><option value="">不指定应用</option><option v-for="installation in operations.sync.installations" :key="installation.id" :value="String(installation.id)">{{ installation.app?.name ?? `安装记录 #${installation.id}` }}</option></select></label>
                            <label><span class="mb-1.5 block text-xs font-semibold text-slate-500">同步模式</span><select v-model="syncForm.mode" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm"><option value="full">全量同步</option><option value="incremental">增量同步</option></select></label>
                            <button type="submit" :disabled="syncForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ syncForm.processing ? '创建中…' : '创建任务' }}</button>
                        </form>
                        <p v-if="syncForm.errors.type || syncForm.errors.app_installation_id" class="mt-3 text-sm text-rose-600">{{ syncForm.errors.type ?? syncForm.errors.app_installation_id }}</p>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                            <div><h3 class="font-semibold text-slate-950">最近同步任务</h3><p class="mt-1 text-sm text-slate-500">展示当前店铺最近 20 个同步任务及执行结果。</p></div>
                            <Link :href="`/sync?store_id=${store.data.id}`" class="text-sm font-semibold text-emerald-700">查看全部 →</Link>
                        </header>
                        <div v-if="operations.sync.jobs.length" class="divide-y divide-slate-100">
                            <article v-for="job in operations.sync.jobs" :key="job.id" class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_140px_160px_auto] md:items-center">
                                <div><Link :href="`/sync/${job.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ syncTypeLabel(job.type) }} · {{ syncModeLabel(job.mode) }}</Link><p class="mt-1 font-mono text-xs text-slate-400">#{{ job.id }} · {{ job.uuid }}</p><p v-if="job.error_code" class="mt-1 text-xs font-medium text-rose-600">错误码：{{ job.error_code }}</p></div>
                                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(job.status)">{{ syncStatusLabel(job.status) }}</span>
                                <p class="text-xs text-slate-500">{{ dateLabel(job.finished_at ?? job.created_at) }}</p>
                                <div class="flex justify-end gap-2"><button v-if="job.can_retry" type="button" :disabled="syncRetryForm.processing" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="retrySyncJob(job.id)">失败重试</button><Link :href="`/sync/${job.id}`" class="rounded-lg px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">详情</Link></div>
                            </article>
                        </div>
                        <EmptyState v-else class="border-0 shadow-none" title="尚无同步任务" description="创建首个同步任务后，执行状态和历史会显示在这里。" icon="sync" />
                    </section>
                </template>
                <EmptyState v-else title="没有同步中心访问权限" description="当前账号可以查看店铺，但没有 sync.view 权限。" icon="sync" />
            </section>

            <section v-else-if="activeTab === 'webhooks'" class="mt-6 space-y-5">
                <template v-if="operations.capabilities.webhooks_view">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <article v-for="item in [
                            { label: '全部事件', value: operations.webhooks.summary.total },
                            { label: '处理中', value: operations.webhooks.summary.active },
                            { label: '已处理', value: operations.webhooks.summary.processed },
                            { label: '失败', value: operations.webhooks.summary.failed },
                        ]" :key="item.label" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-semibold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold text-slate-950">{{ item.value }}</p>
                        </article>
                    </div>
                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                            <div><h3 class="font-semibold text-slate-950">Webhook 事件监控</h3><p class="mt-1 text-sm text-slate-500">查看当前店铺最近 20 个 Shopify 推送事件和处理状态。</p></div>
                            <Link :href="`/webhooks?store_id=${store.data.id}`" class="text-sm font-semibold text-emerald-700">查看全部 →</Link>
                        </header>
                        <div v-if="operations.webhooks.events.length" class="divide-y divide-slate-100">
                            <article v-for="event in operations.webhooks.events" :key="event.id" class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_140px_160px_auto] md:items-center">
                                <div><Link :href="`/webhooks/${event.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ event.topic }}</Link><p class="mt-1 font-mono text-xs text-slate-400">{{ event.webhook_id }} · 已尝试 {{ event.attempts }} 次</p></div>
                                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(event.status)">{{ webhookStatusLabel(event.status) }}</span>
                                <p class="text-xs text-slate-500">{{ dateLabel(event.processed_at ?? event.received_at) }}</p>
                                <div class="flex justify-end gap-2"><button v-if="event.can_retry" type="button" :disabled="webhookRetryForm.processing" class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="retryWebhook(event.id)">失败重试</button><Link :href="`/webhooks/${event.id}`" class="rounded-lg px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">详情</Link></div>
                            </article>
                        </div>
                        <EmptyState v-else class="border-0 shadow-none" title="尚未收到 Webhook 事件" description="Shopify 事件通过校验后，会显示在这里。" icon="webhooks" />
                    </section>
                </template>
                <EmptyState v-else title="没有 Webhook 中心访问权限" description="当前账号可以查看店铺，但没有 webhooks.view 权限。" icon="webhooks" />
            </section>

            <section v-else-if="activeTab === 'logs'" class="mt-6 space-y-5">
                <template v-if="operations.capabilities.audit_view || operations.capabilities.sync_view || operations.capabilities.webhooks_view">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <article v-for="item in [
                            { label: '操作记录', value: operations.logs.summary.operations },
                            { label: '集成事件', value: operations.logs.summary.integrations },
                            { label: '异常追踪', value: operations.logs.summary.exceptions },
                        ]" :key="item.label" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold text-slate-500">{{ item.label }}</p><p class="mt-2 text-2xl font-semibold text-slate-950">{{ item.value }}</p></article>
                    </div>
                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                            <div><h3 class="font-semibold text-slate-950">店铺活动日志</h3><p class="mt-1 text-sm text-slate-500">统一汇总操作审计、同步任务和 Webhook 事件，不展示令牌、密钥或原始请求头。</p></div>
                            <Link v-if="operations.capabilities.audit_view" :href="`/audit-logs?store_id=${store.data.id}`" class="text-sm font-semibold text-emerald-700">审计日志 →</Link>
                        </header>
                        <div v-if="operations.logs.items.length" class="divide-y divide-slate-100">
                            <article v-for="item in operations.logs.items" :key="item.key" class="grid gap-3 px-5 py-4 md:grid-cols-[130px_minmax(0,1fr)_120px_170px] md:items-center">
                                <span class="w-fit rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ logSourceLabel(item.source) }}</span>
                                <div><Link :href="item.href" class="font-semibold text-slate-900 hover:text-emerald-700">{{ item.title }}</Link><p class="mt-1 text-xs text-slate-500">{{ item.description }}</p></div>
                                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="logStatusClass(item.status)">{{ logStatusLabel(item.status) }}</span>
                                <p class="text-xs text-slate-500 md:text-right">{{ dateLabel(item.occurred_at) }}</p>
                            </article>
                        </div>
                        <EmptyState v-else class="border-0 shadow-none" title="尚无店铺日志" description="操作、同步或 Webhook 事件发生后，会按时间统一显示在这里。" icon="audit" />
                    </section>
                </template>
                <EmptyState v-else title="没有店铺日志访问权限" description="当前账号没有审计、同步或 Webhook 查看权限。" icon="audit" />
            </section>

            <EmptyState v-else class="mt-6" :title="`${currentTab.name}中心`" :description="currentTab.description" :icon="currentTab.icon">
                <ul class="grid gap-2 sm:grid-cols-3">
                    <li v-for="feature in currentTab.features" :key="feature" class="rounded-xl bg-slate-50 px-3 py-2 text-center text-xs font-semibold text-slate-600 ring-1 ring-slate-100">{{ feature }}</li>
                </ul>
            </EmptyState>
        </div>
    </AppLayout>
</template>
