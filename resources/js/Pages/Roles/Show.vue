<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Permission, ResourceCollection, Role } from '../../types';

const props = defineProps<{ role: { data: Role }; permissions: ResourceCollection<Permission> }>();
const selected = props.role.data.permissions?.map((permission) => permission.id) ?? [];
const form = useForm({ permission_ids: selected });
const grouped = computed(() => props.permissions.data.reduce<Record<string, Permission[]>>((groups, permission) => {
    (groups[permission.group] ??= []).push(permission);
    return groups;
}, {}));
const submit = () => form.put(`/roles/${props.role.data.id}/permissions`);
</script>

<template>
    <Head :title="role.data.name" />
    <AppLayout>
        <div class="mx-auto max-w-5xl">
            <Link href="/roles" class="text-sm font-medium text-slate-500 hover:text-slate-900">← Back to roles</Link>
            <div class="mt-4 flex items-start justify-between"><div><div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ role.data.name }}</h2><span v-if="role.data.is_system" class="rounded bg-slate-200 px-2 py-1 text-xs font-semibold uppercase text-slate-600">System role</span></div><p class="mt-2 text-sm text-slate-500">{{ role.data.description }}</p></div><code class="rounded-md bg-slate-900 px-3 py-2 text-xs text-slate-100">{{ role.data.slug }}</code></div>
            <form class="mt-6" @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <section v-for="(items, group) in grouped" :key="group" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold capitalize">{{ group }}</h3>
                        <div class="mt-4 space-y-3"><label v-for="permission in items" :key="permission.id" class="flex items-start gap-3 text-sm"><input v-model="form.permission_ids" :value="permission.id" type="checkbox" class="mt-1" /><span><span class="block font-medium">{{ permission.slug }}</span><span class="text-xs text-slate-400">{{ permission.name }}</span></span></label></div>
                    </section>
                </div>
                <div class="mt-6 flex justify-end"><button :disabled="form.processing" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Save permissions</button></div>
            </form>
        </div>
    </AppLayout>
</template>
