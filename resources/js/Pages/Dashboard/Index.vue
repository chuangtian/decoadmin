<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AppIcon from '../../Components/Layout/AppIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps } from '../../types';

const page = usePage<SharedProps>();
const statusCards = [
    { label: 'Application', value: 'Operational', detail: 'Core services are available', color: 'emerald' },
    { label: 'Data sync', value: 'Not configured', detail: 'Available after Shopify setup', color: 'slate' },
    { label: 'Webhooks', value: 'Not configured', detail: 'Available after Shopify setup', color: 'slate' },
];
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout>
        <section class="mb-8 flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
            <div><p class="text-sm font-semibold text-emerald-700">Workspace overview</p><h1 class="mt-1 text-3xl font-semibold tracking-[-0.03em] text-slate-950">Good to see you, {{ page.props.auth.user?.name.split(' ')[0] }}</h1><p class="mt-2 text-sm text-slate-500">Your organization and store context are ready for the day.</p></div>
            <div class="inline-flex items-center gap-2 self-start rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 sm:self-auto"><span class="h-2 w-2 rounded-full bg-emerald-500"/>All systems normal</div>
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <article class="relative overflow-hidden rounded-3xl bg-[#0d1828] p-6 text-white shadow-xl shadow-slate-900/8 sm:p-8">
                <div class="absolute -top-16 -right-12 h-56 w-56 rounded-full bg-emerald-400/10 blur-2xl"/>
                <div class="relative flex h-full min-h-52 flex-col justify-between">
                    <div class="flex items-start justify-between"><div class="grid h-11 w-11 place-items-center rounded-2xl bg-white/8 text-emerald-300"><AppIcon name="stores" :size="22"/></div><span class="rounded-full border border-white/10 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">Current context</span></div>
                    <div><p class="text-sm text-slate-400">{{ page.props.currentOrganization?.name ?? 'No organization' }}</p><h2 class="mt-1 text-3xl font-semibold tracking-tight">{{ page.props.currentStore?.name ?? 'No store selected' }}</h2><p class="mt-3 text-sm text-slate-400">{{ page.props.currentStore ? 'Store access verified for this session.' : 'Ask an administrator to grant store access.' }}</p></div>
                </div>
            </article>
            <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">System status</p>
                <div class="mt-5 divide-y divide-slate-100">
                    <div v-for="status in statusCards" :key="status.label" class="flex items-center gap-4 py-4 first:pt-0 last:pb-0"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="status.color === 'emerald' ? 'bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,.35)]' : 'bg-slate-300'"/><div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-800">{{ status.label }}</p><p class="mt-0.5 truncate text-xs text-slate-400">{{ status.detail }}</p></div><span class="text-xs font-semibold" :class="status.color === 'emerald' ? 'text-emerald-700' : 'text-slate-400'">{{ status.value }}</span></div>
                </div>
            </article>
        </section>

        <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Shopify data</p><h2 class="mt-2 text-xl font-semibold text-slate-900">Commerce insights will live here</h2><p class="mt-1 text-sm text-slate-500">Orders, products, customers, and inventory will appear after a future Shopify connection phase.</p></div><span class="self-start rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">Reserved</span></div>
            <div class="mt-8 grid gap-4 sm:grid-cols-3">
                <div v-for="item in [{ name: 'Orders', icon: 'orders' }, { name: 'Products', icon: 'products' }, { name: 'Customers', icon: 'customers' }]" :key="item.name" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-5"><AppIcon :name="item.icon" class="text-slate-300"/><p class="mt-7 text-sm font-semibold text-slate-600">{{ item.name }}</p><div class="mt-3 h-2 w-24 rounded-full bg-slate-200"/><div class="mt-2 h-2 w-16 rounded-full bg-slate-100"/></div>
            </div>
        </section>
    </AppLayout>
</template>
