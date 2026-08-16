<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { SharedProps } from '../../types';

const page = usePage<SharedProps>();
const details = ref<HTMLDetailsElement | null>(null);
const switching = ref(false);
const switchOrganization = (organizationId: number) => {
    if (organizationId === page.props.currentOrganization?.id) {
        if (details.value) details.value.open = false;
        return;
    }

    switching.value = true;
    const redirectStoreDetail = /^\/stores\/\d+(?:\?.*)?$/.test(page.url);
    router.put('/context/organization', { organization_id: organizationId }, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            if (redirectStoreDetail) router.visit(page.props.currentStore ? `/stores/${page.props.currentStore.id}` : '/stores');
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
            <span class="hidden h-8 w-8 shrink-0 place-items-center rounded-lg bg-slate-900 text-xs font-bold text-white sm:grid">{{ page.props.currentOrganization?.name.charAt(0).toUpperCase() ?? 'O' }}</span>
            <span class="min-w-0 flex-1 text-left"><span class="block text-[9px] font-semibold uppercase tracking-[0.14em] text-slate-400">组织</span><span class="block truncate text-sm font-semibold text-slate-800">{{ page.props.currentOrganization?.name ?? '暂无组织' }}</span></span>
            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </summary>
        <div class="absolute left-0 z-40 mt-2 w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
            <p class="px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">选择组织</p>
            <button v-for="organization in page.props.availableOrganizations" :key="organization.id" type="button" :disabled="switching" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-slate-50 disabled:opacity-50" @click="switchOrganization(organization.id)">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600">{{ organization.name.charAt(0).toUpperCase() }}</span>
                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-800">{{ organization.name }}</span><span class="block text-xs text-slate-400">{{ organization.stores.length }} 个可用店铺</span></span>
                <span v-if="organization.id === page.props.currentOrganization?.id" class="text-sm font-bold text-emerald-600">✓</span>
            </button>
            <p v-if="!page.props.availableOrganizations.length" class="px-3 py-5 text-center text-sm text-slate-500">暂无可用组织</p>
        </div>
    </details>
</template>
