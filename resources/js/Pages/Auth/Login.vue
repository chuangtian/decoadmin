<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

defineProps<{ canResetPassword: boolean; status?: string }>();
const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Sign in" />
    <AuthLayout title="Welcome back" description="Sign in to continue to your administration workspace.">
        <div v-if="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Work email</label><input id="email" v-model="form.email" type="email" autocomplete="username" autofocus required class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="you@company.com"><p v-if="form.errors.email" class="mt-2 text-sm text-rose-600">{{ form.errors.email }}</p></div>
            <div><div class="mb-2 flex items-center justify-between"><label for="password" class="text-sm font-semibold text-slate-700">Password</label><Link v-if="canResetPassword" href="/forgot-password" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Forgot password?</Link></div><input id="password" v-model="form.password" type="password" autocomplete="current-password" required class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"><p v-if="form.errors.password" class="mt-2 text-sm text-rose-600">{{ form.errors.password }}</p></div>
            <label class="flex cursor-pointer items-center gap-3 text-sm text-slate-600"><input v-model="form.remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-emerald-600">Keep me signed in on this device</label>
            <button type="submit" :disabled="form.processing" class="flex h-12 w-full items-center justify-center rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white shadow-lg shadow-slate-950/15 transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">{{ form.processing ? 'Signing in…' : 'Sign in' }}</button>
        </form>
        <p class="mt-8 text-center text-xs leading-5 text-slate-400">Access is limited to authorized team members. Activity may be logged for security.</p>
    </AuthLayout>
</template>
