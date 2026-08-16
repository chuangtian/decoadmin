<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

const props = defineProps<{ email: string; token: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Set new password" />
    <AuthLayout title="Choose a new password" description="Use a strong password you haven’t used for this account before.">
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Work email</label><input id="email" v-model="form.email" type="email" autocomplete="username" required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"><p v-if="form.errors.email" class="mt-2 text-sm text-rose-600">{{ form.errors.email }}</p></div>
            <div><label for="password" class="mb-2 block text-sm font-semibold text-slate-700">New password</label><input id="password" v-model="form.password" type="password" autocomplete="new-password" required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"><p v-if="form.errors.password" class="mt-2 text-sm text-rose-600">{{ form.errors.password }}</p></div>
            <div><label for="password_confirmation" class="mb-2 block text-sm font-semibold text-slate-700">Confirm password</label><input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"></div>
            <button type="submit" :disabled="form.processing" class="h-12 w-full rounded-xl bg-slate-950 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">Reset password</button>
        </form>
    </AuthLayout>
</template>
