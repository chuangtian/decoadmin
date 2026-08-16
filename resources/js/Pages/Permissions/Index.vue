<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Permission, ResourceCollection } from '../../types';

const props = defineProps<{ permissions: ResourceCollection<Permission> }>();
const grouped = computed(() => props.permissions.data.reduce<Record<string, Permission[]>>((groups, permission) => {
    (groups[permission.group] ??= []).push(permission);
    return groups;
}, {}));
</script>

<template>
    <Head title="Permissions" />
    <AppLayout>
        <div><p class="text-sm font-medium text-emerald-700">Access control</p><h2 class="mt-1 text-2xl font-semibold">Permissions</h2><p class="mt-1 text-sm text-slate-500">Read-only registry of capabilities available to roles.</p></div>
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <section v-for="(items, group) in grouped" :key="group" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between"><h3 class="font-semibold capitalize">{{ group }}</h3><span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-500">{{ items.length }}</span></div>
                <div class="mt-4 divide-y divide-slate-100"><div v-for="permission in items" :key="permission.id" class="py-3"><code class="text-xs font-semibold text-indigo-700">{{ permission.slug }}</code><p class="mt-1 text-xs leading-5 text-slate-500">{{ permission.description }}</p></div></div>
            </section>
        </div>
    </AppLayout>
</template>
