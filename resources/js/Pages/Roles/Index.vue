<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Permission, ResourceCollection, Role } from '../../types';

const props = defineProps<{ roles: ResourceCollection<Role>; permissions: ResourceCollection<Permission> }>();
const form = useForm({ name: '', slug: '', description: '', permission_ids: [] as number[] });
const grouped = computed(() => props.permissions.data.reduce<Record<string, Permission[]>>((groups, permission) => {
    (groups[permission.group] ??= []).push(permission);
    return groups;
}, {}));
const submit = () => form.post('/roles', { onSuccess: () => form.reset() });
</script>

<template>
    <Head title="Roles" />
    <AppLayout>
        <div><p class="text-sm font-medium text-emerald-700">Access control</p><h2 class="mt-1 text-2xl font-semibold">Roles</h2><p class="mt-1 text-sm text-slate-500">Permission bundles scoped to this organization.</p></div>
        <div class="mt-6 grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4"><h3 class="font-semibold">Available roles</h3></div>
                <div class="divide-y divide-slate-100">
                    <Link v-for="role in roles.data" :key="role.id" :href="`/roles/${role.id}`" class="flex items-center justify-between px-5 py-4 hover:bg-slate-50">
                        <div><div class="flex items-center gap-2"><p class="font-medium">{{ role.name }}</p><span v-if="role.is_system" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-500">System</span></div><p class="mt-1 text-sm text-slate-500">{{ role.description }}</p></div>
                        <span class="ml-4 whitespace-nowrap rounded-full bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700">{{ role.permissions_count }} permissions</span>
                    </Link>
                </div>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">Create custom role</h3><p class="mt-1 text-sm text-slate-500">Start with a focused permission set.</p>
                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <label class="block text-sm font-medium">Name<input v-model="form.name" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
                    <label class="block text-sm font-medium">Slug<input v-model="form.slug" required pattern="[a-z0-9-]+" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="warehouse-manager" /></label>
                    <label class="block text-sm font-medium">Description<textarea v-model="form.description" rows="3" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
                    <details class="rounded-lg border border-slate-200"><summary class="cursor-pointer px-3 py-2 text-sm font-medium">Select permissions</summary><div class="max-h-56 space-y-4 overflow-y-auto border-t p-3"><div v-for="(items, group) in grouped" :key="group"><p class="mb-2 text-xs font-semibold uppercase text-slate-400">{{ group }}</p><label v-for="permission in items" :key="permission.id" class="mb-2 flex items-center gap-2 text-sm"><input v-model="form.permission_ids" :value="permission.id" type="checkbox" />{{ permission.slug }}</label></div></div></details>
                    <button :disabled="form.processing" class="w-full rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Create role</button>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
