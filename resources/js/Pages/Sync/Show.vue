<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SyncJobDetail } from '../../types';

const props = defineProps<{ syncJob: { data: SyncJobDetail } }>();
const dateLabel = (value: string | null) => value
    ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'long', timeStyle: 'medium' }).format(new Date(value))
    : '—';
const typeLabel = (type: string) => ({ products: '商品', orders: '订单', customers: '客户', inventory: '库存' }[type] ?? type);
const statusLabel = (status: string) => ({ pending: '待处理', queued: '已入队', running: '执行中', completed: '已完成', failed: '失败', cancelled: '已取消' }[status] ?? status);
const statusClass = (status: string) => ({ pending: 'bg-slate-50 text-slate-700 ring-slate-200', queued: 'bg-violet-50 text-violet-700 ring-violet-100', running: 'bg-blue-50 text-blue-700 ring-blue-100', completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100', failed: 'bg-rose-50 text-rose-700 ring-rose-100', cancelled: 'bg-slate-100 text-slate-500 ring-slate-200' }[status] ?? 'bg-slate-50 text-slate-600 ring-slate-200');
const logClass = (level: string) => ({ info: 'bg-blue-500', success: 'bg-emerald-500', warning: 'bg-amber-500', error: 'bg-rose-500' }[level] ?? 'bg-slate-400');
const result = computed(() => props.syncJob.data.result);
const isRealDataSync = computed(() => ['products', 'orders'].includes(props.syncJob.data.type));
const resultTitle = computed(() => props.syncJob.data.type === 'orders' ? '订单同步结果' : '商品同步结果');
const resultEyebrow = computed(() => props.syncJob.data.type === 'orders' ? 'Order Sync Result' : 'Product Sync Result');
const recordsLabel = computed(() => props.syncJob.data.type === 'orders' ? '订单数量' : '商品数量');
const formattedResult = computed(() => JSON.stringify(result.value, null, 2));
const durationLabel = computed(() => {
    const duration = result.value?.metadata.duration_ms;

    if (typeof duration !== 'number') return '—';
    if (duration < 1000) return `${duration} ms`;

    return `${(duration / 1000).toFixed(2)} s`;
});
</script>

<template>
    <Head :title="`同步任务 · ${typeLabel(syncJob.data.type)}`" />
    <AppLayout :breadcrumbs="[{ label: 'Shopify' }, { label: '数据同步', href: '/sync' }, { label: syncJob.data.uuid }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/sync" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回同步任务</Link>
            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0"><p class="text-sm font-semibold text-emerald-700">Sync Job</p><h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">{{ typeLabel(syncJob.data.type) }}同步</h2><p class="mt-2 break-all font-mono text-xs text-slate-400">{{ syncJob.data.uuid }}</p></div>
                <span class="inline-flex w-fit rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="statusClass(syncJob.data.status)">{{ statusLabel(syncJob.data.status) }}</span>
            </div>

            <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Store</p><p class="mt-3 font-semibold text-slate-900">{{ syncJob.data.store.name }}</p><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ syncJob.data.store.shopify_domain }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Started At</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(syncJob.data.started_at) }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Finished At</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(syncJob.data.finished_at) }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Attempts</p><p class="mt-3 font-semibold text-slate-900">{{ syncJob.data.attempts }} / {{ syncJob.data.max_attempts }}</p></section><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">App Installation</p><p class="mt-3 font-semibold text-slate-900">{{ syncJob.data.app_installation?.app?.name ?? '未指定' }}</p><p v-if="syncJob.data.app_installation" class="mt-1 text-xs text-slate-400">{{ syncJob.data.app_installation.status }}</p></section></div>

            <section v-if="syncJob.data.error" class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5"><h3 class="font-semibold text-rose-900">错误信息</h3><pre class="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-rose-800">{{ syncJob.data.error }}</pre></section>

            <section v-if="isRealDataSync && result" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ resultEyebrow }}</p>
                    <h3 class="mt-1 text-xl font-semibold text-slate-950">{{ resultTitle }}</h3>
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <article class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">同步状态</p>
                        <p class="mt-2 font-semibold" :class="result.success ? 'text-emerald-700' : 'text-rose-700'">{{ result.success ? '成功' : '失败' }}</p>
                    </article>
                    <article class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ recordsLabel }}</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-950">{{ result.records_count }}</p>
                    </article>
                    <article class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">执行耗时</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-950">{{ durationLabel }}</p>
                    </article>
                </div>
                <p class="mt-4 text-sm text-slate-500">{{ result.message }}</p>
            </section>

            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h3 class="font-semibold text-slate-950">执行日志</h3><p class="mt-1 text-sm text-slate-500">记录同步任务从创建到完成的生命周期。</p><div v-if="syncJob.data.logs.length" class="mt-5 space-y-4"><article v-for="(log, index) in syncJob.data.logs" :key="`${log.at}-${index}`" class="flex gap-3"><span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="logClass(log.level)" /><div class="min-w-0"><p class="text-sm leading-6 text-slate-700">{{ log.message }}</p><p class="mt-0.5 text-xs text-slate-400">{{ dateLabel(log.at) }}</p></div></article></div><EmptyState v-else class="mt-4 border-0 p-4 shadow-none" title="暂无执行日志" description="任务开始执行后，生命周期日志会显示在这里。" icon="sync" /></section>

            <section v-if="result" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h3 class="font-semibold text-slate-950">执行结果</h3><p class="mt-1 text-sm text-slate-500">同步引擎返回的标准化结果。</p><pre class="mt-5 overflow-auto rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-200">{{ formattedResult }}</pre></section>
        </div>
    </AppLayout>
</template>
