<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '../../Components/Layout/AppIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type HealthStatus = 'healthy' | 'warning' | 'unavailable' | 'unknown';
type OverallStatus = 'healthy' | 'warning' | 'degraded';

interface ServiceStatus {
    key: string;
    name: string;
    description: string;
    status: HealthStatus;
    metadata: Record<string, string | number | null>;
}

interface QueueStatus {
    name: string;
    label: string;
    pending: number | null;
    failed: number | null;
    status: HealthStatus;
}

interface IncidentStatus {
    key: string;
    label: string;
    count: number | null;
    latest_at: string | null;
    status: HealthStatus;
}

interface SystemStatusPayload {
    summary: { status: OverallStatus; checked_at: string };
    services: ServiceStatus[];
    queues: QueueStatus[];
    incidents: IncidentStatus[];
    runtime: {
        application: string;
        environment: string;
        application_version: string | null;
        laravel_version: string;
        php_version: string;
        debug_enabled: boolean;
        timezone: string;
    };
}

const props = defineProps<{ systemStatus: SystemStatusPayload }>();
const refreshing = ref(false);

const statusLabels: Record<HealthStatus | OverallStatus, string> = {
    healthy: '运行正常',
    warning: '需要关注',
    unavailable: '服务不可用',
    unknown: '状态未知',
    degraded: '部分服务异常',
};

const statusClasses: Record<HealthStatus | OverallStatus, string> = {
    healthy: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    warning: 'bg-amber-50 text-amber-700 ring-amber-200',
    unavailable: 'bg-rose-50 text-rose-700 ring-rose-200',
    unknown: 'bg-slate-100 text-slate-600 ring-slate-200',
    degraded: 'bg-rose-50 text-rose-700 ring-rose-200',
};

const dotClasses: Record<HealthStatus | OverallStatus, string> = {
    healthy: 'bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,.38)]',
    warning: 'bg-amber-500',
    unavailable: 'bg-rose-500',
    unknown: 'bg-slate-400',
    degraded: 'bg-rose-500',
};

const serviceIcons: Record<string, string> = {
    application: 'dashboard',
    database: 'products',
    redis: 'sync',
    horizon: 'business',
    scheduler: 'status',
    storage: 'audit',
};

const overallMessage = computed(() => ({
    healthy: '核心服务运行正常，当前未发现基础设施异常。',
    warning: '系统仍可使用，但有状态需要管理员关注。',
    degraded: '检测到基础服务不可用，请尽快检查运行环境。',
}[props.systemStatus.summary.status]));

const environmentLabel = computed(() => ({
    local: '本地开发环境',
    staging: '测试环境',
    production: '正式环境',
    testing: '自动测试环境',
}[props.systemStatus.runtime.environment] ?? props.systemStatus.runtime.environment));

const dateLabel = (value: string | null) => value
    ? new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    }).format(new Date(value))
    : '暂无记录';

const byteLabel = (value: string | number | null | undefined) => {
    if (typeof value !== 'number') return '暂不可用';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }
    return `${size.toFixed(unit > 1 ? 1 : 0)} ${units[unit]}`;
};

const serviceDetail = (service: ServiceStatus) => {
    if (service.key === 'horizon') {
        return `${service.metadata.running ?? 0} / ${service.metadata.masters ?? 0} 个主进程运行中`;
    }
    if (service.key === 'scheduler') {
        return service.metadata.last_heartbeat_at
            ? `最近心跳 ${dateLabel(String(service.metadata.last_heartbeat_at))}`
            : '尚未收到调度心跳';
    }
    if (service.key === 'storage') {
        return `可用空间 ${byteLabel(service.metadata.free_bytes)}`;
    }
    return service.status === 'healthy' ? '连接检测通过' : statusLabels[service.status];
};

const refresh = () => {
    refreshing.value = true;
    router.reload({
        only: ['systemStatus'],
        onFinish: () => { refreshing.value = false; },
    });
};
</script>

