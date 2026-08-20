<script setup lang="ts">
import ShopifyConnectionStatus from '../Shopify/ShopifyConnectionStatus.vue';
import type { ShopifyStore } from '../../types';
import { formatDateTimeInTimezone } from '../../composables/useStoreDateTime';

const props = defineProps<{
    store: ShopifyStore;
}>();

const dateLabel = (value: string | null) => value
    ? formatDateTimeInTimezone(value, props.store.timezone)
    : '暂无记录';
const syncStatusLabel = (status: string) => ({
    pending: '待处理',
    queued: '已入队',
    running: '执行中',
    completed: '已完成',
    failed: '失败',
    cancelled: '已取消',
}[status] ?? status);
const installationStatusLabel = (status: string) => ({
    active: '已安装',
    pending: '待安装',
    inactive: '已停用',
    uninstalled: '已卸载',
    failed: '安装失败',
}[status] ?? status);
</script>

<template>
    <div class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold tracking-wider text-slate-400">店铺名称</p>
                <p class="mt-3 text-lg font-semibold text-slate-900">{{ store.name }}</p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold tracking-wider text-slate-400">Shopify 域名</p>
                <p class="mt-3 break-all font-mono text-sm font-semibold text-slate-700">{{ store.shopify_domain }}</p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold tracking-wider text-slate-400">连接状态</p>
                <div class="mt-3"><ShopifyConnectionStatus :status="store.connection_status" size="md" /></div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold tracking-wider text-slate-400">已安装应用</p>
                <p class="mt-3 text-2xl font-semibold text-slate-900">{{ store.installed_apps_count }}</p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold tracking-wider text-slate-400">最近同步</p>
                <p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(store.last_sync?.at ?? null) }}</p>
                <p v-if="store.last_sync" class="mt-1 text-xs text-slate-400">状态：{{ syncStatusLabel(store.last_sync.status) }}</p>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold tracking-wider text-slate-400">创建时间</p>
                <p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(store.created_at) }}</p>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5">
                <h3 class="font-semibold text-slate-950">已安装应用</h3>
                <p class="mt-1 text-sm text-slate-500">数据来自当前店铺的应用安装记录。</p>
            </div>
            <div v-if="store.app_installations.length" class="divide-y divide-slate-100">
                <div v-for="installation in store.app_installations" :key="installation.id" class="flex items-center justify-between gap-4 px-6 py-4">
                    <div>
                        <p class="font-semibold text-slate-900">{{ installation.app?.name ?? 'Shopify 应用' }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ dateLabel(installation.installed_at) }}</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ installationStatusLabel(installation.status) }}</span>
                </div>
            </div>
            <p v-else class="px-6 py-10 text-center text-sm text-slate-500">暂无已安装应用。</p>
        </section>
    </div>
</template>
