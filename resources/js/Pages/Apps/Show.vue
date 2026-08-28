<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { AppInstallation, ResourceCollection, ShopifyApp } from '../../types';
import { formatDateTimeInTimezone, useStoreDateTime } from '../../composables/useStoreDateTime';

const props = defineProps<{ app: { data: ShopifyApp }; installations: ResourceCollection<AppInstallation> }>();
const activeTab = ref('overview');
const tabs = [
    { id: 'overview', name: '概览' },
    { id: 'installations', name: '安装记录' },
    { id: 'configuration', name: '应用配置' },
    { id: 'logs', name: '应用日志' },
];
const currentApp = computed(() => props.app.data);
const { formatDateTime: currentStoreDateTime } = useStoreDateTime();
const installationTimezones = new Map(props.installations.data
    .filter((installation) => installation.installed_at)
    .map((installation) => [installation.installed_at!, installation.store.timezone] as [string, string]));
const dateLabel = (value: string | null) => value
    ? (installationTimezones.has(value) ? formatDateTimeInTimezone(value, installationTimezones.get(value)!) : currentStoreDateTime(value))
    : '—';
const typeLabel = (type: string) => ({ custom: '自定义应用', public: '公开应用', private: '私有应用' }[type] ?? type);
const appStatusLabel = (status: string) => ({ active: '启用', inactive: '停用', draft: '草稿', disabled: '已禁用' }[status] ?? status);
const appStatusClass = (status: string) => status === 'active'
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
    : status === 'disabled'
        ? 'bg-rose-50 text-rose-700 ring-rose-100'
        : 'bg-amber-50 text-amber-700 ring-amber-100';
const installationStatusLabel = (status: string) => ({
    active: '已安装',
    pending: '待完成',
    disabled: '已禁用',
    uninstalled: '已卸载',
    not_installed: '未安装',
}[status] ?? status);
const installationStatusClass = (status: string) => ({
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    pending: 'bg-amber-50 text-amber-700 ring-amber-100',
    disabled: 'bg-slate-100 text-slate-600 ring-slate-200',
    uninstalled: 'bg-rose-50 text-rose-700 ring-rose-100',
    not_installed: 'bg-slate-100 text-slate-500 ring-slate-200',
}[status] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
const currentInstallationStatus = computed(() => currentApp.value.current_store_installation?.status ?? 'not_installed');
</script>

<template>
    <Head :title="currentApp.name" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: '应用列表', href: '/app-center' }, { label: currentApp.name }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/app-center" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回应用列表</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 items-center gap-4"><span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-xl font-bold text-emerald-700">{{ currentApp.name.charAt(0).toUpperCase() }}</span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="truncate text-3xl font-semibold tracking-tight text-slate-950">{{ currentApp.name }}</h2><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="appStatusClass(currentApp.status)">{{ appStatusLabel(currentApp.status) }}</span></div><p class="mt-1 truncate font-mono text-xs text-slate-500">{{ currentApp.slug }}</p></div></div>
                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-500 shadow-sm"><span>当前店铺</span><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="installationStatusClass(currentInstallationStatus)">{{ installationStatusLabel(currentInstallationStatus) }}</span></div>
            </div>

            <div class="mt-7 overflow-x-auto border-b border-slate-200"><nav class="flex min-w-max gap-1" aria-label="应用详情导航"><button v-for="tab in tabs" :key="tab.id" type="button" class="border-b-2 px-4 py-3 text-sm font-semibold transition" :class="activeTab === tab.id ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'" @click="activeTab = tab.id">{{ tab.name }}</button></nav></div>

            <div v-if="activeTab === 'overview'" class="mt-6 space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">应用名称</p><p class="mt-3 font-semibold text-slate-900">{{ currentApp.name }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">应用类型</p><p class="mt-3 font-semibold text-slate-900">{{ typeLabel(currentApp.type) }}</p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">当前店铺安装</p><p class="mt-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="installationStatusClass(currentInstallationStatus)">{{ installationStatusLabel(currentInstallationStatus) }}</span></p></section>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-wider text-slate-400">创建时间</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(currentApp.created_at) }}</p></section>
                </div>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h3 class="font-semibold text-slate-950">应用说明</h3><p class="mt-3 text-sm leading-7 text-slate-600">{{ currentApp.description || '尚未填写应用说明。' }}</p><div class="mt-5 rounded-xl bg-slate-50 px-4 py-3"><p class="text-xs font-semibold tracking-wider text-slate-400">应用标识</p><p class="mt-1 break-all font-mono text-sm text-slate-700">{{ currentApp.slug }}</p></div></section>
            </div>

            <section v-else-if="activeTab === 'installations'" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-5 sm:px-6"><h3 class="font-semibold text-slate-950">当前店铺安装记录</h3><p class="mt-1 text-sm text-slate-500">仅展示右上角当前店铺与此应用的安装记录。</p></div>
                <div v-if="installations.data.length">
                    <div class="hidden overflow-x-auto sm:block"><table class="w-full min-w-[720px] text-left text-sm"><thead class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3.5">店铺名称</th><th class="px-5 py-3.5">Shopify 域名</th><th class="px-5 py-3.5">状态</th><th class="px-5 py-3.5">安装时间</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="installation in installations.data" :key="installation.id"><td class="px-5 py-4"><Link :href="`/stores/${installation.store.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ installation.store.name }}</Link></td><td class="px-5 py-4 font-mono text-xs text-slate-500">{{ installation.store.shopify_domain }}</td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="installationStatusClass(installation.status)">{{ installationStatusLabel(installation.status) }}</span></td><td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(installation.installed_at) }}</td></tr></tbody></table></div>
                    <div class="divide-y divide-slate-100 sm:hidden"><article v-for="installation in installations.data" :key="installation.id" class="p-5"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><Link :href="`/stores/${installation.store.id}`" class="block truncate font-semibold text-slate-900">{{ installation.store.name }}</Link><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ installation.store.shopify_domain }}</p></div><span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="installationStatusClass(installation.status)">{{ installationStatusLabel(installation.status) }}</span></div><p class="mt-3 text-xs text-slate-500">安装时间：{{ dateLabel(installation.installed_at) }}</p></article></div>
                </div>
                <EmptyState v-else class="border-0 shadow-none" title="当前店铺暂无安装记录" description="当前店铺尚未安装此应用。" icon="installations" />
            </section>

            <EmptyState v-else-if="activeTab === 'configuration'" class="mt-6" title="按店铺管理应用配置" description="应用配置与店铺安装绑定；先选择目标店铺，再进入该应用提供的独立工作台。" icon="settings"><Link href="/app-configurations" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">前往应用配置</Link></EmptyState>
            <EmptyState v-else class="mt-6" title="查看应用日志" description="统一日志中心会按当前账号的组织和店铺权限隔离应用安装、配置与业务操作记录。" icon="audit"><Link href="/app-logs" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">前往应用日志</Link></EmptyState>
        </div>
    </AppLayout>
</template>
