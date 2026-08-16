<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PaginatedResource, SharedProps, ShopifyStore } from '../../types';

defineProps<{ stores: PaginatedResource<ShopifyStore> }>();
const page = usePage<SharedProps>();
const canCreate = computed(() => page.props.auth.permissions.includes('store.create'));
const connectionLabels: Record<ShopifyStore['connection_status'], string> = {
    connected: 'Connected',
    disconnected: 'Disconnected',
    error: 'Error',
    pending: 'Pending',
};
const connectionClasses: Record<ShopifyStore['connection_status'], string> = {
    connected: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
    disconnected: 'bg-slate-100 text-slate-600 ring-slate-500/15',
    error: 'bg-rose-50 text-rose-700 ring-rose-600/15',
    pending: 'bg-amber-50 text-amber-700 ring-amber-600/15',
};
const storeStatusLabel = (status: string) => ({ active: '启用', pending: '待授权', inactive: '停用', error: '异常' }[status] ?? status);
const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
</script>

<template>
    <Head title="店铺管理" />
    <AppLayout>
        <div class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><p class="text-sm font-semibold text-emerald-700">Shopify</p><h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">店铺管理</h2><p class="mt-2 text-sm text-slate-500">查看当前组织内已授权店铺、连接状态和应用安装情况。</p></div>
            <Link v-if="canCreate" href="/stores/create" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">+ 连接 Shopify 店铺</Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-[1120px] w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                        <tr><th class="px-5 py-3.5">店铺名称</th><th class="px-5 py-3.5">Shopify Domain</th><th class="px-5 py-3.5">平台</th><th class="px-5 py-3.5">状态</th><th class="px-5 py-3.5">Connection</th><th class="px-5 py-3.5 text-center">App 数量</th><th class="px-5 py-3.5">创建时间</th><th class="px-5 py-3.5 text-right">操作</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="store in stores.data" :key="store.id" class="transition hover:bg-slate-50/70">
                            <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-xs font-bold text-emerald-700">{{ store.name.charAt(0).toUpperCase() }}</span><div><Link :href="`/stores/${store.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ store.name }}</Link><p class="mt-0.5 text-xs text-slate-400">{{ store.environment === 'development' ? 'Development' : 'Production' }}</p></div></div></td>
                            <td class="px-5 py-4 font-mono text-xs text-slate-600">{{ store.shopify_domain }}</td>
                            <td class="px-5 py-4"><span class="font-medium text-slate-700">{{ store.platform }}</span></td>
                            <td class="px-5 py-4"><span class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-700"><span class="h-2 w-2 rounded-full" :class="store.status === 'active' ? 'bg-emerald-500' : store.status === 'error' ? 'bg-rose-500' : 'bg-amber-400'" />{{ storeStatusLabel(store.status) }}</span></td>
                            <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="connectionClasses[store.connection_status]">{{ connectionLabels[store.connection_status] }}</span></td>
                            <td class="px-5 py-4 text-center font-semibold text-slate-700">{{ store.installed_apps_count }}</td>
                            <td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(store.created_at) }}</td>
                            <td class="px-5 py-4 text-right"><Link :href="`/stores/${store.id}`" class="rounded-lg px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50">查看详情</Link></td>
                        </tr>
                        <tr v-if="!stores.data.length"><td colspan="8" class="px-6 py-16 text-center"><div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-emerald-50 text-lg font-bold text-emerald-700">S</div><p class="mt-4 font-semibold text-slate-900">尚未连接 Shopify 店铺</p><p class="mt-1 text-sm text-slate-500">连接后的真实店铺数据会显示在这里。</p><Link v-if="canCreate" href="/stores/create" class="mt-5 inline-flex rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white">开始连接</Link></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="stores.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in stores.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
    </AppLayout>
</template>
