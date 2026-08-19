<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    active: 'mail' | 'feishu';
    canUpdate: boolean;
}>();
</script>

<template>
    <section class="mb-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-5 px-5 py-5 sm:px-7 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-lg font-bold text-emerald-700 ring-1 ring-emerald-100">
                    {{ store.name.slice(0, 1).toUpperCase() }}
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">当前店铺配置</p>
                    <h1 class="mt-1 truncate text-2xl font-semibold tracking-tight text-slate-950">{{ store.name }}</h1>
                    <p class="mt-1 truncate font-mono text-xs text-slate-500">{{ store.shopify_domain }}</p>
                </div>
            </div>
            <span class="inline-flex self-start rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="canUpdate ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">
                {{ canUpdate ? '可编辑' : '只读查看' }}
            </span>
        </div>
        <nav class="flex gap-1 border-t border-slate-100 px-5 pt-2 sm:px-7" aria-label="店铺设置">
            <Link href="/store-settings/feishu" class="border-b-2 px-4 py-3 text-sm font-semibold transition" :class="active === 'feishu' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-900'">飞书设置</Link>
            <Link href="/store-settings/mail" class="border-b-2 px-4 py-3 text-sm font-semibold transition" :class="active === 'mail' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-900'">邮箱设置</Link>
        </nav>
    </section>
</template>
