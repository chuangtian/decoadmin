<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

defineProps<{ status?: string }>();
const form = useForm({});
const submit = () => form.post('/email/verification-notification');
</script>

<template>
    <Head title="验证邮箱" />
    <AuthLayout title="验证邮箱" description="进入工作台前，请点击我们发送的安全链接完成邮箱验证。">
        <div v-if="status === 'verification-link-sent'" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-700">新的验证链接已发送到你的邮箱。</div>
        <button type="button" :disabled="form.processing" class="h-12 w-full rounded-xl bg-slate-950 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60" @click="submit">重新发送验证邮件</button>
        <Link href="/logout" method="post" as="button" class="mt-5 w-full text-center text-sm font-semibold text-slate-500 hover:text-slate-800">退出登录</Link>
    </AuthLayout>
</template>
