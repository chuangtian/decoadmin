<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

defineProps<{ status?: string }>();
const form = useForm({});
const submit = () => form.post('/email/verification-notification');
</script>

<template>
    <Head title="Verify email" />
    <AuthLayout title="Verify your email" description="Before entering the workspace, confirm your email using the secure link we sent you.">
        <div v-if="status === 'verification-link-sent'" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-700">A new verification link has been sent to your email address.</div>
        <button type="button" :disabled="form.processing" class="h-12 w-full rounded-xl bg-slate-950 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60" @click="submit">Resend verification email</button>
        <Link href="/logout" method="post" as="button" class="mt-5 w-full text-center text-sm font-semibold text-slate-500 hover:text-slate-800">Sign out</Link>
    </AuthLayout>
</template>
