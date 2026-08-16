<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Permission, ResourceCollection } from '../../types';

const props = defineProps<{ permissions: ResourceCollection<Permission> }>();
const grouped = computed(() => props.permissions.data.reduce<Record<string, Permission[]>>((groups, permission) => {
    (groups[permission.group] ??= []).push(permission);
    return groups;
}, {}));
const groupLabels: Record<string, string> = {
    apps: '应用', audit: '审计', customers: '客户', inventory: '库存', orders: '订单', organization: '组织', products: '商品', roles: '角色', shopify: 'Shopify', store: '店铺', sync: '数据同步', system: '系统', users: '用户', webhooks: 'Webhooks',
};
</script>

<template>
    <Head title="权限管理" />
    <AppLayout>
        <div><p class="text-sm font-medium text-emerald-700">访问控制</p><h2 class="mt-1 text-2xl font-semibold">权限管理</h2><p class="mt-1 text-sm text-slate-500">查看系统中可分配给角色的能力清单。</p></div>
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <section v-for="(items, group) in grouped" :key="group" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between"><h3 class="font-semibold">{{ groupLabels[group] ?? group }}</h3><span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-500">{{ items.length }}</span></div>
                <div class="mt-4 divide-y divide-slate-100"><div v-for="permission in items" :key="permission.id" class="py-3"><code class="text-xs font-semibold text-indigo-700">{{ permission.slug }}</code><p class="mt-1 text-xs leading-5 text-slate-500">{{ permission.description }}</p></div></div>
            </section>
        </div>
    </AppLayout>
</template>
