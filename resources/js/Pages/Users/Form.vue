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
    <Head :title="editing ? '编辑用户' : '添加用户'" />
    <AppLayout :breadcrumbs="[{ label: '协作管理' }, { label: '用户管理', href: '/users' }, { label: editing ? '编辑用户' : '添加用户' }]">
        <div class="mx-auto max-w-4xl">
            <Link href="/users" class="text-sm font-medium text-slate-500 hover:text-slate-900">← 返回用户列表</Link>
            <div class="mt-4"><h2 class="text-2xl font-semibold">{{ editing ? '编辑用户' : '添加用户' }}</h2><p class="mt-1 text-sm text-slate-500">统一管理用户身份、组织角色和店铺访问范围。</p></div>
            <form class="mt-6 space-y-6" @submit.prevent="submit">
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="font-semibold">基本信息</h3>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <label class="text-sm font-medium">姓名<input v-model="form.name" required class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-indigo-500" /><span class="mt-1 block text-xs text-red-600">{{ form.errors.name }}</span></label>
                        <label class="text-sm font-medium">邮箱<input v-model="form.email" required type="email" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-indigo-500" /><span class="mt-1 block text-xs text-red-600">{{ form.errors.email }}</span></label>
                        <label class="text-sm font-medium">密码<input v-model="form.password" :required="!editing" type="password" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-indigo-500" :placeholder="editing ? '留空则保持当前密码' : '至少 8 个字符'" /><span class="mt-1 block text-xs text-red-600">{{ form.errors.password }}</span></label>
                        <label class="text-sm font-medium">状态<select v-model="form.status" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal"><option value="active">启用</option><option value="inactive">停用</option></select></label>
                    </div>
                </section>
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h3 class="font-semibold">角色</h3><p class="mt-1 text-sm text-slate-500">用户将继承所有已选组织角色的权限。</p><div class="mt-4 grid gap-3 sm:grid-cols-2"><label v-for="role in roles.data" :key="role.id" class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.role_ids" :value="role.id" type="checkbox" class="mt-1" /><span><span class="block text-sm font-medium">{{ role.name }}</span><span class="text-xs text-slate-500">{{ role.description }}</span></span></label></div></section>
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h3 class="font-semibold">店铺访问</h3><p class="mt-1 text-sm text-slate-500">只有加入店铺的用户才能执行店铺范围操作。</p><div class="mt-4 grid gap-3 sm:grid-cols-2"><label v-for="store in stores" :key="store.id" class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3"><input v-model="form.store_ids" :value="store.id" type="checkbox" /><span class="text-sm font-medium">{{ store.name }}</span></label><p v-if="!stores.length" class="text-sm text-slate-500">尚未配置店铺。</p></div></section>
                <div class="flex justify-end gap-3"><Link href="/users" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold">取消</Link><button :disabled="form.processing" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ form.processing ? '保存中…' : '保存用户' }}</button></div>
            </form>
        </div>
    </AppLayout>
</template>
