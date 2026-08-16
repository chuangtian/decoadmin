<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PaginatedResource, ResourceCollection, Role, StoreOption, User } from '../../types';

defineProps<{
    users: PaginatedResource<User>;
    roles: ResourceCollection<Role>;
    stores: StoreOption[];
}>();

const remove = (user: User) => {
    if (window.confirm(`Remove ${user.name}?`)) router.delete(`/users/${user.id}`);
};
</script>

<template>
    <Head title="People" />
    <AppLayout>
        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-emerald-700">Access control</p>
                <h2 class="mt-1 text-2xl font-semibold tracking-tight">People</h2>
                <p class="mt-1 text-sm text-slate-500">Manage organization membership, roles, and store access.</p>
            </div>
            <Link href="/users/create" class="rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Add user</Link>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Roles</th><th class="px-5 py-3">Stores</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-4"><p class="font-medium">{{ user.name }}</p><p class="text-xs text-slate-500">{{ user.email }}</p></td>
                        <td class="px-5 py-4"><div class="flex flex-wrap gap-1"><span v-for="role in user.roles" :key="role.id" class="rounded-md bg-indigo-50 px-2 py-1 text-xs text-indigo-700">{{ role.name }}</span><span v-if="!user.roles.length" class="text-slate-400">None</span></div></td>
                        <td class="px-5 py-4 text-slate-600">{{ user.stores.map((store) => store.name).join(', ') || 'All via organization role' }}</td>
                        <td class="px-5 py-4"><span class="rounded-full px-2 py-1 text-xs" :class="user.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ user.status }}</span></td>
                        <td class="px-5 py-4 text-right"><Link :href="`/users/${user.id}/edit`" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">Edit</Link><button class="ml-4 text-sm text-red-600 hover:text-red-800" @click="remove(user)">Remove</button></td>
                    </tr>
                    <tr v-if="!users.data.length"><td colspan="5" class="px-5 py-12 text-center text-slate-500">No users in this organization yet.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="users.links.length > 3" class="mt-5 flex flex-wrap gap-2">
            <Link v-for="link in users.links" :key="link.label" :href="link.url ?? ''" class="rounded-md border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" />
        </div>
    </AppLayout>
</template>
