<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface AppLog { id: number; uuid: string; action: string; action_label: string; store: { id: number; name: string } | null; actor: string; module: string | null; created_at: string | null }
const props = defineProps<{ logs: { data: AppLog[]; links: Array<{ url: string | null; label: string; active: boolean }> }; filters: { search: string } }>();
const form = useForm({ search: props.filters.search });
const search = () => form.get('/app-logs', { preserveState: true, replace: true });
const { formatDateTime } = useStoreDateTime();
</script>

<template>
    <Head title="应用日志" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: '应用日志' }]">
        <div class="mx-auto max-w-7xl">
            <header><p class="text-sm font-semibold text-emerald-700">App Center</p><h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">应用日志</h1><p class="mt-2 text-sm text-slate-500">集中查看应用安装、授权、模块配置和凭证变更记录。</p></header>
            <form class="mt-7 flex gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="search"><input v-model="form.search" type="search" placeholder="搜索动作、店铺或操作人" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /><button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white">搜索</button></form>
            <section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="logs.data.length" class="divide-y divide-slate-100"><article v-for="log in logs.data" :key="log.id" class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_180px_150px_180px] md:items-center"><div><p class="font-semibold text-slate-900">{{ log.action_label }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ log.action }} · {{ log.uuid }}</p></div><div><p class="text-xs text-slate-400">店铺</p><Link v-if="log.store" :href="`/stores/${log.store.id}`" class="mt-1 block text-sm font-semibold text-slate-700 hover:text-emerald-700">{{ log.store.name }}</Link><p v-else class="mt-1 text-sm text-slate-500">组织级</p></div><div><p class="text-xs text-slate-400">操作人</p><p class="mt-1 text-sm text-slate-700">{{ log.actor }}</p></div><time class="text-xs text-slate-500 md:text-right">{{ formatDateTime(log.created_at) }}</time></article></div>
                <EmptyState v-else class="border-0 shadow-none" title="暂无应用日志" description="应用安装、配置或凭证发生变化后会在这里留下审计记录。" icon="audit" />
            </section>
            <div v-if="logs.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in logs.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
        </div>
    </AppLayout>
</template>
