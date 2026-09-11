<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface ApplicationConfiguration {
    installation_id: number;
    installation_status: string;
    connection_status: string | null;
    app: { id: number; name: string; handle: string; description: string | null };
    category: string;
    description: string;
    configuration_status: string;
    configuration_status_label: string;
    management_url: string | null;
    action_label: string;
    unavailable_reason: string | null;
    metrics: Array<{ label: string; value: string }>;
}

defineProps<{
    store: { id: number; name: string; shopify_domain: string } | null;
    applications: ApplicationConfiguration[];
}>();

const statusClass = (status: string) => ({
    configured: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    partial: 'bg-blue-50 text-blue-700 ring-blue-200',
    disabled: 'bg-slate-100 text-slate-600 ring-slate-200',
    restricted: 'bg-rose-50 text-rose-700 ring-rose-200',
    unavailable: 'bg-rose-50 text-rose-700 ring-rose-200',
    unsupported: 'bg-slate-100 text-slate-600 ring-slate-200',
}[status] ?? 'bg-amber-50 text-amber-700 ring-amber-200');
</script>

<template>
    <Head title="应用配置" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: '应用配置' }]">
        <div class="mx-auto max-w-7xl">
            <EmptyState v-if="!store" title="请先选择店铺" description="应用配置按店铺隔离；选择有权限的店铺后可查看已安装应用。" icon="stores" />
            <template v-else>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <header><p class="text-sm font-semibold text-emerald-700">{{ store.name }}</p><h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">应用配置</h1><p class="mt-2 text-sm text-slate-500">管理当前店铺已安装应用的业务配置，每个应用拥有独立工作台。</p></header>
                <Link :href="`/stores/${store.id}?tab=apps`" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm">管理安装</Link>
            </div>

            <div v-if="applications.length" class="mt-7 grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <article v-for="application in applications" :key="application.installation_id" class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex-1 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-base font-bold text-emerald-700">{{ application.app.name.charAt(0).toUpperCase() }}</span>
                                <div class="min-w-0"><p class="truncate text-xs font-semibold uppercase tracking-[.12em] text-slate-400">{{ application.category }}</p><h2 class="mt-1 truncate text-base font-semibold text-slate-950" :title="application.app.name">{{ application.app.name }}</h2></div>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold ring-1" :class="statusClass(application.configuration_status)">{{ application.configuration_status_label }}</span>
                        </div>
                        <p class="mt-4 line-clamp-2 min-h-10 text-xs leading-5 text-slate-500">{{ application.description }}</p>
                        <div class="mt-4 grid grid-cols-3 divide-x divide-slate-200 overflow-hidden rounded-xl bg-slate-50">
                            <div v-for="metric in application.metrics" :key="metric.label" class="min-w-0 px-2.5 py-2.5"><p class="truncate text-xs font-semibold text-slate-400" :title="metric.label">{{ metric.label }}</p><p class="mt-1 truncate text-xs font-semibold text-slate-800" :title="metric.value">{{ metric.value }}</p></div>
                        </div>
                        <p v-if="application.unavailable_reason" class="mt-3 rounded-xl bg-rose-50 px-3 py-2.5 text-xs leading-5 text-rose-700">{{ application.unavailable_reason }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-3.5">
                        <div class="min-w-0"><p class="truncate font-mono text-xs text-slate-500" :title="application.app.handle">{{ application.app.handle }}</p><p class="mt-0.5 truncate text-xs text-slate-400">连接：{{ application.connection_status || '未知' }}</p></div>
                        <Link v-if="application.management_url" :href="application.management_url" class="shrink-0 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-800">{{ application.action_label }} →</Link>
                        <span v-else class="shrink-0 text-xs font-semibold text-slate-400">暂不可管理</span>
                    </div>
                </article>
            </div>
            <EmptyState v-else class="mt-7" title="当前店铺尚未安装应用" description="请先在店铺应用页完成 Shopify 授权；安装成功后，应用会在这里提供独立配置入口。" icon="apps"><Link :href="`/stores/${store.id}?tab=apps`" class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">前往安装</Link></EmptyState>
            </template>
        </div>
    </AppLayout>
</template>
