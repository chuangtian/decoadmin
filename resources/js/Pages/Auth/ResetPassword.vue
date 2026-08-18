<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

const props = defineProps<{ email: string; token: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="设置新密码" />
    <AuthLayout title="设置新密码" description="请使用一个此前未在此账号使用过的高强度密码。">
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-semibold text-slate-700">工作邮箱</label><input id="email" v-model="form.email" type="email" autocomplete="username" required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"><p v-if="form.errors.email" class="mt-2 text-sm text-rose-600">{{ form.errors.email }}</p></div>
            <div><label for="password" class="mb-2 block text-sm font-semibold text-slate-700">新密码</label><input id="password" v-model="form.password" type="password" autocomplete="new-password" required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"><p v-if="form.errors.password" class="mt-2 text-sm text-rose-600">{{ form.errors.password }}</p></div>
            <div><label for="password_confirmation" class="mb-2 block text-sm font-semibold text-slate-700">确认密码</label><input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="h-12 w-full rounded-xl border border-slate-200 px-4 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"></div>
            <button type="submit" :disabled="form.processing" class="h-12 w-full rounded-xl bg-slate-950 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">重置密码</button>
        </form>
    </AuthLayout>
</template>
