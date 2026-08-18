<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { WebhookEventDetail } from '../../types';

const props = defineProps<{ event: { data: WebhookEventDetail }; canRetry: boolean }>();
const dateLabel = (value: string | null) => value
    ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'long', timeStyle: 'medium' }).format(new Date(value))
    : '—';
const statusLabel = (status: string) => ({ received: '已接收', queued: '已入队', processing: '处理中', processed: '已处理', failed: '处理失败', retrying: '重试排队中' }[status] ?? status);
const statusClass = (status: string) => ({ received: 'bg-slate-50 text-slate-700 ring-slate-200', queued: 'bg-violet-50 text-violet-700 ring-violet-100', processing: 'bg-blue-50 text-blue-700 ring-blue-100', processed: 'bg-emerald-50 text-emerald-700 ring-emerald-100', failed: 'bg-rose-50 text-rose-700 ring-rose-100', retrying: 'bg-amber-50 text-amber-700 ring-amber-100' }[status] ?? 'bg-slate-50 text-slate-600 ring-slate-200');
const durationLabel = (value: number | null) => value === null ? '—' : value < 1000 ? `${value} ms` : `${(value / 1000).toFixed(2)} s`;
const retry = () => {
    if (window.confirm('确认将这个失败的 Webhook Event 重新加入处理队列吗？')) {
        router.post(`/webhooks/${props.event.data.id}/retry`, {}, { preserveScroll: true });
    }
};
const formattedPayload = JSON.stringify(props.event.data.payload, null, 2);
const formattedHeaders = JSON.stringify(props.event.data.headers, null, 2);
</script>

<template>
    <Head :title="`Webhook · ${event.data.topic}`" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: 'Webhook Events', href: '/webhooks' }, { label: event.data.topic }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/webhooks" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回 Webhook Events</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0"><p class="text-sm font-semibold text-emerald-700">Webhook Event</p><h2 class="mt-1 break-words text-3xl font-semibold tracking-tight text-slate-950">{{ event.data.topic }}</h2><p class="mt-2 break-all font-mono text-xs text-slate-400">{{ event.data.webhook_id }}</p></div>
                <div class="flex shrink-0 items-center gap-3"><span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="statusClass(event.data.status)">{{ statusLabel(event.data.status) }}</span><button v-if="canRetry" type="button" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800" @click="retry">Retry</button></div>
            </div>

            <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Store</p><p class="mt-3 font-semibold text-slate-900">{{ event.data.store.name }}</p><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ event.data.store.shopify_domain }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Received At</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(event.data.received_at) }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Processed At</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(event.data.processed_at) }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Processing Time</p><p class="mt-3 font-semibold text-slate-900">{{ durationLabel(event.data.processing_duration_ms) }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Attempts / API</p><p class="mt-3 font-semibold text-slate-900">{{ event.data.attempts }} / {{ event.data.api_version || '—' }}</p></section></div>

            <section v-if="event.data.processing_result === 'unsupported'" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5"><h3 class="font-semibold text-amber-900">Unsupported Event</h3><p class="mt-2 text-sm leading-6 text-amber-800">{{ event.data.unsupported_reason }}</p></section>

            <section v-if="event.data.handler" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Handler</p><p class="mt-2 break-all font-mono text-sm text-slate-700">{{ event.data.handler }}</p></section>

            <section v-if="event.data.last_error" class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5"><h3 class="font-semibold text-rose-900">错误信息</h3><pre class="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-rose-800">{{ event.data.last_error }}</pre><p v-if="event.data.next_retry_at" class="mt-3 text-xs text-rose-700">下次重试：{{ dateLabel(event.data.next_retry_at) }}</p></section>

            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="font-semibold text-slate-950">原始 Payload</h3><p class="mt-1 text-sm text-slate-500">Payload 在数据库中加密保存，仅在授权详情页解密展示。</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="event.data.payload_integrity_valid ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-rose-50 text-rose-700 ring-rose-100'">{{ event.data.payload_integrity_valid ? '完整性校验通过' : '完整性校验失败' }}</span></div><pre class="mt-5 max-h-[520px] overflow-auto rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-200">{{ formattedPayload }}</pre></section>

            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h3 class="font-semibold text-slate-950">安全请求头</h3><p class="mt-1 text-sm text-slate-500">仅保存定位事件所需的请求头，不保存 HMAC、Token 或 Secret。</p><pre class="mt-5 overflow-auto rounded-xl bg-slate-50 p-4 text-xs leading-6 text-slate-700">{{ formattedHeaders }}</pre></section>
        </div>
    </AppLayout>
</template>
