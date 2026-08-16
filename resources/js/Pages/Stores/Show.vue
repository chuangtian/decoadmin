<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps, ShopifyStore } from '../../types';

const props = defineProps<{ store: { data: ShopifyStore } }>();
const page = usePage<SharedProps>();
const canConnect = computed(() => page.props.auth.permissions.includes('store.connect'));
const activeTab = ref('overview');
const tabs = [
    { id: 'overview', name: '概览', icon: 'stores', description: '', features: [] },
    { id: 'shopify', name: 'Shopify', icon: 'shopify', description: 'Shopify 连接详情与 API 状态将在后续阶段接入。', features: ['连接信息', '授权范围', 'API 状态'] },
    { id: 'apps', name: '应用', icon: 'apps', description: '应用安装与配置能力将在 App Center 阶段接入。', features: ['已安装应用', '安装状态', '应用配置'] },
    { id: 'sync', name: '同步', icon: 'sync', description: '数据同步中心尚未启用，当前不会执行任何业务数据同步。', features: ['同步任务', '执行历史', '错误重试'] },
    { id: 'webhooks', name: 'Webhook', icon: 'webhooks', description: 'Webhook 管理功能正在准备中。', features: ['事件监控', '失败重试', '投递日志'] },
    { id: 'logs', name: '日志', icon: 'audit', description: '店铺级操作与集成日志将在后续阶段统一展示。', features: ['操作记录', '集成事件', '异常追踪'] },
];
const currentTab = computed(() => tabs.find((tab) => tab.id === activeTab.value) ?? tabs[0]);
const form = useForm({ name: props.store.data.name, shop_domain: props.store.data.shopify_domain, environment: props.store.data.environment });
const reconnect = () => form.post(`/stores/${props.store.data.id}/connect`);
const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value)) : '暂无记录';
const connectionLabel = computed(() => ({ connected: 'Connected', disconnected: 'Disconnected', error: 'Error', pending: 'Pending' }[props.store.data.connection_status]));
</script>

<template>
    <Head :title="store.data.name" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '店铺管理', href: '/stores' }, { label: store.data.name }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/stores" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回店铺列表</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex items-center gap-4"><span class="grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-xl font-bold text-emerald-700">{{ store.data.name.charAt(0).toUpperCase() }}</span><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-3xl font-semibold tracking-tight text-slate-950">{{ store.data.name }}</h2><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ store.data.environment === 'development' ? 'Development' : 'Production' }}</span></div><p class="mt-1 font-mono text-xs text-slate-500">{{ store.data.shopify_domain }}</p></div></div>
                <form v-if="canConnect" @submit.prevent="reconnect"><button :disabled="form.processing" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 disabled:opacity-50">{{ form.processing ? '正在跳转…' : (store.data.connection ? '重新授权' : '继续连接') }}</button></form>
            </div>

            <div class="mt-7 overflow-x-auto border-b border-slate-200"><nav class="flex min-w-max gap-1" aria-label="店铺详情导航"><button v-for="tab in tabs" :key="tab.id" type="button" class="border-b-2 px-4 py-3 text-sm font-semibold transition" :class="activeTab === tab.id ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'" @click="activeTab = tab.id">{{ tab.name }}</button></nav></div>

            <div v-if="activeTab === 'overview'" class="mt-6 space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Store Name</p><p class="mt-3 text-lg font-semibold text-slate-900">{{ store.data.name }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Shopify Domain</p><p class="mt-3 break-all font-mono text-sm font-semibold text-slate-700">{{ store.data.shopify_domain }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Connection Status</p><div class="mt-3 flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :class="store.data.connection_status === 'connected' ? 'bg-emerald-500' : store.data.connection_status === 'error' ? 'bg-rose-500' : 'bg-amber-400'" /><p class="font-semibold text-slate-900">{{ connectionLabel }}</p></div></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Installed Apps</p><p class="mt-3 text-2xl font-semibold text-slate-900">{{ store.data.installed_apps_count }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Last Sync</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(store.data.last_sync?.at ?? null) }}</p><p v-if="store.data.last_sync" class="mt-1 text-xs text-slate-400">状态：{{ store.data.last_sync.status }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Created Time</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(store.data.created_at) }}</p></section>
                </div>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 px-6 py-5"><h3 class="font-semibold text-slate-950">已安装应用</h3><p class="mt-1 text-sm text-slate-500">数据来自当前店铺的 App Installation 记录。</p></div><div v-if="store.data.app_installations.length" class="divide-y divide-slate-100"><div v-for="installation in store.data.app_installations" :key="installation.id" class="flex items-center justify-between gap-4 px-6 py-4"><div><p class="font-semibold text-slate-900">{{ installation.app?.name ?? 'Shopify App' }}</p><p class="mt-1 text-xs text-slate-400">{{ dateLabel(installation.installed_at) }}</p></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ installation.status }}</span></div></div><p v-else class="px-6 py-10 text-center text-sm text-slate-500">暂无已安装应用。</p></section>
            </div>

            <EmptyState v-else class="mt-6" :title="`${currentTab.name} Center`" :description="currentTab.description" :icon="currentTab.icon">
                <ul class="grid gap-2 sm:grid-cols-3">
                    <li v-for="feature in currentTab.features" :key="feature" class="rounded-xl bg-slate-50 px-3 py-2 text-center text-xs font-semibold text-slate-600 ring-1 ring-slate-100">{{ feature }}</li>
                </ul>
            </EmptyState>
        </div>
    </AppLayout>
</template>
