<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AuditDataPanel from '../../Components/Audit/AuditDataPanel.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { AuditLogDetail, AuditLogResult } from '../../types';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

const props = defineProps<{ auditLog: AuditLogDetail }>();
const { formatDateTime: dateLabel } = useStoreDateTime();
const resultLabel = (result: AuditLogResult) => ({ success: '成功', warning: '需要关注', error: '异常', info: '已记录' }[result]);
const resultClass = (result: AuditLogResult) => ({
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    warning: 'bg-amber-50 text-amber-700 ring-amber-100',
    error: 'bg-rose-50 text-rose-700 ring-rose-100',
    info: 'bg-slate-100 text-slate-600 ring-slate-200',
}[result]);
</script>

<template>
    <Head :title="`审计日志 · ${auditLog.action_label}`" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '审计日志', href: '/audit-logs' }, { label: auditLog.action_label }]">
        <div class="mx-auto max-w-6xl">
            <Link href="/audit-logs" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回审计日志</Link>
            <section class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-emerald-700">{{ auditLog.category }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">{{ auditLog.action_label }}</h1>
                    <p class="mt-2 break-all font-mono text-xs text-slate-400">{{ auditLog.uuid }}</p>
                </div>
                <span class="inline-flex self-start rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="resultClass(auditLog.result)">{{ resultLabel(auditLog.result) }}</span>
            </section>

            <section class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold text-slate-400">操作人</p><p class="mt-3 font-semibold text-slate-900">{{ auditLog.actor?.name ?? '系统' }}</p><p class="mt-1 break-all text-xs text-slate-400">{{ auditLog.actor?.email ?? '后台自动执行' }}</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold text-slate-400">店铺范围</p><p class="mt-3 font-semibold text-slate-900">{{ auditLog.store?.name ?? '组织范围' }}</p><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ auditLog.store?.shopify_domain ?? '—' }}</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold text-slate-400">操作时间</p><p class="mt-3 text-sm font-semibold text-slate-900">{{ dateLabel(auditLog.created_at) }}</p><p class="mt-1 text-xs text-slate-400">不可修改</p></article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold text-slate-400">操作对象</p><p class="mt-3 font-semibold text-slate-900">{{ auditLog.subject?.type ?? '未指定' }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ auditLog.subject?.id ? `ID ${auditLog.subject.id}` : '—' }}</p></article>
            </section>

            <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><p class="text-xs font-semibold text-slate-400">IP 地址</p><p class="mt-2 font-mono text-sm text-slate-700">{{ auditLog.ip_address ?? '未记录' }}</p></div>
                    <div><p class="text-xs font-semibold text-slate-400">事件标识</p><p class="mt-2 break-all font-mono text-sm text-slate-700">{{ auditLog.action }}</p></div>
                </div>
                <div class="mt-5 border-t border-slate-100 pt-5"><p class="text-xs font-semibold text-slate-400">客户端信息</p><p class="mt-2 break-words text-sm leading-6 text-slate-600">{{ auditLog.user_agent ?? '未记录' }}</p></div>
            </section>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <AuditDataPanel title="变更前" description="操作执行前的脱敏字段。" :values="auditLog.old_values" tone="slate" />
                <AuditDataPanel title="变更后" description="操作完成后的脱敏字段。" :values="auditLog.new_values" tone="emerald" />
            </div>
            <AuditDataPanel class="mt-5" title="事件上下文" description="用于理解本次操作的脱敏辅助信息。" :values="auditLog.metadata" tone="blue" />

            <p class="mt-5 text-xs leading-5 text-slate-400">安全说明：敏感字段会在服务端隐藏，IP 地址仅展示脱敏结果；审计记录不能通过后台编辑或删除。</p>
        </div>
    </AppLayout>
</template>
