<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PaginatedResource, ShopifyApp } from '../../types';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

const props = defineProps<{
    apps: PaginatedResource<ShopifyApp>;
    filters: { search: string; status: string };
    statusOptions: string[];
}>();

const form = useForm({ search: props.filters.search, status: props.filters.status });
const search = () => form.get('/app-center', { preserveState: true, replace: true });
const clearFilters = () => {
    form.search = '';
    form.status = '';
    search();
};
const { formatDateTime: dateLabel } = useStoreDateTime();
const typeLabel = (type: string) => ({ custom: '自定义应用', public: '公开应用', private: '私有应用' }[type] ?? type);
const statusLabel = (status: string) => ({ active: '启用', inactive: '停用', draft: '草稿', disabled: '已禁用' }[status] ?? status);
const statusClass = (status: string) => status === 'active'
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
    : status === 'disabled'
        ? 'bg-rose-50 text-rose-700 ring-rose-100'
        : 'bg-amber-50 text-amber-700 ring-amber-100';
</script>

<template>
    <Head title="应用列表" />
    <AppLayout :breadcrumbs="[{ label: '应用中心' }, { label: '应用列表' }]">
        <div class="mx-auto max-w-7xl">
            <div class="mb-7">
                <p class="text-sm font-semibold text-emerald-700">应用中心</p>
                <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">应用列表</h2>
                <p class="mt-2 text-sm text-slate-500">查看当前组织可用的 Shopify 应用，以及有权访问店铺中的安装状态。</p>
            </div>

            <form class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_220px_auto]" @submit.prevent="search">
                <label class="block">
                    <span class="sr-only">搜索应用</span>
                    <input v-model="form.search" type="search" placeholder="搜索应用名称或 Slug" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" />
                </label>
                <label class="block">
                    <span class="sr-only">状态过滤</span>
                    <select v-model="form.status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100">
                        <option value="">全部状态</option>
                        <option v-for="status in statusOptions" :key="status" :value="status">{{ statusLabel(status) }}</option>
                    </select>
                </label>
                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing" class="flex-1 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50 sm:flex-none">搜索</button>
                    <button v-if="filters.search || filters.status" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="clearFilters">清除</button>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="apps.data.length">
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[880px] text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                <tr><th class="px-5 py-3.5">应用名称</th><th class="px-5 py-3.5">类型</th><th class="px-5 py-3.5">状态</th><th class="px-5 py-3.5 text-center">安装数量</th><th class="px-5 py-3.5">创建时间</th><th class="px-5 py-3.5 text-right">操作</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="app in apps.data" :key="app.id" class="transition hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-sm font-bold text-emerald-700">{{ app.name.charAt(0).toUpperCase() }}</span><div class="min-w-0"><Link :href="`/apps/${app.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ app.name }}</Link><p class="mt-0.5 font-mono text-xs text-slate-400">{{ app.slug }}</p></div></div></td>
                                    <td class="px-5 py-4 font-medium text-slate-600">{{ typeLabel(app.type) }}</td>
                                    <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(app.status)">{{ statusLabel(app.status) }}</span></td>
                                    <td class="px-5 py-4 text-center text-lg font-semibold text-slate-800">{{ app.installations_count }}</td>
                                    <td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(app.created_at) }}</td>
                                    <td class="px-5 py-4 text-right"><Link :href="`/apps/${app.id}`" class="rounded-lg px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50">查看详情</Link></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="divide-y divide-slate-100 md:hidden">
                        <article v-for="app in apps.data" :key="app.id" class="p-5">
                            <div class="flex items-start justify-between gap-3"><div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-sm font-bold text-emerald-700">{{ app.name.charAt(0).toUpperCase() }}</span><div class="min-w-0"><Link :href="`/apps/${app.id}`" class="block truncate font-semibold text-slate-900">{{ app.name }}</Link><p class="mt-0.5 truncate font-mono text-xs text-slate-400">{{ app.slug }}</p></div></div><span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(app.status)">{{ statusLabel(app.status) }}</span></div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm"><div><dt class="text-xs text-slate-400">类型</dt><dd class="mt-1 font-medium text-slate-700">{{ typeLabel(app.type) }}</dd></div><div><dt class="text-xs text-slate-400">安装数量</dt><dd class="mt-1 font-semibold text-slate-900">{{ app.installations_count }}</dd></div></dl>
                            <Link :href="`/apps/${app.id}`" class="mt-4 inline-flex text-sm font-semibold text-emerald-700">查看详情 →</Link>
                        </article>
                    </div>
                </div>
                <EmptyState v-else class="border-0 shadow-none" :title="filters.search || filters.status ? '没有匹配的应用' : '应用注册中心暂无应用'" :description="filters.search || filters.status ? '请调整搜索关键词或状态过滤条件。' : '完成 Shopify OAuth 后，真实应用和安装信息会显示在这里。'" icon="apps" />
            </div>

            <div v-if="apps.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in apps.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
        </div>
    </AppLayout>
</template>
