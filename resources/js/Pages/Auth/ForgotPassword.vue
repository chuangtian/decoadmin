<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

defineProps<{ status?: string }>();
const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>

<template>
    <Head title="忘记密码" />
    <AuthLayout title="重置密码" description="输入工作邮箱，我们会发送安全的密码重置链接。">
        <div v-if="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-semibold text-slate-700">工作邮箱</label><input id="email" v-model="form.email" type="email" autocomplete="username" autofocus required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="you@company.com"><p v-if="form.errors.email" class="mt-2 text-sm text-rose-600">{{ form.errors.email }}</p></div>
            <button type="submit" :disabled="form.processing" class="h-12 w-full rounded-xl bg-slate-950 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">发送重置链接</button>
        </form>
        <Link href="/login" class="mt-6 flex items-center justify-center text-sm font-semibold text-slate-600 hover:text-slate-900">← 返回登录</Link>
    </AuthLayout>
</template>
