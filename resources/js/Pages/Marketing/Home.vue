<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    modules: Array<{ handle: string; name: string; enabled: boolean }>;
}>();
</script>

<template>
    <Head :title="`${store.name} · 营销`" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '店铺管理', href: '/stores' }, { label: store.name, href: `/stores/${store.id}` }, { label: '营销' }]">
        <div class="mx-auto max-w-6xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">Deco Marketing</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">{{ store.name }} 营销中心</h1>
                    <p class="mt-2 font-mono text-xs text-slate-400">{{ store.shopify_domain }}</p>
                </div>
                <Link :href="`/stores/${store.id}?tab=apps`" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm">管理应用</Link>
            </div>

            <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="module in modules" :key="module.handle" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{{ module.handle }}</p>
                            <h2 class="mt-2 text-lg font-semibold text-slate-950">{{ module.name }}</h2>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="module.enabled ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-slate-100 text-slate-500 ring-slate-200'">{{ module.enabled ? '已启用' : '已停用' }}</span>
                    </div>
                    <p class="mt-5 text-sm leading-6 text-slate-500">模块基础入口已建立，后续功能将在这里逐步接入。</p>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
