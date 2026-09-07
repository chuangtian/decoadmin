<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Permission, ResourceCollection, Role } from '../../types';

const props = defineProps<{ role: { data: Role }; permissions: ResourceCollection<Permission> }>();
const selected = props.role.data.permissions?.map((permission) => permission.id) ?? [];
const form = useForm({ permission_ids: selected });
const grouped = computed(() => props.permissions.data.reduce<Record<string, Permission[]>>((groups, permission) => {
    (groups[permission.group] ??= []).push(permission);
    return groups;
}, {}));
const submit = () => form.put(`/roles/${props.role.data.id}/permissions`);
const groupLabels: Record<string, string> = {
    apps: '应用', audit: '审计', codex: 'Codex 插件', customers: '客户', inventory: '库存', orders: '订单', organization: '组织', products: '商品', roles: '角色', shopify: 'Shopify', store: '店铺', sync: '数据同步', system: '系统', users: '用户', webhooks: 'Webhook',
};
</script>

<template>
    <Head :title="role.data.name" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '角色权限', href: '/roles' }, { label: role.data.name }]">
        <div class="mx-auto max-w-5xl">
            <Link href="/roles" class="text-sm font-medium text-slate-500 hover:text-slate-900">← 返回角色列表</Link>
            <div class="mt-4 flex items-start justify-between"><div><div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ role.data.name }}</h2><span v-if="role.data.is_system" class="rounded bg-slate-200 px-2 py-1 text-xs font-semibold text-slate-600">系统角色</span></div><p class="mt-2 text-sm text-slate-500">{{ role.data.description }}</p></div><code class="rounded-md bg-slate-900 px-3 py-2 text-xs text-slate-100">{{ role.data.slug }}</code></div>
            <form class="mt-6" @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <section v-for="(items, group) in grouped" :key="group" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold">{{ groupLabels[group] ?? group }}</h3>
                        <div class="mt-4 space-y-3"><label v-for="permission in items" :key="permission.id" class="flex items-start gap-3 text-sm"><input v-model="form.permission_ids" :value="permission.id" type="checkbox" class="mt-1" /><span><span class="block font-medium">{{ permission.slug }}</span><span class="text-xs text-slate-400">{{ permission.name }}</span></span></label></div>
                    </section>
                </div>
                <div class="mt-6 flex justify-end"><button :disabled="form.processing" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">保存权限</button></div>
            </form>
        </div>
    </AppLayout>
</template>
