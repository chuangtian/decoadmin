<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { SharedProps } from '../../types';

const page = usePage<SharedProps>();
const switching = ref(false);

const switchStore = (event: Event) => {
    const storeId = Number((event.target as HTMLSelectElement).value);
    if (!storeId || storeId === page.props.currentStore?.id) return;
    switching.value = true;
    router.put('/context/store', { store_id: storeId }, {
        preserveScroll: true,
        onFinish: () => { switching.value = false; },
    });
};
</script>

<template>
    <label class="relative block min-w-0">
        <span class="sr-only">Current store</span>
        <select :value="page.props.currentStore?.id ?? ''" :disabled="switching || !page.props.availableOrganizations.length" class="h-10 w-full appearance-none rounded-xl border border-slate-200 bg-white py-0 pr-9 pl-3 text-sm font-medium text-slate-700 shadow-sm outline-none transition hover:border-slate-300 focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/10 disabled:cursor-wait disabled:opacity-60" @change="switchStore">
            <option v-if="!page.props.currentStore" value="">No store available</option>
            <optgroup v-for="organization in page.props.availableOrganizations" :key="organization.id" :label="organization.name">
                <option v-for="store in organization.stores" :key="store.id" :value="store.id">{{ store.name }}</option>
            </optgroup>
        </select>
        <svg class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
    </label>
</template>
