<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ title: string; description: string }>();
</script>

<template>
    <div class="min-h-screen bg-[#07111f] lg:grid lg:grid-cols-[minmax(0,1.1fr)_minmax(480px,.9fr)]">
        <section class="relative hidden overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_15%,rgba(45,212,191,.2),transparent_35%),radial-gradient(circle_at_80%_85%,rgba(59,130,246,.16),transparent_35%)]" />
            <div class="relative flex items-center gap-3">
                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-teal-400 to-emerald-600 shadow-xl shadow-emerald-950/40">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h6a4 4 0 0 1 0 8H7V7Z"/><path d="M7 3v18M13 7h4"/></svg>
                </div>
                <div><p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-400">Commerce OS</p><p class="font-semibold">Shopify Commerce Hub</p></div>
            </div>
            <div class="relative max-w-2xl">
                <p class="mb-6 text-sm font-semibold tracking-[0.24em] text-emerald-400">安全统一的工作空间</p>
                <h1 class="text-5xl font-semibold leading-[1.15] tracking-[-0.04em]">清晰、高效地管理每一家店铺。</h1>
                <p class="mt-6 max-w-xl text-lg leading-8 text-slate-400">将团队、权限与多店铺运营集中在一个管理中心。</p>
            </div>
            <div class="relative flex items-center gap-8 text-xs text-slate-500"><span>角色权限控制</span><span>店铺数据隔离</span><span>安全会话</span></div>
        </section>

        <main class="flex min-h-screen items-center justify-center bg-slate-50 px-5 py-10 sm:px-10 lg:bg-white">
            <div class="w-full max-w-md">
                <Link href="/" class="mb-10 inline-flex items-center gap-2 text-sm font-semibold text-slate-800 lg:hidden"><span class="grid h-9 w-9 place-items-center rounded-xl bg-slate-900 text-white">C</span>Commerce Hub</Link>
                <div class="mb-8"><h2 class="text-3xl font-semibold tracking-[-0.03em] text-slate-950">{{ title }}</h2><p class="mt-2 leading-7 text-slate-500">{{ description }}</p></div>
                <slot />
            </div>
        </main>
    </div>
</template>
