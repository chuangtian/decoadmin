<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

defineProps<{ status?: string }>();
const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>

<template>
    <Head title="Forgot password" />
    <AuthLayout title="Reset your password" description="Enter your work email and we’ll send you a secure reset link.">
        <div v-if="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Work email</label><input id="email" v-model="form.email" type="email" autocomplete="username" autofocus required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="you@company.com"><p v-if="form.errors.email" class="mt-2 text-sm text-rose-600">{{ form.errors.email }}</p></div>
            <button type="submit" :disabled="form.processing" class="h-12 w-full rounded-xl bg-slate-950 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">Email reset link</button>
        </form>
        <Link href="/login" class="mt-6 flex items-center justify-center text-sm font-semibold text-slate-600 hover:text-slate-900">← Back to sign in</Link>
    </AuthLayout>
</template>
