<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface MarketingModule {
    handle: string;
    name: string;
    product_name: string;
    description: string;
    enabled: boolean;
    configured: boolean;
}
interface CredentialProvider { key: string; title: string; configured: boolean; description: string }
defineProps<{
    store: { id: number; name: string; shopify_domain: string } | null;
    installation: { id: number; status: string; app_name: string; modules: MarketingModule[] } | null;
    credentialProviders: CredentialProvider[];
    canConfigure: boolean;
}>();
</script>

<template>
    <Head title="应用配置" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: '应用配置' }]">
        <div class="mx-auto max-w-7xl">
            <EmptyState v-if="!store" title="请先选择店铺" description="应用配置按店铺隔离；选择有权限的店铺后可管理六个营销模块。" icon="stores" />
            <template v-else>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <header><p class="text-sm font-semibold text-emerald-700">{{ store.name }}</p><h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">应用配置</h1><p class="mt-2 text-sm text-slate-500">按当前店铺管理 Deco Marketing 六个模块及安全凭证。</p></header>
                <Link :href="`/stores/${store.id}?tab=apps`" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm">管理安装</Link>
            </div>

            <template v-if="installation">
                <section class="mt-7 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-semibold text-emerald-950">{{ installation.app_name }}</p><p class="mt-1 font-mono text-xs text-emerald-700">{{ store.shopify_domain }}</p></div><span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">已安装 · 六模块已启用</span></div></section>

                <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <article v-for="module in installation.modules" :key="module.handle" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.14em] text-slate-400">{{ module.product_name }}</p><h2 class="mt-2 text-lg font-semibold text-slate-950">{{ module.name }}</h2></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="module.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ module.configured ? '已配置' : '待配置' }}</span></div>
                        <p class="mt-4 min-h-12 text-sm leading-6 text-slate-500">{{ module.description }}</p>
                        <Link :href="`/stores/${store.id}/marketing/${module.handle}`" class="mt-5 inline-flex text-sm font-semibold text-emerald-700">打开配置 →</Link>
                    </article>
                </div>

                <section class="mt-7 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-950">发送服务凭证</h2><p class="mt-1 text-sm text-slate-500">邮件和短信凭证加密保存，不会出现在页面初始数据中。</p><div class="mt-5 grid gap-4 md:grid-cols-2"><article v-for="provider in credentialProviders" :key="provider.key" class="rounded-xl border border-slate-200 p-4"><div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-slate-900">{{ provider.title }}</h3><span class="text-xs font-semibold" :class="provider.configured ? 'text-emerald-700' : 'text-amber-700'">{{ provider.configured ? '已配置' : '未配置' }}</span></div><p class="mt-2 text-sm leading-6 text-slate-500">{{ provider.description }}</p><Link v-if="canConfigure" :href="`/store-settings/credentials?provider=${provider.key}`" class="mt-4 inline-flex text-sm font-semibold text-emerald-700">管理凭证 →</Link></article></div></section>
            </template>
            <EmptyState v-else class="mt-7" title="当前店铺尚未安装应用" description="请先在店铺应用页完成 Shopify 授权，安装成功后六个模块会默认启用。" icon="apps"><Link :href="`/stores/${store.id}?tab=apps`" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">前往安装</Link></EmptyState>
            </template>
        </div>
    </AppLayout>
</template>
