<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import ShopifyConnectionStatus from '../../Components/Shopify/ShopifyConnectionStatus.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps, ShopifyConnectionHistory, ShopifyStore } from '../../types';

const props = defineProps<{ store: { data: ShopifyStore }; connectionHistory: ShopifyConnectionHistory[] }>();
const page = usePage<SharedProps>();
const canConnect = computed(() => page.props.auth.permissions.includes('store.connect'));
const canDisconnect = computed(() => page.props.auth.permissions.includes('store.disconnect') && props.store.data.connection_status !== 'disconnected');
const canReconnect = computed(() => ['invalid', 'disconnected'].includes(props.store.data.connection_status));
const activeTab = ref('overview');
const tabs = [
    { id: 'overview', name: '概览', icon: 'stores', description: '', features: [] },
    { id: 'shopify', name: 'Shopify', icon: 'shopify', description: 'Shopify 连接详情与 API 状态将在后续阶段接入。', features: ['连接信息', '授权范围', 'API 状态'] },
    { id: 'apps', name: '应用', icon: 'apps', description: '应用安装与配置能力将在应用中心阶段接入。', features: ['已安装应用', '安装状态', '应用配置'] },
    { id: 'sync', name: '同步', icon: 'sync', description: '数据同步中心尚未启用，当前不会执行任何业务数据同步。', features: ['同步任务', '执行历史', '错误重试'] },
    { id: 'webhooks', name: 'Webhook', icon: 'webhooks', description: 'Webhook 管理功能正在准备中。', features: ['事件监控', '失败重试', '投递日志'] },
    { id: 'logs', name: '日志', icon: 'audit', description: '店铺级操作与集成日志将在后续阶段统一展示。', features: ['操作记录', '集成事件', '异常追踪'] },
];
const currentTab = computed(() => tabs.find((tab) => tab.id === activeTab.value) ?? tabs[0]);
const form = useForm({ name: props.store.data.name, shop_domain: props.store.data.shopify_domain, environment: props.store.data.environment });
const healthForm = useForm({});
const disconnectForm = useForm({});
const reconnect = () => form.post(`/stores/${props.store.data.id}/connect`);
const verifyConnection = () => healthForm.post(`/stores/${props.store.data.id}/shopify/verify`, { preserveScroll: true });
const disconnect = () => {
    if (window.confirm('确定要断开当前 Shopify 连接吗？连接记录和历史会保留，恢复时需要重新授权。')) {
        disconnectForm.post(`/stores/${props.store.data.id}/shopify/disconnect`, { preserveScroll: true });
    }
};
const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value)) : '暂无记录';
const eventLabels: Record<string, string> = {
    shopify_connection_connected: '连接成功',
    shopify_connection_warning: '连接警告',
    shopify_connection_invalid: '访问令牌失效',
    shopify_connection_disconnected: '连接已断开',
    shopify_connection_reconnected: '重新连接成功',
};
const syncStatusLabel = (status: string) => ({ pending: '待处理', queued: '已入队', running: '执行中', completed: '已完成', failed: '失败', cancelled: '已取消' }[status] ?? status);
const installationStatusLabel = (status: string) => ({ active: '已安装', pending: '待安装', inactive: '已停用', uninstalled: '已卸载', failed: '安装失败' }[status] ?? status);
</script>

<template>
    <Head :title="store.data.name" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '店铺管理', href: '/stores' }, { label: store.data.name }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/stores" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回店铺列表</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex items-center gap-4"><span class="grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-xl font-bold text-emerald-700">{{ store.data.name.charAt(0).toUpperCase() }}</span><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ store.data.name }}</h2><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ store.data.environment === 'development' ? '开发测试环境' : '正式环境' }}</span></div><p class="mt-1 font-mono text-xs text-slate-500">{{ store.data.shopify_domain }}</p></div></div>
                <div class="flex flex-wrap items-center gap-2"><Link :href="`/stores/${store.data.id}/notifications`" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50">通知设置</Link><form v-if="canConnect && !store.data.connection" @submit.prevent="reconnect"><button :disabled="form.processing" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 disabled:opacity-50">{{ form.processing ? '正在跳转…' : '继续连接' }}</button></form></div>
            </div>

            <div class="mt-7 overflow-x-auto border-b border-slate-200"><nav class="flex min-w-max gap-1" aria-label="店铺详情导航"><button v-for="tab in tabs" :key="tab.id" type="button" class="border-b-2 px-4 py-3 text-sm font-semibold transition" :class="activeTab === tab.id ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'" @click="activeTab = tab.id">{{ tab.name }}</button></nav></div>

            <div v-if="activeTab === 'overview'" class="mt-6 space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">店铺名称</p><p class="mt-3 text-lg font-semibold text-slate-900">{{ store.data.name }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">Shopify 域名</p><p class="mt-3 break-all font-mono text-sm font-semibold text-slate-700">{{ store.data.shopify_domain }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">连接状态</p><div class="mt-3"><ShopifyConnectionStatus :status="store.data.connection_status" size="md" /></div></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">已安装应用</p><p class="mt-3 text-2xl font-semibold text-slate-900">{{ store.data.installed_apps_count }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">最近同步</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(store.data.last_sync?.at ?? null) }}</p><p v-if="store.data.last_sync" class="mt-1 text-xs text-slate-400">状态：{{ syncStatusLabel(store.data.last_sync.status) }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">创建时间</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(store.data.created_at) }}</p></section>
                </div>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 px-6 py-5"><h3 class="font-semibold text-slate-950">已安装应用</h3><p class="mt-1 text-sm text-slate-500">数据来自当前店铺的应用安装记录。</p></div><div v-if="store.data.app_installations.length" class="divide-y divide-slate-100"><div v-for="installation in store.data.app_installations" :key="installation.id" class="flex items-center justify-between gap-4 px-6 py-4"><div><p class="font-semibold text-slate-900">{{ installation.app?.name ?? 'Shopify 应用' }}</p><p class="mt-1 text-xs text-slate-400">{{ dateLabel(installation.installed_at) }}</p></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ installationStatusLabel(installation.status) }}</span></div></div><p v-else class="px-6 py-10 text-center text-sm text-slate-500">暂无已安装应用。</p></section>
            </div>

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

            <EmptyState v-else class="mt-6" :title="`${currentTab.name}中心`" :description="currentTab.description" :icon="currentTab.icon">
                <ul class="grid gap-2 sm:grid-cols-3">
                    <li v-for="feature in currentTab.features" :key="feature" class="rounded-xl bg-slate-50 px-3 py-2 text-center text-xs font-semibold text-slate-600 ring-1 ring-slate-100">{{ feature }}</li>
                </ul>
            </EmptyState>
        </div>
    </AppLayout>
</template>
