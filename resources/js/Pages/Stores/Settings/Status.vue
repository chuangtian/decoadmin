<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import StoreStatusOverview from '../../../Components/Stores/StoreStatusOverview.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import type { ShopifyStore } from '../../../types';

defineProps<{
    store: { data: ShopifyStore };
}>();
</script>

<template>
    <Head :title="`${store.data.name} 店铺状态`" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '店铺状态' }]">
        <div class="mx-auto max-w-6xl">
            <header class="flex items-center gap-4">
                <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-xl font-bold text-emerald-700">
                    {{ store.data.name.charAt(0).toUpperCase() }}
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-3xl font-semibold tracking-tight text-slate-950">{{ store.data.name }}</h1>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                            {{ store.data.environment === 'development' ? '开发测试环境' : '正式环境' }}
                        </span>
                    </div>
                    <p class="mt-1 truncate font-mono text-xs text-slate-500">{{ store.data.shopify_domain }}</p>
                </div>
            </header>

            <div class="mt-7 border-b border-slate-200">
                <span class="inline-flex border-b-2 border-emerald-600 px-4 py-3 text-sm font-semibold text-emerald-700">概览</span>
            </div>

            <StoreStatusOverview class="mt-6" :store="store.data" />
        </div>
    </AppLayout>
</template>
