<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PaginatedResource, SharedProps, ShopifyStore } from '../../types';

defineProps<{ stores: PaginatedResource<ShopifyStore> }>();

const page = usePage<SharedProps>();
const canCreate = computed(() => page.props.auth.permissions.includes('store.create'));
const statusLabel = (status: string) => ({ active: '已连接', pending: '待授权', inactive: '未启用' }[status] ?? status);
const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
</script>

<template>
    <Head title="Shopify 店铺" />
    <AppLayout>
        <div class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-emerald-700">Shopify</p>
                <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">店铺连接</h2>
                <p class="mt-2 text-sm text-slate-500">管理当前组织可访问的 Shopify 店铺与应用授权状态。</p>
            </div>
            <Link v-if="canCreate" href="/stores/create" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">连接 Shopify 店铺</Link>
        </div>

        <div v-if="stores.data.length" class="grid gap-4 xl:grid-cols-2">
            <Link v-for="store in stores.data" :key="store.id" :href="`/stores/${store.id}`" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-emerald-50 text-xl font-semibold text-emerald-700">{{ store.name.charAt(0).toUpperCase() }}</div>
                        <div class="min-w-0"><h3 class="truncate text-lg font-semibold text-slate-950">{{ store.name }}</h3><p class="truncate text-sm text-slate-500">{{ store.shopify_domain }}</p></div>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="store.connection?.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ store.connection?.status === 'active' ? '已连接' : statusLabel(store.status) }}</span>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
                    <div><p class="text-xs text-slate-400">连接时间</p><p class="mt-1 font-medium text-slate-700">{{ dateLabel(store.connection?.installed_at ?? null) }}</p></div>
                    <div><p class="text-xs text-slate-400">App 状态</p><p class="mt-1 font-medium text-slate-700">{{ store.app_installations[0]?.status === 'active' ? '已安装' : '待安装' }}</p></div>
                </div>
            </Link>
        </div>

        <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm">
            <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">S</div>
            <h3 class="mt-5 text-lg font-semibold text-slate-950">尚未连接 Shopify 店铺</h3>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">输入店铺的 myshopify.com 域名，完成 Shopify 官方授权后即可在这里查看连接状态。</p>
            <Link v-if="canCreate" href="/stores/create" class="mt-6 inline-flex rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white">开始连接</Link>
        </div>

        <div v-if="stores.links.length > 3" class="mt-6 flex flex-wrap gap-2">
            <Link v-for="link in stores.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" />
        </div>
    </AppLayout>
</template>
