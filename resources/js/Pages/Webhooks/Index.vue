<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PaginatedResource, WebhookEventStatus, WebhookEventSummary } from '../../types';
import { formatDateTimeInTimezone } from '../../composables/useStoreDateTime';

const props = defineProps<{
    events: PaginatedResource<WebhookEventSummary>;
    filters: { topic: string; status: string; store_id: number | null };
    stores: Array<{ id: number; name: string }>;
    statuses: WebhookEventStatus[];
}>();

const form = useForm({
    topic: props.filters.topic,
    status: props.filters.status,
    store_id: props.filters.store_id ? String(props.filters.store_id) : '',
});
const search = () => form.get('/webhooks', { preserveState: true, replace: true });
const clearFilters = () => {
    form.topic = '';
    form.status = '';
    form.store_id = '';
    search();
};
const dateLabel = (value: string | null, timezone: string) => formatDateTimeInTimezone(value, timezone);
const statusLabel = (status: string) => ({
    received: '已接收',
    queued: '已入队',
    processing: '处理中',
    processed: '已处理',
    failed: '处理失败',
    retrying: '重试排队中',
}[status] ?? status);
const statusClass = (status: string) => ({
    received: 'bg-slate-50 text-slate-700 ring-slate-200',
    queued: 'bg-violet-50 text-violet-700 ring-violet-100',
    processing: 'bg-blue-50 text-blue-700 ring-blue-100',
    processed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    failed: 'bg-rose-50 text-rose-700 ring-rose-100',
    retrying: 'bg-amber-50 text-amber-700 ring-amber-100',
}[status] ?? 'bg-slate-50 text-slate-600 ring-slate-200');
const hasFilters = () => Boolean(props.filters.topic || props.filters.status || props.filters.store_id);
</script>

<template>
    <Head title="Webhook 事件" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: 'Webhook 事件' }]">
        <div class="mx-auto max-w-7xl">
            <div class="mb-7">
                <p class="text-sm font-semibold text-emerald-700">Webhook 中心</p>
                <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Webhook 事件</h2>
                <p class="mt-2 text-sm text-slate-500">查看 Shopify 推送事件、处理状态和失败信息，并对失败事件重新入队。</p>
            </div>

            <form class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(0,1fr)_180px_220px_auto]" @submit.prevent="search">
                <label class="block"><span class="sr-only">搜索事件类型</span><input v-model="form.topic" type="search" placeholder="搜索事件类型，例如 orders/create" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /></label>
                <label class="block"><span class="sr-only">状态过滤</span><select v-model="form.status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option value="">全部状态</option><option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
                <label class="block"><span class="sr-only">店铺过滤</span><select v-model="form.store_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option value="">全部授权店铺</option><option v-for="store in stores" :key="store.id" :value="String(store.id)">{{ store.name }}</option></select></label>
                <div class="flex gap-2"><button type="submit" :disabled="form.processing" class="flex-1 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50 lg:flex-none">筛选</button><button v-if="hasFilters()" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="clearFilters">清除</button></div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="events.data.length">
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[920px] text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3.5">事件类型</th><th class="px-5 py-3.5">店铺</th><th class="px-5 py-3.5">状态</th><th class="px-5 py-3.5">接收时间</th><th class="px-5 py-3.5">处理时间</th><th class="px-5 py-3.5 text-right">操作</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="event in events.data" :key="event.id" class="transition hover:bg-slate-50/70"><td class="px-5 py-4"><Link :href="`/webhooks/${event.id}`" class="font-semibold text-slate-900 hover:text-emerald-700">{{ event.topic }}</Link><p class="mt-1 font-mono text-[11px] text-slate-400">{{ event.webhook_id }}</p></td><td class="px-5 py-4"><p class="font-medium text-slate-800">{{ event.store.name }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ event.store.shopify_domain }}</p></td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(event.status)">{{ statusLabel(event.status) }}</span><p v-if="event.processing_result === 'unsupported'" class="mt-1.5 text-xs font-medium text-amber-700">暂不支持</p></td><td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(event.received_at, event.store.timezone) }}</td><td class="px-5 py-4 text-xs text-slate-500">{{ dateLabel(event.processed_at, event.store.timezone) }}</td><td class="px-5 py-4 text-right"><Link :href="`/webhooks/${event.id}`" class="rounded-lg px-3 py-2 font-semibold text-emerald-700 transition hover:bg-emerald-50">查看详情</Link></td></tr></tbody>
                        </table>
                    </div>
                    <div class="divide-y divide-slate-100 md:hidden"><article v-for="event in events.data" :key="event.id" class="p-5"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><Link :href="`/webhooks/${event.id}`" class="block truncate font-semibold text-slate-900">{{ event.topic }}</Link><p class="mt-1 truncate font-mono text-[11px] text-slate-400">{{ event.webhook_id }}</p></div><div class="text-right"><span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClass(event.status)">{{ statusLabel(event.status) }}</span><p v-if="event.processing_result === 'unsupported'" class="mt-1.5 text-xs font-medium text-amber-700">暂不支持</p></div></div><div class="mt-4 rounded-xl bg-slate-50 p-3"><p class="font-medium text-slate-800">{{ event.store.name }}</p><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ event.store.shopify_domain }}</p></div><dl class="mt-3 grid grid-cols-2 gap-3 text-xs text-slate-500"><div><dt>接收时间</dt><dd class="mt-1 text-slate-700">{{ dateLabel(event.received_at, event.store.timezone) }}</dd></div><div><dt>处理时间</dt><dd class="mt-1 text-slate-700">{{ dateLabel(event.processed_at, event.store.timezone) }}</dd></div></dl><Link :href="`/webhooks/${event.id}`" class="mt-4 inline-flex text-sm font-semibold text-emerald-700">查看详情 →</Link></article></div>
                </div>
                <EmptyState v-else class="border-0 shadow-none" :title="hasFilters() ? '没有匹配的 Webhook 事件' : '尚未收到 Webhook 事件'" :description="hasFilters() ? '请调整事件类型、状态或店铺过滤条件。' : 'Shopify 推送事件并通过 HMAC 验证后，会安全地显示在这里。'" icon="webhooks" />
            </div>

            <div v-if="events.links.length > 3" class="mt-6 flex flex-wrap gap-2"><Link v-for="link in events.links" :key="link.label" :href="link.url ?? ''" class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600'" v-html="link.label" /></div>
        </div>
    </AppLayout>
</template>
