<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { ResourceCollection, Role, StoreOption, User } from '../../types';

const props = defineProps<{ user?: { data: User }; roles: ResourceCollection<Role>; stores: StoreOption[] }>();
const editing = Boolean(props.user);
const form = useForm({
    name: props.user?.data.name ?? '',
    email: props.user?.data.email ?? '',
    password: '',
    status: props.user?.data.status ?? 'active',
    role_ids: props.user?.data.roles.map((role) => role.id) ?? [],
    store_ids: props.user?.data.stores.map((store) => store.id) ?? [],
});
const submit = () => editing ? form.put(`/users/${props.user!.data.id}`) : form.post('/users');
</script>

<template>
    <Head :title="editing ? 'Edit user' : 'Add user'" />
    <AppLayout>
        <div class="mx-auto max-w-4xl">
            <Link href="/users" class="text-sm font-medium text-slate-500 hover:text-slate-900">← Back to people</Link>
            <div class="mt-4"><h2 class="text-2xl font-semibold">{{ editing ? 'Edit user' : 'Add user' }}</h2><p class="mt-1 text-sm text-slate-500">Identity, organization role, and store scope are managed together.</p></div>
            <form class="mt-6 space-y-6" @submit.prevent="submit">
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="font-semibold">Profile</h3>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <label class="text-sm font-medium">Name<input v-model="form.name" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-indigo-500" /><span class="mt-1 block text-xs text-red-600">{{ form.errors.name }}</span></label>
                        <label class="text-sm font-medium">Email<input v-model="form.email" required type="email" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-indigo-500" /><span class="mt-1 block text-xs text-red-600">{{ form.errors.email }}</span></label>
                        <label class="text-sm font-medium">Password<input v-model="form.password" :required="!editing" type="password" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-indigo-500" :placeholder="editing ? 'Leave blank to keep current password' : 'At least 8 characters'" /><span class="mt-1 block text-xs text-red-600">{{ form.errors.password }}</span></label>
                        <label class="text-sm font-medium">Status<select v-model="form.status" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                    </div>
                </section>
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h3 class="font-semibold">Roles</h3><p class="mt-1 text-sm text-slate-500">Permissions are inherited from every selected organization role.</p><div class="mt-4 grid gap-3 sm:grid-cols-2"><label v-for="role in roles.data" :key="role.id" class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.role_ids" :value="role.id" type="checkbox" class="mt-1" /><span><span class="block text-sm font-medium">{{ role.name }}</span><span class="text-xs text-slate-500">{{ role.description }}</span></span></label></div></section>
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h3 class="font-semibold">Store access</h3><p class="mt-1 text-sm text-slate-500">Store-scoped actions are denied unless the user is a member.</p><div class="mt-4 grid gap-3 sm:grid-cols-2"><label v-for="store in stores" :key="store.id" class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.store_ids" :value="store.id" type="checkbox" /><span class="text-sm font-medium">{{ store.name }}</span></label><p v-if="!stores.length" class="text-sm text-slate-500">No stores are configured.</p></div></section>
                <div class="flex justify-end gap-3"><Link href="/users" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold">Cancel</Link><button :disabled="form.processing" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Save user' }}</button></div>
            </form>
        </div>
    </AppLayout>
</template>