<template>
    <Head title="系统状态" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '系统状态' }]">
        <div class="mx-auto max-w-7xl">
            <section class="mb-7 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">系统管理</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">系统状态</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">查看应用、数据库、缓存、队列和定时任务的实时运行情况。页面仅展示脱敏后的健康信息。</p>
                </div>
                <button type="button" :disabled="refreshing" class="inline-flex items-center justify-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 lg:self-auto" @click="refresh">
                    <svg class="h-4 w-4" :class="{ 'animate-spin': refreshing }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12a8 8 0 1 1-2.34-5.66L20 8"/><path d="M20 3v5h-5"/></svg>
                    {{ refreshing ? '检测中…' : '重新检测' }}
                </button>
            </section>

            <section class="mb-6 overflow-hidden rounded-3xl bg-[#0d1828] p-6 text-white shadow-xl shadow-slate-900/8 sm:p-8">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-4">
                        <span class="mt-1 h-3 w-3 shrink-0 rounded-full" :class="dotClasses[systemStatus.summary.status]" />
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">整体运行状态</p>
                            <h2 class="mt-2 text-2xl font-semibold">{{ statusLabels[systemStatus.summary.status] }}</h2>
                            <p class="mt-2 text-sm text-slate-400">{{ overallMessage }}</p>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 lg:text-right">
                        <p class="text-xs text-slate-400">最近检测时间</p>
                        <p class="mt-1 text-sm font-semibold text-slate-100">{{ dateLabel(systemStatus.summary.checked_at) }}</p>
                    </div>
                </div>
            </section>

            <section class="mb-6">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div><h2 class="text-lg font-semibold text-slate-950">核心服务</h2><p class="mt-1 text-sm text-slate-500">服务状态由后台实时检测，不依赖前端推测。</p></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <article v-for="service in systemStatus.services" :key="service.key" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-600"><AppIcon :name="serviceIcons[service.key] ?? 'status'" :size="21" /></span>
                            <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClasses[service.status]"><span class="h-1.5 w-1.5 rounded-full" :class="dotClasses[service.status]" />{{ statusLabels[service.status] }}</span>
                        </div>
                        <h3 class="mt-5 font-semibold text-slate-950">{{ service.name }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ service.description }}</p>
                        <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-400">{{ serviceDetail(service) }}</p>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,.75fr)]">
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                        <h2 class="font-semibold text-slate-950">队列运行情况</h2>
                        <p class="mt-1 text-sm text-slate-500">待处理数量为当前积压，失败数量来自失败任务记录。</p>
                    </div>
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50/80 text-xs font-semibold text-slate-500"><tr><th class="px-6 py-3.5">队列</th><th class="px-6 py-3.5">待处理</th><th class="px-6 py-3.5">失败</th><th class="px-6 py-3.5 text-right">状态</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="queue in systemStatus.queues" :key="queue.name"><td class="px-6 py-4"><p class="font-semibold text-slate-900">{{ queue.label }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ queue.name }}</p></td><td class="px-6 py-4 font-semibold text-slate-700">{{ queue.pending ?? '—' }}</td><td class="px-6 py-4 font-semibold" :class="queue.failed ? 'text-rose-600' : 'text-slate-700'">{{ queue.failed ?? '—' }}</td><td class="px-6 py-4 text-right"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClasses[queue.status]">{{ statusLabels[queue.status] }}</span></td></tr></tbody>
                        </table>
                    </div>
                    <div class="divide-y divide-slate-100 sm:hidden"><article v-for="queue in systemStatus.queues" :key="queue.name" class="p-5"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-slate-900">{{ queue.label }}</p><p class="mt-1 font-mono text-xs text-slate-400">{{ queue.name }}</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="statusClasses[queue.status]">{{ statusLabels[queue.status] }}</span></div><dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm"><div><dt class="text-xs text-slate-400">待处理</dt><dd class="mt-1 font-semibold text-slate-800">{{ queue.pending ?? '—' }}</dd></div><div><dt class="text-xs text-slate-400">失败</dt><dd class="mt-1 font-semibold" :class="queue.failed ? 'text-rose-600' : 'text-slate-800'">{{ queue.failed ?? '—' }}</dd></div></dl></article></div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="font-semibold text-slate-950">运行环境</h2>
                    <p class="mt-1 text-sm text-slate-500">仅展示公开的版本与环境信息。</p>
                    <dl class="mt-5 divide-y divide-slate-100 text-sm">
                        <div class="flex items-center justify-between gap-4 py-3 first:pt-0"><dt class="text-slate-500">应用</dt><dd class="truncate font-semibold text-slate-900">{{ systemStatus.runtime.application }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500">环境</dt><dd class="font-semibold text-slate-900">{{ environmentLabel }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500">应用版本</dt><dd class="font-mono text-xs text-slate-700">{{ systemStatus.runtime.application_version || '未设置' }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500">Laravel</dt><dd class="font-mono text-xs text-slate-700">{{ systemStatus.runtime.laravel_version }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500">PHP</dt><dd class="font-mono text-xs text-slate-700">{{ systemStatus.runtime.php_version }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-3"><dt class="text-slate-500">调试模式</dt><dd class="font-semibold" :class="systemStatus.runtime.debug_enabled ? 'text-amber-600' : 'text-emerald-700'">{{ systemStatus.runtime.debug_enabled ? '已开启' : '已关闭' }}</dd></div>
                        <div class="flex items-center justify-between gap-4 pt-3"><dt class="text-slate-500">系统时区</dt><dd class="font-mono text-xs text-slate-700">{{ systemStatus.runtime.timezone }}</dd></div>
                    </dl>
                </section>
            </div>

            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div><h2 class="font-semibold text-slate-950">最近 24 小时异常</h2><p class="mt-1 text-sm text-slate-500">同步与 Webhook 数据仅统计当前组织内有权访问的店铺，不展示错误内容或敏感载荷。</p></div>
                <div class="mt-5 grid gap-4 md:grid-cols-3">
                    <article v-for="incident in systemStatus.incidents" :key="incident.key" class="rounded-2xl border p-4" :class="incident.status === 'warning' ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-slate-50/70'">
                        <div class="flex items-start justify-between gap-3"><p class="text-sm font-semibold text-slate-800">{{ incident.label }}</p><span class="text-2xl font-semibold" :class="incident.status === 'warning' ? 'text-amber-700' : 'text-slate-900'">{{ incident.count ?? '—' }}</span></div>
                        <p class="mt-4 text-xs text-slate-500">{{ incident.latest_at ? `最近发生于 ${dateLabel(incident.latest_at)}` : (incident.count === null ? '当前无法读取统计数据' : '没有异常记录') }}</p>
                    </article>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
