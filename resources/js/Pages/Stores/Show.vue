<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps, ShopifyStore } from '../../types';

const props = defineProps<{ store: { data: ShopifyStore } }>();
const page = usePage<SharedProps>();
const canConnect = computed(() => page.props.auth.permissions.includes('store.connect'));
const form = useForm({ name: props.store.data.name, shop_domain: props.store.data.shopify_domain });
const reconnect = () => form.post(`/stores/${props.store.data.id}/connect`);
const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value)) : '—';
</script>

<template>
    <Head :title="store.data.name" />
    <AppLayout>
        <div class="mx-auto max-w-5xl">
            <Link href="/stores" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回店铺列表</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-sm font-semibold text-emerald-700">Shopify 店铺</p><h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">{{ store.data.name }}</h2><p class="mt-2 text-sm text-slate-500">{{ store.data.shopify_domain }}</p></div>
                <form v-if="canConnect" @submit.prevent="reconnect"><button :disabled="form.processing" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50 disabled:opacity-50">{{ form.processing ? '正在跳转…' : (store.data.connection ? '重新授权' : '继续连接') }}</button></form>
            </div>

            <div class="mt-7 grid gap-5 lg:grid-cols-3">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">店铺状态</p><div class="mt-4 flex items-center gap-3"><span class="h-2.5 w-2.5 rounded-full" :class="store.data.status === 'active' ? 'bg-emerald-500' : 'bg-amber-400'" /><p class="font-semibold text-slate-900">{{ store.data.status === 'active' ? '已启用' : '等待 Shopify 授权' }}</p></div></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">OAuth 连接</p><p class="mt-4 font-semibold" :class="store.data.connection?.status === 'active' ? 'text-emerald-700' : 'text-amber-700'">{{ store.data.connection?.status === 'active' ? '连接正常' : '尚未连接' }}</p><p class="mt-1 text-xs text-slate-500">{{ dateLabel(store.data.connection?.installed_at ?? null) }}</p></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">API 版本</p><p class="mt-4 font-semibold text-slate-900">{{ store.data.connection?.api_version ?? '授权后确定' }}</p><p class="mt-1 text-xs text-slate-500">GraphQL Admin API</p></section>
            </div>

            <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-5"><h3 class="text-lg font-semibold text-slate-950">App Installation</h3><p class="mt-1 text-sm text-slate-500">当前店铺的 Shopify App 安装状态。</p></div>
                <div v-if="store.data.app_installations.length" class="divide-y divide-slate-100">
                    <div v-for="installation in store.data.app_installations" :key="installation.id" class="flex items-center justify-between gap-4 px-6 py-5"><div><p class="font-semibold text-slate-900">{{ installation.app?.name ?? 'Shopify App' }}</p><p class="mt-1 text-xs text-slate-500">安装于 {{ dateLabel(installation.installed_at) }}</p></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">已安装</span></div>
                </div>
                <div v-else class="px-6 py-10 text-center text-sm text-slate-500">完成 Shopify OAuth 后将创建 App Installation。</div>
            </section>

            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h3 class="font-semibold text-slate-950">已授权范围</h3><div class="mt-4 flex flex-wrap gap-2"><span v-for="scope in store.data.connection?.scopes ?? []" :key="scope" class="rounded-lg bg-slate-100 px-2.5 py-1.5 font-mono text-xs text-slate-600">{{ scope }}</span><span v-if="!store.data.connection?.scopes.length" class="text-sm text-slate-500">尚未获得授权。</span></div></section>
        </div>
    </AppLayout>
</template>
