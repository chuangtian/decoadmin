<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface Installation {
    id: number;
    status: string;
    app: { id: number; name: string; handle: string } | null;
    store: { id: number; name: string; shopify_domain: string };
    installed_by: string | null;
    installed_at: string | null;
    uninstalled_at: string | null;
}

const props = defineProps<{
    installations: { data: Installation[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { search: string; status: string };
}>();
const form = useForm({ ...props.filters });
const submit = () => form.get('/app-installations', { preserveState: true, replace: true });
const clear = () => { form.search = ''; form.status = ''; submit(); };
const { formatDateTime } = useStoreDateTime();
const statusLabel = (status: string) => ({ active: '已安装', pending: '待处理', uninstalled: '已卸载', disabled: '已停用' }[status] ?? status);
const statusClass = (status: string) => status === 'active'
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
    : status === 'uninstalled'
        ? 'bg-slate-100 text-slate-600 ring-slate-200'
        : 'bg-amber-50 text-amber-700 ring-amber-100';
</script>

<template>
    <Head title="安装管理" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: '安装管理' }]">
        <div class="mx-auto max-w-7xl">
            <header class="mb-7">
                <p class="text-sm font-semibold text-emerald-700">App Center</p>
                <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">安装管理</h1>
                <p class="mt-2 text-sm text-slate-500">查看当前账号有权访问的店铺应用安装记录与授权状态。</p>
            </header>

            <form class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_190px_auto]" @submit.prevent="submit">
                <input v-model="form.search" type="search" placeholder="搜索应用、店铺或 Shopify 域名" class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" />
                <select v-model="form.status" class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100">
                    <option value="">全部状态</option><option value="active">已安装</option><option value="pending">待处理</option><option value="uninstalled">已卸载</option><option value="disabled">已停用</option>
                </select>
                <div class="flex gap-2"><button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white">筛选</button><button v-if="filters.search || filters.status" type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600" @click="clear">清除</button></div>
            </form>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="installations.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50/80 text-xs font-semibold text-slate-500"><tr><th class="px-5 py-4">应用</th><th class="px-5 py-4">店铺</th><th class="px-5 py-4">状态</th><th class="px-5 py-4">安装人</th><th class="px-5 py-4">安装时间</th><th class="px-5 py-4 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="installation in installations.data" :key="installation.id" class="hover:bg-slate-50/70">
                                <td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ installation.app?.name ?? '未知应用' }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ installation.app?.handle ?? '—' }}</p></td>
                                <td class="px-5 py-4"><Link :href="`/stores/${installation.store.id}?tab=apps`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ installation.store.name }}</Link><p class="mt-1 font-mono text-xs text-slate-400">{{ installation.store.shopify_domain }}</p></td>
                                <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(installation.status)">{{ statusLabel(installation.status) }}</span></td>
                                <td class="px-5 py-4 text-slate-600">{{ installation.installed_by ?? '系统' }}</td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ formatDateTime(installation.installed_at) }}</td>
                                <td class="px-5 py-4 text-right"><Link :href="`/stores/${installation.store.id}?tab=apps`" class="text-sm font-semibold text-emerald-700">管理安装</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else class="border-0 shadow-none" title="暂无安装记录" description="完成 Shopify 应用授权后，安装记录会显示在这里。" icon="installations" />
            </section>
            <div v-if="installations.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in installations.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
        </div>
    </AppLayout>
</template>
