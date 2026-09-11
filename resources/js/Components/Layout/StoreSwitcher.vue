<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { SharedProps } from '../../types';

const page = usePage<SharedProps>();
const details = ref<HTMLDetailsElement | null>(null);
const switching = ref(false);
const switchStore = (storeId: number) => {
    if (storeId === page.props.currentStore?.id) {
        if (details.value) details.value.open = false;
        return;
    }

    switching.value = true;
    const redirectStoreDetail = /^\/stores\/\d+(?:\?.*)?$/.test(page.url);
    router.put('/context/store', { store_id: storeId }, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            if (redirectStoreDetail) router.visit(`/stores/${storeId}`);
        },
        onFinish: () => {
            switching.value = false;
            if (details.value) details.value.open = false;
        },
    });
};
</script>

<template>
    <details ref="details" class="group relative w-full min-w-0">
        <summary class="flex h-11 min-w-0 cursor-pointer list-none items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 shadow-sm transition hover:border-slate-300">
            <span class="min-w-0 flex-1 text-left"><span class="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">店铺</span><span class="block truncate text-sm font-semibold text-slate-800">{{ page.props.currentStore?.name ?? '暂无可用店铺' }}</span></span>
            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </summary>
        <div class="absolute right-0 z-40 mt-2 max-h-[70vh] w-72 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10 sm:right-auto sm:left-0 sm:w-80">
            <template v-for="organization in page.props.availableOrganizations" :key="organization.id">
                <p class="px-3 pt-3 pb-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{{ organization.name }}</p>
                <button v-for="store in organization.stores" :key="store.id" type="button" :disabled="switching" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-slate-50 disabled:opacity-50" @click="switchStore(store.id)">
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-800">{{ store.name }}</span><span class="block text-xs text-slate-400">{{ store.status === 'active' ? '运行中' : '待连接' }}</span></span>
                    <span v-if="store.id === page.props.currentStore?.id" class="text-sm font-bold text-emerald-600">✓</span>
                </button>
                <p v-if="!organization.stores.length" class="px-3 py-2 text-xs text-slate-400">该组织暂无授权店铺</p>
            </template>
            <p v-if="!page.props.availableOrganizations.some((organization) => organization.stores.length)" class="px-3 py-6 text-center text-sm text-slate-500">暂无可用店铺</p>
        </div>
    </details>
</template>
